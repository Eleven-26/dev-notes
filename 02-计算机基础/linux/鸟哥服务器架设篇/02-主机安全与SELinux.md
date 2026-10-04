# 主机安全与SELinux

> 架站之前的安全基线：最小化原则、SELinux 的三种模式与上下文、auditd 与异常登录检测。
>
> 内容整理自个人学习笔记。**基础框架**参考鸟哥官网《服务器架设篇 - RockyLinux 9》
> 相关章节（主机安全）的读后整理；SELinux 命令与 auditd 用法基于官方文档独立整理。

---

## 一、最小化原则：少开就是安全

**本节要点**：一部没经过保护就联上 Internet 的 Linux 主机，可能在数小时内被入侵或当成跳板——安全的第一步不是装工具，而是**关掉不用的东西**。

### 1.1 三件事

1. **最小化安装**：装系统时只选必要组件，少一个服务就少一个漏洞面；
2. **关停不需要的服务**：`systemctl disable --now <svc>`；
3. **只开放需要的端口**：对外暴露的端口越少越好（配合 [04-防火墙与NAT.md](04-防火墙与NAT.md)）。

```bash
ss -tulnp                              # 本机在监听哪些端口、属哪个进程
systemctl list-unit-files --state=enabled   # 哪些服务开机自启
sudo firewall-cmd --list-all           # 防火墙实际放开了什么
```

### 1.2 更新策略

```bash
sudo dnf check-update                  # 看有哪些更新
sudo dnf update -y                     # 全部更新
sudo dnf update --security -y          # ⭐ 只装安全更新（更稳）
```

⚠️ 生产环境别在不打招呼时「全部更新」——内核 / glibc 升级可能需要重启或影响兼容性；建议**安全更新自动化、功能性更新走变更流程**。

---

## 二、SELinux：内核级的强制访问控制

**本节要点**：传统权限（rwx）管「谁（用户）能对什么做什么」；SELinux 管「**进程（域）能对什么资源（类型）做什么**」，粒度更细、且默认拒绝。

### 2.1 三种模式

| 模式 | 行为 | 用途 |
|------|------|------|
| `enforcing` | 拒绝并记录违规 | 生产推荐 |
| `permissive` | **只记录不拒绝** | 排障 / 调试策略 |
| `disabled` | 完全关闭 | ⚠️ 不推荐 |

```bash
getenforce                       # 看当前模式
sudo setenforce 0                # 临时切 permissive（重启失效）
sestatus                         # 详细状态（含策略类型 targeted）
# 永久：/etc/selinux/config 的 SELINUX=enforcing|permissive|disabled（改完需重启）
```

### 2.2 上下文（context）与布尔值（boolean）

```bash
ls -Z /var/www/html               # 看文件的安全上下文（type）
ps -eZ | grep nginx               # 看进程的域

# 给自定义目录打上正确的上下文（服务才能访问）
sudo semanage fcontext -a -t httpd_sys_content_t "/srv/web(/.*)?"
sudo restorecon -Rv /srv/web

getsebool -a | grep httpd         # 看与 httpd 相关的开关
sudo setsebool -P httpd_can_network_connect on   # -P 永久
```

⭐ **「服务起不来但日志没明显报错」时**，先怀疑 SELinux：`sudo ausearch -m avc -ts recent` 看有没有 AVC 拒绝记录。

---

## 三、审计与异常登录检测

**本节要点**：`auditd` 记录系统调用与安全事件，`last` / `lastb` 看登录；这两样是「有没有人动过我的机器」的证据来源。

```bash
sudo systemctl status auditd --no-pager
sudo ausearch -m avc -ts today          # 今天的 SELinux 拒绝
sudo aureport --auth                    # 认证事件汇总

last | head                            # 成功登录记录
sudo lastb | head                      # 失败登录（暴力破解痕迹）
sudo lastlog | grep -v "Never"         # 各账号最近一次登录
journalctl -u sshd --since "today" | grep -i "failed\|invalid" | head
```

| 关注点 | 线索 |
|--------|------|
| 暴力破解 | `lastb` 大量记录、sshd 日志成片 Failed password |
| 异常提权 | `aureport --auth` 里的 sudo / su 记录 |
| 策略拦截 | `ausearch -m avc` 的 AVC 记录 |
| 可疑账号 | `/etc/passwd` 里多出的 UID 0 账号 |

---

## 使用：给一台新 Web 机做安全基线

**本节要点**：把「最小化 + SELinux + 审计」落成一次可复现的检查。

```bash
# ① 盘点暴露面
ss -tulnp
systemctl list-unit-files --state=enabled | head

# ② 确认 SELinux 开启（生产应为 enforcing）
getenforce
sestatus | head -5

# ③ 站点目录上下文（自定义路径时必做）
sudo semanage fcontext -a -t httpd_sys_content_t "/srv/web(/.*)?"
sudo restorecon -Rv /srv/web

# ④ 排障时看 AVC
sudo ausearch -m avc -ts recent

# ⑤ 检查可疑登录
sudo lastb | head
```

判据：`ss` 里没有意料之外的监听端口、`getenforce` 是 `Enforcing`、`ls -Z /srv/web` 是 `httpd_sys_content_t`、`ausearch` 无持续 AVC 告警。

---

## 延伸追问

- **SELinux 和防火墙有什么区别？**
  → 层次不同：**防火墙**管「网络包能不能进来 / 出去」（网络层，按端口 / 地址）；**SELinux** 管「进了本机的进程能访问哪些资源」（主机层，按进程域与文件类型）。二者互补——防火墙放行了 80 端口，若 nginx 的 domain 无权读某个文件，SELinux 仍会拒。
- **为什么建议 enforcing 而不是 disabled？**
  → `disabled` 意味着放弃一整层保护（也是「出事之后再想开」最麻烦的状态：重新开启要重打文件标签，`autorelabel` 耗时且有风险）。真遇到策略问题，用 `permissive` 先定位、再按需加策略 / 布尔值，最终回到 `enforcing`。
- **怎么快速判断一个问题是 SELinux 引起的？**
  → 三步：① `getenforce` 确认在 enforcing；② `sudo ausearch -m avc -ts recent` 或 `journalctl -t setroubleshoot` 找 AVC 记录；③ 临时 `sudo setenforce 0` 验证「问题是否消失」。⚠️ 验证完记得切回 enforcing，并把正确的上下文 / 布尔值补上。

---

## 关联

- [01-虚拟化与云系统搭建.md](01-虚拟化与云系统搭建.md) — 母机与虚拟机的安全基线
- [04-防火墙与NAT.md](04-防火墙与NAT.md) — 网络层的访问控制（与本篇互补）
- [05-SSH远程连接.md](05-SSH远程连接.md) — 防暴力破解与密钥登录
- [README.md](../鸟哥基础学习篇/README.md) — 基础学习篇：进程管理与权限模型
- [README.md](README.md) — 本目录导读：版本坐标与阅读顺序
> 反向引用（本篇被下列文档引到）：[08-WWW服务器.md](08-WWW服务器.md)、[09-文件共享服务.md](09-文件共享服务.md)、[10-邮件服务器.md](10-邮件服务器.md)
