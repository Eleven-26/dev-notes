# 服务与systemd

> systemd 与 SysVinit 的差异、systemctl 常用命令、Unit 文件三段的写法、
> cgroups 资源限制、与 journalctl 的日志联动。
>
> 内容整理自个人学习笔记。**基础框架**参考《鸟哥的 Linux 私房菜：基础学习篇（第四版）》
> 相关章节（进程管理与 SELinux 初探、认识系统服务）的读后整理；cgroups v2 与 systemd 250+ 的差异基于官方文档独立整理。

---

## 一、systemd 是什么，和 SysVinit 差在哪？

**本节要点**：systemd 是现代 Linux 的 1 号进程（PID 1），它把「服务」抽象成 **unit**，用**并行 + 按需**启动取代了 SysVinit 的顺序启动。

| 维度 | SysVinit | systemd |
|------|----------|---------|
| 启动方式 | 串行，按 runlevel 顺序 | **并行**，按依赖关系启动 |
| 服务描述 | `/etc/init.d/` 脚本 | `.service` unit 文件 |
| 启动 / 停止 | `service x start` | `systemctl start x` |
| 依赖处理 | 脚本里自己判断 | `After=` / `Wants=` / `Requires=` 声明 |
| 按需启动 | 无 | 有（socket / dbus 激活） |
| 日志 | 各服务自己写 | 统一进 journald |

⭐ 一句话：SysVinit 是「一步步来」，systemd 是「你声明依赖，我来调度」。代价是 systemd 变复杂了，也因此常被吐槽。

### 1.1 unit 的几种类型

| 后缀 | 用途 |
|------|------|
| `.service` | 系统服务（最常用） |
| `.socket` | 套接字（按需激活服务） |
| `.target` | 一组 unit 的集合（相当于 runlevel，如 `multi-user.target`） |
| `.timer` | 定时任务（cron 的替代） |
| `.mount` / `.device` | 挂载点 / 设备（systemd 也能管挂载） |

---

## 二、systemctl 常用命令

**本节要点**：`start/stop/restart` 管本次运行，`enable/disable` 管开机自启——**这是两组正交的状态**，别混。

| 命令 | 作用 |
|------|------|
| `systemctl status nginx` | 看状态、最近日志、是否 enabled |
| `systemctl start / stop / restart nginx` | 本次运行控制 |
| `systemctl reload nginx` | 重载配置**不中断**（服务需支持） |
| `systemctl enable --now nginx` | 设开机自启**并立即启动** |
| `systemctl disable nginx` | 取消开机自启（不停止当前） |
| `systemctl list-units --type=service` | 列出所有服务单元 |
| `systemctl list-unit-files --state=enabled` | 列出已自启的 |
| `systemctl is-active / is-enabled nginx` | 脚本里判断用 |

```bash
systemctl status nginx            # ● active (running) / loaded / enabled
sudo systemctl enable --now nginx
systemctl cat nginx               # 看 unit 文件全文（含被 override 的）
```

⚠️ **`enabled` 不代表正在运行**：`enable` 只是建了个开机启动的软链接；`active` 才是当前在跑。排查「重启后没起来」时两者都要看。

---

## 三、Unit 文件怎么写？

**本节要点**：一个 service unit 通常三段——`[Unit]` 说「我是谁、依赖谁」、`[Service]` 说「怎么跑」、`[Install]` 说「什么时候自启」。

```text
[Unit]
Description=My App
After=network.target
Wants=network-online.target

[Service]
Type=simple
User=app
Group=app
WorkingDirectory=/srv/app
ExecStart=/srv/app/bin/app --config /srv/app/config.yaml
ExecReload=/bin/kill -HUP $MAINPID
Restart=on-failure
RestartSec=5

[Install]
WantedBy=multi-user.target
```

| 关键项 | 说明 |
|--------|------|
| `Type=simple` | 默认；`forking`（自身 fork 后退出）、`oneshot`（跑完即退）要按程序行为选 |
| `Restart=` | `on-failure` / `always` / `no`；崩溃自愈的关键 |
| `Environment=` / `EnvironmentFile=` | 注入环境变量 |
| `WantedBy=multi-user.target` | 决定 `enable` 挂到哪个 target |

```bash
sudo vim /etc/systemd/system/app.service
sudo systemctl daemon-reload        # ⚠️ 改完 unit 必须 daemon-reload
sudo systemctl enable --now app
```

⚠️ **`ExecStart` 必须是前台进程**：程序若自己 daemon 化（fork 到后台），要配 `Type=forking` 并给 `PIDFile=`，否则 systemd 会以为它退出了。

---

## 四、cgroups 与服务资源限制

**本节要点**：systemd 用 cgroups 给每个 service 建一个控制组，`CPUQuota` / `MemoryMax` 等指令直接落到 cgroup 上——这是「单个服务吃满整机」的解法。

```text
[Service]
CPUQuota=150%            # 最多用 1.5 个核
MemoryMax=512M           # 硬上限，超了被 OOM 杀
MemoryHigh=400M          # 软上限，超了开始回收
IOWeight=100             # I/O 权重（相对值）
```

```bash
systemd-cgtop                          # 按 cgroup 看 CPU/内存占用（像 top）
systemctl show app -p MemoryMax        # 看某服务的限制值
systemd-cgls                           # 看 cgroup 层级树
```

⚠️ **cgroups v1 vs v2**：CentOS 7 是 v1（各子系统独立挂载），Rocky 9 / 现代发行版默认 **v2**（统一层级）。⭐ 对使用者的影响主要是「容器运行时」——Docker / K8s 在 v2 上的资源限制语义不同，排查 OOM 时要先确认宿主是 v1 还是 v2。

---

## 五、和 journalctl 怎么联动？

**本节要点**：服务的 stdout / stderr 默认进 journald，`journalctl -u` 就是「这个服务的日志」——再也不用去猜日志写在哪个文件。

```bash
journalctl -u nginx -f            # 实时跟踪某服务
journalctl -u nginx --since "10 min ago"
journalctl -u nginx -p err        # 只看 error 及以上
journalctl -b -u nginx            # 本次开机以来的
```

⚠️ 服务「起不来但 journalctl 里没有服务日志」时，先看 `systemctl status` 的提示行（`ExecStart` 失败、权限、端口占用都在那里），再看 `journalctl -xe`。日志体系详见 [07-日志与journalctl.md](07-日志与journalctl.md)。

---

## 使用：把自研程序做成 systemd 服务

**本节要点**：从二进制到「开机自启 + 崩溃重拉 + 有日志」的完整闭环。

```bash
# ① 写 unit 文件（见上一节的模板）
sudo tee /etc/systemd/system/app.service > /dev/null <<'EOF'
[Unit]
Description=My App
After=network-online.target

[Service]
Type=simple
User=app
ExecStart=/srv/app/bin/app
Restart=on-failure
RestartSec=3
MemoryMax=512M

[Install]
WantedBy=multi-user.target
EOF

# ② 加载、启动、自启
sudo systemctl daemon-reload
sudo systemctl enable --now app

# ③ 验证
systemctl status app
journalctl -u app -n 20 --no-pager
# ④ 模拟崩溃：kill 掉主进程，看是否自动重拉
sudo systemctl kill -s KILL app && sleep 5 && systemctl is-active app
```

判据：`status` 显示 `active (running)` 且 `enabled`；`kill` 后 5 秒内 `is-active` 仍为 `active`（说明 `Restart=on-failure` 生效）；`systemctl show app -p MemoryMax` 是 `524288000`。

---

## 延伸追问

- **`systemctl` 和 `service` 的区别？**
  → `service` 是老的 SysVinit 前端（现在多数发行版把它转到 systemd）。用 `systemctl` 才能拿到「并行启动、依赖声明、日志联动、cgroup 限制」这些能力；`service x start` 在现代系统上通常被重定向到 `systemctl start x`，但行为不完全等价（比如它不认 `enable`）。
- **怎么让服务崩溃后自动重启？**
  → `[Service]` 里写 `Restart=on-failure`（或 `always`）+ `RestartSec=3`。⚠️ 注意两点：① `Restart` 有**频率限制**，短时间内反复失败会进入 `start-limit`，需 `systemctl reset-failed`；② 若进程是被 OOM 杀或自身正常退出（码 0），`on-failure` 可能不触发，按需用 `always`。
- **cgroups v1 和 v2 有什么区别？**
  → v1 里每个子系统（cpu、memory、blkio…）可**独立挂载**、一个进程可属于多个不同层级；v2 是**单一层级**（unified hierarchy），进程只属于一个 cgroup，管控更一致。对使用者的直接影响在**容器**：v2 下 Docker / containerd 的资源限制实现不同，K8s 的 `cgroupDriver` 要配成 `systemd`。
- **改了 unit 文件为什么没生效？**
  → 忘了 `systemctl daemon-reload`。systemd 缓存了 unit 的解析结果，改完文件必须 reload 才会重读；只 `restart` 服务不够。

---

## 关联

- [07-日志与journalctl.md](07-日志与journalctl.md) — 服务日志的完整体系与轮转
- [08-软件包管理.md](08-软件包管理.md) — 包安装的服务如何被 systemd 接管
- [04-Shell与脚本.md](04-Shell与脚本.md) — 脚本作为 `ExecStart` 时的注意点（前台进程）
- [进程与线程.md](../进程与线程.md) — 进程状态、信号与 `kill -HUP` 的原理
- [README.md](README.md) — 本目录导读：版本坐标与阅读顺序
> 反向引用（本篇被下列文档引到）：[03-用户与权限.md](03-用户与权限.md)、[10-系统管理综合.md](10-系统管理综合.md)、[11-容器化衔接.md](../鸟哥服务器架设篇/11-容器化衔接.md)
