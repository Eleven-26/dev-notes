# Go 接入 Kafka

> 覆盖 Go 侧接入 Kafka 的完整手册：客户端三选一、生产者与消费者（含消费者组与手动提交）、优雅退出，以及一组在容器里对三节点集群**真实跑通**的输出。
>
> 不覆盖：Kafka 的概念、分区与副本、可靠性参数与排障见 [Kafka 目录导读](../../../03-数据与中间件/中间件/消息队列/Kafka/README.md)（零件篇从 [核心概念与架构.md](../../../03-数据与中间件/中间件/消息队列/Kafka/核心概念与架构.md) 起读）。
>
> 内容整理自个人学习笔记。本篇代码在 `golang:1.26-alpine`（Go 1.26.8）中 `gofmt` / `go vet` / `go build` 全部通过，并对本机三节点 KRaft 集群（`apache/kafka:3.9.0`，网络 `kafka-lab-net`）实测跑通，输出见第六节。

---

## 一、客户端三选一

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

> ⚠️ `kafka-go` 的 `Writer` **不暴露 `enable.idempotence`**：要严格的幂等 / 事务语义请换 `sarama` 或 `confluent-kafka-go`；日常业务用「失败重试 + 消费端幂等」即可。

## 二、生产者

```go
package main

import (
	"context"
	"fmt"
	"log"
	"time"

	"github.com/segmentio/kafka-go"
)

func main() {
	w := &kafka.Writer{
		Addr:         kafka.TCP("kafka-lab-1:9092"),
		Topic:        "go-lab",
		Balancer:     &kafka.Hash{},    // 同 key → 同分区（默认是轮询，会打散顺序）
		RequiredAcks: kafka.RequireAll, // 对应 acks=all（默认 RequireNone）
		BatchTimeout: 10 * time.Millisecond,
		Async:        false,            // 同步发送，方便拿到错误
	}
	defer w.Close()

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
	fmt.Printf("PRODUCE ok: %d messages, acks=all, balancer=Hash\n", len(msgs))
}
```

| 写代码时要注意的 | 说明 |
| --- | --- |
| **`Balancer` 默认是轮询** | 不显式设 `&kafka.Hash{}`，同 key 的消息会被打散到不同分区，顺序保证直接失效 |
| **`RequiredAcks` 默认是 `RequireNone`** | 等价 `acks=0`：发出去就算成功，异步线程刷失败也不报错。要可靠性必须显式设为 `RequireAll` |
| **攒批是吞吐的前提** | `BatchTimeout` + 批量 `WriteMessages` 会让 broker 侧收到大批次（实测一个批次 542 条 / 约 16 KiB），代价是单条延迟上升 |
| **`Async: true` 要慎用** | 它把错误交给后台重试，`WriteMessages` 立刻返回 nil，容易误判成功 |

### 2.1 生产者必须单例

`kafka.Writer` 内部维护连接池与后台 flush 协程，**整个进程只应创建一个并复用**，绝不能在请求处理函数里每次 `new`。用 `sync.OnceValue` 包一层最省事（Go 1.21+）：

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

⚠️ 生产者是「有状态的长生命周期资源」，重复创建只会把连接与协程数线性放大——这是「**生产者单例**」在 Kafka 上的落地。**消费者则相反，见 3.1。**

## 三、消费者（消费者组 + 手动提交）

```go
package main

import (
	"context"
	"fmt"
	"log"
	"time"

	"github.com/segmentio/kafka-go"
)

func main() {
	r := kafka.NewReader(kafka.ReaderConfig{
		Brokers:        []string{"kafka-lab-1:9092"},
		GroupID:        "golang-lab-group", // 配了 GroupID 才是「消费组模式」
		Topic:          "go-lab",
		CommitInterval: 0,                 // ⭐ 0 = 关闭自动提交，改为手动
		StartOffset:    kafka.FirstOffset, // 组内无位点时从头消费（等价 earliest）
		MaxWait:        500 * time.Millisecond,
	})
	defer r.Close()

	ctx := context.Background()
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
}
```

> ⭐ **`ReadMessage` 会按 `CommitInterval` 自动提交，`FetchMessage` 不提交**——要「处理成功再提交」就用 `CommitInterval: 0` + `FetchMessage` + `CommitMessages`。
>
> ⚠️ `GroupID` 留空会退化成**独立消费者**：不参与消费组、不自动分配分区，需要自己指定 `Partition`。**「加了实例但没人分到分区」通常就是这一条漏了。**

### 3.1 消费者不要做成单例

与生产者相反，`kafka.Reader` **不应**包成进程级全局单例：它绑定「消费组 + 订阅 Topic」，内部跑独立的 fetch / 心跳循环，位点提交也按实例记录。

- 按「**一个订阅一个 Reader**」创建，与消费循环同生命周期（`go consume(r)` 后由该循环负责 `r.Close()`）；
- 需要多个消费组 / 多个 Topic 时各自建 Reader——共享一个单例会让它们互相抢位点、无法独立启停；
- ⚠️ 提高消费并发靠「分区数 + 多实例分摊」，而不是在一个实例里多开 goroutine（`Reader` 不是并发安全的，别跨 goroutine 共享）。

> 一句话：**生产者单例（省连接），消费者非单例（一个订阅一份独立状态与生命周期）。**

## 四、优雅退出

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

## 五、需要幂等 / 事务时换 sarama

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

## 六、验证与排查（本机实测）

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

排查入口与常见故障对照：

| 症状 | 先查什么 |
| --- | --- |
| 消费组不消费、实例都在但分不到分区 | `--describe --group` 看 `CONSUMER-ID` 是否为空；再确认分区数是否 ≥ 实例数 |
| 报 `NOT_ENOUGH_REPLICAS` | 集群 `Isr` 是否少于 `min.insync.replicas`（见 [消息可靠性.md](../../../03-数据与中间件/中间件/消息队列/Kafka/消息可靠性.md) 第五节） |
| 消息顺序错乱 | `Balancer` 是否设成 `&kafka.Hash{}`；`max.in.flight` 与重试是否放大乱序 |
| 重复消费 | 位点提交时机（先处理再提交）+ 业务侧幂等是否到位 |

## 延伸追问

- **`kafka-go` 和 `sarama` 怎么选？** → 只要基本的生产消费、部署要静态二进制 → `kafka-go`；需要幂等 / 事务、或要对齐 Java 客户端的行为 → `sarama`；极致性能且能接受 cgo → `confluent-kafka-go`。
- **为什么默认 `RequiredAcks` 是 `RequireNone`？** → 库把「最快」当默认，可靠性要显式声明。⚠️ 忘了设就等于 `acks=0`，这是最隐蔽的一处丢消息。
- **不设 `Balancer` 会怎样？** → 默认轮询会把同 key 的消息打散到多个分区，**顺序保证失效**；要「同实体有序」必须用 `&kafka.Hash{}` 或自定义按 key 的均衡器。
- **消费者怎么做到「至少处理一次」？** → `CommitInterval: 0` + `FetchMessage` + 处理成功后 `CommitMessages`；失败不提交，靠消费端幂等承担重复。
- **`Close()` 一定要调吗？** → 一定要。它会提交一次位点并释放连接；直接退出会让本次进度丢失，重启后从旧位点重复消费。

## 关联

- **上游/总纲**：[Kafka 目录导读](../../../03-数据与中间件/中间件/消息队列/Kafka/README.md) — Kafka 的定位、阅读路线与实测坐标
- **对照/互补**：[核心概念与架构.md](../../../03-数据与中间件/中间件/消息队列/Kafka/核心概念与架构.md) — 同 key 同分区背后的分区路由与存储结构
- **对照/互补**：[消息可靠性.md](../../../03-数据与中间件/中间件/消息队列/Kafka/消息可靠性.md) — `acks` / `min.insync.replicas` 与服务端侧的配套
- **对照/互补**：[消费者组与重平衡.md](../../../03-数据与中间件/中间件/消息队列/Kafka/消费者组与重平衡.md) — 消费组语义与静态成员配置
- **对照/互补**：[接入Kafka.md](../../java/接入Kafka.md) — Java 侧同一套语义的写法
- **对照/互补**：[数据访问与连接池.md](数据访问与连接池.md) — 同为「外部资源客户端」的生命周期与超时管理
