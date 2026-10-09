# gRPC-Web 与 Connect

> 浏览器为什么说不了原生 gRPC、gRPC-Web 补了哪一块、接它要付出的那条代理、以及 Connect（Buf）为什么能「一个服务端同时说三种协议」。含三条接法与选型判据。
>
> 内容整理自个人学习笔记，**官方口径 + 公开文档，本篇没有读数**。素材见 [素材清单](../../../../素材清单.md)。
>
> ⚠️ **未实测声明（三段式）**：
> **归属**：gRPC-Web 规范、Envoy / nginx 的过滤器文档、Connect（`connectrpc.com`）与 grpc-gateway 的公开文档；
> **范围与边界**：只讲「为什么需要、有哪几条路、各自代价」，不讲前端框架集成与前端工程化；
> **✅ 实测口径**：**无**——本实验台是纯 gRPC（Go + Java）的容器台，**浏览器与任何转码层都没搭起来**，所以本篇出现的所有配置片段**都不是读数**；
> **⚠️ 仍未实测**：Envoy 的 `grpc_web` 过滤器、nginx 侧的 gRPC-Web 能力、Connect 服务端、grpc-gateway 的转码——**四项全未跑过**。本篇的作用是「选型判据 + 排查时知道该往哪查」。

---

## 一、浏览器为什么不能直接说 gRPC？

**本节要点**：不是「浏览器不支持 HTTP/2」（它支持），而是**JS 拿不到 HTTP/2 的帧级控制权**——`fetch` / `XHR` 无法读 trailer、无法设 `te` 头、也无法流式发送请求体。这三条正好是 gRPC 的三个必需品。

| gRPC 需要 | 浏览器给不给 | 后果 |
|---|---|---|
| 读 HTTP **trailer**（`grpc-status` 在这） | ❌ `fetch` / `XHR` 都读不到 trailer | 拿不到结束状态——**这是最致命的一条** |
| 设 `te: trailers` 等受限头 | ❌ 部分头被浏览器禁止 | 无法完整表达 gRPC 请求 |
| **流式发送**请求体（客户端流 / 双向流） | ❌ `fetch` 不能边算边发请求体 | 浏览器只能做一元与服务端流 |
| HTTP/2 本身 | ✅ 支持，但走的是「一个请求一条流」的封装 | 多路复用由浏览器内部决定 |

所以「浏览器 + gRPC」不是协议问题，是**浏览器 API 的表达力问题**。这也解释了为什么所有方案都得在中间加一层或换一种编码。

---

## 二、gRPC-Web：把 trailer 搬进响应体

**本节要点**：gRPC-Web 的思路是「**换一种浏览器能产出的编码**」——用 HTTP/1.1 或 HTTP/2 的正常请求响应，把 trailer 编码进**响应体末尾**；代价是必须有一个代理做双向翻译。

三条规范层面的差异：

| 项 | 原生 gRPC | gRPC-Web |
|---|---|---|
| `content-type` | `application/grpc`（子类型可加 `+proto` / `+json`） | `application/grpc-web`（另有 `application/grpc-web-text`，base64 文本模式） |
| 结果状态 | HTTP/2 **trailer** | **响应体末尾的 trailer 帧**（浏览器读不到真正的 trailer） |
| 流的支持 | 四种全支持 | **只有一元与服务端流**——客户端流与双向流在浏览器上不可用 |
| 需要代理吗 | 不需要 | **需要**（Envoy 的 `grpc_web` 过滤器，或 Go 侧的 grpcweb 包装） |

⭐ **一条必须记住的边界**：gRPC-Web **不支持**客户端流与双向流。
如果你的接口设计里用了双向流（实时对话那类），浏览器这条路要么改成「服务端流 + 另一个上行通道」，
要么换 Connect / WebSocket（[四种通信模式.md](四种通信模式.md) 6.4 有选型判据）。

---

## 三、三条接法

**本节要点**：**Envoy 转码**最成熟、**grpc-gateway**给的是 REST/JSON（不是 gRPC-Web）、**Connect** 不需要代理；选哪条取决于「前端是自家浏览器应用还是第三方」。

### 3.1 Envoy 的 `grpc_web` 过滤器（原路，最成熟）

链路：`浏览器 →(gRPC-Web) Envoy →(原生 gRPC) 后端`。

Envoy 侧要做两件事：装 `envoy.filters.http.grpc_web` 过滤器（做协议翻译），
并按需装 `envoy.filters.http.cors`（浏览器跨域）。
⚠️ **CORS 是这条路最常见的坑**：gRPC-Web 的 `content-type` 与自定义 metadata 头都不在简单请求允许的头里，
**必须显式配 `Access-Control-Allow-Headers`**，否则前端看到的是「预检失败」而不是业务错误。
具体配置字段**本仓未实测**，以 Envoy 当前版本的文档为准。

### 3.2 nginx 侧

⚠️ **先核能力再动手**：nginx 官方的 `ngx_http_grpc_module` 提供的是 `grpc_pass`
（**原生 gRPC 反代**，见 [生态与网关.md](生态与网关.md) 第四节），**它不是 gRPC-Web 的转码器**——
「要不要额外模块、当前版本有没有内建支持」这件事**本仓没有实测**，
接之前先按你所用 nginx 版本的模块清单核对，别照着「网上说加一行就行」的帖子配。

### 3.3 grpc-gateway：给的是 REST，不是 gRPC-Web

`github.com/grpc-ecosystem/grpc-gateway/v2` 是 protoc 插件：读 `.proto` 里的
`google.api.http` 注解，生成一个**反向代理**，把 REST/JSON 请求翻成 gRPC 调用；
顺带能生成 OpenAPI（`protoc-gen-openapiv2`）。

**它和 gRPC-Web 解决的不是同一个问题**：

| | gRPC-Web | grpc-gateway |
|---|---|---|
| 前端拿到什么 | 生成的 JS 客户端（protobuf） | **普通 REST/JSON**（`fetch` 就能调） |
| 谁在用 | 自家前端 | **第三方 / 只想给 REST 的场景** |
| 流式 | 一元 + 服务端流 | 更弱（更像 SSE 风格） |
| 额外收益 | —— | **OpenAPI 文档**、路径与动词是 REST 形状 |
| 代价 | 需要转码代理 | 需要维护注解与生成物；**REST 是二等公民**（表达力不如 proto） |

判据：**前端是自家浏览器应用 → gRPC-Web / Connect；要给外部方标准 REST → grpc-gateway**。
两者可以并存（同一后端既跑 gRPC 又挂 gateway），但那就是两套契约要同时演进。

---

## 四、Connect：一个服务端同时说三种协议

**本节要点**：Connect（Buf）把「协议翻译」从代理搬进了你的 handler——同一个服务端**原生支持 Connect / gRPC / gRPC-Web 三种协议**，于是浏览器不再需要中间那一跳。

### 4.1 它兑现了什么

- **同一个 handler 三种协议**：Connect 协议（HTTP POST + JSON 或 proto）、gRPC、gRPC-Web；
  客户端用 `WithGRPC` / `WithGRPCWeb` 选择；
- **浏览器不需要转码代理**：Connect 的 Go 服务端**原生处理 gRPC-Web 请求**（二进制模式）；
  ⚠️ **不支持 gRPC-Web 的文本（base64）模式**——用 `protoc-gen-grpc-web` 生成前端代码时要指定 `mode=grpcweb`；
- **对 curl 友好**：Connect 协议用普通 HTTP POST，可以像 REST 一样 `curl` 调试
  （而原生 gRPC 得靠 `grpcurl`）；
- **生态件齐**：反射 `connectrpc.com/grpcreflect`（新版与 v1alpha 两个版本都要挂，
  否则 grpcurl 可能认不出）、健康检查 `connectrpc.com/grpchealth`；
- **Go 侧落进 `net/http`**：handler 是标准 `http.Handler`，中间件、路由、可观测性都能复用现成的
  （代价：gRPC 的拦截器与 metadata API 要换成 Connect 的 `connect.Request` / `connect.Response`）。

### 4.2 代价与适用面

| 项 | 说明 |
|---|---|
| 需要改服务端代码 | 不是「加一层」，而是**把服务实现搬到 Connect 的接口上**；客户端可以不动（继续用 gRPC） |
| 「全流式」有条件 | 服务端之间走 HTTP/2 时四种模式都能用；**浏览器侧仍受 `fetch` 限制**（一元 + 服务端流） |
| 生态更小 | 比 Envoy + gRPC-Web 那条老路新，周边（多语言实现、网关集成）还在长 |
| 调试更简单 | 能用 `curl` 与 `grpcurl` 两种方式调同一个方法，排障成本明显低 |

---

## 五、选型判据

**本节要点**：三问定方案——**前端是谁**、**要不要流**、**愿不愿意多一跳**。

| 场景 | 推荐 | 理由 |
|---|---|---|
| 自家浏览器应用，且已有 Envoy / 服务网格 | **gRPC-Web + 转码** | 代理已在链路上，加个过滤器成本最低；方案最成熟 |
| 自家浏览器应用，不想加代理 | **Connect** | 协议翻译内置，浏览器直连；前端拿 TypeScript 生成物 |
| 要给第三方 / 要 OpenAPI 文档 | **grpc-gateway** | 输出标准 REST/JSON，正是第三方要的形状 |
| 需要浏览器上的**双向流** | **都不是**（gRPC-Web 做不到） | 用 WebSocket / SSE 另开一条通道，或把交互改成「服务端流 + 上行普通请求」 |
| 只服务端之间通信 | **不需要本篇任何方案** | 直接 gRPC（本目录其余篇目） |

三条反向判据（别为了省事选错）：

- **别用 gRPC-Web 去做「REST 对外开放」**：那是 grpc-gateway 的活，gRPC-Web 的前端不是给人手写的；
- **别为了「不加代理」而重写服务**：如果服务端已经稳定、代理也已经在链路上，
  加一个 Envoy 过滤器比迁移到 Connect 便宜得多；
- **别指望浏览器做客户端流**：这是 `fetch` 的限制，换协议也解决不了。

---

## 使用：接上去之前要确认的六件事

**本节要点**：一份检查清单——本篇四项全未实测，所以这里给的是「**该确认什么**」，不是「照抄就能跑」。

> ⚠️ 下面所有配置**未实测**，字段名与写法以你所用版本（Envoy / nginx / Connect / grpc-gateway）的文档为准。

1. **前端要的到底是 gRPC-Web 还是 REST？** → 前者给自家前端、后者给第三方；选错了会白做一层。
2. **接口里有没有客户端流 / 双向流？** → 有的话浏览器这条路走不通，先改接口形状（第五节）。
3. **CORS 配了没有？** → gRPC-Web 的 `content-type` 与自定义 metadata 头**必须显式放进 `Access-Control-Allow-Headers`**；
   漏配的典型症状是「预检请求失败」，而不是业务错误。
4. **贴合的 `content-type` 对不对？** → `application/grpc-web`（二进制）与 `application/grpc-web-text`（base64）
   是两个东西；**Connect 只支持二进制那个模式**（第四节 4.1）。
5. **后端要不要改？** → gRPC-Web / grpc-gateway 都是「加一层」；Connect 是「改服务实现」；
   这一条决定工作量级别。
6. **调试手段有没有？** → 原生 gRPC 靠 `grpcurl`（需要反射，见 [生态与网关.md](生态与网关.md) 1.3）；
   Connect 可以用 `curl`；**先把 `list` / 一次一元调通**，再接前端。

---

## 延伸追问

- **浏览器支持 HTTP/2，为什么还说不了 gRPC？** → 支持的是传输，缺的是**表达能力**：
  读不到 trailer、发不了流式请求体、设不了受限头（第一节）。
- **gRPC-Web 为什么必须有个代理？** → 因为它是一种**不同的编码**，
  后端只会说原生 gRPC，中间得有人翻译（第二节）。
- **gRPC-Web 能不能做双向流？** → 不能。浏览器侧只有一元与服务端流；
  要双向得换通道（WebSocket / SSE）或改交互形状（第二节、第五节）。
- **grpc-gateway 和 gRPC-Web 有什么区别？** → 前者给的是 **REST/JSON**（给第三方、带 OpenAPI），
  后者给的是**给自家前端的 protobuf 客户端**（第三节 3.3）。
- **Connect 是不是「另一种 gRPC」？** → 它是一个**协议家族**：自己一套（HTTP POST + JSON/proto），
  同时兼容 gRPC 与 gRPC-Web；服务端因此不需要转码代理（第四节）。
- **Connect 要我改客户端吗？** → 不用。现有 gRPC 客户端可以继续用；改的是**服务端实现**（第四节 4.2）。
- **nginx 上能不能直接开 gRPC-Web？** → 本仓没实测，也不写死结论；
  `ngx_http_grpc_module` 提供的是 `grpc_pass`（原生 gRPC 反代），**别默认它带 gRPC-Web 转码**（第三节 3.2）。
- **这条链上加了一层，怎么排障？** → 按「先直连后端、再走一层」的顺序：
  先用 `grpcurl` 直连后端确认业务正常，再接前端——把「业务问题」与「转码问题」分开
  （[故障排查与调试.md](故障排查与调试.md)）。

---

## 关联

- [README.md](README.md) — 本目录的目录导读（gRPC 知识库总纲）
- [四种通信模式.md](四种通信模式.md) — 浏览器侧为什么只有「一元 + 服务端流」可选
- [HTTP2机制.md](HTTP2机制.md) — trailer 在协议上是 HEADERS 帧；gRPC-Web 要绕开它
- [生态与网关.md](生态与网关.md) — `grpc_pass`、反射与 `grpcurl`、网关侧的其他配置
- [安全与认证.md](安全与认证.md) — 浏览器侧的凭据怎么带（token 在 header 上，不在 mTLS 里）
- [可观测性.md](可观测性.md) — 多一层转码后，trace 上下文还能不能过
- [故障排查与调试.md](故障排查与调试.md) — 先直连、再逐层加回来的排查顺序
- [数据序列化.md](../../../../02-计算机基础/网络/数据序列化.md) — 浏览器上 JSON 与 Protobuf 的取舍
