# 网络笔记索引（本目录导读）

> 本目录是「一次请求怎么从本机走到服务端」的完整链条：**纵向（分层与封装）** 与 **横向（链路与节点）**
> 各有一篇总纲，其余篇目都是这条链上的某个环节。
>
> 内容整理自个人学习笔记。**本文件只给阅读顺序与依赖关系**——每篇的知识点描述见
> [全仓知识点目录](../../目录.md)，本目录**不重复描述**；跨目录的延伸见「四、与其它目录的分工」。
>
> 当前共 **22 篇网络专题文档**（不含本 README）。

---

## 一、先读哪两篇（总纲，互不重复）

| 顺序 | 篇 | 视角 | 一句话 |
|---|---|---|---|
| ① | [网络分层与数据包旅程.md](网络分层与数据包旅程.md) | ⭐ **纵向** | 五层模型 + 一个包在本机怎么被逐层造出来（逐层加首部、TLS 在哪一层、掉进 MTU 会怎样） |
| ② | [网络通信链路详解.md](网络通信链路详解.md) | ⭐ **横向** | 跨过哪些节点、每一跳改了什么、在哪儿观测；**13 步旅程的完整时序只在这里有一份** |

> ⭐ **这两篇怎么配合**：先看 ① 建立「层」的概念，再看 ② 才看得懂「每一跳改的是哪一层」。
> ② 的 2.1 表同时是**本目录的入口**——13 步每一步都链到了对应的篇。

## 二、推荐阅读顺序

### 2.1 主线：跟着一个请求走（第一次通读走这条）

```text
① 网络分层与数据包旅程      ← 纵向总纲：层是什么、包怎么被造出来
② 网络通信链路详解          ← 横向总纲：链路上有谁、13 步怎么走
        ↓
③ DHCP                      ← 想出门先拿到四个参数（IP / 掩码 / 网关 / DNS）
④ IP 与路由基础             ← 拿到 IP 之后：同网段还是跨网段、下一跳是谁、MAC 怎么解析
⑤ DNS 解析                  ← 拿到域名对应的 IP（可能是 CDN 的就近节点）
        ↓
⑥ UDP 与数据报传输          ← 传输层的另一半：没有连接、没有保证，应用要自己补什么
⑦ tcp/TCP报文结构           ← 首部字段与序号：后面四篇的共同基础
⑧ tcp/TCP三次握手           ← 建连接
⑨ tcp/TCP滑动窗口           ← 传数据（窗口决定吞吐上限）
⑩ tcp/TCP的Nagle与延迟确认   ← 小包优化（这两条一起看才懂为什么"互相伤害"）
⑪ TCP拥塞控制算法           ← 窗口的另一半：cwnd 与 ECN
⑫ tcp/TCP四次挥手           ← 断连接（TIME_WAIT / CLOSE_WAIT 都在这一步）
        ↓
⑬ HTTPS与TLS               ← 连接之上的加密与证书
⑭ HTTP/1.1与HTTP/2的区别     ← 应用层的传输绑定：并发模型、消息定界、头部压缩、两级流控
⑮ HTTP与gRPC               ← gRPC 与 HTTP 的差别、连接池与多路复用实测
⑯ QUIC与HTTP3              ← 为什么把 TCP 换掉；浏览器怎么发现 h3
⑰ 数据序列化                ← 请求体到底怎么编码（JSON / Protobuf）
        ↓
⑱ IO多路复用                ← 换个视角：服务端怎么同时接住成千上万条连接
⑲ 通信选型                  ← 最后做决策（见过各层才有判断依据）
        ↓
⑳ 反向代理原理与实现          ← 换一层视角：服务端怎么把请求转给上游、头部怎么治理
㉑ 客户端真实IP与可信代理       ← 代理链上「客户端到底是谁」（与 ⑳ 是一对）
        ↓
🧰 抓包实战                  ← 手上功夫：在哪抓、怎么读（随时可插入）
```

### 2.2 专题线：只想补某一块

| 目的 | 按这个顺序读 |
|---|---|
| **排查"慢 / 不通"** | [网络通信链路详解.md](网络通信链路详解.md)（五、症状→层级→证据→工具表）→ [IP与路由基础.md](IP与路由基础.md)（第八节 MTU / PMTUD）→ [DNS解析.md](DNS解析.md)（排查命令速查）→ [tcp/TCP四次挥手.md](tcp/TCP四次挥手.md)（`CLOSE_WAIT` 堆积） |
| **复习 TCP** | [tcp/TCP报文结构.md](tcp/TCP报文结构.md) → [tcp/TCP三次握手.md](tcp/TCP三次握手.md) → [tcp/TCP滑动窗口.md](tcp/TCP滑动窗口.md) → [tcp/TCP的Nagle与延迟确认.md](tcp/TCP的Nagle与延迟确认.md) → [TCP拥塞控制算法.md](TCP拥塞控制算法.md) → [tcp/TCP四次挥手.md](tcp/TCP四次挥手.md) |
| **搞懂 IP 与路由** | [IP与路由基础.md](IP与路由基础.md)（地址 / CIDR → 路由表 → ARP/NDP → ICMP → MTU/分片）→ [网络通信链路详解.md](网络通信链路详解.md)（逐跳改写） |
| **做技术选型** | [通信选型.md](通信选型.md) → [HTTP1.1与HTTP2的区别.md](HTTP1.1与HTTP2的区别.md) → [HTTP与gRPC.md](HTTP与gRPC.md) → [数据序列化.md](数据序列化.md) |
| **只想搞懂 HTTP 版本差异** | [HTTP1.1与HTTP2的区别.md](HTTP1.1与HTTP2的区别.md)（连接模型 / 定界 / 头部压缩 / 流控 / 队头阻塞边界）→ [HTTP与gRPC.md](HTTP与gRPC.md)（gRPC 与 HTTP 的关系、连接池）→ [QUIC与HTTP3.md](QUIC与HTTP3.md)（TCP 层阻塞怎么根治） |
| **只想搞懂一次访问** | ① [网络分层与数据包旅程.md](网络分层与数据包旅程.md) ② [网络通信链路详解.md](网络通信链路详解.md) 两篇总纲 + 后者的「13 步全景」表 |
| **只看 HTTP/3 相关** | [QUIC与HTTP3.md](QUIC与HTTP3.md) → [HTTPS与TLS.md](HTTPS与TLS.md)（ALPN 与证书）→ [tcp/TCP三次握手.md](tcp/TCP三次握手.md)（对照它被压成 1-RTT） |
| **要动手抓一次包** | [抓包实战.md](抓包实战.md) → [网络通信链路详解.md](网络通信链路详解.md)（五、分层观测点，先定位再抓） |
| **做反向代理 / 网关** | [反向代理原理与实现.md](反向代理原理与实现.md) → [客户端真实IP与可信代理.md](客户端真实IP与可信代理.md) → [通信选型.md](通信选型.md) |
| **排查「客户端 IP 不对 / 限流按 IP 误伤」** | [客户端真实IP与可信代理.md](客户端真实IP与可信代理.md)（可信代理链）→ [反向代理原理与实现.md](反向代理原理与实现.md)（XFF 由谁写、为什么不能手写） |

### 2.3 依赖关系（谁要前置）

| 本篇 | 建议先读 | 为什么 |
|---|---|---|
| [网络通信链路详解.md](网络通信链路详解.md) | [网络分层与数据包旅程.md](网络分层与数据包旅程.md) | 先有「层」，才看得懂「每跳改的是哪一层」 |
| [IP与路由基础.md](IP与路由基础.md) | [网络分层与数据包旅程.md](网络分层与数据包旅程.md) | 先知道「为什么要两套地址」，再谈怎么寻址与选路 |
| [UDP与数据报传输.md](UDP与数据报传输.md) | [IP与路由基础.md](IP与路由基础.md) | 大包与分片的代价要先懂 MTU |
| [tcp/TCP三次握手.md](tcp/TCP三次握手.md) | [tcp/TCP报文结构.md](tcp/TCP报文结构.md) | 握手报文就是带 `SYN` / `ACK` 标志位的首部 |
| [tcp/TCP滑动窗口.md](tcp/TCP滑动窗口.md) | [tcp/TCP报文结构.md](tcp/TCP报文结构.md)、[tcp/TCP三次握手.md](tcp/TCP三次握手.md) | 窗口字段在首部里；连接建好才谈吞吐 |
| [tcp/TCP的Nagle与延迟确认.md](tcp/TCP的Nagle与延迟确认.md) | [tcp/TCP滑动窗口.md](tcp/TCP滑动窗口.md) | 都是传输效率问题，放一起看 |
| [TCP拥塞控制算法.md](TCP拥塞控制算法.md) | [tcp/TCP滑动窗口.md](tcp/TCP滑动窗口.md) | `min(rwnd, cwnd)` 的另一半 |
| [tcp/TCP四次挥手.md](tcp/TCP四次挥手.md) | [tcp/TCP三次握手.md](tcp/TCP三次握手.md) | 关闭是握手的镜像，`TIME_WAIT` 在这一步出现 |
| [HTTPS与TLS.md](HTTPS与TLS.md) | [tcp/TCP三次握手.md](tcp/TCP三次握手.md)、[DNS解析.md](DNS解析.md) | TLS 跑在 TCP 之上；证书要靠 SNI 选 |
| [HTTP1.1与HTTP2的区别.md](HTTP1.1与HTTP2的区别.md) | [HTTPS与TLS.md](HTTPS与TLS.md)、[tcp/TCP报文结构.md](tcp/TCP报文结构.md) | 版本是 ALPN 协商的结果；定界的根因是「TCP 是字节流、没有边界」 |
| [HTTP与gRPC.md](HTTP与gRPC.md) | [HTTPS与TLS.md](HTTPS与TLS.md)、[HTTP1.1与HTTP2的区别.md](HTTP1.1与HTTP2的区别.md) | 应用层在 TLS 之上；HTTP/2 是 gRPC 的地基（机制在上一条，本文只留 gRPC 相关结论） |
| [QUIC与HTTP3.md](QUIC与HTTP3.md) | [HTTPS与TLS.md](HTTPS与TLS.md)、[tcp/TCP三次握手.md](tcp/TCP三次握手.md)、[UDP与数据报传输.md](UDP与数据报传输.md) | 要懂 1-RTT / 0-RTT 的价值，先知道 TCP + TLS 要几次往返 |
| [数据序列化.md](数据序列化.md) | [HTTP与gRPC.md](HTTP与gRPC.md) | 请求体怎么编码 |
| [反向代理原理与实现.md](反向代理原理与实现.md) | [HTTP与gRPC.md](HTTP与gRPC.md)、[tcp/TCP报文结构.md](tcp/TCP报文结构.md) | 要懂逐跳头与连接复用，才看得懂「哪些头必须剥、哪些必须补」 |
| [客户端真实IP与可信代理.md](客户端真实IP与可信代理.md) | [反向代理原理与实现.md](反向代理原理与实现.md) | 先知道代理这一跳写了什么头，再谈「信哪个」 |
| [抓包实战.md](抓包实战.md) | [网络通信链路详解.md](网络通信链路详解.md)、[tcp/](tcp/) | 先知道"该看什么"，抓包才有意义 |
| [通信选型.md](通信选型.md) | 前面大部分 | 没见过各层，选型表看不出取舍 |
| [DHCP.md](DHCP.md)、[DNS解析.md](DNS解析.md)、[IO多路复用.md](IO多路复用.md) | — | 独立成篇，随时可读 |

> ⚠️ **两条"反直觉"的顺序提醒**：
> 1. **DHCP 排在 DNS 之前**——没有 IP / 掩码 / 网关 / DNS 这四参数，连 DNS 查询都发不出去；
> 2. **DHCP 与 DNS 都在 TCP 之前**——它们是"建连接之前"的准备动作，不是 TCP 的一部分。

## 三、现有篇章地图（按主题分组）

### 总纲与端到端链路

| 篇章 | 主要职责 |
|---|---|
| [网络分层与数据包旅程.md](网络分层与数据包旅程.md) | ⭐ **纵向**：五层模型、两套地址的分工、以太网帧结构、一次封装示意与 TLS 所在的层 |
| [网络通信链路详解.md](网络通信链路详解.md) | ⭐ **横向**：13 步端到端时序、逐跳改写、NAT/conntrack、AS/BGP、CDN/LB、MTU 与分层排障导航 |

### 网络层与传输层基础

| 篇章 | 主要职责 |
|---|---|
| [IP与路由基础.md](IP与路由基础.md) | IP 地址与 CIDR、最长前缀匹配、下一跳决策、ARP/NDP、ICMP、MTU/MSS/分片与 PMTUD，含三条故障判据 |
| [UDP与数据报传输.md](UDP与数据报传输.md) | 数据报语义与消息边界、8 字节首部与伪首部校验和、不保证什么、应用层补什么、MTU 与大包、socket 队列丢包、Go/Java 收发 |
| [DHCP.md](DHCP.md) | DHCPv4 DORA 与 T1/T2（含广播/单播的准确口径）、租约形状的复用、IPv6 的 RA/SLAAC/DHCPv6/DAD |
| [DNS解析.md](DNS解析.md) | 域名层级、递归/迭代、TTL 与缓存、记录类型、GSLB、劫持与污染判据、DNSSEC/负缓存、DoH/DoT/DoQ |

### TCP 传输机制

| 篇章 | 主要职责 |
|---|---|
| [tcp/TCP报文结构.md](tcp/TCP报文结构.md) | 连接的本质、首部字段（含 ECE/CWR）、`SEG.LEN` 与确认号公式、累计 ACK 与 SACK、字节流边界 |
| [tcp/TCP三次握手.md](tcp/TCP三次握手.md) | 握手逐步拆解、两个队列与 `backlog`、溢出行为、TCP Fast Open |
| [tcp/TCP滑动窗口.md](tcp/TCP滑动窗口.md) | 窗口滑动、`min(rwnd, cwnd)`、窗口缩放 / SACK / 零窗口探测、socket 选项与实验 |
| [tcp/TCP的Nagle与延迟确认.md](tcp/TCP的Nagle与延迟确认.md) | Nagle 的条件触发规则、延迟确认的实现差异、两者叠加的时序与破解 |
| [TCP拥塞控制算法.md](TCP拥塞控制算法.md) | AIMD 四条机制、Tahoe/Reno 实测、ECN 的协商与回显、CUBIC（RFC 9438）与 BBR 的边界 |
| [tcp/TCP四次挥手.md](tcp/TCP四次挥手.md) | 关闭时序、半关闭、`TIME_WAIT` 与 2MSL、`CLOSE_WAIT` 成因与排查 |

### 应用协议与选型

| 篇章 | 主要职责 |
|---|---|
| [HTTPS与TLS.md](HTTPS与TLS.md) | TLS 1.2/1.3 握手、RSA vs ECDHE、证书验证、会话复用、0-RTT、mTLS、ECH 与 `HTTPS` 记录 |
| [HTTP1.1与HTTP2的区别.md](HTTP1.1与HTTP2的区别.md) | ⭐ **HTTP 版本差异的唯一详解源**：语义层与传输绑定的分离、ALPN 协商与 h2c、连接与并发模型、三种定界、HPACK（按连接维护）、两级流控与 `rwnd`/`cwnd` 的分工、推送与优先级的现状、队头阻塞的两层边界；含三条观测路径 |
| [HTTP与gRPC.md](HTTP与gRPC.md) | gRPC 与 HTTP 的本质差别（默认 Protobuf）、1.2 的五处改动结论表、第二节四条易混结论、WebSocket/SSE 对照、连接池与 `httptrace` 复用观测、多路复用实测 |
| [QUIC与HTTP3.md](QUIC与HTTP3.md) | QUIC 的取舍与流隔离边界、0-RTT 与连接迁移、**两条发现路径（DNS SVCB / Alt-Svc）与回退** |
| [通信选型.md](通信选型.md) | 同步 RPC 与异步消息、七种应用层协议与六种序列化格式的横向选型 |
| [数据序列化.md](数据序列化.md) | JSON 与 Protobuf 的对比与选型；序列化不定义消息边界 |

### I/O、代理与排障

| 篇章 | 主要职责 |
|---|---|
| [IO多路复用.md](IO多路复用.md) | 五种 I/O 模型、select/poll/epoll、LT vs ET、Reactor、`io_uring` 的提交—完成模型、fd 上限 |
| [反向代理原理与实现.md](反向代理原理与实现.md) | `ReverseProxy` 的三个坑、头部治理口径、`Forwarded` 与 `X-Forwarded-*` 的差异、Transport 参数 |
| [客户端真实IP与可信代理.md](客户端真实IP与可信代理.md) | 可信代理链解析、五个易错点、`Forwarded` 对照、L4 的 PROXY protocol、入口规范化与出口复用 |
| [抓包实战.md](抓包实战.md) | 抓包点与过滤器、报文判读、三类假象、TCP/UDP/QUIC 与 IPv4/IPv6 的过滤边界；本机 `pktmon` 实测 |

## 四、与其它目录的分工

| 相关目录 | 关系 |
|---|---|
| [go/](../../01-编程语言/go/) | 语言侧实现：`go/运行时/GMP调度.md` 决定一个网络请求由谁处理；接入篇（`接入Jaeger.md` / `接入Skywalking.md`）是链路追踪的落地 |
| [java/](../../01-编程语言/java/) | 同上：`java/接入Skywalking.md`、`IO多路复用` 里 Java NIO 的对照组 |
| [部署/](../../06-工程实践/部署/) | 容器与集群里的网络：`docker/网络与存储.md`（veth / bridge / DNAT）、`k8s/K8s部署流程.md`（CNI、Service、Ingress） |
| [安全/](../../06-工程实践/安全/) | TLS 的上游：`数字证书与PKI.md`（证书链与信任）、`加密算法.md`（套件里的算法） |
| [分布式/](../../04-架构与系统/分布式/) | 再往上一层：`服务发现与负载均衡.md`、`Raft协议.md` 等以网络为前提 |
| [算法/](../算法/) | `TCP拥塞控制算法.md`（慢启动 / CUBIC / BBR）是「tcp/TCP滑动窗口」的延伸原理 |

## 五、本目录的边界与缺口

**边界**：本目录只收**网络协议与链路本身**——从"四个参数怎么拿到"到"请求能不能到达、多快到达"为止。

| 本目录收 | 不收（在哪） |
|---|---|
| 分层与封装、链路与逐跳、IP 与路由、TCP/UDP、DNS、TLS、应用层协议 | **组件的用法**（Redis / MQ / 注册中心）→ [中间件/](../../03-数据与中间件/中间件/)、[数据存储/](../../03-数据与中间件/数据存储/) |
| 服务端的连接模型（I/O 多路复用） | **容器与集群网络**（veth / bridge / CNI / Service / Ingress）→ [部署/](../../06-工程实践/部署/) |
| 协议与序列化选型 | **密码算法与证书体系** → [安全/](../../06-工程实践/安全/) |
| | **系统侧观测工具**（eBPF / perf / `ss` 的深度用法）→ [性能排查.md](../linux/性能排查.md) |
| | **服务端框架的写法** → [go/](../../01-编程语言/go/)、[java/](../../01-编程语言/java/) |

**缺口处理记录**：

| 原缺口 | 现在补在哪 | 怎么补的 |
|---|---|---|
| QUIC / HTTP/3 | [QUIC与HTTP3.md](QUIC与HTTP3.md) | 队头阻塞 → UDP 之上的取舍 → 两条发现路径与回退；**实测** DNS 的 SVCB 记录与 ALPN 协商 |
| 抓包实战 | [抓包实战.md](抓包实战.md) | 在哪抓 / 过滤表达式 / 怎么读；**实测**用 Windows `pktmon` 抓完整 HTTPS 连接 |
| **IP 与路由** | [IP与路由基础.md](IP与路由基础.md) | 2026-10-09 新建：CIDR、最长前缀匹配、ARP/NDP、ICMP、MTU/分片/PMTUD |
| **UDP 与数据报** | [UDP与数据报传输.md](UDP与数据报传输.md) | 2026-10-09 新建：数据报语义、首部与校验和、应用层补什么、队列丢包 |
| **HTTP/1.1 与 HTTP/2 对照** | [HTTP1.1与HTTP2的区别.md](HTTP1.1与HTTP2的区别.md) | 2026-10-09 新建：把原先散在 [HTTP与gRPC.md](HTTP与gRPC.md) 第一、二节的差异机制收成一篇；该篇只留结论表，HTTP/2 的字节级纵切仍在 [HTTP2机制.md](../../03-数据与中间件/中间件/RPC框架/gRPC/HTTP2机制.md) |
| IPv6 | [网络通信链路详解.md](网络通信链路详解.md) 第六节 + [IP与路由基础.md](IP与路由基础.md) 第五节 + [DHCP.md](DHCP.md) 第二节 | 链路视角四处差异、地址与 NDP、RA/SLAAC/DHCPv6/DAD |
| WebSocket / SSE | [HTTP与gRPC.md](HTTP与gRPC.md) 第三节 | 与 gRPC 流式三方对照 + 判据 + 链路侧坑 |

**仍保持"暂不补"的 4 项**：

| 缺口 | 现状 | 结论 |
|---|---|---|
| **BGP / 路由协议** | 只在链路篇"公网那一跳"点到 | 属运营商领域，本仓库以"应用视角的链路"为主 |
| **SOCKS / 正向代理** | 全仓 0 次 | 与仓库主线（后端服务链路）距离较远 |
| **VPN**（IPsec / WireGuard） | 只在链路篇的封装开销表里出现 | 同上 |
| **iptables / netfilter / eBPF** | 链路篇 1 次；eBPF 全仓都在 [linux/](../linux/) | 观测手段链到 linux 目录，不搬进来 |

## 六、三条维护原则

1. **一处讲透，其他地方互链。** 网络层的寻址与选路由 [IP与路由基础.md](IP与路由基础.md) 负责；分层概览由 [网络分层与数据包旅程.md](网络分层与数据包旅程.md) 负责；NAT、CDN、LB、AS/BGP 与端到端观测由 [网络通信链路详解.md](网络通信链路详解.md) 负责；UDP 机制由 [UDP与数据报传输.md](UDP与数据报传输.md) 负责；HTTP/1.1 与 HTTP/2 的差异机制由 [HTTP1.1与HTTP2的区别.md](HTTP1.1与HTTP2的区别.md) 负责（[HTTP与gRPC.md](HTTP与gRPC.md) 只留与 gRPC 相关的结论表，HTTP/2 的字节级纵切在 [HTTP2机制.md](../../03-数据与中间件/中间件/RPC框架/gRPC/HTTP2机制.md)）。
2. **不要混淆相近机制。** IP 分片 ≠ TCP 分段；TCP 字节流 ≠ UDP 数据报；`rwnd` 流量控制 ≠ `cwnd` 拥塞控制；HTTP/2 应用层多路复用 ≠ 消除 TCP 层队头阻塞；DNSSEC 完整性 ≠ DNS 查询加密；TLS ≠ HTTP；`io_uring` 的完成式语义 ≠ epoll 的就绪式语义。
3. **版本敏感内容要标口径。** Linux sysctl 默认值（如 `somaxconn`）、内核 API、浏览器 HTTP/3 发现方式、延迟确认定时器、Go/Java 运行时实现都须注明版本或写"实现相关"；**单机实测不能直接写成协议定律**。

## 七、参考标准与官方文档

- [RFC 9293 — TCP](https://www.rfc-editor.org/rfc/rfc9293.html)、[RFC 7413 — TCP Fast Open（Experimental）](https://www.rfc-editor.org/rfc/rfc7413.html)、[Linux `listen(2)`](https://man7.org/linux/man-pages/man2/listen.2.html)
- [RFC 791 — IPv4](https://www.rfc-editor.org/rfc/rfc791.html)、[RFC 8200 — IPv6](https://www.rfc-editor.org/rfc/rfc8200.html)、[RFC 5737 — IPv4 文档地址](https://www.rfc-editor.org/rfc/rfc5737.html)、[RFC 3849 — IPv6 文档地址](https://www.rfc-editor.org/rfc/rfc3849.html)
- [RFC 4861 — NDP](https://www.rfc-editor.org/rfc/rfc4861.html)、[RFC 4862 — SLAAC](https://www.rfc-editor.org/rfc/rfc4862.html)、[RFC 8201 — IPv6 PMTUD](https://www.rfc-editor.org/rfc/rfc8201.html)、[RFC 8899 — PLPMTUD](https://www.rfc-editor.org/rfc/rfc8899.html)、[RFC 1191 — PMTUD](https://www.rfc-editor.org/rfc/rfc1191.html)
- [RFC 768 — UDP](https://www.rfc-editor.org/rfc/rfc768.html)、[RFC 2131 — DHCP](https://www.rfc-editor.org/rfc/rfc2131.html)
- [RFC 9112 — HTTP/1.1](https://www.rfc-editor.org/rfc/rfc9112.html)、[RFC 9113 — HTTP/2](https://www.rfc-editor.org/rfc/rfc9113.html)、[RFC 9218 — HTTP 优先级](https://www.rfc-editor.org/rfc/rfc9218.html)、[RFC 9114 — HTTP/3](https://www.rfc-editor.org/rfc/rfc9114.html)、[RFC 9460 — SVCB/HTTPS 记录](https://www.rfc-editor.org/rfc/rfc9460.html)
- [RFC 9520 — DNS 负缓存](https://www.rfc-editor.org/rfc/rfc9520.html)、[RFC 9250 — DNS over QUIC](https://www.rfc-editor.org/rfc/rfc9250.html)、[RFC 8484 — DoH](https://www.rfc-editor.org/rfc/rfc8484.html)
- [RFC 9438 — TCP CUBIC](https://www.rfc-editor.org/rfc/rfc9438.html)、[RFC 3168 — ECN](https://www.rfc-editor.org/rfc/rfc3168.html)、[`io_uring(7)`](https://man7.org/linux/man-pages/man7/io_uring.7.html)
