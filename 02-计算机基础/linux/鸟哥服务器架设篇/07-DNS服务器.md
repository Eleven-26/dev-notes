# DNS服务器

> BIND 的配置、正反解析、主从架构与缓存 DNS、dig / rndc 排错；
> 与 [DNS解析.md](../../网络/DNS解析.md) 形成「协议原理 ↔ 服务架设」的对照。
>
> 内容整理自个人学习笔记。**基础框架**参考鸟哥官网《服务器架设篇 - RockyLinux 9》
> 相关章节（DNS 服务器）的读后整理；BIND 9 的配置与工具用法基于官方文档独立整理。

---

## 一、DNS 基础回顾

**本节要点**：DNS 是**分布式的层级数据库**——从根出发逐级委派，最终由权威服务器给出答案；解析过程就是「一路问下来 + 一路缓存」。

| 记录 | 作用 | 例 |
|------|------|-----|
| `A` / `AAAA` | 域名 → IPv4 / IPv6 | `web01 A 10.0.10.20` |
| `CNAME` | 别名 | `www CNAME web01` |
| `MX` | 邮件服务器 | `@ MX 10 mail` |
| `NS` | 该域的权威服务器 | `@ NS ns1` |
| `PTR` | IP → 域名（反解析） | `20 PTR web01.example.com.` |
| `TXT` | 任意文本（SPF / DKIM / 验证） | `@ TXT "v=spf1 ..."` |

⭐ 完整查询流程（递归 + 迭代）见 [DNS解析.md](../../网络/DNS解析.md)；本篇讲「怎么把这套服务**架**起来」。

---

## 二、BIND 怎么配？

**本节要点**：BIND 的配置分两层——`named.conf` 说「有哪些 zone、去哪个文件找」，`zone 文件` 写「这个域里有哪些记录」。

```bash
sudo dnf install -y bind bind-utils
sudo systemctl enable --now named
```

```text
# /etc/named.conf（节选）
options {
  listen-on port 53 { any; };
  allow-query     { 10.0.0.0/8; localhost; };   # ⚠️ 别对公网开放递归
  recursion yes;
  forwarders { 223.5.5.5; };
  dnssec-validation no;
};

zone "internal.example.com" IN {
  type master;
  file "internal.example.com.zone";
};
zone "10.0.10.in-addr.arpa" IN {          # 反解析：网段倒写 + in-addr.arpa
  type master;
  file "10.0.10.rev";
};
```

```text
# /var/named/internal.example.com.zone
$TTL 3600
@   IN  SOA ns1.internal.example.com. admin.internal.example.com. (
        2026100401 ; serial ⭐ 改一次 +1，从服务器靠它判断要不要同步
        3600       ; refresh
        900        ; retry
        604800     ; expire
        86400 )    ; minimum
    IN  NS  ns1.internal.example.com.
ns1 IN  A   10.0.10.53
web01 IN A 10.0.10.20
www IN  CNAME web01
```

⚠️ zone 文件里写域名要注意**结尾的点**（`web01.example.com.` 才是全限定名）；少一个点会变成相对名，是经典事故。

---

## 三、主从与缓存怎么搭？

**本节要点**：主从靠 **区域传送**（AXFR 全量 / IXFR 增量）同步，从服务器用 `serial` 判断新旧；缓存 DNS 只替客户端递归查询、不持有权威数据。

```text
# 从服务器 /etc/named.conf
zone "internal.example.com" IN {
  type slave;
  masters { 10.0.10.53; };
  file "slaves/internal.example.com.zone";
};
```

```bash
sudo rndc reload                # 主服务器改完 zone 后重载
sudo rndc status                # 运行状态
sudo rndc notify                # 主动通知从服务器
sudo rndc zonestatus internal.example.com
```

| 类型 | 持有数据 | 用途 |
|------|----------|------|
| 权威（master / slave） | ✅ 该域的全部记录 | 内网域名解析 |
| 缓存 / 转发（forwarder） | ❌ 只缓存 | 给内网提供统一出口递归 |

⚠️ **限制区域传送**：只允许从服务器做 AXFR（`allow-transfer { 10.0.10.54; };`）——否则整个内网的域名清单可被外人一次拉走。

---

## 四、DNS 怎么排错？

**本节要点**：`dig` 是主力的查询与诊断工具，`rndc` 管服务状态；先「问对服务器」再「看返回码」。

```bash
dig @10.0.10.53 web01.internal.example.com A +short
dig @10.0.10.53 -x 10.0.10.20 +short           # 反解析
dig web01.internal.example.com +trace          # 从根开始逐级追踪
nslookup web01.internal.example.com 10.0.10.53
named-checkconf                                 # 校验 named.conf 语法
named-checkzone internal.example.com /var/named/internal.example.com.zone   # 校验 zone 文件
```

| 返回码 | 含义 | 常见原因 |
|--------|------|----------|
| `NOERROR` | 成功 | — |
| `NXDOMAIN` | 域名不存在 | 记录没写 / 写错域 |
| `SERVFAIL` | 服务器失败 | zone 文件语法错、转发不可达 |
| `REFUSED` | 拒绝 | `allow-query` 没包含你的来源 IP |
| `TIMEOUT` | 无响应 | 防火墙挡了 udp/53、服务没起 |

⚠️ `named-checkconf` + `named-checkzone` 是**改完先跑**的习惯动作；改错 zone 文件会直接让该域解析全挂。

---

## 使用：为内网域名搭一台权威 DNS

**本节要点**：从装包到「用 dig 验证内外解析」的一条龙。

```bash
# ① 装 BIND
sudo dnf install -y bind bind-utils

# ② 写 named.conf（zone）与 zone 文件（上文的模板）
#    /etc/named.conf、/var/named/internal.example.com.zone、/var/named/10.0.10.rev

# ③ 先校验，再启动
sudo named-checkconf
sudo named-checkzone internal.example.com /var/named/internal.example.com.zone
sudo systemctl enable --now named

# ④ 放行 53（对内网段）
sudo firewall-cmd --add-service=dns --permanent && sudo firewall-cmd --reload

# ⑤ 验证
dig @127.0.0.1 web01.internal.example.com +short
dig @127.0.0.1 -x 10.0.10.20 +short
```

判据：`named-checkconf` / `named-checkzone` 无输出（表示 OK）；`dig` 正解析返回 `10.0.10.20`、反解析返回 `web01.internal.example.com.`；`REFUSED` 说明来源未在 `allow-query`。

---

## 延伸追问

- **区域传送为什么容易被滥用？**
  → 因为默认若不加 `allow-transfer`，任何能连上 53 端口的人都能发起 **AXFR** 把你整个域的记录**一次性拉走**——等于把内网主机名 / IP 清单拱手送人，直接为后续攻击提供地图。所以必须显式限制只让从服务器传。
- **缓存 DNS 和递归 DNS 是一回事吗？**
  → 常被混用但不完全等同：**递归**指「替你从根一路问到底」的能力，**缓存**指「把问过的答案存下来复用」。内网 **forwarder / caching DNS** 通常两者兼具：对客户端做递归（或转发给上游），并缓存结果。⭐ 关键安全点：**权威服务器不应对外提供递归**（否则会被利用做 DNS 放大攻击）。
- **内网 DNS 和公网 DNS 怎么共存？**
  → 两条路：① **分域**——内网域（如 `internal.example.com`）只在内网 BIND 上解析，公网域用公网权威；客户端 / 路由器把内网 DNS 内网域指向内网服务器、其余转发上游；② **同域 split-horizon（视图）**——BIND 的 `view` 按来源网段返回不同记录，内网解析到内网 IP、公网解析到公网 IP。

---

## 关联

- [DNS解析.md](../../网络/DNS解析.md) — 查询流程、递归与迭代、缓存（原理层）
- [10-邮件服务器.md](10-邮件服务器.md) — SPF / DKIM / DMARC 都靠 TXT 记录
- [06-DHCP与NTP.md](06-DHCP与NTP.md) — DHCP 下发的 DNS 指向这里
- [03-网络基础与规划.md](03-网络基础与规划.md) — 内网网段与反解析域的对应
- [README.md](README.md) — 本目录导读：版本坐标与阅读顺序
