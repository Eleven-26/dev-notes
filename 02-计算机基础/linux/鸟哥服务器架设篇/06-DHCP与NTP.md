# DHCP与NTP

> DHCP 的四步交互与租约 / 固定地址绑定、客户端配置、chrony 时间同步与漂移排查；
> 看似简单，但租约与时间漂移是很多「莫名其妙」问题的根源。
>
> 内容整理自个人学习笔记。**基础框架**参考鸟哥官网《服务器架设篇 - RockyLinux 9》
> 相关章节（DHCP 与 NTP）的读后整理；协议交互细节与 [DHCP.md](../../网络/DHCP.md) 对照阅读。

---

## 一、DHCP 服务器怎么工作？

**本节要点**：客户端还没 IP，只能**广播**寻找服务器；四步交互 **DISCOVER → OFFER → REQUEST → ACK** 完成一次租约。

| 报文 | 方向 | 含义 |
|------|------|------|
| DISCOVER | 客户端广播 | 「谁是 DHCP 服务器？」 |
| OFFER | 服务器 → 客户端 | 「我可以给你这个地址」 |
| REQUEST | 客户端广播 | 「我要这个地址」（广播是为了告知其他服务器） |
| ACK | 服务器 → 客户端 | 「确认，租给你 N 秒」 |

```text
# /etc/dhcp/dhcpd.conf
subnet 10.0.20.0 netmask 255.255.255.0 {
  range 10.0.20.100 10.0.20.200;
  option routers 10.0.20.1;
  option domain-name-servers 10.0.10.53, 223.5.5.5;
  option domain-name "internal.example.com";
  default-lease-time 3600;
  max-lease-time 7200;
}

# 固定地址绑定（按 MAC）
host printer01 {
  hardware ethernet 52:54:00:aa:bb:cc;
  fixed-address 10.0.20.50;
}
```

```bash
sudo dnf install -y dhcp-server
sudo systemctl enable --now dhcpd
sudo journalctl -u dhcpd -f          # 看租约发放过程
```

⚠️ DHCP 服务器自身要有静态 IP；一个广播域里**只能有一个** DHCP 服务器（或做高可用 / 中继配合），否则会互相抢答。

---

## 二、客户端与租约怎么看？

**本节要点**：客户端拿租约、续租、释放；服务器侧的租约文件记录「谁拿了什么地址、什么时候到期」。

```bash
# Linux 客户端（NetworkManager）
nmcli connection modify ens33 ipv4.method auto
nmcli connection up ens33
ip addr show ens33                       # 看拿到的地址

# 主动续租 / 释放
sudo nmcli connection down ens33 && sudo nmcli connection up ens33
```

```bash
# 服务器侧租约文件
cat /var/lib/dhcpd/dhcpd.leases         # 记录租约（绑定、起止时间、客户端标识）
```

| 客户端 | 配置方式 |
|--------|----------|
| Linux | `nmcli ... ipv4.method auto` 或改 `ifcfg-*` 的 `BOOTPROTO=dhcp` |
| Windows | 适配器属性里选「自动获得 IP」；`ipconfig /release` + `/renew` |

⚠️ 租约到期前客户端会自动续租（默认续租点是 50% 租期）；续租失败会重新 DISCOVER。

---

## 三、NTP 时间服务器怎么配？

**本节要点**：现代发行版用 **chrony**，它比老的 ntpd 更适应「网络时断时续、虚拟机时钟漂移」的场景；服务端 / 客户端都靠 `chrony.conf` 里的 `server` / `pool` 指定上游。

| 角色 | 配置要点 |
|------|----------|
| 客户端 | `server` / `pool` 指向上游 NTP，`iburst` 加速首次同步 |
| 服务端 | 允许内网客户端同步（`allow 10.0.0.0/8`），本地时钟作兜底（`local stratum 10`） |

```text
# /etc/chrony.conf（客户端）
pool 2.rocky.pool.ntp.org iburst
server ntp.aliyun.com iburst
driftfile /var/lib/chrony/drift
makestep 1.0 3            # 前 3 次同步允许直接跳变
rtcsync
```

```bash
sudo dnf install -y chrony
sudo systemctl enable --now chronyd

chronyc sources -v            # 看上游服务器与状态（^* = 当前同步源）
chronyc tracking              # 看本机与上游的偏差、是否已同步
timedatectl                   # NTP service: active 才算真正在同步
```

⭐ **层级（stratum）**：stratum 0 是原子钟 / GPS，stratum 1 直连基准，stratum 2 从 stratum 1 同步……层级越大离基准越远。

⚠️ **时间漂移**的排查顺序：`timedatectl` 看 NTP 是否 active → `chronyc sources` 看有没有可达上游 → 检查 UDP 123 是否被防火墙挡。

---

## 使用：给内网搭一套「地址 + 时间」服务

**本节要点**：一个网关型主机的典型职责——给终端发地址、给全内网提供时间基准。

```bash
# ① DHCP（服务器需静态 IP）
sudo dnf install -y dhcp-server
sudo vi /etc/dhcp/dhcpd.conf          # 按上文模板填 subnet / range / option
sudo systemctl enable --now dhcpd
sudo journalctl -u dhcpd -n 20 --no-pager

# ② NTP 服务端：同步上游 + 对内提供服务
sudo dnf install -y chrony
sudo vi /etc/chrony.conf              # 加上 allow 10.0.0.0/8 与 local stratum 10
sudo systemctl enable --now chronyd

# ③ 验证
sudo firewall-cmd --add-service=dhcp --permanent && sudo firewall-cmd --reload
sudo firewall-cmd --add-service=ntp  --permanent && sudo firewall-cmd --reload
chronyc tracking
cat /var/lib/dhcpd/dhcpd.leases | tail -20
```

判据：客户端能拿到 `range` 内的地址与正确网关 / DNS；客户端 `chronyc sources` 里该服务器可达、`timedatectl` 显示同步 active。

---

## 延伸追问

- **DHCP 为什么要用广播？**
  → 因为在拿到地址**之前**，客户端还不知道自己该用哪个源 IP、也不知道服务器的 IP，无法单播——只能发到 `255.255.255.255`（或子网广播）让同广播域里的服务器听到。REQUEST 也用广播，是为了**告知其他可能应答过的服务器**「我已经选了这一家」。
- **时间不同步会引发什么问题？**
  → 一连串「看起来不相干」的故障：**TLS 证书校验失败**（「证书尚未生效 / 已过期」）、**日志时间线错乱**导致排查困难、**分布式系统选举 / 租约异常**（Raft、etcd、Redis 集群都依赖时钟）、**一次性口令（TOTP）失效**、**数据库主从延迟判断错误**。⚠️ 排查这类问题先 `timedatectl` 确认时间同步。
- **chrony 和 ntpd 的区别？**
  → `chrony` 是更现代的实现：**收敛更快**（首次同步可秒级）、**更适应断续网络与虚拟机时钟漂移**、配置简洁，已经是 RHEL / CentOS 8+ / Ubuntu 的默认。老 `ntpd` 仍在用，但新部署建议 chrony；⚠️ 两者不能同时在本机跑。

---

## 关联

- [DHCP.md](../../网络/DHCP.md) — DHCP 协议交互的原理对照
- [07-DNS服务器.md](07-DNS服务器.md) — DHCP 下发的 DNS 由谁来解析
- [04-防火墙与NAT.md](04-防火墙与NAT.md) — 放行 DHCP（udp/67、68）与 NTP（udp/123）
- [03-网络基础与规划.md](03-网络基础与规划.md) — 地址段规划是 DHCP 的前提
- [README.md](README.md) — 本目录导读：版本坐标与阅读顺序
