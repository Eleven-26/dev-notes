# Go 接入 Kafka

> 覆盖：Go 侧接入 Kafka 的完整手册 —— 客户端选型、初始化、单例边界、初始化顺序、各场景调用、跨进程传播、优雅退出、验证与排障。
>
> 不覆盖：Kafka 的概念、分区与副本、可靠性参数与排障见 [Kafka 目录导读](../../../03-数据与中间件/中间件/消息队列/Kafka/README.md)（零件篇从 [核心概念与架构.md](../../../03-数据与中间件/中间件/消息队列/Kafka/核心概念与架构.md) 起读）；Java 侧写法见 [接入Kafka.md](../../java/工程实践/接入Kafka.md)。
>
> 内容整理自个人学习笔记。本篇代码在 `golang:1.26-alpine`（Go 1.26.8）+ `kafka-go v0.4.51` 下 `gofmt` / `go vet` / `go build` 全部通过，并对本机三节点 KRaft 集群（`apache/kafka:3.9.0`，网络 `kafka-lab-net`）实测跑通，输出见第十节。

---

## 一、客户端选型与依赖

**本节要点**：Go 侧有三个客户端，先用「要不要幂等 / 事务」与「能不能接受 cgo」定选型，再谈别的。

| 客户端 | 依赖 | 幂等 / 事务 | 特点与取舍 |
| --- | --- | --- | --- |
| **`github.com/segmentio/kafka-go`** ⭐ | 纯 Go，**无 cgo** | 幂等支持有限 | API 简洁、静态二进制部署无痛；**日常业务首选**（本篇示例用的就是它） |
| `github.com/IBM/sarama` | 纯 Go | ⭐ 支持幂等与事务 | 功能最全、社区大；⚠️ **原名 `Shopify/sarama`，已迁移到 `github.com/IBM/sarama`**，别再写过时路径 |
| `github.com/confluentinc/confluent-kafka-go` | **依赖 cgo + librdkafka** | ⭐ 支持幂等与事务 | 最贴近 Java 客户端、性能最好；⚠️ 交叉编译要带 C 运行库，镜像变大 |

```bash
go get github.com/segmentio/kafka-go        # 本篇示例
go get github.com/IBM/sarama                # 需要幂等 / 事务时
go get github.com/confluentinc/confluent-kafka-go/v2   # 追求极致性能时
```

⚠️ **常见误装**：

- `kafka-go` 的 `Writer` **不暴露 `enable.idempotence`**：要严格的幂等 / 事务语义请换 `sarama` 或 `confluent-kafka-go`，日常业务用「失败重试 + 消费端幂等」即可；
- 别把 `sarama` 写成 `Shopify/sarama`——模块已迁到 `github.com/IBM/sarama`，旧路径拉下来的是过时版本。

## 二、前提

**本节要点**：Go 客户端不创建任何服务端资源，连上之前集群那侧必须先齐全；缺一样都有对应的报错。

| 依赖 | 要先准备好什么 | 漏了会报什么 |
| --- | --- | --- |
| **Broker** | 至少一个 `kafka-lab-1:9092` 可达 | 连接被拒（`connect: connection refused`）/ 拉元数据超时 |
| **Topic** | 已建，或集群开了 `auto.create.topics.enable` | ⚠️ `write failed: [3] Unknown Topic Or Partition`（见下条） |
| **分区数** | ≥ 想要达到的消费并发度 | 多出的实例分不到分区、空转（`LAG` 不动） |
| **消费组** | 配了 `GroupID` 才是消费组模式，无需预建 | 不配 `GroupID` → 退化成**独立消费者**，不自动分配分区 |
| **端口** | `9092`（PLAINTEXT） | 连接被拒 |
| **副本 / ISR** | 写 `acks=all` 时，ISR ≥ `min.insync.replicas` | `NOT_ENOUGH_REPLICAS`（见 [消息可靠性.md](../../../03-数据与中间件/中间件/消息队列/Kafka/消息可靠性.md) 第五节） |

> ⚠️ **本机实测踩到过**：`go-props` 这个 Topic 没预建时，`kafka-go` 直接报 `write failed: [3] Unknown Topic Or Partition`；而同一集群里 Java 客户端用 `java-props` 时**自动建成了**。差别在元数据请求的时机——跨环境跑示例前，**显式建好 Topic** 最稳妥。

## 三、初始化

**本节要点**：生产者的初始化只有「构造 `Writer`」一步；构造本身**不建立 TCP**，kafka-go 是惰性连接，第一次写才连 Broker。在哪调很关键——放进程启动处建一次，不要放请求处理里。

```go
// 构造本身不连网；第一次 WriteMessages 才建连接
w := &kafka.Writer{
	Addr:         kafka.TCP("kafka-lab-1:9092"),
	Topic:        "go-lab",
	Balancer:     &kafka.Hash{},    // 同 key → 同分区（默认是轮询，会打散顺序）
	RequiredAcks: kafka.RequireAll, // 对应 acks=all（默认 RequireNone）
	BatchTimeout: 10 * time.Millisecond,
	Async:        false,            // 同步发送，方便拿到错误
}
```

**在哪调**：进程启动阶段（`main` 或依赖注入的初始化函数里）建一次，之后全局复用；**不要**在 HTTP handler 里每次 `new kafka.Writer`。

**初始化失败的报错原文**（本机实测，Topic 未建时第一次写）：

```text
write failed: [3] Unknown Topic Or Partition: the request is for a topic or partition that does not exist on this broker
```

—— 注意它**不在构造时报错**，而是在第一次 `WriteMessages` 才暴露；只做「构造 + 启动」的自检不会发现这个坑。

## 四、单例边界 ⭐

**本节要点**：判「要不要单例」只看一件事——**这个对象内部有没有长活的资源（连接池、后台 flush 协程、心跳循环）**。有 → 进程级单例；没有、且绑定一次业务操作的 → 请求级。

| 对象 | 单例？ | 为什么 |
| --- | --- | --- |
| `*kafka.Writer` | ✅ 进程级 | 持有连接池与后台 flush 协程，反复新建 = 连接与协程线性膨胀 |
| `*kafka.Reader` | ❌ 非单例 | 绑定「消费组 + 订阅 Topic」，内部跑独立 fetch / 心跳循环，位点按实例记录 |
| `*http.Client`（若同时有出站调用） | ✅ 进程级 | 内部是连接池，反复新建 = 端口耗尽 |
| `kafka.Message` | ❌ 请求级 | 每条消息独立；跨请求复用会串消息内容 |
| `ctx`（发送 / 消费循环里） | ❌ 请求级 | 随调用传入，别存进结构体字段 |

**写错的后果**：

- 生产者每次请求新建 → 连接数与后台 flush 协程随 QPS 线性膨胀，最终打爆 Broker 连接上限；
- 消费者做成全局单例 → 「一个订阅一份状态」的边界失效：多组 / 多 Topic 会互相抢位点、无法独立启停；而且 **`Reader` 不是并发安全的**，跨 goroutine 共享会直接串数据。

> 一句话：**生产者单例（省连接），消费者非单例（一个订阅一份独立状态与生命周期）。**

## 五、初始化顺序 ⭐

**本节要点**：⭐ 判据是「**谁在初始化时捕获了全局单例**」——凡是有这个特征的下游组件（各类 instrumentation、SDK 全局 hook），都必须排在被捕获者**之后**初始化，否则它拿到的是初始化那一刻的旧值（常常是 noop），且**不报错**。

Kafka 侧两条实序：

1. **消费位点配置只在「组内无位点」时生效**：`StartOffset: kafka.FirstOffset`（等价 `earliest`）只在消费组**没有已提交位点**时起作用；已有位点的组，重启后一律从位点继续，改 `StartOffset` 无效。想重置要换 `GroupID`（或删组）。
2. **MQ 客户端要晚于它依赖的全局设施**：客户端初始化会起心跳 / fetch 循环、并可能捕获全局 logger 等；把全局配置 / 日志 / 链路追踪先初始化，客户端再起，才能被它们接管。

> ⭐ 这个判据的经典例子在链路追踪栈：**GORM 的 OTel 插件在 `Install` 时就捕获了当时的全局 `TracerProvider`**——若 `InitMySQL`（装插件）排在 `InitJaeger`（设全局 provider）之前，插件拿到 noop provider，**SQL span 永远不上报且不报错**。详见 [接入Jaeger.md](../可观测性/接入Jaeger.md)。

## 六、单例与依赖注入的封装

**本节要点**：生产者用 `sync.OnceValue` + 包级变量兜底，是最不容易写错的形态（Go 1.21+）；消费者不套单例，用「构造 → 消费循环 → 关闭」的生命周期方法封装。

生产者（可直接抄）：

```go
import "sync"

// 整个进程只建一个 Writer，复用其内部的连接池；不要在请求处理函数里每次 new。
var orderWriter = sync.OnceValue(func() *kafka.Writer {
	return &kafka.Writer{
		Addr:         kafka.TCP("kafka-lab-1:9092"),
		Topic:        "go-lab",
		Balancer:     &kafka.Hash{},
		RequiredAcks: kafka.RequireAll,
	}
})

// 退出时统一关闭（会提交一次位点、释放连接）
func shutdown() { orderWriter().Close() }
```

消费者（非单例，交给生命周期管理）：

```go
// 每个「消费组 / 订阅」建一个 Reader；随消费循环同生命周期，不共享。
func newOrderReader() *kafka.Reader {
	return kafka.NewReader(kafka.ReaderConfig{
		Brokers:        []string{"kafka-lab-1:9092"},
		GroupID:        "golang-lab-group", // 配了 GroupID 才是「消费组模式」
		Topic:          "go-lab",
		CommitInterval: 0,                  // ⭐ 0 = 关闭自动提交，改为手动
		StartOffset:    kafka.FirstOffset,
		MaxWait:        500 * time.Millisecond,
	})
}
```

> ⚠️ `sync.OnceValue` 需 Go 1.21+；旧版本用 `sync.Once` + 包级变量等价表达。

## 七、实际调用

**本节要点**：同一个单例 Writer，按场景换 `Balancer` / `RequiredAcks` / 批量即可覆盖全部发送形态；消费侧按「处理成功再提交」写循环；要幂等与事务换 `sarama`。

### 7.1 生产者：批量发送（吞吐的主路径）

```go
ctx := context.Background()
msgs := make([]kafka.Message, 0, 6)
for i := 1; i <= 3; i++ {
	msgs = append(msgs,
		kafka.Message{Key: []byte("ORDER_1001"), Value: []byte(fmt.Sprintf(`{"event":"created","seq":%d}`, i))},
		kafka.Message{Key: []byte("ORDER_1002"), Value: []byte(fmt.Sprintf(`{"event":"created","seq":%d}`, i))},
	)
}
if err := w.WriteMessages(ctx, msgs...); err != nil {
	log.Fatalf("write failed: %v", err) // acks=all 下失败即真失败，应由上层重试
}
```

| 写代码时要注意的 | 说明 |
| --- | --- |
| **`Balancer` 默认是轮询** | 不显式设 `&kafka.Hash{}`，同 key 的消息会被打散到不同分区，顺序保证直接失效 |
| **`RequiredAcks` 默认是 `RequireNone`** | 等价 `acks=0`：发出去就算成功，异步线程刷失败也不报错。要可靠性必须显式设为 `RequireAll` |
| **攒批是吞吐的前提** | `BatchTimeout` + 批量 `WriteMessages` 会让 broker 侧收到大批次（实测一个批次 542 条 / 约 16 KiB），代价是单条延迟上升 |
| **`Async: true` 要慎用** | 它把错误交给后台重试，`WriteMessages` 立刻返回 nil，容易误判成功 |

### 7.2 消费者：处理成功再提交

```go
for {
	m, err := r.FetchMessage(ctx) // ⭐ Fetch 不提交，Read 会自动提交
	if err != nil {
		log.Printf("fetch stopped: %v", err)
		return
	}
	// 1) 先处理业务（必须幂等：重平衡/失败重试都会导致重复）
	fmt.Printf("CONSUME partition=%d offset=%d key=%s value=%s\n",
		m.Partition, m.Offset, m.Key, m.Value)
	// 2) 处理成功后再提交位点；失败就 continue，重启后重复消费靠幂等兜底
	if err := r.CommitMessages(ctx, m); err != nil {
		log.Printf("commit failed: %v", err)
	}
}
```

> ⭐ **`ReadMessage` 会按 `CommitInterval` 自动提交，`FetchMessage` 不提交**——要「处理成功再提交」就用 `CommitInterval: 0` + `FetchMessage` + `CommitMessages`。
>
> ⚠️ `GroupID` 留空会退化成**独立消费者**：不参与消费组、不自动分配分区，需要自己指定 `Partition`。**「加了实例但没人分到分区」通常就是这一条漏了。**

### 7.3 需要幂等 / 事务时换 sarama

`kafka-go` 覆盖不了幂等与事务，这两项要换 `sarama`：

```go
cfg := sarama.NewConfig()
cfg.Version = sarama.V3_6_0_0
cfg.Producer.RequiredAcks = sarama.WaitForAll          // acks=all
cfg.Producer.Idempotent = true                         // 幂等生产者
cfg.Net.MaxOpenRequests = 1                            // 幂等要求单连接未确认请求数为 1
cfg.Producer.Return.Successes = true
cfg.Consumer.Offsets.AutoCommit.Enable = false         // 手动提交
cfg.Consumer.Group.Rebalance.GroupStrategies = []sarama.BalanceStrategy{sarama.NewBalanceStrategyCooperativeSticky()}
```

> ⚠️ 开了 `Idempotent` 之后，`Net.MaxOpenRequests` 必须为 1，否则 `sarama` 会在启动时报错——这是它与 Java 客户端（`enable.idempotence` 后允许 `max.in.flight>1`）最容易踩的差异。

### 7.4 定时任务

定时任务里发消息复用同一个 Writer 单例即可。⚠️ Kafka **没有延迟消息**：要「稍后投递」得在业务侧存表 + 定时扫描重投，或另接调度组件（`kafka-go` 与 `sarama` 都不提供 delay queue）。

## 八、跨进程与跨协程传播

**本节要点**：跨进程携带业务上下文，用 **record headers** 做 Inject / Extract；跨协程携带用 `ctx` 传参，别存结构体字段。

- **Inject（生产端）**：把 trace_id / 业务键写进 `kafka.Message.Headers`；
- **Extract（消费端）**：从 `m.Headers` 取回，接到本进程的链路 / 日志上下文里。

```go
// 生产端：把链路与业务头写进消息头（不是消息体）
msg := kafka.Message{
	Key:   []byte("ORDER_GO_9001"),
	Value: []byte("go-props-1"),
	Headers: []kafka.Header{
		{Key: "x-biz-id", Value: []byte("ORDER_GO_9001")},
		{Key: "x-trace-id", Value: []byte("trace-go-abc123")},
	},
}
```

```go
// 消费端：Extract 出来
hdr := map[string]string{}
for _, h := range m.Headers {
	hdr[h.Key] = string(h.Value)
}
fmt.Printf("GO-PROPS-CONSUME partition=%d offset=%d biz=%s trace=%s body=%s\n",
	m.Partition, m.Offset, hdr["x-biz-id"], hdr["x-trace-id"], m.Value)
```

本机实测（属性完整往返）：

```text
GO-PROPS-PRODUCE ok topic=go-props
GO-PROPS-CONSUME partition=0 offset=0 biz=ORDER_GO_9001 trace=trace-go-abc123 body=go-props-1
GO-PROPS DONE
```

**ctx 传参铁律**：

- 生产端 `WriteMessages(ctx, ...)` 的 `ctx` 控制**发送超时**，请求进来就用请求的 ctx；
- 消费循环的 `ctx` 由本进程持有（`FetchMessage(ctx)`），**别塞进任何进程级结构体**；
- ⚠️ **别把请求级数据存进单例 Writer 的字段**（比如给 Writer 挂一个「当前 trace_id」）——单例被多个协程共享，立刻串数据。上下文要**随消息走**，不能随对象走。

## 九、优雅退出

**本节要点**：退出顺序 = 反初始化顺序：**先停止拉取 → 处理完在途消息 → 最后 `Close()`**；漏掉 `Close` 会让本次进度丢失。

```go
sig := make(chan os.Signal, 1)
signal.Notify(sig, syscall.SIGTERM, syscall.SIGINT)
go func() {
	<-sig
	// 1) 停止拉取 → 2) 处理完在途消息 → 3) 关闭（Close 会提交一次位点）
	r.Close()
}()
```

| 顺序 | 为什么 |
| --- | --- |
| 先停止拉取 | 避免退出过程中还在把新消息取进内存 |
| 处理完在途消息 | 否则「已 fetch 未提交」的那批会在重启后重复消费（有幂等兜底才安全） |
| 最后 `Close()` | 它会提交一次位点并关闭连接；**不调用就退出**会让本次进度丢失 |

**漏了会丢什么**：`Writer` 不 `Close` → 缓冲中未发出的批可能丢、后台协程泄漏；`Reader` 不 `Close` → 本次位点不提交，重启后重复消费。Go 与 Java 不同：`main` 返回进程即退出，把「优雅退出」接到信号处理里、走完就返回即可。

## 十、验证与排查

**本节要点**：先确认「接入真的生效」（看格式检查、成功码与位点），再用故障速查表定位。

### 10.1 先确认生效

环境：`golang:1.26-alpine`（Go 1.26.8）+ `kafka-go v0.4.51`，容器接入 `kafka-lab-net`，目标集群为三节点 KRaft（`apache/kafka:3.9.0`）。

```text
go version go1.26.8 linux/amd64
gofmt -l .        → 无输出（格式干净）
go vet ./...      → 通过
go build          → 通过
PRODUCE ok: 6 messages, acks=all, balancer=Hash
CONSUME partition=2 offset=0 key=ORDER_1001 value={"event":"created","seq":1}
CONSUME partition=2 offset=1 key=ORDER_1001 value={"event":"created","seq":2}
CONSUME partition=2 offset=2 key=ORDER_1001 value={"event":"created","seq":3}
CONSUME partition=0 offset=0 key=ORDER_1002 value={"event":"created","seq":1}
CONSUME partition=0 offset=1 key=ORDER_1002 value={"event":"created","seq":2}
CONSUME partition=0 offset=2 key=ORDER_1002 value={"event":"created","seq":3}
CONSUME done: 6 messages, group=golang-lab-group, commit=manual
KEY-MAP key=ORDER_1001 -> partition=2
KEY-MAP key=ORDER_1002 -> partition=0

# kafka-consumer-groups.sh --describe --group golang-lab-group
GROUP              TOPIC   PARTITION  CURRENT-OFFSET  LOG-END-OFFSET  LAG
golang-lab-group   go-lab  0          3               3               0
golang-lab-group   go-lab  2          3               3               0
```

**这段输出一次验证了三件事**：

| 结论 | 证据 |
| --- | --- |
| ⭐ **同 key 落同一分区** | `ORDER_1001` 的 3 条全在 `partition=2`，`ORDER_1002` 的 3 条全在 `partition=0`，没有一条散落 |
| ⭐ **分区内按写入顺序排列** | 两个分区里 `offset` 都是 `0,1,2` 且 `seq` 恰好是 `1,2,3` |
| ⭐ **手动提交生效** | 消费组位点正好停在各分区末尾（`CURRENT-OFFSET = 3`），`LAG = 0`，且组内已无活跃成员 |

### 10.2 常见故障速查

| 症状 | 先查什么 |
| --- | --- |
| `write failed: [3] Unknown Topic Or Partition` | Topic 没建且未开自动创建；先显式建 Topic（第二节） |
| 消费组不消费、实例都在但分不到分区 | `--describe --group` 看 `CONSUMER-ID` 是否为空；再确认分区数是否 ≥ 实例数 |
| 报 `NOT_ENOUGH_REPLICAS` | 集群 `Isr` 是否少于 `min.insync.replicas`（见 [消息可靠性.md](../../../03-数据与中间件/中间件/消息队列/Kafka/消息可靠性.md) 第五节） |
| 消息顺序错乱 | `Balancer` 是否设成 `&kafka.Hash{}`；`max.in.flight` 与重试是否放大乱序 |
| 重复消费 | 位点提交时机（先处理再提交）+ 业务侧幂等是否到位 |
| 加了实例但消费不动 | `GroupID` 是否漏配（退化成独立消费者） |

| 排查项 | 命令 |
| --- | --- |
| 消费堆量 / 位点 | `kafka-consumer-groups.sh --describe --group golang-lab-group` |
| 各分区位点 | `kafka-topics.sh --describe --topic go-lab` |

## 十一、延伸追问

- **`kafka-go` 和 `sarama` 怎么选？** → 只要基本的生产消费、部署要静态二进制 → `kafka-go`；需要幂等 / 事务、或要对齐 Java 客户端的行为 → `sarama`；极致性能且能接受 cgo → `confluent-kafka-go`。详见第一节。
- **为什么默认 `RequiredAcks` 是 `RequireNone`？** → 库把「最快」当默认，可靠性要显式声明。⚠️ 忘了设就等于 `acks=0`，这是最隐蔽的一处丢消息。详见 7.1。
- **不设 `Balancer` 会怎样？** → 默认轮询会把同 key 的消息打散到多个分区，**顺序保证失效**；要「同实体有序」必须用 `&kafka.Hash{}` 或自定义按 key 的均衡器。详见 7.1。
- **消费者怎么做到「至少处理一次」？** → `CommitInterval: 0` + `FetchMessage` + 处理成功后 `CommitMessages`；失败不提交，靠消费端幂等承担重复。详见 7.2。
- **`Close()` 一定要调吗？** → 一定要。它会提交一次位点并释放连接；直接退出会让本次进度丢失，重启后从旧位点重复消费。详见第九节。

## 十二、校验口径

> ✅ 本篇代码的校验方式与范围（`golang:1.26-alpine`，Go 1.26.8）：

- **真编译 + 运行通过**：第一、二节依赖安装与 Topic 准备、第三节 `Writer` 构造、第六节 `sync.OnceValue` 生产者封装与 `newOrderReader`、7.1 批量发送、7.2 手动提交消费、第八节 `Headers` 的 Inject / Extract。
- **只编译、未运行**：第六节的封装（`sync.OnceValue` / `newOrderReader`）与 7.1、7.2 的可运行 `main` 同处一个包，随 `go build ./...` 编译通过；运行时走的是 `main` 里的直接构造。
- **未独立编译**：7.3 `sarama` 的幂等 / 事务配置（换库即换依赖，本机未装 `sarama`）；7.4 定时任务只有说明、没有代码。
- **实测环境**：`apache/kafka:3.9.0`，三节点 KRaft 集群（网络 `kafka-lab-net`），容器间用 `kafka-lab-1:9092` 通信。
- **未实测**：`confluent-kafka-go`（需 cgo + librdkafka）；SASL / TLS；跨机房集群。

| 依赖 | 实际版本 |
| --- | --- |
| `github.com/segmentio/kafka-go` | v0.4.51 |
| `github.com/IBM/sarama` | 未编译（见上） |
| `github.com/confluentinc/confluent-kafka-go/v2` | 未编译（见上） |

## 关联

- **上游/总纲**：[Kafka 目录导读](../../../03-数据与中间件/中间件/消息队列/Kafka/README.md) — Kafka 的定位、阅读路线与实测坐标
- **对照/互补**：[接入Kafka.md](../../java/工程实践/接入Kafka.md) — Java 侧同一套语义的写法与差异
- **对照/互补**：[接入RocketMQ.md](接入RocketMQ.md) — Go 接 RocketMQ 的对照手册
- **下游机制**：[核心概念与架构.md](../../../03-数据与中间件/中间件/消息队列/Kafka/核心概念与架构.md) — 同 key 同分区背后的分区路由与存储结构
- **下游机制**：[消息可靠性.md](../../../03-数据与中间件/中间件/消息队列/Kafka/消息可靠性.md) — `acks` / `min.insync.replicas` 与服务端侧的配套
- **下游机制**：[消费者组与重平衡.md](../../../03-数据与中间件/中间件/消息队列/Kafka/消费者组与重平衡.md) — 消费组语义与静态成员配置
- **运维/配置**：[数据访问与连接池.md](数据访问与连接池.md) — 同为「外部资源客户端」的生命周期与超时管理
