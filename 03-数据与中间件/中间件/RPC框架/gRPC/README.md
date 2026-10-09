# gRPC

> 本目录是 gRPC 的**纵向深入**：**13 篇**从「一次调用在 wire 上长什么样」讲到「上线前该检查什么」。
> 横向选型（该不该上 gRPC、和 Dubbo / REST 怎么比）在上一层的 [RPC框架选型对比.md](../RPC框架选型对比.md)；
> 本目录**假定调用层已经选了 gRPC**，只回答「它怎么用、会踩什么」。
>
> 内容整理自个人学习笔记。**本文件只给阅读顺序、依赖关系、边界与实测坐标**——每篇的知识点描述见
> [目录.md](../../../../目录.md)，本文件不重复描述。素材见 [素材清单.md](../../../../素材清单.md)。
> 约定见 [AGENTS.md](../../../../AGENTS.md)。

---

## 一、先读哪三篇

| 顺序 | 篇 | 一句话 |
|---|---|---|
| ① | [基础概念.md](基础概念.md) | ⭐ **地基**：HTTP/2 · Protobuf · gRPC **三层各自是什么、能不能换**、一次一元调用在 wire 上的全过程、连接为什么只建一次、metadata / deadline / 状态码三件套、默认限额。「不读它，后面每篇的『为什么』都缺一个解释框架」 |
| ② | [四种通信模式.md](四种通信模式.md) | gRPC 相对 REST 的**一等公民能力**：四种模式怎么选、真实业务场景与反例、服务端写法差在哪、流的结束语义（`EOF` 不是错误）、Go / Java 耗时对照与五个坑 |
| ③ | [实战示例.md](实战示例.md) | 落地那一半：该不该上、工程怎么布局、`.proto` 与生成物怎么审、上线前必须确认的十条 |

⭐ **顺序不能倒**：先建立「帧」这个视角，再谈「四种模式怎么写」，最后才是「怎么落地」。

---

## 二、三条阅读路线

### 2.1 快速上手（只想今天跑通）

```text
基础概念.md ① ──→ 四种通信模式.md ② ──→ 实战示例.md ③
                                     ↘ 故障排查与调试.md（跑不通时对照）
```

### 2.2 原理深挖（想把「为什么」弄清楚）

```text
基础概念.md ① ──→ HTTP2机制.md ② ──→ Protobuf.md ④
      ↓                    ↓                ↓
连接与生命周期.md ⑤   拦截器与元数据.md ⑥   安全与认证.md ⑦
```

先有帧与流（HTTP/2 机制），再有连接与调用（生命周期、拦截器），最后才是契约与安全。

### 2.3 工程落地（要上线、要值守）

```text
生态与网关.md ⑧ ──→ 可观测性.md ⑨ ──→ 性能与调优.md ⑩
      ↓                    ↓                ↓
安全与认证.md ⑦      故障排查与调试.md ⑫   实战示例.md ⑪
                                     ↘ gRPC-Web与Connect.md ⑬（有浏览器端时）
```

---

## 三、常见任务导航

| 你的任务 / 问题 | 直接读 |
|---|---|
| 「该不该上 gRPC」 | [RPC框架选型对比.md](../RPC框架选型对比.md)（本目录假定已经上了）；[实战示例.md](实战示例.md) 开头的判据 |
| 「报文一超过几 MB 就报 `ResourceExhausted`」 | [基础概念.md](基础概念.md) 第五节、[HTTP2机制.md](HTTP2机制.md) 第七节 |
| 「正常结束却拿到 `EOF`，这是错误吗？」 | [四种通信模式.md](四种通信模式.md) 第三节 |
| 「流式调用把连接拖死 / 服务端一直等」 | [四种通信模式.md](四种通信模式.md) 第五节、[连接与生命周期.md](连接与生命周期.md) 第四节 |
| 「字段删了不报错，但数据错位了」 | [Protobuf.md](Protobuf.md) 第三节（`reserved`） |
| 「schema 改动怎么防回退」 | [Protobuf.md](Protobuf.md) 第四节 + 使用三（`buf breaking`） |
| 「首连卡 8 秒 / 跨容器连不上」 | [生态与网关.md](生态与网关.md) 第五节、[故障排查与调试.md](故障排查与调试.md) 第二节 |
| 「nginx 转 gRPC 报 `no valid HTTP/1.0 header`」 | [生态与网关.md](生态与网关.md) 第四节、[故障排查与调试.md](故障排查与调试.md) 第四节 |
| 「截获不到 READY / 一直停在 IDLE」 | [连接与生命周期.md](连接与生命周期.md) 第一节 |
| 「偶发几十秒后被断连」 | [连接与生命周期.md](连接与生命周期.md) 第三节（`too_many_pings`） |
| 「滚动更新时在途请求被切断」 | [连接与生命周期.md](连接与生命周期.md) 第四节（两段式退出） |
| 「客户端只看到 `Unknown`」 | [拦截器与元数据.md](拦截器与元数据.md) 第七节 7.3 |
| 「要传结构化错误给前端」 | [拦截器与元数据.md](拦截器与元数据.md) 第七节 7.2（`errdetails`） |
| 「超时到底传没传到对端」 | [拦截器与元数据.md](拦截器与元数据.md) 第四节 |
| 「要重试，但对哪些码重试」 | [拦截器与元数据.md](拦截器与元数据.md) 第五节 |
| 「上 TLS / mTLS 怎么配、报错怎么读」 | [安全与认证.md](安全与认证.md) 第二、三节 |
| 「证书链合法但说我没带证书」 | [安全与认证.md](安全与认证.md) 第三节 3.1 |
| 「service mesh 里还要不要开客户端 LB」 | [生态与网关.md](生态与网关.md) 第三节 3.5 |
| 「链路追踪 / 指标怎么埋」 | [可观测性.md](可观测性.md) 第二、三节 |
| 「压测该怎么量、报哪些数」 | [性能与调优.md](性能与调优.md) 第二节 |
| 「加并发吞吐不涨」 | [性能与调优.md](性能与调优.md) 第三节 3.3 |
| 「浏览器能直接调吗」 | [gRPC-Web与Connect.md](gRPC-Web与Connect.md) 第一、五节 |
| 「Java / Go 的接入代码」 | 语言分册 —— 见第五节边界表最后两行 |

---

## 四、依赖关系（谁是谁的前置）

| 本篇 | 硬前置 | 强关联 |
|---|---|---|
| [基础概念.md](基础概念.md) | — | [HTTP与gRPC.md](../../../../02-计算机基础/网络/HTTP与gRPC.md)（HTTP/2 本体） |
| [HTTP2机制.md](HTTP2机制.md) | 基础概念 | [HTTP与gRPC.md](../../../../02-计算机基础/网络/HTTP与gRPC.md)、[连接与生命周期.md](连接与生命周期.md) |
| [四种通信模式.md](四种通信模式.md) | 基础概念 | [HTTP与gRPC.md](../../../../02-计算机基础/网络/HTTP与gRPC.md)（SSE / WebSocket / 流三条推送路） |
| [Protobuf.md](Protobuf.md) | — | [数据序列化.md](../../../../02-计算机基础/网络/数据序列化.md) |
| [连接与生命周期.md](连接与生命周期.md) | 基础概念 | [HTTP2机制.md](HTTP2机制.md)、[生态与网关.md](生态与网关.md) |
| [拦截器与元数据.md](拦截器与元数据.md) | 基础概念 | [可观测性.md](可观测性.md)、[安全与认证.md](安全与认证.md) |
| [安全与认证.md](安全与认证.md) | 基础概念 | [连接与生命周期.md](连接与生命周期.md)、[拦截器与元数据.md](拦截器与元数据.md) |
| [生态与网关.md](生态与网关.md) | 基础概念 | [网关选型对比.md](../../网关与代理/网关选型对比.md)、[Nacos.md](../../注册与配置中心/Nacos.md) |
| [可观测性.md](可观测性.md) | 拦截器与元数据 | [可观测性选型对比.md](../../可观测性/可观测性选型对比.md) |
| [性能与调优.md](性能与调优.md) | 四种通信模式、HTTP2机制 | [RPC框架选型对比.md](../RPC框架选型对比.md) |
| [实战示例.md](实战示例.md) | 前面各篇 | [接入gRPC.md](../../../../01-编程语言/java/接入gRPC.md)（Java 分册） |
| [故障排查与调试.md](故障排查与调试.md) | 基础概念 | 全目录（它是「症状 → 篇目」的索引） |
| [gRPC-Web与Connect.md](gRPC-Web与Connect.md) | 四种通信模式 | [生态与网关.md](生态与网关.md) |

---

## 五、每篇的边界（一句话）

| 篇 | 本篇答什么 | 本篇不答什么 |
|---|---|---|
| [基础概念.md](基础概念.md) | 三层是什么 / 能不能换、一次调用的四步、连接复用、三件套、限额 | 帧字段与状态机的细节（→ HTTP2机制）、怎么调优（→ 性能与调优） |
| [HTTP2机制.md](HTTP2机制.md) | 帧头字段、帧类型与标志、流与奇数 ID、HPACK 索引编码、流控、五态状态机 | 业务怎么调用、怎么写代码（→ 基础概念 / 四种通信模式） |
| [四种通信模式.md](四种通信模式.md) | 四种模式的定义、业务场景与反例、服务端写法差异、`EOF` 结束语义、五个坑 | 序列化格式（→ Protobuf）、工程化配置（→ 生态与网关） |
| [Protobuf.md](Protobuf.md) | 体积/速度为什么占优、一条消息的二进制形态、`reserved`、schema 演进、buf 门禁 | 与 JSON 的横向选型（→ 数据序列化）、协议本身（→ HTTP2机制） |
| [连接与生命周期.md](连接与生命周期.md) | 五态迁移、keepalive 的下限与违规判据、`GracefulStop` vs `Stop`、连接寿命旋钮 | 握手里证书怎么验（→ 安全与认证） |
| [拦截器与元数据.md](拦截器与元数据.md) | 洋葱序、一元拦截器为什么要自己调、流方向标志、deadline、重试、metadata 约定、状态码与 `errdetails` | 埋点后怎么展示（→ 可观测性）、认证凭据（→ 安全与认证） |
| [安全与认证.md](安全与认证.md) | TLS / mTLS 接法、握手失败的三类报错、客户端证书被静默过滤、`peer` 能拿到什么、token 与授权 | 证书体系的运维（→ 各有专篇）、网关侧 TLS 终止（→ 生态与网关） |
| [生态与网关.md](生态与网关.md) | resolver / balancer / 网格、`grpc_pass` 对照、DNS TXT 8 秒、nginx 与 K8s 清单 | 调用内部的机制（→ 基础概念 / 拦截器与元数据） |
| [可观测性.md](可观测性.md) | 三件套各自落在哪、埋什么属性/指标、基数陷阱、健康检查/反射/channelz | 后端选型与部署（→ 可观测性选型）、具体语言 SDK 的完整配置 |
| [性能与调优.md](性能与调优.md) | 收益从哪来、该怎么量（四个数）、并发模型与闸门、压缩与批量、调优清单 | **任何压测读数（本目录没做过压测）**；其他框架的对比（→ RPC框架选型对比） |
| [实战示例.md](实战示例.md) | 该不该上的判据、工程布局、`.proto` 与生成物审查、上线十条、语言分册索引 | 各机制的原理（→ 前面各篇） |
| [故障排查与调试.md](故障排查与调试.md) | 「症状 → 判据 → 处置」的五层定位、静默失效三连、工具箱与排查顺序 | 各问题背后的机制（逐条指向对应篇目）、后端侧的运维 |
| [gRPC-Web与Connect.md](gRPC-Web与Connect.md) | 浏览器为什么说不了 gRPC、gRPC-Web 的边界、三条接法、Connect、选型判据 | 前端工程化与框架集成；**本篇没有任何实测** |
| [接入gRPC.md](../../../../01-编程语言/java/接入gRPC.md) | **Java 侧接入分册**（另一个目录，见第六节） | —— |
| [不使用protoc的gRPC.md](../../../../01-编程语言/go/网络编程/不使用protoc的gRPC.md) | **Go 侧接入分册**（另一个目录） | —— |

---

## 六、与其它目录的分工

| 篇 | 分工口径 |
|---|---|
| [RPC框架选型对比.md](../RPC框架选型对比.md) | **横向选型**：调用层选 gRPC 还是 Dubbo / REST。本目录不重复选型结论，需要时引回去 |
| [HTTP与gRPC.md](../../../../02-计算机基础/网络/HTTP与gRPC.md) | **HTTP/2 本体**（相对 HTTP/1.1 的五点改进、多路复用与连接池观测）。本目录只讲 gRPC 怎么用它、以及帧级细节 |
| [数据序列化.md](../../../../02-计算机基础/网络/数据序列化.md) | **JSON 与 Protobuf 的横向对比与格式选型**。本目录的 [Protobuf.md](Protobuf.md) 讲的是 **Protobuf 本体**（编码 / 纪律 / 演进） |
| [不使用protoc的gRPC.md](../../../../01-编程语言/go/网络编程/不使用protoc的gRPC.md) | **Go 侧接入分册**：换 codec、手写 `ServiceDesc`、`ForceServerCodec`、连接池设计 |
| [从零实现网关.md](../../../../01-编程语言/go/网络编程/从零实现网关.md) | **HTTP → gRPC 转码**的完整网关实现（本目录只给判据与配置清单） |
| [接入gRPC.md](../../../../01-编程语言/java/接入gRPC.md) | **Java 侧接入分册**（grpc-java + Netty；不用 protoc 的手写 `MethodDescriptor`） |
| [网关选型对比.md](../../网关与代理/网关选型对比.md) | 网关横向选型：谁支持 gRPC 转码与 `grpc_pass` |
| [可观测性选型对比.md](../../可观测性/可观测性选型对比.md) | 可观测性后端的横向选型（本目录只讲 gRPC 侧该埋什么） |
| [Dubbo/README.md](../Dubbo/README.md) | 另一个调用框架，**目前是占位** |

> ⭐ 与 [网络目录导读](../../../../02-计算机基础/网络/README.md) 同一写法：**导读只给顺序、依赖与边界**，
> 知识点描述一律留给 [目录.md](../../../../目录.md)，避免两处同时过期。

---

## 七、实测坐标（每个数字的来历）

本目录 13 篇里的读数**全部来自本机 Docker Desktop 里的 `grpclab` 实测台**（`.workbuddy/tmp/exp/grpclab/`），
不是抄文档。原始输出在 `out/*.txt`（可复跑，两遍 `diff` 逐行相同才算可复现）。

| 项 | 值 |
|---|---|
| Go 侧容器 | `golang:1.26-alpine` → 容器内 `go1.26.8` |
| Go 依赖 | `google.golang.org/grpc v1.84.0`、`golang.org/x/net v0.57.0` |
| Java 侧容器 | `eclipse-temurin:17-jdk` → `java 17.0.20.1` |
| Java 依赖 | `grpc-java 1.60.0` + guava 33.0.0-jre + `failureaccess 1.0.2` |
| nginx | `nginx:1.26.3`（官方 alpine 镜像） |
| 网络 | Docker 自定义桥，容器间按**容器名**寻址；内嵌 DNS `127.0.0.11:53` |
| 服务 | 不用 protoc：JSON codec（`encoding.RegisterCodec`）+ 手写 `grpc.ServiceDesc` 的 echo 服务 |
| 输出清单 | `wire.txt` / `limits.txt` / `hpack.txt` / `modes.txt` / `base.txt` / `reuse.txt` / `probe-*.txt` / `nginx-lab.txt` / `tls.txt` / `lifecycle.txt` / `status.txt` / `java-modes.txt` / `java-base.txt` |

⭐ **三个最容易记错的口径**：

1. **时序类数字有波动，只断言趋势**——一元 `2~4 ms`、服务端流首字节 `16~17 ms` 是同一台上多轮跑出的范围；
   而 `streams=3 / msgsIn=8 / msgsOut=9`、`dialCalls=1 / serverAcceptedConns=1`、`framesSeen=15` 这类**计数是确定性的**，可以直接引用。
2. **Java 侧一元首调 `751~1433 ms` 不是「协议慢」**——那是 JVM 预热 + Netty 建连的一次性成本，
   稳态流式调用（首字节 `44~75 ms`、总 `96~129 ms`）与 Go 的差距在同一量级。
   ⭐ 更硬的证据：**Java 与服务端统计的三个计数完全一致**（`streams=3 msgsIn=8 msgsOut=9 unaryCalls=1`）。
3. **`tls.txt` / `lifecycle.txt` / `status.txt` / `hpack.txt` 是「零耗时」输出**——正文不含任何时间数字，
   错误文案里的临时端口已用 `<port>` 抹平、`EOF` 与 `connection reset by peer` 的摆动也已归一，
   所以它们能**逐行比对**；前两类（时序类）不能。

---

## 八、版本与依赖矩阵

| 组件 | 本仓实测用的版本 | 出现在哪 | 备注 |
|---|---|---|---|
| grpc-go | **v1.84.0** | 全部 Go 侧实验 | 源码行号（`server.go:1284`、`http2_server.go:386-393/882-932`）都按这个版本标 |
| grpc-java | **1.60.0** | Java 对照（`java-modes.txt` / `java-base.txt`） | 另需 guava 33.0.0-jre 与 `failureaccess 1.0.2` |
| golang.org/x/net | **v0.57.0** | HPACK 一节（`http2/hpack`） | grpc-go 的间接依赖，本实验直接引用 |
| google.golang.org/protobuf | **v1.36.11**（grpclab 的间接依赖）/ **v1.36.12**（[Protobuf.md](Protobuf.md) 的独立实验台） | 两处 | 两处是**不同的实验台**，读数不要混用 |
| genproto/googleapis/rpc | pin 到 `v0.0.0-20260706201446-f0a921348800` | 错误详情（`errdetails`） | 间接依赖 |
| buf | **1.73.0** | [Protobuf.md](Protobuf.md) 的 CI 门禁（`buf.yaml` `version: v2`） | 本节列的是那一篇实验台的版本 |
| protoc / protoc-gen-go | ⚠️ **版本未记** | [Protobuf.md](Protobuf.md) | 那一篇没写版本；要用请自行固定并登记 |
| Go 工具链 | go1.26.8（容器内） | 全部 Go 侧实验 | 宿主兜底时用的是 go1.26.5（见 [Protobuf.md](Protobuf.md) 的校验说明） |
| JDK | 17.0.20.1 | Java 对照 | 本目录不用 JDK 8 |
| nginx | 1.26.3 | `nginx-lab.txt` | `grpc_pass` vs `proxy_pass` 对照 |

⚠️ **口径纪律**：上表是**本仓实测过的版本**，不是「推荐版本」。
升级任何一项之后，**带源码行号的结论要重新核**（尤其 [拦截器与元数据.md](拦截器与元数据.md) 与 [连接与生命周期.md](连接与生命周期.md) 引的 `server.go` / `http2_server.go` 行号）。

---

## 九、配套工具清单

| 工具 | 干什么 | 本仓用过吗 |
|---|---|---|
| `protoc` | 由 `.proto` 生成代码 | ✅ 用在 [Protobuf.md](Protobuf.md) 的生成代码一节（**版本未记**） |
| `buf` | `lint` / `breaking` / `generate` 三件套，把 schema 变更变成 CI 门禁 | ✅ [Protobuf.md](Protobuf.md) 八组实测（buf 1.73.0） |
| `grpcurl` | 命令行调 gRPC，配合反射可**不用 `.proto`** | ⚠️ 本仓**没跑过**；只讲「它靠反射工作」的判据（[生态与网关.md](生态与网关.md) 1.3） |
| `grpcui` | 给 gRPC 服务套一个浏览器调试界面（同样依赖反射） | ⚠️ 没跑过 |
| `ghz` | gRPC 压测 / 负载测试 | ⚠️ **没跑过**——[性能与调优.md](性能与调优.md) 只写参数口径，没有任何吞吐读数 |
| Wireshark | 抓包看 HTTP/2 帧 | ⚠️ 没跑过（本机没有 `tcpdump` / `tshark`；本仓的帧表是**自写 TCP 代理**解出来的） |
| 健康探针 `grpc_health_probe` | K8s 侧用 gRPC 健康协议探活 | ⚠️ 没跑过，只给清单（[生态与网关.md](生态与网关.md) 使用节） |
| `channelz` | 看连接与流的运行期计数 | ⚠️ 没跑过，只在 [可观测性.md](可观测性.md) 里点到 |

---

## 十、与外部文档的对应

| 依据 | 用来核什么 |
|---|---|
| [gRPC 官方文档](https://grpc.io/docs/) | 概念、四种模式、状态码、健康检查 / 反射的标准服务名 |
| [gRPC over HTTP/2 规范](https://github.com/grpc/grpc/blob/master/doc/PROTOCOL-HTTP2.md) | `:path` 格式、`content-type`、Length-Prefixed-Message、trailer 约定 |
| [HTTP/2 RFC 9113](https://www.rfc-editor.org/rfc/rfc9113) | 帧格式与标志位、流状态机、流控、错误码 |
| [HPACK RFC 7541](https://www.rfc-editor.org/rfc/rfc7541) | 静态表（附录 A）、动态表大小与条目大小口径（§4.1 的 +32） |
| [gRPC 状态码](https://grpc.io/docs/guides/status-codes/) | 17 个码的语义与可重试性 |
| [google.rpc.Status / errdetails](https://github.com/googleapis/googleapis/tree/master/google/rpc) | 富错误的类型定义（`BadRequest` / `ErrorInfo` / `RetryInfo`） |
| [go-grpc 仓库](https://github.com/grpc/grpc-go) | 源码行号（`server.go` / `internal/transport/http2_server.go` / `keepalive/keepalive.go`） |
| [grpc-java 仓库](https://github.com/grpc/grpc-java) | Java 侧 API（`ClientCalls` / `ServerInterceptors` / `GrpcSslContexts`） |
| [nginx `ngx_http_grpc_module`](https://nginx.org/en/docs/http/ngx_http_grpc_module.html) | `grpc_pass` 与那一组 `grpc_*` 超时旋钮 |
| [Connect（Buf）](https://connectrpc.com/) | Connect 协议的三种模式与浏览器侧能力 |
| [grpc-gateway](https://github.com/grpc-ecosystem/grpc-gateway) | REST/JSON 转码与 OpenAPI 生成 |
| [OpenTelemetry 语义约定](https://opentelemetry.io/docs/specs/semconv/rpc/) | RPC span 属性名；⚠️ 指标名改过一版，接之前核当前版本 |

---

## 十一、已知缺口

| 缺口 | 现状 | 补了吗 |
|---|---|---|
| **TLS / mTLS** | 原本「全目录走 `insecure` 明文」 | ✅ **已补**：[安全与认证.md](安全与认证.md)（十种结局 + `peer` 字段 + 授权，零耗时输出） |
| **连接状态机 / keepalive / 优雅退出** | 原本只有探针里的一小段 | ✅ **已补**：[连接与生命周期.md](连接与生命周期.md)（含裸 HTTP/2 客户端验证 ping 判据） |
| **拦截器与状态码** | 原本散在「生态与实战」里 | ✅ **已补**：[拦截器与元数据.md](拦截器与元数据.md)（含 `errdetails` 往返实测） |
| **帧 / HPACK 的字节级证据** | 原本只到「93 → 15」这个现象 | ✅ **已补**：`hpack.txt`（70 → 7 → 25、逐条首次编码） |
| **性能压测** | 只有 200 次一元的复用计数 | ❌ **仍未做**：[性能与调优.md](性能与调优.md) 明确声明「没有任何压测读数」 |
| **gRPC-Web / `grpc-gateway` / Connect** | 只写了判据 | ❌ **仍未实测**：[gRPC-Web与Connect.md](gRPC-Web与Connect.md) 四项全未跑（浏览器与转码层都没搭） |
| **负载均衡策略实测** | 只验证了「连接被复用」 | ❌ 未做：`round_robin` / `pick_first` 的行为差异没有实测 |
| **健康检查与反射** | 只提到它们在生态里的位置 | ❌ 未做：没有实跑（含 `grpc_health_probe` / `grpcurl`） |
| **服务网格** | 只有选型判据（[生态与网关.md](生态与网关.md) 3.5） | ❌ 未做：没有起过 sidecar |
| **可观测性** | 只有埋点口径 | ❌ **未接任何后端**：[可观测性.md](可观测性.md) 全篇无读数 |
| **Dubbo** | 仍是占位 | ❌ [Dubbo/README.md](../Dubbo/README.md) |

---

## 关联

- [RPC框架选型对比.md](../RPC框架选型对比.md) — 上层的横向选型（先读它决定要不要进本目录）
- [Dubbo/README.md](../Dubbo/README.md) — 另一个调用框架的占位导读
- [基础概念.md](基础概念.md) — 三层关系与一次调用的 wire 全过程
- [HTTP2机制.md](HTTP2机制.md) — 帧 / 流 / HPACK / 流控 / 状态机
- [四种通信模式.md](四种通信模式.md) — 一元 / 服务端流 / 客户端流 / 双向流
- [Protobuf.md](Protobuf.md) — 序列化格式侧的深入（编码 / `reserved` / buf 门禁）
- [连接与生命周期.md](连接与生命周期.md) — 状态机 / keepalive / 优雅退出
- [拦截器与元数据.md](拦截器与元数据.md) — 洋葱序 / deadline / 重试 / metadata / 富错误
- [安全与认证.md](安全与认证.md) — TLS / mTLS / 凭据 / 授权
- [生态与网关.md](生态与网关.md) — resolver / balancer / 网关 / 网格
- [可观测性.md](可观测性.md) — trace / metrics / log 三件套
- [性能与调优.md](性能与调优.md) — 该怎么量、有哪些旋钮
- [实战示例.md](实战示例.md) — 工程布局、上线清单与语言分册索引
- [故障排查与调试.md](故障排查与调试.md) — 症状 → 判据 → 处置
- [gRPC-Web与Connect.md](gRPC-Web与Connect.md) — 浏览器侧的三条路与选型
- [HTTP与gRPC.md](../../../../02-计算机基础/网络/HTTP与gRPC.md) — gRPC 的底座（HTTP/2 本体）
- [数据序列化.md](../../../../02-计算机基础/网络/数据序列化.md) — JSON 与 Protobuf 的横向对比
- [接入gRPC.md](../../../../01-编程语言/java/接入gRPC.md) — Java 侧接入分册
- [不使用protoc的gRPC.md](../../../../01-编程语言/go/网络编程/不使用protoc的gRPC.md) — Go 侧接入分册
- [目录.md](../../../../目录.md) — 全仓知识点索引
- [素材清单.md](../../../../素材清单.md) — 素材来源登记
