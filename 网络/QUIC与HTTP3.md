# QUIC 与 HTTP/3

> HTTP/3 为什么把 TCP 换掉、QUIC 在 UDP 之上重建了哪些东西、0-RTT 与连接迁移怎么工作，
> 以及浏览器**怎么知道**该不该走 HTTP/3（答案藏在 DNS 里）。
>
> 内容整理自个人学习笔记；**第三节的 SVCB 与 ALPN 是本机实测**（Windows 11，2026-09-30）。
> TCP 侧细节见 [tcp/](tcp/) 五篇，TLS 见 [HTTPS与TLS.md](HTTPS与TLS.md)，
> 协议选型见 [通信选型.md](通信选型.md)，链路与逐跳见 [网络通信链路详解.md](网络通信链路详解.md)。

---

## 一、HTTP/3 为什么把 TCP 换掉了？

**本节要点**：不是"TCP 慢"，而是 **TCP 的队头阻塞**在丢包时无法回避——
HTTP/2 把多路复用做在了应用层，却仍被下面那一条 TCP 连接拖住。QUIC 的解法是**把"流"做进传输层**。

### 1.1 HTTP/2 的尴尬：应用层多路复用，被传输层连坐

HTTP/2 用**一条 TCP 连接**跑所有请求（流），看起来很美。但只要链路丢一个包：

```text
一条 TCP 连接 = 一个有序字节流
丢包 → 后面的字节全部要等重传 → **所有 HTTP/2 流一起卡住**（传输层队头阻塞）
```

这是**协议设计问题**，不是实现问题：TCP 不知道"字节流里哪一段属于哪个请求"。

### 1.2 QUIC 的解法：流级别的重传

QUIC 把"流"这个概念搬到了传输层：每个流有**独立的序号空间**，一个流丢包**只重传那个流的数据**，
其他流照常交付。所以：

| | HTTP/1.1 | HTTP/2 | HTTP/3 |
|---|---|---|---|
| 多路复用 | 靠开多条连接 | 一条连接多个流（**应用层**） | 一条连接多个流（**传输层**） |
| 丢包影响 | 只影响那条连接 | **所有流一起等** | **只影响出问题的那条流** |
| 建连开销 | 每连接 TCP + TLS | 同左（复用连接后省） | **1-RTT 建连建流一步到位** |

### 1.3 那为什么不直接改 TCP？

三条现实理由，比"技术先进"更重要：

1. **中间设备固化**：互联网上大量防火墙 / NAT / 负载均衡只认 TCP/UDP 的固定头部结构，
   新的 TCP 选项与语义很可能被丢弃或改写（还记得 [网络通信链路详解.md](网络通信链路详解.md) 里
   "每一跳都可能改 MAC / IP / 端口"吗）；
2. **协议升级的推进速度**：TCP 跑在内核里，升级要等操作系统与控制面；QUIC **跑在用户态**，
   浏览器随版本更新就能改；
3. **加密是前提不是附加**：QUIC 强制加密（含头部的大部分字段），中间设备**本就看不到**内部结构，
   于是"不依赖中间设备兼容"成为可能。

> ⭐ 所以 HTTP/3 ≈ **"借 UDP 的壳，把 TCP 该做的可靠传输 + TLS 1.3 合起来在用户态重做一遍"**。
> 选择 UDP 不是因为 UDP 好用，而是因为**UDP 是唯一能穿过所有中间设备的"空白载体"**。

## 二、QUIC 在 UDP 之上重建了什么？

**本节要点**：QUIC 自带 TCP 的能力（可靠、有序、流控、拥塞控制）+ TLS 1.3 的加密，
再加两件事 TCP 给不了的：**0-RTT 复用**与**连接迁移**。

### 2.1 它自己实现的"TCP 能力"

| 能力 | QUIC 怎么做 |
|---|---|
| 可靠传输 | 每个包有包号（Packet Number），**只 ACK 包号**、不回带"下一个期望号" |
| 流量控制 | **流级 + 连接级**两层窗口（TCP 只有连接级） |
| 拥塞控制 | CUBIC / BBR 都可插（见 [../算法/拥塞控制算法.md](../算法/拥塞控制算法.md)），**在用户态可换** |
| 避免队头阻塞 | 流级序号 + 独立重传（第一节的核心） |
| 防止"呆滞" | 包号单调递增，**不重用**，因此不会出现 TCP 的重传歧义 |

### 2.2 它自己实现的"TLS 能力"

QUIC **内嵌 TLS 1.3**（不是"TLS 跑在 QUIC 之上"，而是握手过程本身就是 QUIC 的握手），
所以：

- **没有明文握手阶段**：TCP 上 TLS 1.2 的证书是明文传的，QUIC 里第一轮就把密钥协商走完；
- **也能协商 ALPN**——这是浏览器与服务器"对上暗号"的地方，也是第三节的实验对象；
- **握手与传输合并**：TCP 握手 + TLS 握手要两次往返（见 [网络通信链路详解.md 2.3](网络通信链路详解.md)），
  QUIC **首次 1-RTT、复用 0-RTT**。

### 2.3 0-RTT 与它的代价

- **1-RTT（首次连接）**：客户端发 `Initial`（含 ClientHello）→ 服务端回 `Handshake`，之后即可发数据；
- **0-RTT（复用会话）**：客户端把上次的 ticket 带上，**第一个包就能携带应用数据**；
- ⚠️ **0-RTT 的数据可被重放**：攻击者截获这一串字节可以重复发给服务端。所以规范与工程实践都要求
  **只对幂等请求**用 0-RTT（`GET` 可以，支付 / 下单这类不行）——这是"要不要开 0-RTT"的真正判据。

### 2.4 连接迁移：靠 Connection ID，不靠四元组

TCP 连接的身份是**四元组**（源 IP:端口 + 目的 IP:端口）——手机从 WiFi 切到 4G，IP 一变，
**TCP 连接就断了**（要么重连、要么靠应用层补救）。

QUIC 的连接身份是服务端分配的 **Connection ID**，写在每个包的头部：

```text
四元组变了（换网、NAT 重新映射）→ 只要 Connection ID 不变 → 连接仍然有效
```

> ⭐ 这正是"地铁里刷视频不断线"的技术原因之一。

## 三、浏览器怎么知道该不该用 HTTP/3？（本机实测）

**本节要点**：靠 **DNS 的 `HTTPS` / `SVCB` 记录**里的 `alpn` 参数——**不是猜、也没有第二个 HTTP 请求**。
这解释了为什么"HTTP/3 的入口其实在 DNS"。

### 3.1 实测：`nslookup` 查不了这个类型

```bash
$ nslookup -type=HTTPS example.com
unknown query type: HTTPS
```

⚠️ Windows 自带的 `nslookup` **不支持 HTTPS(SVCB) 查询类型**。改用 Python 手写 DNS 报文查 **type 65**：

```python
# 构造 DNS 查询：header + QNAME + QTYPE(65=HTTPS) + QCLASS(1)
def build(name, qtype): ...
# SVCB/HTTPS 的 rdata = priority(2B) + target + 参数列表；key 1 = alpn、key 4/6 = ipv4hint/ipv6hint
```

查三个域名，结果如下（`本机 DNS 10.44.147.58`，TTL 600）：

| 域名 | SVCB/HTTPS 记录的 `alpn` | 含义 |
|---|---|---|
| `example.com` | `priority=1 target=. alpn="h2"` | ⚠️ **没宣告 h3** → 浏览器走 HTTP/2 |
| `cloudflare.com` | `priority=1 target=. alpn="h3,h2"` | ✅ 宣告支持 → 浏览器**可以试 HTTP/3** |
| `www.cloudflare.com` | `priority=1 target=. alpn="h3,h2"` | ✅ 同上 |
| `www.google.com` | `priority=1 target=. alpn="h2"` | ⚠️ 该记录未宣告 h3 |

> ⭐ **这张表就是第三节的结论**：`alpn` 里出现 `h3`，客户端才**尝试**用 QUIC 建连；
> 没有 `h3` 就直接走 TCP（即便这台服务器其实支持 HTTP/3——**宣告是逐域名配置的**，
> 这也解释了"同一家 CDN，有的站点快有的不支持"）。

### 3.2 顺带读出的两个参数

同一条记录里还有：

- **`key4` = ipv4hint**（如 `681084e5` = 104.16.132.229）：**直接在 DNS 里给 IP 候选**，
  省掉一次 A 记录查询；
- **`key6` = ipv6hint**：同理给 IPv6 候选；
- `priority=1` + `target=.` 表示 **ServiceMode，服务就在这个域名本身**（没有换成别的目标主机）。

> 有了 hint，客户端可以**在解析阶段就拿到"换 IP 也不用重连"的候选地址**——这是 QUIC 连接迁移的提前量。

### 3.3 ALPN 对比实验：协商不到会怎样

用 `openssl s_client` 走 **TCP+TLS**（不是 QUIC）分别声明不同 ALPN：

```bash
# ① 只声明 h3 —— 服务端不会选它（h3 只在 QUIC 上提供），握手仍然成功但协商结果为空
$ openssl s_client -connect example.com:443 -servername example.com -alpn h3 </dev/null
Protocol version: TLSv1.3
Ciphersuite: TLS_AES_256_GCM_SHA384
Negotiated TLS1.3 group: X25519MLKEM768
# ← 注意：没有 "ALPN protocol:" 这一行
# ② 声明 h2,http/1.1 —— 服务端选了 h2
$ openssl s_client -connect example.com:443 -servername example.com -alpn h2,http/1.1 </dev/null
ALPN protocol: h2
```

**结论（ALPN 的本质）**：客户端**提议**一串协议，服务端**挑一个它支持的**；
**没有交集时握手照样成功，只是协商结果为空**——这正是"HTTP/3 走不通时浏览器能无缝退回 HTTP/2"的机制来源。

> 顺手拿到一个现代 TLS 证据：本机与服务端协商出的是 **`X25519MLKEM768`**（X25519 + ML-KEM-768 的
> **后量子混合密钥交换**），说明这条链路的密钥交换已经抗量子计算。

### 3.4 本机 curl 不支持 HTTP/3（以及怎么确认）

```bash
$ curl --version
curl 8.13.0 (Windows) libcurl/8.13.0 Schannel zlib/1.3.1 WinIDN
Protocols: dict file ftp ftps http https imap imaps ipfs ipns mqtt pop3 pop3s smb smbs smtp smtps telnet tftp ws wss
```

⭐ **判断方法：看 `Protocols:` 里有没有 `h3`** —— 这里**没有**，说明这份 curl 编译时没启用 HTTP/3
（需要 ngtcp2 / quiche 之类的 QUIC 后端）。所以**本文没有做"端到端 HTTP/3 往返"的实测**，
只做到"宣告（SVCB）+ 协商（ALPN）"这两步可实测的部分。

## 四、QUIC 的代价与现状

**本节要点**：QUIC 不是"更快的 TCP"，它把问题从"协议先进不先进"换成了**"UDP 在你的网络里好不好使"**。

### 4.1 三个真实代价

| 代价 | 说明 | 典型症状 |
|---|---|---|
| **UDP 被限速 / 封禁** | 不少企业网与运营商对 UDP 有策略（限速、丢包、直接封） | 首包超时后**回落 TCP**（用户感知为"慢一下"，不报错） |
| **CPU 成本更高** | 协议栈在用户态，收发包、加解密都在应用进程里 | 高并发下 CPU 占用高于同带宽的 TCP |
| **NAT 绑定与超时** | NAT 对 UDP 的映射超时通常**比 TCP 短** | 长连接静默失效（同 [网络通信链路详解.md 4.2](网络通信链路详解.md) 的 conntrack 问题） |

### 4.2 观测与排查

- **看某站支不支持**：查 `HTTPS` 记录的 `alpn`（第三节 3.1 的脚本）；
- **看浏览器实际用了哪个**：DevTools → Network → 右键列头勾选 **Protocol** 列，会显示 `h2` / `h3`；
- **看本机是否在发 QUIC**：`UDP 443` 有持续流量就是（抓包见 [抓包实战.md](抓包实战.md)）；
- ⚠️ **不要在 UDP 被封的网络里"强开 HTTP/3"**：表现为首包等超时再回落，反而更慢。

---

## 使用：怎么确认一个站点支不支持 HTTP/3

```bash
# ① 看 DNS 有没有宣告 h3（最权威：浏览器就是看这个）—— 需要能查 type 65 的工具
#    Windows nslookup 不支持；用 Python 手写查询（见第三节脚本）或 dig 的现代版本：
dig +short HTTPS cloudflare.com        # → 1 . alpn="h3,h2" ipv4hint=...
# ② 看服务端在 TCP 上认哪些 ALPN（能协商出 h2 说明它是 HTTP/2 站点）
openssl s_client -connect <域名>:443 -servername <域名> -alpn h2,http/1.1 </dev/null | grep -i alpn
# ③ 自己测一次 HTTP/3（需要 curl 编译了 h3；先 `curl --version` 看 Protocols 里有没有 h3）
curl -sS --http3 -o /dev/null -w 'HTTP/%{http_version} 建连 %{time_connect}s 首字节 %{time_starttransfer}s\n' https://<域名>/
# ④ 浏览器里直接看：DevTools → Network → 勾选 Protocol 列 → 看是 h2 还是 h3
```

> ⚠️ 顺序很重要：**① 没有 h3 就不用做 ③**——客户端根本不会尝试 QUIC。

## 延伸追问

- **HTTP/3 为什么不直接用 TCP 而要造 UDP 之上的新协议？** → 三条：中间设备对 TCP 头部与选项的
  固有假设难以改变（改 TCP 会被"看不懂就丢弃"）；TCP 在内核里、升级慢，QUIC 在用户态、随应用更新；
  以及 QUIC 强制加密要求"中间设备本就看不到内部"。**UDP 是唯一能穿过去的空白载体。**
- **QUIC 怎么解决 TCP 的队头阻塞？** → 把"流"做进传输层：每个流有独立序号空间，
  丢包只重传那一条流的数据，其它流不受影响。TCP 做不到，因为它只知道"一个有序字节流"。
- **0-RTT 有什么风险？为什么不能对所有请求开？** → **可重放**：截获的 0-RTT 数据能重复发。
  所以只对**幂等**请求使用（`GET` 可以，下单 / 支付不行）。
- **手机换网为什么 QUIC 不断线，TCP 会断？** → TCP 连接身份是四元组，IP 一变就失配；
  QUIC 用服务端分配的 **Connection ID** 标识连接，四元组变化不影响。
- **ALPN 协商不到会怎样？** → **握手照样成功，只是协商结果为空**（本机实测：只声明 `h3` 时没有
  `ALPN protocol:` 行）。这正是"HTTP/3 失败能无缝回落 HTTP/2"的机制。
- **HTTP/3 一定比 HTTP/2 快吗？** → 不一定。链路质量好时差别很小；
  丢包多、跨移动网络的场景优势明显；**UDP 被限速的环境里反而更慢**（首包超时后回落）。
- **SVCB 的 ipv4hint 有什么用？** → 在 DNS 阶段就给出 IP 候选，省一次解析，
  并让客户端在"换 IP 也不重连"时有提前量。
- **QUIC 的 CPU 开销为什么更高？** → 协议栈在用户态、每个包都要加解密，
  省下的系统调用换成了应用进程的开销；高并发下比同带宽 TCP 更吃 CPU。

---

## 关联

- [tcp/TCP三次握手.md](tcp/TCP三次握手.md) — QUIC 把这里的三次握手压成了 1-RTT（甚至 0-RTT）的对照
- [tcp/TCP四次挥手.md](tcp/TCP四次挥手.md) — QUIC 没有 `TIME_WAIT`：包号不复用，退出更干净
- [tcp/TCP滑动窗口.md](tcp/TCP滑动窗口.md) — QUIC 的流控是「流级 + 连接级」两层，这里是单层
- [HTTPS与TLS.md](HTTPS与TLS.md) — QUIC 内嵌 TLS 1.3，ALPN 与证书都来自这里
- [HTTP与gRPC.md](HTTP与gRPC.md) — 应用层多路复用在 h2 与 h3 下的差别
- [DNS解析.md](DNS解析.md) — `HTTPS` / `SVCB` 记录属于资源记录类型，HTTP/3 的入口在这里
- [通信选型.md](通信选型.md) — 什么时候该上 HTTP/3（以及什么时候不该）
- [抓包实战.md](抓包实战.md) — QUIC 走 UDP 443，怎么在抓包里认出来
- [../算法/拥塞控制算法.md](../算法/拥塞控制算法.md) — QUIC 可插拔的拥塞控制（CUBIC / BBR 在用户态）
