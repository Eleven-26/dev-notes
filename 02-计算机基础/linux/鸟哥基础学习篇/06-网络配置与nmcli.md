# 网络配置与nmcli

> IP / 掩码 / 网关 / DNS 的概念与查看、nmcli 的命令式配置、路由与解析、
> firewalld 与 nftables 的防火墙、常用排错命令。
>
> 内容整理自个人学习笔记。**基础框架**参考《鸟哥的 Linux 私房菜：基础学习篇（第四版）》
> 相关章节（基础系统设置与备份策略中的网络设置）的读后整理；nmcli / nftables / firewalld 的现代用法基于官方文档独立整理。

---

## 一、网络基础参数有哪些？

**本节要点**：一台主机联网只需四样东西——**IP、子网掩码、默认网关、DNS**；前三个决定「能不能通」，DNS 决定「能不能用域名通」。

| 参数 | 作用 | 典型值 |
|------|------|--------|
| IP | 主机地址 | `192.168.1.10/24` |
| 子网掩码 | 划分网络位与主机位 | `/24` = `255.255.255.0` |
| 默认网关 | 出本网段的出口 | `192.168.1.1` |
| DNS | 域名解析 | `223.5.5.5` |

```bash
ip addr show                 # 看 IP（取代 ifconfig）
ip route show                # 看路由表与默认网关
cat /etc/resolv.conf         # 看 DNS（⚠️ 可能被 NetworkManager 管理）
```

### 1.1 临时 vs 永久

- **临时**：`ip addr add` / `ip route add` 直接生效，**重启即丢**；
- **永久**：改 NetworkManager 连接（`nmcli` / `/etc/sysconfig/network-scripts/`），重启仍在。

⚠️ CentOS 7 / RHEL 8+ 默认由 **NetworkManager** 管理网络，手改 `ifcfg-*` 文件后要 `nmcli connection reload` 或重启服务，否则可能被覆盖。

---

## 二、nmcli 常用命令

**本节要点**：`nmcli` 三件事——看设备、看连接、改连接；「设备」是物理网卡，「连接」是配置档案，一个设备可挂多个连接。

```bash
nmcli device status                      # 设备与它当前挂的连接
nmcli connection show                    # 所有连接（配置档案）
nmcli connection show "ens33"            # 看某连接的详细参数

# 改：静态 IP（一条命令改多个属性）
sudo nmcli connection modify ens33 \
  ipv4.addresses 192.168.1.10/24 \
  ipv4.gateway 192.168.1.1 \
  ipv4.dns "223.5.5.5 8.8.8.8" \
  ipv4.method manual
sudo nmcli connection up ens33           # 让配置生效

# 改回 DHCP
sudo nmcli connection modify ens33 ipv4.method auto
```

| 命令 | 作用 |
|------|------|
| `nmcli con up/down <名>` | 启用 / 停用某连接 |
| `nmcli con mod <名> ...` | 改属性（`+` / `-` 前缀可增删列表项，如 `+ipv4.dns`） |
| `nmcli dev disconnect <设备>` | 断开设备 |
| `nmcli networking off/on` | 全局开关网络 |

⭐ 记住：`nmcli con mod` 只改「档案」，要 `nmcli con up` 才「生效」。

---

## 三、路由与 DNS 怎么配？

**本节要点**：默认网关管「出网段」，额外路由管「去特定网段走特定口」；DNS 在 NetworkManager 下由连接托管，`resolv.conf` 只是结果。

### 3.1 路由

```bash
ip route show
ip route get 8.8.8.8                    # 看内核会选哪条路
sudo ip route add 10.20.0.0/16 via 192.168.1.254   # 临时加一条
sudo ip route del 10.20.0.0/16                     # 删除
```

⚠️ `ip route add` 是**临时**的，重启即失；永久路由要在连接里加 `ipv4.routes`。

### 3.2 DNS

```bash
nmcli connection modify ens33 ipv4.dns "223.5.5.5"   # 永久
nmcli connection up ens33
cat /etc/resolv.conf                                  # 看实际生效的
```

- NetworkManager 会**生成** `/etc/resolv.conf`（常是 `resolv.conf` 软链或由 `systemd-resolved` 托管）；
- ⚠️ **手改 `/etc/resolv.conf` 可能被覆盖**：要永久生效就改连接配置，或用 `nmcli con mod ... ipv4.ignore-auto-dns yes`。
- DNS 解析的协议原理见 [DNS解析.md](../../网络/DNS解析.md)。

---

## 四、防火墙怎么开端口？

**本节要点**：现代发行版默认 `firewalld`（背后是 nftables / iptables），管控的是「**区域（zone）** 里允许哪些服务 / 端口」；CentOS 7 用 iptables 后端的 firewalld，Rocky 9 起是 nftables 后端。

```bash
sudo firewall-cmd --state                      # 是否运行
sudo firewall-cmd --get-default-zone           # 默认区域
sudo firewall-cmd --list-all                   # 当前区域的全部规则

# 开放端口 / 服务（--permanent 才是永久，需 reload）
sudo firewall-cmd --add-port=8080/tcp --permanent
sudo firewall-cmd --add-service=https --permanent
sudo firewall-cmd --reload
```

| 概念 | 说明 |
|------|------|
| zone | 按「信任程度」分组的规则集（`public` / `trusted` / `dmz`…） |
| service | 预定义的端口集合（`http` = 80、`https` = 443） |
| port | 直接开某个端口 |
| rich rule | 复杂规则（限来源 IP、限频率） |

⚠️ 忘了 `--permanent`：`firewall-cmd` 默认只改**运行时**规则，reload 或重启就没了。

---

## 五、网络排错从哪查起？

**本节要点**：按「能不能通 → 通到哪 → 谁在听」三层排查：`ping` 测连通、`traceroute` 看路径、`ss` 看本机监听与连接。

```bash
ping -c 3 192.168.1.1          # 网关通不通（L3）
ping -c 3 223.5.5.5            # 出网段通不通
traceroute 8.8.8.8             # 逐跳看路径断在哪

ss -tulnp                      # 本机在监听哪些 tcp/udp 端口、属哪个进程
ss -tan state established      # 已建立的连接
nmcli device status            # 网卡与连接状态
```

| 现象 | 先看 |
|------|------|
| 完全不通 | `ip addr`（有没有 IP）、`ip route`（有没有默认网关） |
| 通网关不通外网 | 默认网关、防火墙（出站）、NAT |
| 域名不通但 IP 通 | `cat /etc/resolv.conf`、`dig` 测解析 |
| 服务连不上 | `ss -tulnp` 看有没有监听、`firewall-cmd` 看端口开没开 |

⚠️ `ss` 里监听地址是 `127.0.0.1:8080` 时，只有本机能连；对外要监听 `0.0.0.0`。

---

## 使用：给新机器配一套静态网络

**本节要点**：从查设备到验证连通的一条龙，改完不重启即生效。

```bash
# ① 找到设备名与当前连接
nmcli device status

# ② 配静态 IP
sudo nmcli connection modify ens33 \
  ipv4.method manual \
  ipv4.addresses 192.168.1.50/24 \
  ipv4.gateway 192.168.1.1 \
  ipv4.dns "223.5.5.5"
sudo nmcli connection up ens33

# ③ 开放服务端口（永久）
sudo firewall-cmd --add-service=https --permanent && sudo firewall-cmd --reload

# ④ 验证
ip addr show ens33
ip route show
ping -c 2 192.168.1.1
ss -tulnp | grep -E "443|80"
```

判据：`ip addr` 是目标 IP、`ip route` 有默认网关、`ping` 网关通、`ss` 能看到端口监听、外部 `firewall-cmd --list-all` 里出现 https。

---

## 延伸追问

- **nmcli 和直接改配置文件哪个好？**
  → 优先 `nmcli`。它是 NetworkManager 的**官方接口**，改完即写回配置并保持一致；手改 `/etc/sysconfig/network-scripts/ifcfg-*` 也能生效，但要记得触发重载，且容易被 NM 的其他操作覆盖。脚本化 / 远程批量场景尤其推荐 `nmcli`（一条命令、免交互）。
- **firewalld 和 iptables 的关系？**
  → `firewalld` 是**上层管理器**，`iptables` 是**下层的规则工具**（现代内核里两者最终都落到 **nftables**）。firewalld 把「区域 + 服务 + 端口」翻译成底层规则，还支持运行时/永久两套规则；直接用 `iptables` 就是裸写规则、无 zone 概念。⭐ 二选一即可，**不要两个同时管**，否则互相覆盖。
- **怎么临时加一条路由？**
  → `sudo ip route add 10.20.0.0/16 via 192.168.1.254 dev ens33`。临时路由重启即失；要永久就在 NM 连接里加 `ipv4.routes "10.20.0.0/16 192.168.1.254"` 再 `nmcli con up`。
- **改了 `/etc/resolv.conf` 为什么过一会儿又变回去？**
  → 它被 NetworkManager / `systemd-resolved` 托管，运行时会**重新生成**。永久 DNS 要在连接里配（`nmcli con mod ... ipv4.dns`），或设 `ipv4.ignore-auto-dns yes` 阻止 DHCP 下发的 DNS 覆盖你的配置。

---

## 关联

- [DNS解析.md](../../网络/DNS解析.md) — 域名解析的协议原理（本节的原理对照）
- [网络分层与数据包旅程.md](../../网络/网络分层与数据包旅程.md) — 一个包从应用到网卡的完整路径
- [07-日志与journalctl.md](07-日志与journalctl.md) — 网络问题的日志从哪看
- [常用命令.md](../常用命令.md) — 查端口、看连接的命令速查
- [README.md](README.md) — 本目录导读：版本坐标与阅读顺序
