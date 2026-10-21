# ActiveMQ 目录导读

> 覆盖 ActiveMQ Classic 的定位与现状、JMS 术语与对象模型、KahaDB 单 store 与高可用形态、Queue / Topic 的消费语义（含 `JMSXGroupID` 亲和与 prefetch）、端到端可靠性（ACK、重投、DLQ）、部署与配置、运维排障、四协议并存的线上格式，以及 Go（STOMP）与 Java（官方客户端 + Spring JMS）两侧的接入——共 **6 篇零件 + 1 篇追问汇编**。
>
> 不覆盖：六款消息队列的横向对比与选型见 [消息队列选型对比.md](../消息队列选型对比.md)；「该拆几个队列、分几组」的**跨产品判据**见 [消息模型与存储代价.md](../消息模型与存储代价.md)；JMS 规范本身（三层约束、两个域、四种确认模式）见 [JMS规范.md](../JMS规范.md)；可被多产品实现的协议族（STOMP / AMQP / MQTT）见 [消息协议族全景.md](../../../../02-计算机基础/网络/消息协议/消息协议族全景.md)。
>
> 内容整理自个人学习笔记。本目录带一组本机容器实测：`apache/activemq-classic:6.1.4` 单机容器 `activemq-lab`（宿主 61616 / 8161），客户端为 Java 17 + `activemq-client 6.1.4`；各篇逐处标注读数。

---

## 一、一句话定位与它凭什么

**ActiveMQ Classic 是 Apache 的 JMS broker**（2004 年起、2007 年毕业为顶级项目），它当年被广泛采用的理由只有一个：**协议覆盖最全**——一个 broker 同时开 OpenWire（JMS 原生）、STOMP、AMQP、MQTT 四种接入协议，Java 侧直接实现 JMS 规范，是 Spring `JmsTemplate` / `@JmsListener` 的默认落地实现。

| 维度 | ActiveMQ Classic 的事实 |
| --- | --- |
| 协议 | OpenWire（61616）/ STOMP（61613）/ AMQP 1.0（5672）/ MQTT（1883）/ WebSocket（61614），Web 控制台 8161 |
| 存储 | 默认 **KahaDB**（文件型 journal + 索引），可换 JDBC / 纯内存 |
| 高可用 | 主从（共享存储 / 租约）与 Network of Brokers 级联，**没有 Raft 类自动选主** |
| 消费模型 | JMS 语义：Queue 竞争消费、Topic 发布订阅（含持久订阅）、selector 服务端过滤 |
| 现状 | ⚠️ Apache 官方后继是**独立重写的 ActiveMQ Artemis**；Classic 社区活跃度已明显下降，新项目的默认答案不再是它 |

![ActiveMQ 高可用：Shared-Store 主从、租约型主从与 Network of Brokers 级联三种形态](images/ActiveMQ主从与级联拓扑.svg)

这张图要读出的核心：**ActiveMQ Classic 的扩展只有「主从」与「级联」两条路**，没有分区副本那套横向扩展。所以「单队列能扛多大」= 「单个 broker 能扛多大」，这是它不适合数据管道的根因。

> ⭐ 一句话记忆：**ActiveMQ 是「JMS 规范的参考实现 + 协议瑞士军刀」，不是吞吐型流平台**。它的价值在兼容与生态，不在性能。
>
> ⚠️ 选型口径：**维护存量、JMS 生态绑定、多协议（尤其 MQTT/STOMP）网关**才选它；数据管道选 Kafka，业务消息选 RocketMQ，云原生多租户选 Pulsar，Go 系轻量通信选 NATS（见 [消息队列选型对比.md](../消息队列选型对比.md)）。

## 二、核心概念速览

| 概念 | 一句话 |
| --- | --- |
| **Destination** | 目的地，分 Queue（竞争消费）与 Topic（每订阅者一份） |
| **Connection / Session** | 连接是重量对象（含 IO 线程），会话是生产与确认的最小单位 |
| **MessageProducer / Consumer** | 由 Session 创建，绑定一个 Destination |
| **DeliveryMode** | `PERSISTENT` 落 KahaDB；`NON_PERSISTENT` 只进内存，重启即丢 |
| **Durable Subscription** | Topic 上「离线期间也留着」的订阅，用客户端 ID + 订阅名标识 |
| **Selector** ⭐ | 服务端按消息属性过滤（SQL92 子集）；**被滤掉的消息仍占队列空间** |
| **AcknowledgeMode** | `AUTO_ACKNOWLEDGE` / `CLIENT_ACKNOWLEDGE` / `DUPS_OK_ACKNOWLEDGE` 三档 |
| **`JMSXGroupID`** ⭐ | 同组消息绑定到同一消费者的唯一手段（亲和），也是 ActiveMQ 保序的方式 |
| **`ActiveMQ.DLQ`** | 重投超过 `maximumRedeliveries`（默认 6）后的死信队列 |
| **KahaDB** | 全局单 store：所有目的地共用一个 journal 目录 |

> ⚠️ **铁律：JMS 是规范，行为由 broker 决定。** 同一个 `receive(5000)`、同一个 `acknowledge()`，在 ActiveMQ / Artemis / IBM MQ 上的超时语义、重投时机、`JMSXDeliveryCount` 是否自动填充都可能不同——跨 broker 迁移时，这些「规范没写死」的地方才是坑。

## 三、阅读路线

本目录的阅读顺序（每篇的知识点描述见 [全仓知识点目录](../../../../目录.md)，此处**不重复描述**）：

| 顺序 | 篇 | 视角 | 什么时候读 |
| --- | --- | --- | --- |
| ① | [核心概念与架构.md](核心概念与架构.md) | ⭐ **主线 / 原理** | JMS 术语对照表、KahaDB 为什么是全局单 store、三档水位与生产者流控、主从 / 级联三种高可用、Classic 与 Artemis 的分界 |
| ② | [消息类型与消费语义.md](消息类型与消费语义.md) | 消费侧语义 | Queue 与 Topic 的分发、持久订阅、selector 在哪一侧过滤、`JMSXGroupID` 亲和（含本机 20/20 与 2/8/1/9 的对照）、prefetch 默认值、延迟投递的四个属性 |
| ③ | [消息可靠性.md](消息可靠性.md) | 可靠性 | PERSISTENT 与 `useAsyncSend` 的「返回不等于落盘」、三种 ACK 模式、`redeliveryPolicy` 与 DLQ（含投递次数 1/2/3 的实测）、为什么只能做到 at-least-once |
| ④ | [部署与配置.md](部署与配置.md) | 落地 | 单机起法与五个连接器、`activemq.xml` 关键项（含 `schedulerSupport` 这个延迟消息的开关）、鉴权、水位与队列上限 |
| ⑤ | [运维与排障.md](运维与排障.md) | 排障 | dstat / browse / Jolokia 三个入口、`QueueSize` 与 `Enqueue-Dequeue` 对不上怎么解释、生产者卡住、整个 broker 变慢、DLQ 膨胀 |
| ⑥ | [ActiveMQ协议.md](ActiveMQ协议.md) | 协议层 | ⭐ **JMS 不是协议、OpenWire 才是字节层**、同一进程四个端口的四种协议、STOMP 帧的五段结构、AMQP 1.0 与 0-9-1 不能混用 |
| ⑦ | [延伸追问.md](延伸追问.md) | 自查 | 把各篇最常被连环追问的问题集中成一页：一句话结论 + 指向详解，附两条答题链 |

语言分册（按 AGENTS.md 约定，**接入代码放在语言目录，本目录只留链接**）：

| 语言 | 篇 | 内容 |
| --- | --- | --- |
| Java | [接入ActiveMQ.md](../../../../01-编程语言/java/工程实践/接入ActiveMQ.md) | 接入手册：`jakarta.jms` 与 `activemq-client` 版本兼容、ConnectionFactory / Connection 单例、原生生产者与消费者、Spring JMS、重投与 DLQ 配置、优雅退出 |
| Go | [接入ActiveMQ.md](../../../../01-编程语言/go/工程实践/接入ActiveMQ.md) | 接入手册：为什么走 STOMP、`go-stomp/stomp/v3` 连接单例、收发与 ack/nack、selector 与延迟属性、Temporary Queue 请求-应答、排障 |

### 依赖关系

| 本篇 | 建议先读 | 为什么 |
| --- | --- | --- |
| 消息类型与消费语义.md | 核心概念与架构.md | Queue / Topic / selector / 亲和都建在 JMS 对象模型上 |
| 消息可靠性.md | 核心概念与架构.md、消息类型与消费语义.md | 不先弄清 ACK 模式与重投，DLQ 只是「多出来一个队列」 |
| 部署与配置.md | — | 可独立读；但 `schedulerSupport` 与延迟消息要配合消费语义篇 |
| 运维与排障.md | 前三篇 | 排障是「水位、积压、重投」三组结论的应用面 |
| [ActiveMQ协议.md](ActiveMQ协议.md) | 核心概念与架构.md | 先懂 JMS 是接口规范，再谈 OpenWire 是字节层 |
| 延伸追问.md | 建议最后读 | 它的作用是暴露缺口，不是第一次学习 |

> ⭐ **两条线**：**原理线**（① 核心概念与架构 → ② 消息类型与消费语义 → ③ 消息可靠性）回答「它怎么存、怎么投、为什么只能 at-least-once」；**落地线**（④ 部署与配置 → ⑤ 运维与排障 → 语言分册）回答「怎么起、怎么用、出事怎么查」。⑥ 解释「换一门语言接入时，为什么字节会变」。

## 四、本轮实测坐标

| 项 | 值 |
| --- | --- |
| 镜像 / 版本 | `apache/activemq-classic:6.1.4`（Jolokia `BrokerVersion` 实测 `6.1.4`） |
| 集群 | 单机容器 `activemq-lab`，宿主 61616（OpenWire）/ 8161（Web 控制台与 Jolokia） |
| 连接器 | 运行时 `TransportConnectors` 实测 5 个：openwire 61616、amqp 5672、stomp 61613、mqtt 1883、ws 61614，均 `maximumConnections=1000`、`maxFrameSize=104857600` |
| Broker 配置 | `schedulerSupport="true"`（为延迟消息手工打开，原始配置没有） |
| 客户端 | Java 17 + `activemq-client 6.1.4`（真编译运行）；Go 侧 `go-stomp/stomp/v3 v3.1.5`（容器网络内真跑 STOMP 61613） |
| 场景队列 | `lab.orders`（收发压测）、`lab.poison`（重投与 DLQ）、`lab.groups` / `lab.nogroups`（亲和对照）、`lab.delayed` |

六条最值得记住的读数：

| 读数 | 值 | 出处 |
| --- | --- | --- |
| `JMSXGroupID` 是真正的亲和 | 40 条 / 4 组 / 2 消费者 → 每消费者正好拿到 2 组共 20 条；去掉 GroupID 后同一业务键被拆成 `2/8`、`1/9` | [消息类型与消费语义.md](消息类型与消费语义.md) 第三节 |
| prefetch 默认值差三个数量级 | queue **1000**、topic **32767**、queueBrowser **500**、durableTopic **100** | [消息类型与消费语义.md](消息类型与消费语义.md) 第四节 |
| 重投到 DLQ 是「第 3 次之后」 | `maximumRedeliveries=2` 时 `JMSXDeliveryCount` 依次 1、2、3，第 4 次取不到消息，DLQ 里读到了它 | [消息可靠性.md](消息可靠性.md) 第四节 |
| 延迟投递必须开 `schedulerSupport` | 未开时 `AMQ_SCHEDULED_DELAY=5000` 只延迟了 **171 ms**；开并重启后 **5113 ms** | [部署与配置.md](部署与配置.md) 第三节 |
| 持久化发送的实际速率 | 200 条 PERSISTENT 消息 `1129 ms`，平均 **5.645 ms/条**（异步发送、单机未调优） | [消息可靠性.md](消息可靠性.md) 第二节 |
| `QueueSize` 与 `Enqueue-Dequeue` 会对不上 | `lab.orders`：`QueueSize=90`，而 `EnqueueCount=DequeueCount=200` —— 90 条是 broker 启动时从 store 载入的存量 | [运维与排障.md](运维与排障.md) 第三节 |

> ⚠️ **未实测项**：主从 / Shared-Store / Network of Brokers 均未搭建（单容器）；STOMP 61613 已由 Go 客户端在容器网络内实测（见 [go/工程实践/接入ActiveMQ.md](../../../../01-编程语言/go/工程实践/接入ActiveMQ.md)），AMQP 5672 与 MQTT 1883 两个连接器**已在运行时确认存在**，但宿主机只映射了 61616 / 8161，**未做协议级互通测试**。

## 五、本目录的边界

| 本目录收 | 不收（在哪） |
| --- | --- |
| ActiveMQ Classic 这一个产品自身的机制与用法 | **多产品横向选型** → [消息队列选型对比.md](../消息队列选型对比.md) |
| JMS 对象模型在产品侧的落地 | **JMS 规范本身**（三层约束、两个域、四种确认）→ [JMS规范.md](../JMS规范.md) |
| 概念、消费语义、可靠性、配置、排障 | **各语言接入代码** → [java/工程实践](../../../../01-编程语言/java/工程实践)、[go/工程实践](../../../../01-编程语言/go/工程实践) 下的 `接入ActiveMQ.md` |
| OpenWire 的位置与「为什么不该单独学」 | **有公开规范的协议族** → [STOMP.md](../../../../02-计算机基础/网络/消息协议/STOMP.md)、[AMQP.md](../../../../02-计算机基础/网络/消息协议/AMQP.md)、[MQTT.md](../../../../02-计算机基础/网络/消息协议/MQTT.md) |
| 主从与级联的「为什么」 | **Raft 等共识协议原理** → [Raft协议.md](../../../../04-架构与系统/分布式/共识协议/Raft协议.md)（Classic 不使用） |
| Queue / Topic / 订阅的**具体切法** | **跨产品判据** → [消息模型与存储代价.md](../消息模型与存储代价.md) |

## 延伸追问

- **只想补一块，最短路径是什么？** → 「消息重复/丢失」读 [消息可靠性.md](消息可靠性.md) 第一、四节；「生产者卡住」直接读 [运维与排障.md](运维与排障.md) 第三节；要写代码跳语言分册。
- **这套实测环境怎么复现？** → [部署与配置.md](部署与配置.md) 第二节给了 `docker run` 参数与 `activemq.xml` 的两处必改项；客户端连 `tcp://127.0.0.1:61616`、`admin/admin`。
- **为什么「消费语义」和「可靠性」要分成两篇？** → 读者不同：语义篇服务「消息该谁处理、怎么过滤」，可靠性篇服务「会不会丢、重投几次」。混在一篇最容易出现的错误，是把「没收到」当成「重投配置错了」。

## 关联

- **上游/总纲**：[消息队列选型对比.md](../消息队列选型对比.md) — 六款 MQ 的横向对比与选型入口
- **上游/总纲**：[消息模型与存储代价.md](../消息模型与存储代价.md) — 队列与订阅粒度的跨产品判据
- **对照/互补**：[README.md](../RabbitMQ/README.md) — 同为「业务消息 broker」，交换机路由与 confirm 语义的对照
- **对照/互补**：[README.md](../Kafka/README.md) — 分区日志路线：为什么数据管道不选 ActiveMQ
- **对照/互补**：[README.md](../RocketMQ/README.md) — 延迟与事务消息的另一种实现（级别表 / 半消息）
- **对照/互补**：[README.md](../Pulsar/README.md) — 存算分离：把 KahaDB 单 store 换成 BookKeeper
- **对照/互补**：[README.md](../NATS/README.md) — Go 系轻量通信的对照选项
- **下游机制**：[ActiveMQ协议.md](ActiveMQ协议.md) — 四种接入协议并存的字节层
