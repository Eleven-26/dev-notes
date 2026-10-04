# 日志与journalctl

> systemd-journald 与 rsyslog 的分工、journalctl 常用查询、logrotate 日志轮转、
> 内核与启动日志的排查。
>
> 内容整理自个人学习笔记。**基础框架**参考《鸟哥的 Linux 私房菜：基础学习篇（第四版）》
> 相关章节（认识与分析登录档）的读后整理；journald 配置与 logrotate 的现代用法基于官方文档独立整理。

---

## 一、日志体系由哪几层组成？

**本节要点**：现代 Linux 有**两套并存的日志**——systemd-journald（二进制、结构化）与 rsyslog（文本、可转发），内核另有自己的环形缓冲区。

| 组件 | 形态 | 特点 |
|------|------|------|
| `systemd-journald` | 二进制（`/var/log/journal/`，默认可能只在内存） | 结构化、可按字段查、日志量大时占空间 |
| `rsyslog` | 文本（`/var/log/messages`、`/var/log/secure`） | 可转发到远端、默认按设施分文件 |
| 内核环形缓冲区 | 内存（`dmesg`） | 记录硬件、驱动、OOM、网络等内核事件 |

```bash
journalctl -b                    # 本次开机的全部日志（journald）
tail -f /var/log/messages        # 系统常规日志（rsyslog）
sudo tail -f /var/log/secure     # 认证与登录（rsyslog）
dmesg -T                         # 内核日志（带人类可读时间）
```

⭐ 分工：**服务自己的 stdout/stderr** 进 journald（`journalctl -u`）；**系统级事件**（登录、内核）通常两边都有，rsyslog 更适合转发到日志中心。

---

## 二、journalctl 常用命令

**本节要点**：journalctl 的强项是「按维度过滤」——按服务、按时间、按优先级，而不是靠 `grep` 一个文本文件。

```bash
journalctl -u nginx                 # 只看某服务
journalctl -u nginx -f              # 实时跟踪（follow）
journalctl -u nginx --since "10 min ago"
journalctl --since "2026-10-04 09:00" --until "2026-10-04 10:00"
journalctl -p err -b                # 本次开机里 error 及以上
journalctl -b -1                    # 上一次开机的日志（重启后排查利器）
journalctl -k                       # 只内核日志（等价 dmesg）
```

| 选项 | 作用 |
|------|------|
| `-u <unit>` | 按服务过滤 |
| `-p <级别>` | `emerg/alert/crit/err/warning/notice/info/debug` |
| `-b [N]` | 本 / 第 N 次开机 |
| `-f` | 持续输出 |
| `-o json-pretty` | 输出结构化字段，便于程序解析 |
| `--no-pager` | 不分页（脚本里必加） |

⚠️ 加 `--no-pager`（或管道 `| cat`）避免进入 `less`，否则脚本 / 自动化会卡住。

---

## 三、日志轮转：logrotate

**本节要点**：日志无限增长会撑满磁盘，`logrotate` 按「时间 / 大小」切分、压缩、保留 N 份——系统日志的策略在 `/etc/logrotate.d/`。

```text
/var/log/myapp/*.log {
    daily              # 每天轮转
    rotate 7           # 保留 7 份
    size 100M          # 或超过 100M 就轮转
    compress           # 压缩旧文件
    delaycompress      # 最近一份先不压
    missingok          # 文件不存在不报错
    notifempty         # 空文件不轮转
    copytruncate       # 拷完再清空（程序不会重开文件时用）
}
```

```bash
sudo logrotate -d /etc/logrotate.d/myapp     # -d 干跑，看它会做什么
sudo logrotate -f /etc/logrotate.d/myapp     # -f 强制立即轮转
```

- 默认由 **systemd timer**（`logrotate.timer`）或 cron 每天触发；
- ⚠️ `copytruncate` 与 `create` 二选一：程序会按配置重开日志（如 nginx `USR1` 信号）用 `create`；不会重开的用 `copytruncate`（有极小的丢日志窗口）。

---

## 四、内核与启动日志怎么查？

**本节要点**：`dmesg` / `journalctl -k` 看内核，`journalctl -b -1` 看上次启动，启动失败先进救援模式（rescue / emergency）。

```bash
dmesg -T | tail -50              # 最近的内核消息（-T 人类时间）
dmesg -T | grep -i "oom\|error"  # 找 OOM 与错误
journalctl -b -p err             # 本次开机的错误
journalctl -b -1 -p err          # 上次开机的错误（重启后排查）
journalctl --list-boots          # 有哪些启动记录
```

| 现象 | 线索 |
|------|------|
| 进程被杀 | `dmesg` 里的 `Out of memory: Killed process` |
| 磁盘报错 | `dmesg` 里的 I/O error、`EXT4-fs error`、`XFS ... corruption` |
| 服务未启动 | `systemctl --failed`、`journalctl -u <svc> -b` |
| 启动卡住 | 进 rescue mode，`journalctl -xb` 看当次 |

⚠️ 内核环形缓冲区**重启即清**（除非配了持久 journal），要留证据先 `dmesg > /tmp/dmesg.log` 落盘。

---

## 使用：定位「服务半夜挂了」

**本节要点**：典型排查路径——先看服务单元状态，再按时间 / 优先级翻 journal，必要时看内核与上次启动。

```bash
# ① 服务当前状态与失败原因
systemctl status myapp --no-pager
systemctl --failed

# ② 按时间窗翻服务日志
journalctl -u myapp --since "today 00:00" --until "today 08:00" -p warning --no-pager

# ③ 若是被 OOM 杀
dmesg -T | grep -i "killed process"
journalctl -k --since "today 00:00" | grep -i oom

# ④ 看是不是整机重启过
journalctl --list-boots
last -x | head
```

判据：能从 `journalctl -u` 里找到**最后一条业务日志**与**退出原因**；若是 OOM，`dmesg` 里能看到被杀进程名与 RSS。

---

## 延伸追问

- **journalctl 的日志存在哪？**
  → 两种模式：**易失**（默认，存 `/run/log/journal/`，重启即丢，占内存）与**持久**（存 `/var/log/journal/`，重启还在）。是否为持久看 `/etc/systemd/journald.conf` 的 `Storage=`（`auto` 时「目录存在就持久」）。
- **怎么永久保存 journal？**
  → `sudo mkdir -p /var/log/journal && sudo systemd-tmpfiles --create --prefix /var/log/journal && sudo systemctl restart systemd-journald`，并把 `Storage=persistent`。⚠️ 持久化会占磁盘，要同时配 `SystemMaxUse=` 限制总量，否则日志能吃满盘。
- **日志太多怎么清理？**
  → `journalctl --vacuum-size=500M` 或 `--vacuum-time=7d` 按体积 / 时间清理；rsyslog 的文本日志交给 `logrotate`。根治办法是**调低日志级别**（不要全开 debug）并配 `SystemMaxUse` / `MaxRetentionSec`。
- **`journalctl -u` 没有某服务的日志？**
  → 可能：① 服务没通过 systemd 启动（手动跑的进程不进 journal）；② 服务把日志写进了自己的文件而不是 stdout；③ 该日志在**上一次开机**（加 `-b -1`）；④ 日志被 vacuum 清掉了。

---

## 关联

- [05-服务与systemd.md](05-服务与systemd.md) — 服务状态与 `journalctl -u` 的联动
- [10-系统管理综合.md](10-系统管理综合.md) — 例行巡检里对磁盘 / 日志的检查项
- [性能排查.md](../性能排查.md) — OOM Killer 与内核日志的排查套路
- [内存管理.md](../内存管理.md) — OOM 判定与 Page Cache 的原理
- [README.md](README.md) — 本目录导读：版本坐标与阅读顺序
> 反向引用（本篇被下列文档引到）：[06-网络配置与nmcli.md](06-网络配置与nmcli.md)
