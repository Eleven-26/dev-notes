# 防火墙与NAT

> firewalld 的 zone / service / port 三层模型与富规则、SNAT / DNAT 与端口转发、
> 以及「包到底有没有进来」的排错方法。
>
> 内容整理自个人学习笔记。**基础框架**参考鸟哥官网《服务器架设篇 - RockyLinux 9》
> 相关章节（防火墙与 NAT 服务器）的读后整理；firewalld 与 Docker 端口映射的关系基于官方文档独立整理。

---

## 一、firewalld 的三层模型

**本节要点**：firewalld 不让你直接写规则，而是把规则组织成「**区域 zone 里允许哪些 service / port**」；默认区域通常是 `public`。

| 概念 | 含义 |
|------|------|
| zone | 按信任程度分组的规则集（`public` / `trusted` / `dmz` / `drop`…） |
| service | 预定义的端口集合（`http` = 80/tcp，`https` = 443/tcp） |
| port | 直接放行某个端口（`8080/tcp`） |
| rich rule | 复杂规则（限源 IP、限频率、限日志） |

```bash
sudo firewall-cmd --get-default-zone        # 默认区域
sudo firewall-cmd --list-all                # 当前区域全部规则
sudo firewall-cmd --get-active-zones        # 哪个接口属于哪个区域

# 放行服务 / 端口（--permanent 才永久，之后 reload）
sudo firewall-cmd --add-service=https --permanent
sudo firewall-cmd --add-port=8080/tcp --permanent
sudo firewall-cmd --reload
```

### 1.1 富规则

```bash
# 只允许 10.0.0.0/24 访问 3306
sudo firewall-cmd --permanent --add-rich-rule='rule family="ipv4" \
  source address="10.0.0.0/24" port port="3306" protocol="tcp" accept'
sudo firewall-cmd --reload
```

⚠️ **运行时 vs 永久**：不带 `--permanent` 只改运行时、reload 即丢；带 `--permanent` 要 `--reload` 才生效。改完用 `--list-all` 复核。

---

## 二、NAT 服务器怎么做？

**本节要点**：NAT 改的是「包的地址」——**SNAT** 改源地址（内网出网），**DNAT** 改目标地址（外网访问内网服务，即端口转发）。

| 类型 | 改什么 | 方向 | 典型场景 |
|------|--------|------|----------|
| SNAT / MASQUERADE | 源 IP | 内网 → 外网 | 内网共享出口 |
| DNAT | 目标 IP + 端口 | 外网 → 内网 | 把公网 8080 转到内网 80 |

```bash
# ① 开启内核转发（NAT 服务器是路由器）
echo "net.ipv4.ip_forward = 1" | sudo tee /etc/sysctl.d/99-ipforward.conf
sudo sysctl --system

# ② SNAT：让 10.0.10.0/24 借本机出网（配合 firewalld 的 masquerade）
sudo firewall-cmd --permanent --zone=external --add-masquerade
sudo firewall-cmd --reload

# ③ DNAT：公网 8080 → 内网 10.0.10.20:80（端口转发）
sudo firewall-cmd --permanent --add-forward-port=\
  port=8080:proto=tcp:toport=80:toaddr=10.0.10.20
sudo firewall-cmd --reload
```

⭐ **DNAT 与端口转发本质是一回事**：把「目标地址 + 端口」改写成另一台主机 / 另一个端口，正是 DNAT 做的事。

---

## 三、防火墙排错：包到底进来了吗？

**本节要点**：排错要能区分「**没到**」「到了但被拒」「到了没服务听」三种情况——靠 counters、日志与抓包区分。

```bash
sudo firewall-cmd --list-all --zone=public     # 规则与（部分）计数
sudo firewall-cmd --set-log-denied=all         # ⭐ 记录被拒的包（排障专用，事后关掉）
journalctl -k -f | grep -i "DPT\|DROP"         # 看内核对被拒包的记录
```

| 现象 | 判断 | 下一步 |
|------|------|--------|
| 连不上、无任何日志 | 包没到或被上游丢 | 在上游 / 本机抓包（`ss`、`journalctl -k`） |
| 有 DROP 日志 | 到本机但被防火墙拒 | 放行端口 / 调整 zone |
| 无 DROP、本机也没监听 | 服务没起来或只听 127.0.0.1 | `ss -tulnp` 确认绑定地址 |

⚠️ 本机 `nc -vz 127.0.0.1 8080` 通、外部不通，多半是**服务只监听 127.0.0.1** 或**主机防火墙未放行**，与「网络不通」是两码事。

---

## 使用：开放一个对外服务并验证

**本节要点**：一条完整链路——放行端口 → 确认服务监听 → 从外部验证可达。

```bash
# ① 放行端口（永久）
sudo firewall-cmd --add-port=8080/tcp --permanent
sudo firewall-cmd --reload

# ② 确认服务监听在 0.0.0.0 而不是 127.0.0.1
ss -tulnp | grep 8080

# ③ 本机自测
curl -sS -o /dev/null -w "%{http_code}\n" http://127.0.0.1:8080/

# ④ 外部（另一台机）验证
nc -vz 10.0.10.20 8080
```

判据：`firewall-cmd --list-all` 里出现 `8080/tcp`、`ss` 显示监听 `0.0.0.0:8080`、本机 `curl` 返回 200/30x、外部 `nc` 显示 succeeded。

---

## 延伸追问

- **firewalld 和 iptables 的关系？**
  → `firewalld` 是**上层管理器**，`iptables` 是**下层规则工具**（现代内核里二者都落到 **nftables**）。firewalld 把「zone / service / port」翻译成底层规则，并维护「运行时 / 永久」两套状态；直接 `iptables` 就是裸写规则、无 zone。⭐ **二选一**，同时用会互相覆盖。
- **DNAT 和端口转发是一回事吗？**
  → 本质上是一回事：**端口转发就是 DNAT 的一种表达**（改目标地址 + 目标端口）。差别只在说法：说「端口转发」时强调「从 A 端口转到 B 服务」，说 DNAT 时强调「改写目标地址」。反向的 SNAT 才对应「内网出网改源地址」。
- **容器里的端口映射和主机防火墙冲突怎么办？**
  → Docker 默认会**自己写 iptables 规则并插在 filter 链前面**，可能绕过 firewalld 的 zone 规则 → 表现为「防火墙没放行但容器端口却可达」。处理办法：① 用 `firewall-cmd` 的 **`--add-forward-port`** / 在 `docker` zone 里放行；② 让 Docker 别动 iptables（`"iptables": false`，但需自行管理）；③ 只在 `127.0.0.1` 上发布端口（`-p 127.0.0.1:8080:80`），避免直接暴露。

---

## 关联

- [03-网络基础与规划.md](03-网络基础与规划.md) — 私有地址出网与路由（NAT 的前提）
- [网络分层与数据包旅程.md](../../网络/网络分层与数据包旅程.md) — 每一跳的地址改写（DNAT 的原理对照）
- [客户端真实IP与可信代理.md](../../网络/客户端真实IP与可信代理.md) — 反向代理下怎么拿到真实源 IP
- [网络与存储.md](../../../06-工程实践/部署/docker/网络与存储.md) — 容器网络模式与端口发布
- [README.md](README.md) — 本目录导读：版本坐标与阅读顺序
> 反向引用（本篇被下列文档引到）：[02-主机安全与SELinux.md](02-主机安全与SELinux.md)、[05-SSH远程连接.md](05-SSH远程连接.md)、[06-DHCP与NTP.md](06-DHCP与NTP.md)、[09-文件共享服务.md](09-文件共享服务.md)
