# HTTP 与 gRPC

> 覆盖：gRPC 与 HTTP 的本质差别（默认编码 Protobuf）、连接池与 `httptrace` 复用观测、多路复用实测。
>
> 不覆盖：HTTP/1.1 与 HTTP/2 的差异机制见 [HTTP1.1与HTTP2的区别.md](HTTP1.1与HTTP2的区别.md)（本仓该主题的唯一详解源）；HTTP/2 的字节级机制见 [HTTP2机制.md](../../../03-数据与中间件/中间件/RPC框架/gRPC/HTTP2机制.md)；TLS 与 ALPN 见 [HTTPS与TLS.md](HTTPS与TLS.md)。
>
> 内容整理自个人学习笔记，素材见 [素材清单](../../../素材清单.md)。
>
> ✅ 实测口径：第四节（具体使用）的 Go 代码只用标准库，`go build` + `go vet` + `gofmt` 通过，60 请求多路复用实验**在本机真实跑过**；⚠️ **未实测**抓包一层（本机无 `tcpdump` / `tshark`），Java 侧只编译未运行。

---

## 一、HTTP 与 gRPC 有什么区别？

**本节要点**：**关键思路转换——这个问题等价于「HTTP/1.1 vs HTTP/2」**。
答不出这个转换，就会在"HTTP 和 gRPC 传的东西不一样"上绕圈。

### 1.1 先做问题转换（一句话点透）

> **gRPC 的底层就是 HTTP/2**。
> 所以"HTTP 和 gRPC 的区别"→ 转成 **"HTTP/2 和 HTTP/1.1 的区别"**
> （我们现在常用的 HTTP 是 1.1）。
>
> 而 **HTTP/1.1 和 HTTP/2 底层都是 TCP**，**底层是一样的**，
> 区别全在**应用层**。

![HTTP 与 gRPC 的三层分层对照：语义层、承载层、编码层的差别分布，两者的底层都是 TCP](images/HTTP与gRPC的三层分层对照.svg)

把两个东西拆成三层看，就能看出「HTTP 和 gRPC 的区别」这个问题为什么等价于「HTTP/2 和 HTTP/1.1 的区别」——真正的差异集中在承载层与编码层，语义层是同一套 HTTP 语义。这也解释了为什么 gRPC 换成 JSON codec 仍然能跑：编码层是可替换的，承载层的多路复用与头压缩才是它快的原因。

⚠️ **一句必须先说清的前提**：HTTP/1.1 **规范里是有「管线化」（pipelining）的**——允许一个连接上连续发出多个请求，不必等前一个响应。
但**响应必须按请求顺序返回**，只要第一个请求慢，后面的响应就得排队；再加上中间设备与早期实现的兼容性问题，**主流浏览器实际上都没有启用它**。
所以准确说法是：**HTTP/1.1 具备管线化的协议能力，但它的实际行为近似「一次一个请求」**——这两件事要分开说，不能把「浏览器不用」讲成「协议不支持」。

### 1.2 HTTP/2 相对 HTTP/1.1 改了哪五处（只留结论）

> 完整机制见 [HTTP1.1与HTTP2的区别.md](HTTP1.1与HTTP2的区别.md)——它是本仓「HTTP/1.1 ↔ HTTP/2 对照」的**唯一详解源**。这里只保留与 gRPC 直接相关的结论，避免两处重复。

| # | 改动 | 结论（gRPC 视角） |
|---|---|---|
| **①** | **多路复用** | 一条连接跑多条流，消除**应用层**队头阻塞；**TCP 层**的仍在（丢一个包阻塞整条连接）——新篇第二、七节 |
| **②** | **二进制分帧** | 计算机对二进制解析有天然优势；这是「gRPC 解析优于 HTTP」的来源之一（另一个是默认 Protobuf，见 1.3） |
| **③** | **HPACK 头部压缩** | 索引表**按连接**维护 → 「一条连接跑更多请求」才能把收益吃满，这也是 HTTP/2 要减少连接数的原因之一 |
| **④** | **服务端推送** | 协议里有 `PUSH_PROMISE`，但**主流浏览器已移除**（Chrome 106 起）；不宜再当核心优势讲——新篇 6.1 |
| **⑤** | **流的优先级** | 原来的依赖树 + 权重**已被弃用**（RFC 9113 标记），改由 **RFC 9218** 的紧急度 + 增量承接——新篇 6.2 |

📎 多路复用的量化：同一连接上 60 个并发请求，HTTP/1.1 恒为 6.05s（60 × 100ms，纯排队），HTTP/2 降到 110ms（真并发）——见 [HTTP1.1与HTTP2的区别.md](HTTP1.1与HTTP2的区别.md) 7.4 的完整表与复现程序。

⚠️ 常见错误说法「HTTP/1.1 需要额外配 TLS、HTTP/2 天然支持 TLS」**混淆了层次**：HTTP 与 TLS 是两层，两者都能跑在 TLS 之上、也都可以不跑。浏览器 HTTPS 之所以"看起来是 h2"，是握手时用 **ALPN** 协商出了 `h2`（实测见 [QUIC与HTTP3.md](QUIC与HTTP3.md) 3.3）；HTTP/2 另有明文版本 **`h2c`**，gRPC 的明文连接就是它。

### 1.3 ⚠️ 一个重要澄清（避免答错）

> **HTTP 和 gRPC 的区别，不是"传输内容的格式不同"。**
>
> - gRPC **也可以**用 JSON 传输（换 codec 即可，见 [不使用protoc的gRPC.md](../../../01-编程语言/go/网络编程/不使用protoc的gRPC.md)）；
> - HTTP **同样可以**传 JSON，**也可以**传 Protobuf。
>
> **真正的区别是「机制」**——
> 比如**怎么解决应用层队头阻塞**（多路复用）、**怎么压缩头部**（HPACK）、**怎么定义服务契约**（`.proto` + 代码生成）。

⚠️ 但补一句必要的限定：**gRPC 的标准默认编码就是 Protobuf**。所以「gRPC 默认传什么」和「gRPC 能不能传别的」是两回事：
**默认是 Protobuf（二进制、需 schema）**，换成 JSON 属于**定制 codec**，会同时丢掉「体积小、编解码快、编译期强类型」这三项收益。
不要因为「gRPC 也能用 JSON」就以为**线上常见配置**里 JSON 是常态。

### 1.4 抓住一到两个点讲透就够

> **不要五点平铺。**
> 比较扎实的选择是：**把「多路复用」讲明白**——尤其是**应用层队头阻塞与 TCP 层队头阻塞的区别**（1.2 表第 ① 行，完整机制见 [HTTP1.1与HTTP2的区别.md](HTTP1.1与HTTP2的区别.md) 第七节）；
> 或者**把「头部压缩」讲清楚**——重点答出「索引表**按连接**维护，所以复用同一条连接才能把收益吃满」。

---

## 二、连接复用了，那「一条消息到哪儿结束」？

**本节要点**：复用一条连接的前提是**每条消息都能被准确定界**。1.1 靠头部约定（长度 / 分块 / 关闭），2 换成「帧自带长度」，代价是引入**两级流控**。⚠️ 完整机制（三种定界方式、`Content-Length` 与 `Transfer-Encoding` 并存为何要按错误处理、两级窗口的初始值与边界）见 [HTTP1.1与HTTP2的区别.md](HTTP1.1与HTTP2的区别.md) 第三、五节；本节只留四条最常被问混的结论。

| 说法 | 准确性 |
| --- | --- |
| 「HTTP/1.1 靠 `Content-Length` 定界」 | 只说了一种——还有 **`chunked`**（0 长度块收尾）与**连接关闭**；三者根因都是 **TCP 是字节流、没有边界** |
| 「HTTP/2 的流控就是 TCP 流控」 | ❌ 两层独立：TCP 的 `rwnd` 管**接收方内核缓冲**，HTTP/2 用**连接级 + 流级**窗口管**多条流之间的配额**；而且**只有 `DATA` 帧受它约束** |
| 「HTTP/2 比 HTTP/1.1 多了流控」 | 要说得更准：多的是「**应用层多流之间的配额分配**」，**不取代** TCP 的流控，与拥塞控制（`cwnd`）也是三件不同的事 |
| 「HTTP/1.1 不支持连接复用」 | ❌ 支持——1.1 起**持久连接是默认行为**，只是**不能在同一条连接上并行多个响应** |

⭐ 一条落在工程上的结论：**「响应体不读完就关连接」会污染连接池**——剩下的字节会被当成下一条响应的开头。这也是为什么客户端必须把 body 读干再 `Close`（见 [HTTP客户端与连接池.md](../../../01-编程语言/go/网络编程/HTTP客户端与连接池.md) 第六节）。

---

## 三、应用场景

**本节要点**：gRPC 与 HTTP 的取舍落在「服务间调用」与「浏览器/第三方对接」两类场景上。

| 场景 | 涉及哪一块 | 为什么值得注意 |
|---|---|---|
| 服务间 RPC | 默认 Protobuf + HTTP/2 | 强类型、体积小、可双向流，详见 本篇第一节 |
| 浏览器 / 第三方对接 | REST + JSON | 无需代码生成、可读性好，详见 [数据序列化.md](../选型/数据序列化.md) |
| 长连接被复用 | 连接池与 `httptrace` | 池子大小与超时分档见 [HTTP客户端与连接池.md](../../../01-编程语言/go/网络编程/HTTP客户端与连接池.md) 第四节 |
| 服务端推送 | WebSocket / SSE / gRPC 流 | 三者适用面不同，横向判据见 [通信选型.md](../选型/通信选型.md) 3.1 ②；协议细节见 [WebSocket.md](WebSocket.md) 与 [SSE.md](SSE.md) |
| 网关上的协议转换 | HTTP ↔ gRPC 转码 | 一条连接上跑两种语义，详见 [从零实现网关.md](../../../01-编程语言/go/网络编程/从零实现网关.md) |
| 连接池被污染 | body 不读完就关 | 残留字节会被当下一条响应，见 [HTTP客户端与连接池.md](../../../01-编程语言/go/网络编程/HTTP客户端与连接池.md) 第六节 |

## 四、具体使用

**本节要点**：这一节只留两件本篇独有的事——**gRPC 接入比 HTTP 多做了什么**，以及**怎么在命令行与抓包里确认版本、ALPN 与帧**。Go / Java 的客户端池调参已按语言拆出，见 [HTTP客户端与连接池.md](../../../01-编程语言/go/网络编程/HTTP客户端与连接池.md) 与 [接入HTTP客户端.md](../../../01-编程语言/java/工程实践/接入HTTP客户端.md)。

### 4.1 gRPC：机制与 HTTP/2 完全一致，代码只多一层生成

第一节的澄清是「区别在机制，不在传什么」。所以工程上接入 gRPC 只有两件事：
**① `.proto` 与服务生成（见 [Protobuf.md](../../../03-数据与中间件/中间件/RPC框架/gRPC/Protobuf.md)），② 客户端保持长连接**（因为它是一条 HTTP/2 连接上跑多流）。

```go
package httpdemo

// 说明性片段：真实的 gRPC 拦截器要 `google.golang.org/grpc` 与生成的 stub，
// 这里只给出**必须存在**的三件事——超时、重试预算、单例连接。
//
//  1. 连接是单例：grpc.ClientConn 内部自带负载均衡与重连，
//     一次调用一个 grpc.Dial 等于每次重新握手，而且会泄漏连接（必须 Close）。
//     conn := grpc.NewClient(target, grpc.WithDefaultServiceConfig(...))  // 全进程共用
//
//  2. 超时由调用方给：ctx, cancel := context.WithTimeout(ctx, 200*time.Millisecond)
//     第一节说 gRPC 是"二进制帧 + 多流"，但**慢流会占住那条连接的一个流配额**，
//     所以服务端要设 MaxConcurrentStreams，客户端要设每调用的 deadline。
//
//  3. 重试要幂等：grpc 的 retry policy 会在 TRANSIENT_FAILURE 上重放，
//     非幂等接口（扣款）必须关掉自动重试，改由业务侧带幂等键。
```

### 4.2 抓包与命令实操

![连接是否复用这条链路的三层观测路径：客户端侧 httptrace 的新建与复用两分支读数、命令行侧 curl 的关键三行与 -j 并发下 h2 一条连接对 1.1 四条连接的对照、抓包侧 tshark 按帧字段验证五项机制，底部是症状到结论的四条判据](images/连接是否复用的三层观测路径.svg)

图注：连接是否复用这条链路的三层观测路径：客户端侧 httptrace 的新建与复用两分支读数、命令行侧 curl 的关键三行与 -j 并发下 h2 一条连接对 1.1 四条连接的对照、抓包侧 tshark 按帧字段验证五项机制，底部是症状到结论的四条判据。图里 ① 那一层的复现程序与读数见 [HTTP客户端与连接池.md](../../../01-编程语言/go/网络编程/HTTP客户端与连接池.md) 第二节。

#### 一眼看清协议版本、ALPN 与帧

```bash
# 关键看三行：Connected to / ALPN 协商结果 / 是否 "Using HTTP2, server supports multiplexing"
curl -v --http2 https://example.com/ -o /dev/null 2>&1 | egrep 'ALPN|SSL connection|HTTP/|< |expire'

# 强制退回 1.1，对比同一个接口的连接数（即 [HTTP1.1与HTTP2的区别.md](HTTP1.1与HTTP2的区别.md) 7.4 实测的结论）
curl -v --http1.1 https://example.com/ -o /dev/null

# 跳过 TLS 直连 h2c（需要先验 prior knowledge）
curl -v --http2-prior-knowledge http://127.0.0.1:8080/

# 证书链与到期：TLS 是 h2 的事实前提（HTTP 与 TLS 是两层，靠 ALPN 协商出 h2）
echo | openssl s_client -connect example.com:443 -alpn h2 2>/dev/null | openssl x509 -noout -subject -issuer -dates -ext subjectAltName
```

**容器实测**（服务端：`golang:1.26-alpine` 里 `net/http` 起的 TLS 服务，标准库自动开 h2；客户端：`nginx:1.26-alpine` 自带的 `curl 8.12.1`（nghttp2/1.62.1），两个容器接在同一张自建网络里；`-k` 只因为服务端证书是每次启动现生成的自签证书）：

```text
* ALPN: curl offers h2,http/1.1
* SSL connection using TLSv1.3 / TLS_AES_128_GCM_SHA256 / x25519 / id-ecPublicKey
* ALPN: server accepted h2
* Connected to w2ra2d-h2srv (192.168.208.2) port 8443
< HTTP/2 200
< x-served-proto: HTTP/2.0
```

六条读数说明的事：

- **同一个地址、同一场 TLS 握手，加不加 `--http1.1` 就换掉应用层版本**：改成 `--http1.1` 后是 `ALPN: server accepted http/1.1` 配 `< HTTP/1.1 200 OK`——版本是**协商**出来的，不是「升级」出来的，也再次印证 HTTP 与 TLS 是两层（1.2 那条常见错误说法）。
- **头部字段名在 h2 上是小写**：同一个自定义头，1.1 响应回 `X-Served-Proto: HTTP/1.1`，h2 响应回 `x-served-proto: HTTP/2.0`（HPACK 要求字段名小写），过网关时按大小写取头就会踩坑。
- **复用能亲眼看到**：一次 `curl` 传两个 URL，第二条前面是 `Re-using existing connection with host w2ra2d-h2srv`，两条都是 `< HTTP/2 200`——对应 Go 侧 `httptrace` 里 `GotConn` 的 `Reused=true`（见 [HTTP客户端与连接池.md](../../../01-编程语言/go/网络编程/HTTP客户端与连接池.md) 第二节）。
- ⚠️ **`Using HTTP2, server supports multiplexing` 这行在新版 curl 里已经没有了**：上面这台 `curl 8.12.1` 的 `-v` 输出搜不到它，判据改成看 `ALPN: server accepted h2` 与 `< HTTP/2 200` 两行。
- **明文 h2c 走不通**：`curl --http2-prior-knowledge http://同一地址:8443/` 直接回 `curl: (16) Error in the HTTP2 framing layer`（退出码 16），因为 Go 标准库的 HTTP/2 只在 TLS 之上靠 ALPN 开启。
- **并发下「省的是连接数」也能在命令行上量出来**：`curl -j --parallel --parallel-max 4` 一次传 4 个会让服务端睡 1.5s 的 URL（`?slow=x`），分两批各跑 3 轮、共 6 轮，趋势一致——
  - `--http2`：`Connected to` 只出现 **1 次**、`Re-using existing connection` **3 次**、`ALPN: curl offers` **1 次**，四条各自的 `time_total` 全落在 **1.508s ~ 1.516s**（四条同时在同一条连接上跑完）；
  - `--http1.1`：`Connected to` **4 次**、`Re-using` **0 次**，四条各自 **1.511s ~ 1.555s**，且 `ALPN: curl offers http/1.1` 也重复了 **4 次**——4 场 TLS 握手；
  - ⚠️ **两者墙钟时间在这个场景看不出差别**：`-j` 会给 1.1 另开 4 条连接去并行，所以省下来的是**连接数与握手次数**，不是请求耗时。要把墙钟的差别压出来，必须把并发压到**同一条连接**上，见 [HTTP1.1与HTTP2的区别.md](HTTP1.1与HTTP2的区别.md) 7.4 那组 60 个请求的实测。

  > 📌 这台服务端把 `?slow=` 的睡眠固定在 1.5s（`time.Sleep`），所以「单条请求 1.5s」是**服务端下限**，读数只用来比连接数与并发形态，不用来比真实性能。

#### 直接看 HTTP/2 的帧与 HPACK

```bash
# tshark：解开 TLS 才能看帧（用 SSLKEYLOGFILE，方法见 HTTPS与TLS.md）
TSHARK_DEBUGS="Wireshark:DecryptionKeys:/tmp/sslkey.log:TLS" \
  tshark -i lo -f 'tcp port 8443' -Y http2 \
  -T fields -e frame.number -e http2.type -e http2.streamid -e http2.header.name -e http2.header.value

# 只看 HEADERS 帧：能亲眼看到"第二次请求不再重复发 user-agent"（HPACK 增量索引）
tshark -r h2.pcapng -Y 'http2.frame.type==1' -T fields -e http2.streamid -e http2.headers
```

| 想验证的机制 | 看什么 |
|---|---|
| 「帧 + 流 ID 重排」 | `http2.streamid` 在同一个 TCP 连接上**交错出现** |
| 「同一请求的数据不要求连续」 | 同一 stream 有多个 `DATA` 帧，中间夹着别的 stream |
| 「各自维护一张索引表」 | 第二个请求的 `HEADERS` 帧长度显著变小 |
| 「服务端推送」 | 出现 `PUSH_PROMISE` 帧（Go 标准库**不支持**推送，要用 h2_bundle 或换语言） |
| 「流的优先级」 | `HEADERS` 帧里的 `PRIORITY` 段；h2 `dependency`/`weight` |

> 📌 环境口径：本节 1 小节的命令行读数在容器里实跑过，Go 侧 `httptrace` 读数见 [HTTP客户端与连接池.md](../../../01-编程语言/go/网络编程/HTTP客户端与连接池.md) 第二节；**抓包这一层未实测**——本机与本机镜像里都没有 `tcpdump` / `tshark`，这张表给的是命令、字段名和判据。帧与 HPACK 的字节级细节见 [HTTP2机制.md](../../../03-数据与中间件/中间件/RPC框架/gRPC/HTTP2机制.md)。

---

## 延伸追问

- **HTTP/1.1 与 HTTP/2 到底差在哪？** → 见 [HTTP1.1与HTTP2的区别.md](HTTP1.1与HTTP2的区别.md)——并发模型、消息定界、头部压缩、两级流控、优先级与服务端推送的现状、队头阻塞的边界都在那一篇；本篇只保留与 gRPC 有关的结论（1.2 表）。
- **gRPC 一定比 HTTP+JSON 快吗？** → 主要快在**序列化体积**（默认 Protobuf）与多路复用，不是协议本身；载荷小、调用少的场景差异很小，还要付出可读性与网关兼容性的代价。⚠️ 「gRPC 也能传 JSON」说的是**可以换 codec**，但**默认就是 Protobuf**，换成 JSON 会同时丢掉体积、速度与编译期类型检查三项收益。
- **连接池该配多大？** → 看「并发请求数 ÷ 单连接可承载并发」。HTTP/2 一条连接能跑多流，池子该**小**；HTTP/1.1 才需要按并发数放大（见 [HTTP1.1与HTTP2的区别.md](HTTP1.1与HTTP2的区别.md) 7.4 的实测）。
- **长连接有哪些坑？** → 空闲连接被中间设备静默断开（要 keepalive + 重试）、`MaxConnsPerHost` 配太小导致排队、以及服务端 LB 下连接分布不均。
- **怎么确认连接到底复用了没？** → 用 `httptrace` 的 `GotConn` 看 `Reused` 字段（见 [HTTP客户端与连接池.md](../../../01-编程语言/go/网络编程/HTTP客户端与连接池.md) 第二节），比在代码里打日志可靠；⚠️ `Transport` 才是池的主人，`Client` 只是壳。
- **代理会破坏 HTTP/2 吗？** → 会：非 h2-aware 的代理要么把连接降级成 1.1、要么逐条转发丢掉多路复用；上线前用 ALPN 协商结果确认。

## 关联

- **上游/总纲**：[HTTP1.1与HTTP2的区别.md](HTTP1.1与HTTP2的区别.md) — 本篇的上游：HTTP/1.1 与 HTTP/2 的完整差异机制
- **对照/互补**：[QUIC与HTTP3.md](QUIC与HTTP3.md) — 传输层队头阻塞的根治方案，以及 ALPN / Alt-Svc 的发现路径
- **对照/互补**：[HTTPS与TLS.md](HTTPS与TLS.md) — `h2` 的事实前提：TLS 与 ALPN 协商
- **对照/互补**：[TCP报文结构.md](../传输层/tcp/TCP报文结构.md) — 字节流为什么没有边界、应用层如何自己划界
- **对照/互补**：[TCP滑动窗口.md](../传输层/tcp/TCP滑动窗口.md) — `rwnd`（流控）与 `cwnd`（拥塞控制）的分工
- **对照/互补**：[UDP与数据报传输.md](../传输层/UDP与数据报传输.md) — 与「保留消息边界」的数据报模型对照
- **下游机制**：[HTTP2机制.md](../../../03-数据与中间件/中间件/RPC框架/gRPC/HTTP2机制.md) — HTTP/2 的字节级机制：帧头、HPACK、流状态机、两层限额
- **下游机制**：[HTTP客户端与连接池.md](../../../01-编程语言/go/网络编程/HTTP客户端与连接池.md) — Go 侧客户端池调参：三层结构、池参数默认值、超时分档与容器实测（原本篇 §5.1 的实测读数在那）
- **下游机制**：[接入HTTP客户端.md](../../../01-编程语言/java/工程实践/接入HTTP客户端.md) — Java 侧 JDK `HttpClient` 与 OkHttp 的接入与三套客户端参数对照
- **下游机制**：[数据序列化.md](../选型/数据序列化.md) — gRPC 默认载荷 Protobuf 的编码形态

> 反向引用（本篇被下列文档引到）：[接入Jaeger.md](../../../01-编程语言/go/可观测性/接入Jaeger.md)、[context.md](../../../01-编程语言/go/工程实践/context.md)、[HTTP客户端与连接池.md](../../../01-编程语言/go/网络编程/HTTP客户端与连接池.md)、[net包与TCP-UDP编程.md](../../../01-编程语言/go/网络编程/net包与TCP-UDP编程.md)、[不使用protoc的gRPC.md](../../../01-编程语言/go/网络编程/不使用protoc的gRPC.md)、[从零实现网关.md](../../../01-编程语言/go/网络编程/从零实现网关.md)、[网关路由匹配.md](../../../01-编程语言/go/网络编程/网关路由匹配.md)、[接入gRPC.md](../../../01-编程语言/java/工程实践/接入gRPC.md)、[08-WWW服务器.md](../../linux/鸟哥服务器架设篇/08-WWW服务器.md)、[反向代理原理.md](../代理与网关/反向代理原理.md)、[WebSocket.md](WebSocket.md)、[SSE.md](SSE.md)、[网络分层与数据包旅程.md](../网络分层与数据包旅程.md)、[DHCP.md](../网络层与寻址/DHCP.md)、[DNS解析.md](../网络层与寻址/DNS解析.md)、[通信选型.md](../选型/通信选型.md)、[RPC框架选型对比.md](../../../03-数据与中间件/中间件/RPC框架/RPC框架选型对比.md)、[Protobuf.md](../../../03-数据与中间件/中间件/RPC框架/gRPC/Protobuf.md)、[四种通信模式.md](../../../03-数据与中间件/中间件/RPC框架/gRPC/四种通信模式.md)、[基础概念.md](../../../03-数据与中间件/中间件/RPC框架/gRPC/基础概念.md)、[生态与网关.md](../../../03-数据与中间件/中间件/RPC框架/gRPC/生态与网关.md)、[Jaeger.md](../../../03-数据与中间件/中间件/可观测性/Jaeger.md)、[Skywalking.md](../../../03-数据与中间件/中间件/可观测性/Skywalking.md)、[可观测性选型对比.md](../../../03-数据与中间件/中间件/可观测性/可观测性选型对比.md)
