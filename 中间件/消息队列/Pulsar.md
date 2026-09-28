# Pulsar 消息与流平台

> 覆盖 Pulsar 的定位与核心概念、存算分离（Broker + BookKeeper）的架构原理、四种订阅类型、端到端可靠性与回溯语义，以及 Go / Java 双端完整示例、部署配置与运维排障要点。
>
> 内容整理自个人学习笔记。五款消息队列的横向对比与选型见 [消息队列选型.md](消息队列选型.md)；同类技术路线的对照篇见 [Kafka.md](Kafka.md)、[RocketMQ.md](RocketMQ.md)、[Nats.md](Nats.md)。

---

## 一、一句话定位与它凭什么

**Apache Pulsar 是云原生的消息与流一体化平台**（Yahoo 开源、贡献给 Apache），它相对 Kafka / RocketMQ 最本质的差别只有一个：**计算与存储分离**——Broker 只负责协议、路由与订阅管理，**消息数据全部落在 Apache BookKeeper**，Broker 自己不留数据。

由此带来四个别家做不到或做不好的能力：

| 能力 | 依赖的架构前提 | 对比 Kafka |
| --- | --- | --- |
| ⭐ **扩容 / 故障转移不搬数据** | 数据在 BookKeeper，任何 Broker 都能读到任意 ledger | Kafka 加分区要做副本重分配，Broker 挂了靠副本重建 |
| ⭐ **原生多租户**（tenant / namespace / bundle 三级） | 命名空间承载策略（保留、TTL、配额、复制、分层存储） | Kafka 只有前缀约定 + ACL，没有租户层 |
| ⭐ **一份数据被多个订阅独立读**（游标各自推进，扇出不复制） | 读位置（cursor）由订阅持有，与存储无关 | Kafka 也是共享日志，但保留期是分区固定的，慢消费者只能等数据被删 |
| **分层存储（Tiered Storage）成熟** | BookKeeper 之上再挂 S3/GCS/MinIO 归档 | Kafka Tiered Storage 落地更晚 |

核心卖点之外，还有三项「业务消息」常用能力是 Kafka 没有的：**任意时刻的延迟投递**（`deliverAt`）、**原生死信/重试队列**、**按生产者序号的消息去重（幂等）**。

> ⭐ 一句话记忆：**Kafka 是「数据跟着分区走」，Pulsar 是「数据跟着 BookKeeper 走，Broker 只是随时可换的临时工」。**
> ⚠️ 代价也很直白：**组件最多**（Broker + BookKeeper + 元数据存储）、**写路径多一跳网络**（BookKeeper quorum 确认）、**流处理生态远小于 Kafka**。选型结论见 [消息队列选型.md](消息队列选型.md)。

---

## 二、核心概念 ⭐

| 概念 | 说明 | 关键点 |
| --- | --- | --- |
| **Broker** | 无状态服务节点：收发协议、订阅分发、负载均衡 | 挂掉后 topic 所有权被别的 Broker 接管，**不涉及数据迁移** |
| **Bookie（BookKeeper 节点）** | 存储节点，真正落盘消息 | 一组 bookie 构成 ensemble，写入按 quorum 确认 |
| **Ledger / Entry** | BookKeeper 的存储单位：Ledger 是只追加日志，Entry 是一条记录 | 一个 topic 的某段数据由若干 Ledger 组成 |
| **MessageId** | 消息定位符 = `(ledgerId, entryId, partitionIdx)` | ⚠️ **不是单调递增的数字**，不能加减，只能整体保存/传入 `seek` |
| **Tenant（租户）** | 最顶层隔离单位，`persistent://tenant/namespace/topic` | 跨租户隔离配额、复制、权限 |
| **Namespace（命名空间）** | ⭐ **策略容器**：保留、TTL、背压、复制、去重、分层存储全在这里配 | 相当于 Kafka「Topic 配置」的上移，一次配置管一批 topic |
| **Bundle（负载均衡包）** | 一个命名空间下的 topic 哈希区间，是**所有权与负载均衡的最小单位** | topic 太多会让单个 bundle 过载 → 触发 `split-bundle` / `unload` |
| **Topic** | `persistent://`（写 BookKeeper）或 `non-persistent://`（只在 Broker 内存，不持久化） | 可再分 Partition（分区 topic），非必需 |
| **Subscription（订阅）** | ⭐ 消费进度持有者，**独立于 topic 存在**，Broker 侧保存游标 | Kafka 消费组的位置；一个 topic 可有多个订阅，各自独立回溯 |
| **Consumer** | 订阅的接入实例，同一订阅可挂多个 Consumer | 行为由**订阅类型**决定（第四节） |
| **Reader** | 不建订阅、自管位置的读取模式 | 适合重放/对账，不影响任何订阅进度 |
| **Proxy** | 可选组件：客户端统一入口、多机房转发、协议终结 | 配合 K8s Ingress / LB 时基本必装 |

### 铁律：⭐MessageId 不是 Kafka 的 offset

| 事项 | 说明 |
| --- | --- |
| 结构 | `(ledgerId, entryId, partitionIndex)`，字符串形态是编码后的三段值，**不是**「分区内第 N 条」 |
| 不能做算术 | 没有「offset + 1」「lag = 末尾 − 当前」这种直白算法；积压量看 `topics stats` 的 `backlogSize` / `pulsar-admin` 输出 |
| 回溯入口 | ① `seek(MessageId)`；② `seekByTime(Instant)` **按时间戳回溯**（Kafka 靠 timeindex 也能做，Pulsar 是默认能力）；③ 用 Reader 自定起点 |
| 幂等键 | ⚠️ 别拿 MessageId 当业务幂等键，要用**业务唯一键**；MessageId 只能证明「同一条消息重复投递了」 |

> 💡 一句话概括：「Pulsar 没有 offset 概念，消费进度是订阅游标记录的 MessageId，回溯按 MessageId 或时间戳做。」

---

## 三、整体架构：存算分离换来了什么

### 3.1 写路径

```text
Producer → Broker（校验/多副本策略/批次）→ BookKeeper ensemble
         E 个 bookie 并行写、W 个写成功、Q 个 ack 满足即返回 Producer 成功
```

默认 `bookkeeperEnsemble=3`、`bookkeeperWriteQuorum=2`、`bookkeeperAckQuorum=2`：**写成功即数据已在 2 个 bookie 上落盘**，Broker 崩了也不丢。

> ⭐ 这是 Pulsar 与 Kafka 可靠性叙事上最容易被忽略的差异：Kafka 的 `acks=all` 只保证 ISR 副本收到，**不保证落盘**（还靠 OS 刷盘）；Pulsar 的确认来自 BookKeeper 的 quorum 写，语义更接近「已持久化」。
> ⚠️ 代价：写路径多一次跨节点确认，**单条延迟与吞吐都不如 Kafka 的 PageCache 顺序写**，这就是"Pulsar 吞吐低于 Kafka 一个量级"说法的真实来源。

### 3.2 读路径与「一份数据多路消费」

Broker 收到订阅的请求后从 BookKeeper 拉 Entry，按订阅类型分发；**每个订阅有自己的游标（mark-delete 位置）**，互不影响。

| 场景 | Pulsar 的表现 | 说明 |
| --- | --- | --- |
| 同一 topic 被 5 个业务组消费 | 只有一份 Ledger，5 个游标各自推进 | 扇出不复制数据 |
| 某个订阅消费很慢 | 它把「可删位置」拖住，其余订阅照常读 | ⚠️ 会顶高磁盘占用，需要 `backlogQuota` 限制 |
| 想重放 | 游标 `seek` 回去即可 | 前提是数据还在保留范围内（见 6.4） |

### 3.3 为什么扩容 / Broker 故障「不用搬数据」

| 步骤 | 发生什么 |
| --- | --- |
| Broker 宕机 | 它持有的 bundle 所有权（ZooKeeper / pulsar-metadata 上的租约）超时释放 |
| 其他 Broker | 抢到 bundle 所有权，直接从 BookKeeper 读同一段 Ledger 继续服务 |
| 加 Broker | 负载均衡器把部分 bundle 迁过去，**没有副本重分配、没有数据拷贝** |

> 💡 这就是 Pulsar 宣传「弹性扩缩容」的技术底座：**迁的是所有权，不是数据**。Kafka 的 `kafka-reassign-partitions` 那套搬数据流程在 Pulsar 里不存在。

### 3.4 ⚠️ 「组件多」这笔账要算清

| 组件 | 作用 | 能不能省 |
| --- | --- | --- |
| Broker | 计算层 | 不能 |
| Bookie | 存储层 | 不能（这是 Pulsar 的立身之本） |
| ZooKeeper / 元数据存储 | 集群元数据、命名空间策略、ledger 元数据、bundle 所有权 | 社区长期在推进「减 ZK 依赖」（`pulsar-metadata` 支持 Raft + RocksDB、元数据地址与配置存储地址分离），但**生产部署里 ZK 仍是常见组件** |
| Proxy | 客户端入口 / 跨机房 | 单机自建可省，K8s 与多机房一般要 |
| Functions Worker / Connector | 流式计算与数据集成 | 按需 |

> ⚠️ 选型时「运维复杂度高」不是随口一说：**Broker、Bookie、ZK 三类组件的参数与故障域互相牵连**（bookie 磁盘坏 → ledger 可读性下降 → Broker 侧消费报错）。团队没有 JVM 与分布式存储运维经验时，这条权重应该放高。

---

## 四、订阅模型：四种订阅类型怎么选 ⭐

| 订阅类型 | 同一订阅允许的 Consumer 数 | 消息分发 | 顺序保证 | 故障切换 | 典型场景 |
| --- | --- | --- | --- | --- | --- |
| **Exclusive** | 只能 1 个（其余连接会报「已占用」） | 全给这个消费者 | ⭐ topic/分区内严格有序 | 需要外部改配置或重启才能换人 | 单实例顺序消费、 keyed 单线程处理 |
| **Shared** | 多个 | ⭐ **轮询**派发给任意一个空闲消费者 | ❌ 整体无序（同 key 也不保证） | 任一消费者挂掉，未 ack 的消息重投给别人 | 水平扩容的主力，吞吐优先 |
| **Key_Shared** | 多个 | 按 `key`/`orderingKey` 哈希到固定消费者 | ⭐ **同 key 有序**，不同 key 并行 | 消费者变动时只有相关 key 的映射会重排 | 既要并行又要「同一实体有序」（订单状态机） |
| **Failover** | 多个（有优先级） | 只给最高优先级的活跃消费者 | 有序（等价于「带热备的 Exclusive」） | ⭐ 高优先级挂掉自动切到次优先级 | 需要有序 + 高可用，如单实例主备 |

> ⭐ 与 Kafka 的对应关系：Exclusive ≈ 「1 分区 1 消费者」；Shared ≈ Kafka 消费组的默认行为（但 Kafka 是**按分区**分配，Pulsar Shared 是**按消息**轮询）；Key_Shared ≈ Kafka 按 key 哈希路由 + 消费者与分区一一对应；Failover ≈ Kafka 没有的原生主备。
> ⚠️ **Shared 会破坏顺序**：这是从 Kafka 迁过来最容易踩的认知差——Kafka 一个分区只会被组内一个消费者拿，Pulsar 的 Shared 会把同一分区的消息轮询发出去。要有序请用 Key_Shared（并保证生产端设置 key）或 Exclusive。

### 4.1 起始位置

| 客户端 | 怎么写 | 注意 |
| --- | --- | --- |
| Java | `.subscriptionInitialPosition(SubscriptionInitialPosition.Earliest / Latest)` | ⭐ **只对「新建订阅」生效**，订阅已有游标时按游标继续 |
| Go | `ConsumerOptions` 里没有该字段，新订阅默认从 Latest 开始；要补历史就 `consumer.Seek(pulsar.EarliestMessageID())`（或 `SeekByTime`） | 版本差异要记牢：这是 Go 客户端功能滞后的典型例子 |
| 通用 | `StartMessageIDInclusive` 决定起点那条消息本身算不算 | 默认排他，容易「少了第一条」 |

### 4.2 多 Topic 订阅与通配符

| 方式 | Java | Go |
| --- | --- | --- |
| 显式多 topic | `.topics(List.of("a","b"))` | `ConsumerOptions.Topics: []string{...}` |
| 正则匹配 | `.pattern("persistent://shop/default/event-.*")` | `TopicsPattern: "persistent://shop/default/event-.*"` |
| 匹配范围 | `RegexSubscriptionMode PersistentOnly / AllTopics` | 同义配置 |

> 💡 通配符订阅是 Pulsar 的常用玩法：**一个消费者吃下整个命名空间的事件流**（埋点、审计）。Kafka 侧要靠 `topicsPattern`（较新版本才有），RocketMQ 只能用 tag 过滤。

---

## 五、消息可靠性 ⭐

「不丢」同样要三处齐配，但每处的抓手和 Kafka 不一样。

### 5.1 生产端

| 手段 | 配置 / API | 语义 |
| --- | --- | --- |
| 同步发送 | `producer.send(msg)` / Go `Producer.Send(ctx, msg)` | 返回 MessageId 才算 BookKeeper quorum 已确认；失败抛异常，交给调用方重试 |
| 发送超时 | `sendTimeout(30, SECONDS)` / `ProducerOptions.SendTimeout` | ⚠️ 不配则可能长期挂住；超时即报错，不要吞异常 |
| 在途与背压 | `maxPendingMessages(1000)` + `blockIfQueueFull(true)`；客户端总缓冲 `memoryLimit` | 队列满时**阻塞**发送线程形成背压，而不是抛「队列满」把压力变成异常 |
| 异步回调 | `sendAsync().whenComplete(...)` / `SendAsync(..., callback)` | ⚠️ 回调里必须判异常；进程退出前 `flush()` |
| 消息去重（幂等） | 命名空间策略 `brokerDeduplicationEnabled=true`，配合生产者 `SequenceID` | ⭐ Pulsar 原生按「生产者 + 序号」去重，重试导致的重复写可由 Broker 挡掉；Kafka 需 `enable.idempotence` |

### 5.2 存储端

| 项 | 抓手 | 建议 |
| --- | --- | --- |
| 副本数 | `bookkeeperEnsemble / WriteQuorum / AckQuorum`（默认 3/2/2） | 生产至少 3 个 bookie；E=3/W=2/Q=2 是延迟与可靠的常用折中 |
| 落盘 | BookKeeper 写成功即视为 durable（bookie 侧另有 journal + 显式 fsync 配置） | ⚠️ 别照搬 Kafka 的「PageCache + OS 刷盘」心智模型 |
| 保留 | 命名空间 `retentionSizeInMB` / `retentionTimeInMinutes`（0 = 所有订阅消费完即可删，-1 = 不主动删） | ⭐ 想重放历史必须**显式打开 retention**，否则回溯无数据可回 |
| TTL | 命名空间 `messageTTLInSeconds`（0 = 不过期） | 未 ack 消息超过 TTL 会被丢弃并进死信（若配了） |

### 5.3 消费端

| 手段 | 配置 | 语义 |
| --- | --- | --- |
| 先处理后 ack | `acknowledge(msg)` / `consumer.Ack(msg)` 放在业务成功之后 | 与 Kafka 一致的铁律 |
| ack 超时重投 | `ackTimeout(60, SECONDS)` | ⚠️ 处理慢于该值 → 消息被重投给别的消费者，**表现为「没崩却重复消费」** |
| 显式否定应答 | `negativeAcknowledge(msg)` / Go `consumer.Nack(msg)` | 按 `negativeAckRedeliveryDelay` 延迟重投，不占用队列 |
| 单条自定义延迟重投 | Java `negativeAckRedeliveryDelay` / Go `consumer.ReconsumeLater(msg, delay)` | 退避重试（比 MQ 一律固定延迟灵活） |
| 死信 + 重试队列 | `DeadLetterPolicy.builder().maxRedeliverCount(3).deadLetterTopic(...).retryLetterTopic(...)`；Go `ConsumerOptions.DLQ` | ⭐ 仅 **Shared / Key_Shared** 可用；订阅名不带 `-DLQ`/`-RETRY` 后缀时要显式给全名 |
| ack 可追踪 | Go `AckWithResponse: true` | 让 Broker 回 ack 结果，能发现「以为提交了其实失败」 |

### 5.4 结论表：不丢 / 不重 / 恰好一次

| 目标 | 生产端 | 存储端 | 消费端 |
| --- | --- | --- | --- |
| **不丢（At Least Once）** | 同步发送 + `sendTimeout` + 失败重试 | E/W/Q ≥ 3/2/2 + 打开 retention | 先处理后 ack，`ackTimeout` 大于业务 P99 |
| **不重（幂等）** | 开命名空间去重 + 稳定 `SequenceID` | —— | ⚠️ 仍需业务幂等：唯一键去重表 / 状态机 CAS |
| **恰好一次** | 事务 API（较新、默认关闭） | 事务协调器 + ledger | 只在同集群流内成立，跨系统仍靠幂等 |

> ⭐ 现实结论与 Kafka 相同：**中间件只能做到 At Least Once，「不重」靠消费端幂等**。幂等的四种做法见 [消息队列选型.md](消息队列选型.md)。

---

## 六、Pulsar 的差异化能力清单

### 6.1 延迟投递（任意时刻）

```java
producer.newMessage().key("ORDER_1003").value(json)
        .deliverAfter(30, TimeUnit.MINUTES)     // 或 deliverAt(Instant)
        .sendAsync();
```

| 要点 | 说明 |
| --- | --- |
| 能力 | ⭐ **每条消息独立的绝对时间**，不需要像 RocketMQ 4.x 那样预置 18 个级别 |
| 实现 | Broker 侧用延迟投递 tracker（按时间索引暂存），到点再投给订阅 |
| 保护 | 命名空间策略 `maxMessagesInDelayedDelivery`（默认 100）：延迟消息数超过阈值时不再保证精延迟，会**提前投递** |
| 边界 | 跨天/跨周的长延迟任务不建议用 MQ 承载，用「定时任务 + 状态表」（理由见 [消息队列选型.md](消息队列选型.md) 延迟队列一节） |

### 6.2 消息去重（生产者幂等）

命名空间级策略：`pulsar-admin namespaces set-deduplication <tenant/ns> --enable`。判定依据是**生产者身份 + 消息序号**，因此要求生产者显式设 `producerName`（否则重启后是新身份，去重窗口失效）。

### 6.3 事务

`transactionEnabled` 相关能力较新且默认关闭，用于「读 A → 处理 → 写 B（+ ack）」的原子提交，定位与 Kafka 事务一致（**流内** exactly-once），不是 RocketMQ 那种「半消息 + 回查」的业务事务消息。业务侧分布式事务仍优先看 [../../分布式/分布式事务.md](../../分布式/分布式事务.md)。

### 6.4 分层存储与保留

| 手段 | 配置入口 | 用途 |
| --- | --- | --- |
| 命名空间保留 | `set-retention --size --time` | 决定「历史还能回溯多久」 |
| 压缩 | `pulsar-admin topics truncate` / compaction（按 key 保留最新值） | 状态型 topic 瘦身 |
| 分层存储 | Broker 开 `tieredStorageEnabled` + 命名空间 `set-offload-policy`（S3 / GCS / Azure / filesystem / MinIO） | 冷数据下沉对象存储，本地盘只留热数据 |

> ⚠️ 最常见的生产事故不是丢消息，而是**没开 retention 就想去回溯**：Pulsar 默认「所有订阅都 ack 完的数据可以立刻删除」，重放窗口为零。从 Kafka（默认保留 7 天）迁过来的团队几乎都栽过一次。

### 6.5 其他常备能力

- **非持久 topic**（`non-persistent://`）：只在 Broker 内存，不落 BookKeeper，适合可丢的实时信号。
- **Schema Registry**：`Schema.STRING` / `AVRO` / `JSON` + 自动演化（`SchemaInfoWithVersion`），生产者与消费者共享 schema。
- **地理复制**：`clusters create` + `namespaces add-cluster`，消息级可控制 `ReplicationClusters` / `DisableReplication`（Go 客户端有对应字段）。
- **Pulsar Functions / IO Connectors**：平台内置的轻量流计算与数据集成，省一层 Flink 的场景。
- **消息追踪与统计**：`topics stats`（backlog、各订阅游标）、`peek-messages`，配合 `subscription` 级指标做告警。

---

## 七、使用一：Go ⭐

### 客户端与依赖

| 项 | 说明 |
| --- | --- |
| 库 | `github.com/apache/pulsar-client-go`（**官方**，纯 Go 实现协议，**无 cgo**） |
| 优势 | 与 confluent-kafka-go 需要 librdkafka 对比，交叉编译和镜像体积都友好 |
| 局限 | ⚠️ 功能落后于 Java 客户端：例如新建订阅的位置只能靠 `Seek`、事务与部分 admin 能力缺失；管理类操作一般直接调 REST（`pulsar-admin` 的 HTTP API） |

安装：`go get github.com/apache/pulsar-client-go@v0.21.0`

### 生产者

```go
package main

import (
	"context"
	"fmt"
	"log"
	"sync"
	"time"

	"github.com/apache/pulsar-client-go/pulsar"
)

// ⭐ 客户端单例：pulsar.Client 内部维护连接池、IO 线程与 Producer/Consumer 缓存，进程内只建一次
var client = sync.OnceValue(func() pulsar.Client {
	c, err := pulsar.NewClient(pulsar.ClientOptions{
		URL:               "pulsar://127.0.0.1:6650",
		OperationTimeout:  30 * time.Second,
		ConnectionTimeout: 10 * time.Second,
	})
	if err != nil {
		log.Fatalf("new pulsar client: %v", err)
	}
	return c
})

func main() {
	c := client()
	defer c.Close()

	producer, err := c.CreateProducer(pulsar.ProducerOptions{
		Topic:                   "persistent://shop/default/order-events",
		Name:                    "order-producer",
		MaxPendingMessages:      1000,             // 在途队列上限，与 SendTimeout 一起构成背压
		SendTimeout:             30 * time.Second, // 超时未确认即返回 error，不会静默丢
		DisableBatching:         false,
		BatchingMaxPublishDelay: 10 * time.Millisecond,
		BatchingMaxMessages:     500,
	})
	if err != nil {
		log.Fatalf("create producer: %v", err)
	}
	defer producer.Close()

	ctx := context.Background()

	// 同步发送：拿到 MessageID 才表示 Broker（BookKeeper）已确认写入
	id, err := producer.Send(ctx, &pulsar.ProducerMessage{
		Key:     "ORDER_1001", // ⭐ 有 Key 时默认按 key 哈希路由，同一实体恒定进同一分区
		Payload: []byte(`{"event":"created"}`),
	})
	if err != nil {
		log.Fatalf("send failed: %v", err)
	}
	fmt.Printf("sent ledger=%d entry=%d\n", id.LedgerID(), id.EntryID())

	// 异步发送：吞吐优先，回调里必须判 err（否则等于「发出即忘」）
	for i := 0; i < 2; i++ {
		producer.SendAsync(ctx, &pulsar.ProducerMessage{
			Key:     "ORDER_1002",
			Payload: []byte(fmt.Sprintf(`{"event":"paid","seq":%d}`, i)),
		}, func(id pulsar.MessageID, msg *pulsar.ProducerMessage, err error) {
			if err != nil {
				log.Printf("async send failed, retry needed: %v", err)
				return
			}
			fmt.Printf("async ok ledger=%d entry=%d\n", id.LedgerID(), id.EntryID())
		})
	}

	// 延迟投递：60 秒后才对订阅者可见（原生能力，Kafka 需自建）
	if _, err := producer.Send(ctx, &pulsar.ProducerMessage{
		Key:          "ORDER_1003",
		Payload:      []byte(`{"event":"timeout-check"}`),
		DeliverAfter: 60 * time.Second,
	}); err != nil {
		log.Fatalf("send delayed: %v", err)
	}

	producer.Flush() // 阻塞直到异步请求全部落地，进程退出前必调
}
```

> ⚠️ `ProducerMessage` 有 `Key` 与 `OrderingKey` 两个字段：分区路由默认按 `OrderingKey`（有则优先）再退到 `Key`；`Key` 还会随消息传给订阅端做 Key_Shared 哈希，**只设 `Key` 在多数场景够用**。

### 消费者（Shared + 手动 ack + 死信）

```go
package main

import (
	"context"
	"log"
	"sync"
	"time"

	"github.com/apache/pulsar-client-go/pulsar"
)

var client = sync.OnceValue(func() pulsar.Client {
	c, err := pulsar.NewClient(pulsar.ClientOptions{URL: "pulsar://127.0.0.1:6650"})
	if err != nil {
		log.Fatalf("new pulsar client: %v", err)
	}
	return c
})

func main() {
	c := client()
	defer c.Close()
	ctx := context.Background()

	consumer, err := c.Subscribe(pulsar.ConsumerOptions{
		Topic:               "persistent://shop/default/order-events",
		SubscriptionName:    "order-service",     // ⭐ 订阅（游标）独立于消费者实例，进度存在 Broker 侧
		Type:                pulsar.Shared,       // 多实例并行消费；要「同 Key 有序」用 KeyShared
		ReceiverQueueSize:   1000,                // 本地预取队列，同时也是背压点
		NackRedeliveryDelay: time.Minute,         // NegativeAck 后多久重投
		AckWithResponse:     true,                // ack 让 Broker 确认，能感知「以为提交了其实失败」
		DLQ: &pulsar.DLQPolicy{                   // 重投到达上限后进死信主题
			MaxDeliveries:   3,
			DeadLetterTopic: "persistent://shop/default/order-events-DLQ",
		},
	})
	if err != nil {
		log.Fatalf("subscribe: %v", err)
	}
	defer consumer.Close()

	// Go 客户端新建订阅默认从 Latest 开始；要补历史消息得显式定位游标
	if err := consumer.Seek(pulsar.EarliestMessageID()); err != nil {
		log.Printf("seek earliest: %v", err)
	}
	// 也可以按时间回溯（Pulsar 没有 offset，游标按 MessageID 或时间定位）
	// _ = consumer.SeekByTime(time.Now().Add(-time.Hour))

	for {
		msg, err := consumer.Receive(ctx) // 也可用 consumer.Chan() 配合 select 做优雅退出
		if err != nil {
			log.Printf("receive stopped: %v", err)
			return
		}
		if err := handle(msg); err != nil {
			consumer.Nack(msg) // 不提交：按 NackRedeliveryDelay 重投，超过 MaxDeliveries 进 DLQ
			continue
		}
		if err := consumer.Ack(msg); err != nil { // ⭐ 先处理业务，成功后再 ack
			log.Printf("ack failed: %v", err)
		}
	}
}

func handle(msg pulsar.Message) error {
	log.Printf("key=%s redelivery=%d payload=%s", msg.Key(), msg.RedeliveryCount(), msg.Payload())
	return nil
}
```

### Reader：重放一段历史

```go
package main

import (
	"context"
	"fmt"
	"log"
	"time"

	"github.com/apache/pulsar-client-go/pulsar"
)

func main() {
	c, err := pulsar.NewClient(pulsar.ClientOptions{URL: "pulsar://127.0.0.1:6650"})
	if err != nil {
		log.Fatalf("new pulsar client: %v", err)
	}
	defer c.Close()
	ctx, cancel := context.WithTimeout(context.Background(), 30*time.Second)
	defer cancel()

	// Reader：不建订阅、自管游标位置，适合「重放一段历史」（对账、离线补数）
	reader, err := c.CreateReader(pulsar.ReaderOptions{
		Topic:                   "persistent://shop/default/order-events",
		StartMessageID:          pulsar.EarliestMessageID(),
		StartMessageIDInclusive: true,
		Name:                    "order-replay",
	})
	if err != nil {
		log.Fatalf("create reader: %v", err)
	}
	defer reader.Close()

	for {
		if !reader.HasNext() { // 只读游标推进，不影响任何订阅
			log.Printf("replay finished")
			return
		}
		msg, err := reader.Next(ctx)
		if err != nil {
			log.Printf("next: %v", err)
			return
		}
		fmt.Printf("ledger=%d entry=%d key=%s\n", msg.ID().LedgerID(), msg.ID().EntryID(), msg.Key())
	}
}
```

> ✅ **校验口径**：以上三段 Go 示例用 `go1.26.5` + `github.com/apache/pulsar-client-go v0.21.0` 逐字通过 `go build` / `go vet` / `gofmt -l`；本机没有 Pulsar Broker 实例，因此**只编译校验、未实际连集群运行**，文中不含伪造的输出。

---

## 八、使用二：Java ⭐

### 依赖坐标（Maven）

```xml
<dependency>
  <groupId>org.apache.pulsar</groupId>
  <artifactId>pulsar-client</artifactId>
  <version>4.2.4</version>   <!-- 客户端大版本与 Broker 对齐；4.x 系列已要求 JDK 17+ -->
</dependency>
<dependency>
  <groupId>org.springframework.pulsar</groupId>
  <artifactId>spring-pulsar-spring-boot-starter</artifactId>
  <version>1.2.18</version>  <!-- Spring Boot 3.5.x 对应 Spring Pulsar 1.2.x -->
</dependency>
```

### 客户端单例

```java
import org.apache.pulsar.client.api.PulsarClient;
import org.apache.pulsar.client.api.PulsarClientException;
import org.apache.pulsar.client.api.SizeUnit;

import java.util.concurrent.TimeUnit;

/** ⭐ 客户端单例：PulsarClient 内部自带连接池、IO 线程与 Producer/Consumer 缓存，进程内只建一次 */
public final class PulsarClientHolder {
    private static volatile PulsarClient instance;

    private PulsarClientHolder() {
    }

    public static PulsarClient get() throws PulsarClientException {
        if (instance == null) {
            synchronized (PulsarClientHolder.class) {
                if (instance == null) {
                    instance = PulsarClient.builder()
                            .serviceUrl("pulsar://127.0.0.1:6650")
                            .connectionTimeout(10, TimeUnit.SECONDS)
                            .operationTimeout(30, TimeUnit.SECONDS)
                            .ioThreads(4)
                            .memoryLimit(64, SizeUnit.MEGA_BYTES) // 客户端缓冲上限：超了 send 会阻塞（背压）
                            .build();
                }
            }
        }
        return instance;
    }
}
```

> 💡 Spring 项目里不要写这段双检锁：`spring-pulsar` 已经把 `PulsarClient` 做成单例 Bean，直接构造注入即可（见本节末）。

### 原生 Producer

```java
import org.apache.pulsar.client.api.MessageId;
import org.apache.pulsar.client.api.Producer;
import org.apache.pulsar.client.api.PulsarClient;
import org.apache.pulsar.client.api.Schema;

import java.util.concurrent.TimeUnit;

public class OrderProducerDemo {
    public static void main(String[] args) throws Exception {
        PulsarClient client = PulsarClientHolder.get();

        try (Producer<String> producer = client.newProducer(Schema.STRING)
                .topic("persistent://shop/default/order-events")
                .producerName("order-producer")
                .sendTimeout(30, TimeUnit.SECONDS) // 超时未确认即抛异常，不会静默丢
                .maxPendingMessages(1000)
                .blockIfQueueFull(true)            // 在途队列满时阻塞发送线程，而不是抛「队列满」
                .enableBatching(true)
                .batchingMaxPublishDelay(10, TimeUnit.MILLISECONDS)
                .batchingMaxMessages(500)
                .create()) {

            // 同步发送：返回 MessageId 即 Broker（BookKeeper quorum）已确认写入
            MessageId id = producer.newMessage()
                    .key("ORDER_1001")             // ⭐ 有 key 走 key_hash 路由，同一实体恒定进同一分区
                    .value("{\"event\":\"created\"}")
                    .send();
            System.out.printf("sent messageId=%s%n", id); // MessageId 不是 offset：它指向 BookKeeper 的 ledger/entry

            // 异步发送：吞吐优先，回调里必须判异常；退出前 flush
            for (int i = 0; i < 2; i++) {
                producer.newMessage()
                        .key("ORDER_1002")
                        .value("{\"event\":\"paid\",\"seq\":" + i + "}")
                        .sendAsync()
                        .whenComplete((mid, ex) -> {
                            if (ex != null) {
                                System.err.println("async send failed, retry needed: " + ex.getMessage());
                            }
                        });
            }

            // 延迟投递：60 秒后才对订阅者可见（原生能力，Kafka 需自建）
            producer.newMessage().key("ORDER_1003").value("{\"event\":\"timeout-check\"}")
                    .deliverAfter(60, TimeUnit.SECONDS).sendAsync();

            producer.flush();
        }
    }
}
```

### 原生 Consumer（Shared + 死信 + 按时间回溯）

```java
import org.apache.pulsar.client.api.Consumer;
import org.apache.pulsar.client.api.DeadLetterPolicy;
import org.apache.pulsar.client.api.Message;
import org.apache.pulsar.client.api.PulsarClient;
import org.apache.pulsar.client.api.Schema;
import org.apache.pulsar.client.api.SubscriptionInitialPosition;
import org.apache.pulsar.client.api.SubscriptionType;

import java.util.concurrent.TimeUnit;

public class OrderConsumerDemo {
    public static void main(String[] args) throws Exception {
        PulsarClient client = PulsarClientHolder.get();

        try (Consumer<String> consumer = client.newConsumer(Schema.STRING)
                .topic("persistent://shop/default/order-events")
                .subscriptionName("order-service")
                .subscriptionType(SubscriptionType.Shared) // 多实例并行；要「同 key 有序」用 Key_Shared
                .subscriptionInitialPosition(SubscriptionInitialPosition.Earliest)
                .receiverQueueSize(1000)                   // 本地预取队列，也是背压的一环
                .ackTimeout(60, TimeUnit.SECONDS)          // 60 秒未 ack 由 Broker 重投
                .negativeAckRedeliveryDelay(1, TimeUnit.MINUTES)
                .deadLetterPolicy(DeadLetterPolicy.builder() // 重投到达上限后进死信主题
                        .maxRedeliverCount(3)
                        .deadLetterTopic("persistent://shop/default/order-events-DLQ")
                        .retryLetterTopic("persistent://shop/default/order-events-RETRY")
                        .build())
                .subscribe()) {

            // 回溯：按时间重放（Pulsar 没有 offset，游标按 MessageId 或时间定位；入参是毫秒时间戳）
            consumer.seek(System.currentTimeMillis() - 3600_000L);

            for (int i = 0; i < 10; i++) {
                Message<String> msg = consumer.receive(5, TimeUnit.SECONDS);
                if (msg == null) {
                    continue;
                }
                try {
                    handle(msg);
                    consumer.acknowledge(msg);       // ⭐ 先处理业务，成功后再 ack
                } catch (Exception e) {
                    consumer.negativeAcknowledge(msg); // 交还 Broker 按延迟重投，不幂等就会重复处理
                }
            }
        }
    }

    private static void handle(Message<String> msg) {
        System.out.printf("key=%s redelivery=%d value=%s%n",
                msg.getKey(), msg.getRedeliveryCount(), msg.getValue());
    }
}
```

> ⚠️ **和 Go 客户端对不上的三个命名**（都是编译期踩到的）：Java 侧是 `negativeAcknowledge(msg)`，不是 Go 的 `Nack`；`Consumer.seek(...)` 的重载只吃 `MessageId` / `long 毫秒时间戳` / 分区路由函数，**没有 `Instant` 重载**；`MessageId` 接口本身不暴露 ledger/entry 取值方法（Go 的 `id.LedgerID()` 在 Java 里要强转成 `MessageIdImpl`），所以打日志直接 `printf("%s", id)` 让它自己 toString 即可。

### Spring Pulsar：PulsarTemplate + @PulsarListener

```yaml
spring:
  pulsar:
    client:
      service-url: pulsar://127.0.0.1:6650
    producer:
      producer-name: order-producer
    consumer:
      keys:
        subscription-name: order-service
      subscription-type: Shared          # ⭐ 与原生一致：并行消费但无序
      subscription-initial-position: Earliest
      receiver-queue-size: 1000
    listeners:
      ack-mode: manual                   # ⭐ 处理成功再 ack
      concurrency: 3                     # 并发消费者数，配合分区/订阅类型看效果
```

```java
// 生产：PulsarTemplate 内部复用单例 client 与 producer 缓存
@Service
public class OrderProducerService {
    private final PulsarTemplate<String> pulsarTemplate;

    public OrderProducerService(PulsarTemplate<String> pulsarTemplate) {
        this.pulsarTemplate = pulsarTemplate;
    }

    public void publish(String orderId, String payload) throws PulsarClientException {
        // key = 业务主键 ⇒ 同实体恒定进同一分区；typed message 可带延迟时间
        pulsarTemplate.newMessage(payload)
                .withTopic("persistent://shop/default/order-events")
                .withMessageCustomizer(builder -> builder.key(orderId))
                .asyncSend();
    }
}

// 消费：@PulsarListener + 手动 ack + 死信（重投 3 次后进 DLQ）
@Service
public class OrderConsumerService {
    @PulsarListener(
            topics = "persistent://shop/default/order-events",
            subscriptionName = "order-service",
            subscriptionType = SubscriptionType.Shared,
            deadLetterPolicy = @DeadLetterPolicy(maxRedeliverCount = 3,
                    deadLetterTopic = "persistent://shop/default/order-events-DLQ"))
    public void onMessage(MessageSchema bytes, String payload, Consumer<String> consumer, MessageId messageId) {
        handle(payload);                  // 1) 业务处理 + 幂等校验
        consumer.acknowledge(messageId);  // 2) ⭐ 成功才 ack
    }
}
```

> ⚠️ Spring Pulsar 的 `ack-mode: manual` 下必须由监听方法自己 ack，否则消息会等 `ackTimeout` 重投（表现为「消费了但一直重复」）。Spring Boot 4 / Spring Pulsar 2.x 的 starter 坐标与属性前缀有调整，升级时以对应版本文档为准。

> ✅ **校验口径**：以上原生 Java 段（单例 + Producer + Consumer）用 JBR 21 + Maven 3.8.1 逐字抽成工程后 `mvn -B clean compile` 通过，实际解析到 `org.apache.pulsar:pulsar-client:4.2.4`；Spring Pulsar 段只按注解与 API 名称书写，**未编译校验**，接入前请在本地起一个 Broker（第九节 standalone 方式）跑一遍。本机无 Pulsar 集群，因此所有 Java 段都是**只编译、未运行**，文中不含伪造输出。

---

## 九、部署

### 最小开发环境（standalone 自带 ZK + BookKeeper）

```bash
# 单容器起「ZK + BookKeeper + Broker」，本地开发最快路径
docker run -it -p 6650:6650 -p 8080:8080 \
  --name pulsar apachepulsar/pulsar:4.0.13 bin/pulsar standalone

# 建租户 / 命名空间 / topic（standalone 自带 public/default）
docker exec pulsar bin/pulsar-admin tenants create shop
docker exec pulsar bin/pulsar-admin namespaces create shop/default
docker exec pulsar bin/pulsar-admin topics create persistent://shop/default/order-events
# ⭐ 想能回溯必须先打开 retention（默认 0，ack 完就能删）
docker exec pulsar bin/pulsar-admin namespaces set-retention shop/default \
  --size 10G --time 7d
# 打开生产者去重（幂等）
docker exec pulsar bin/pulsar-admin namespaces set-deduplication shop/default --enable
```

### docker-compose.yml（Broker + BookKeeper + ZooKeeper + Proxy）

```yaml
services:
  zookeeper:
    image: apachepulsar/pulsar:4.0.13
    command: bin/pulsar zookeeper
    healthcheck:
      test: ["CMD", "bin/pulsar", "zk", "status"]
      interval: 10s
    volumes:
      - zk-data:/data/zookeeper

  bookie:
    image: apachepulsar/pulsar:4.0.13
    command: >
      bin/apply-config-from-env.py conf/bookkeeper.conf &&
      bin/pulsar bookie
    environment:
      BOOKIE_PORT: "3181"
      # bookie 侧的元数据地址：BK 自己的写法（md:: 前缀 = ZooKeeper 元数据工厂）
      zkServers: zookeeper:2181
      metadataServiceUri: md::zookeeper:2181
      # ⭐ 三副本：写 2 个成功、ack 2 个即返回，至少要有 3 个 bookie
      journalDirectory: /data/journal
      ledgerDirectories: /data/ledgers
    depends_on:
      zookeeper:
        condition: service_healthy
    volumes:
      - bk-journal:/data/journal
      - bk-ledgers:/data/ledgers

  broker:
    image: apachepulsar/pulsar:4.0.13
    command: >
      bin/apply-config-from-env.py conf/broker.conf &&
      exec bin/pulsar broker
    environment:
      # ⭐ 元数据存储：新版键是 metadataStoreUrl（`zk:` 前缀指后端类型，不是协议）；
      # 旧键 zookeeperServers 仍被接受，两个都给最省事
      metadataStoreUrl: zk:zookeeper:2181
      zookeeperServers: zookeeper:2181
      webServiceUrl: http://broker:8080
      brokerServiceUrl: pulsar://broker:6650
      advertisedAddress: broker
      # ⭐ 与 BookKeeper 的三副本策略对齐
      bookkeeperEnsemble: 3
      bookkeeperWriteQuorum: 2
      bookkeeperAckQuorum: 2
      # 事务默认关闭；开启前确认业务真的需要
      transactionEnable: "false"
      # 生产环境：禁止自动建 topic，避免拼错名字产生垃圾
      allowAutoTopicCreation: "false"
      # 背压：单消费者未 ack 上限
      maxUnackedMessagesPerConsumer: 50000
    ports:
      - "6650:6650"
      - "8080:8080"
    depends_on:
      - bookie
    volumes:
      - broker-conf:/pulsar/conf

  proxy:
    image: apachepulsar/pulsar:4.0.13
    command: >
      bin/apply-config-from-env.py conf/proxy.conf &&
      exec bin/pulsar proxy
    environment:
      metadataStoreUrl: zk:zookeeper:2181
      brokerServiceURL: pulsar://broker:6650
    ports:
      - "6651:6650"
    depends_on:
      - broker

volumes:
  zk-data:
  bk-journal:
  bk-ledgers:
  broker-conf:
```

> ⚠️ 上面的 compose 是**能跑通的单机演示形态**：`bookkeeperEnsemble=3` 需要 3 个 bookie 才真正达到写 2 确认，单 bookie 时要把它降到 1/1/1（可靠性也随之降到无冗余），这点在测试与生产必须区分开。
> ⚠️ **未实机验证**：本仓库机器没有跑这套 compose，配置键名（尤其 `metadataStoreUrl` / `zookeeperServers` / `metadataServiceUri`）随 Broker 版本有差异，落地前以所用版本 `conf/*.conf` 模板与 `bin/pulsar broker` 的启动日志为准。

### 生产关键配置

| 配置 | 位置 | 建议值 | 说明 |
| --- | --- | --- | --- |
| `managedLedgerDefaultEnsembleSize` / `WriteQuorum` / `AckQuorum` | broker.conf | 3 / 2 / 2 | 副本与确认策略；bookie 数不足会直接写入失败 |
| `backlogQuotaDefaultLimitGB` | 命名空间策略 | 按盘定 | ⭐ 防止慢订阅把磁盘顶满；超限动作可配 `producer_request_hold` / `exception` / `consumer_backlog_eviction` |
| `messageTTLInSeconds` | 命名空间策略 | 按业务 | 未 ack 消息的存活期，过期丢弃或进死信 |
| `retentionTimeInMinutes` / `retentionSizeInMB` | 命名空间策略 | 需要重放就必须 > 0 | 默认 0，即「所有订阅 ack 完即可删」 |
| `maxUnackedMessagesPerConsumer` / `...Broker` | broker.conf | 50000 / 200000 | 未 ack 达阈值会**暂停该订阅的投递**（背压，表现为「不消费了」） |
| `brokerDeduplicationEnabled` | 命名空间策略 | 核心链路开 | 生产者 + 序号去重 |
| `tieredStorageEnabled` + offloader | broker.conf / 命名空间 | 冷数据多时开 | 本地盘只留热数据 |
| `loadBalancerEnabled` / bundle 分裂 | broker.conf | 默认开 | 单 bundle topic 过载时触发 split，或用 `pulsar-admin namespaces split-bundle` |

---

## 十、运维与常见问题

### 常用排查命令

```bash
# 订阅进度与积压：看 backlogSize / 各订阅 markDelete 位置 / 消费者是否掉线
bin/pulsar-admin topics stats persistent://shop/default/order-events

# 只看某个订阅的游标与积压（stats 输出里 per-subscription 段）
bin/pulsar-admin topics stats -s order-service persistent://shop/default/order-events

# 窥探未消费的消息（不推进游标）
bin/pulsar-admin topics peek-messages -s order-service -n 5 persistent://shop/default/order-events

# 手动改游标（回溯或跳过坏消息）—— 生产操作前先确认幂等
bin/pulsar-admin topics skip-all -s order-service persistent://shop/default/order-events
bin/pulsar-admin topics reset-cursor -s order-service --time 1h persistent://shop/default/order-events

# 命令行收发消息（链路联调）
bin/pulsar-client produce persistent://shop/default/order-events -m '{"event":"created"}'
bin/pulsar-client consume persistent://shop/default/order-events -s test-sub -t Shared -n 10
```

### 高频故障表

| 现象 | 根因 | 处理 |
| --- | --- | --- |
| 生产者报 `ProducerBusyException` / 队列满 | 在途队列打满，且未开 `blockIfQueueFull` | 提 `maxPendingMessages` 或开阻塞形成背压；查 BookKeeper 写延迟与 bookie 磁盘 |
| 写入报 `NotEnoughBookiesException` | 可用 bookie 数 < `ensemble` | 恢复 bookie 或按容量策略调整；别调低 ackQuorum 换可用性（丢副本） |
| 消费者突然「不投消息了」 | 未 ack 数触发 `maxUnackedMessagesPerConsumer` 背压 | 查 `topics stats` 的 `unackedMessages`；提并发、减小 `receiverQueueSize`、排查卡在下游的线程 |
| 没崩溃却重复消费 | `ackTimeout` 小于业务处理 P99，Broker 判定超时重投 | 调大 `ackTimeout` 或改成手动 Nack 控制重投；核心是**消费端幂等** |
| 想回溯但没数据 | 命名空间 retention 为 0，消息 ack 完即被删 | ⭐ 开 retention（并配 TTL 控成本），历史窗口提前规划 |
| 磁盘持续上涨、`backlogSize` 不降 | 有慢订阅/僵尸订阅拖住删除位置 | 清理无用订阅（`topics unsubscribe`）、给命名空间设 backlog 配额 |
| Broker 频繁 Full GC / OOM | 批次与预取队列过大（`receiverQueueSize` × 并发消费者） | 降预取、限 `memoryLimit`、按 topic 数拆 Broker |
| 顺序被打乱 | 用了 Shared 订阅；或生产端没设 key 导致轮询；或扩分区/改 bundle | 要「同 key 有序」用 Key_Shared 并保证 `key`/`orderingKey` 一致，消费端同 key 单线程 |
| 单 topic 性能上不去 | 一个 topic 只属于一个 bundle、由一个 Broker 持有 | 建**分区 topic** 或拆 topic，让负载均衡器把 bundle 摊开 |

### 消息追踪与观测

| 指标 | 来源 | 用途 |
| --- | --- | --- |
| `backlogSize`、`msgBacklog`、`publishRate`、`dispatchRate` | `topics stats` | 积压与吞吐 |
| 订阅级 `blockedSubscriptionOnUnackedMsgs` | `topics stats` | 定位「被背压卡住的订阅」 |
| broker / bookie JVM 指标 | Prometheus 端点（Broker 8080 `/metrics`、Bookie 8000） | 容量与 GC |
| TraceId 透传 | 消息 `Properties` / OpenTelemetry 集成 | 跨系统链路追踪 |

> 💡 接入方式与指标口径见 [../../可观测性/可观测性选型.md](../../可观测性/可观测性选型.md)。

---

## 十一、Pulsar vs Kafka：一页决策表

| 维度 | Pulsar | Kafka |
| --- | --- | --- |
| 架构 | ⭐ 存算分离（Broker + BookKeeper） | 存算一体（分区副本落本机盘） |
| 扩容 / 故障转移 | 迁 bundle 所有权，**不搬数据** | 副本重选 + 数据重分配（要搬） |
| 保留与回溯 | 按订阅游标 + 命名空间 retention（默认 0，易踩） | 按分区固定 retention（默认 7 天） |
| 多租户 | ⭐ 原生 tenant/namespace/bundle + 配额 | 前缀约定 + ACL，需自建治理 |
| 延迟消息 | ⭐ `deliverAt` 任意时刻 | ❌ 无，需自建 |
| 死信 / 重试 | ⭐ 客户端原生 `DeadLetterPolicy`（含 RETRY topic） | 需自己实现（或用库） |
| 生产者幂等 | ⭐ 命名空间级去重（生产者 + 序号） | `enable.idempotence` |
| 顺序 | Key_Shared（同 key 有序）；Shared 无序 | 分区内有序，`hash(key) % 分区数` |
| 吞吐 | 十万级 msg/s 量级（写路径多一跳 quorum） | ⭐ 十万~百万级（顺序写 + PageCache + 零拷贝） |
| 流处理生态 | Functions / Connectors 内置，但社区规模小 | ⭐ Flink / Spark / Streams / Debezium 事实标准 |
| 运维成本 | ⭐ 高（Broker + Bookie + ZK/元数据） | 中（KRaft 后已去 ZK） |
| 客户端语言 | ⭐ Java（功能全）、Go（官方、纯 Go）、C++/Python 官方 | Java/Scala 原生最全；Go 靠第三方 |

> ⭐ 决策口径：**「多租户 + 需要延迟/死信/回溯 + 弹性扩缩容」→ Pulsar；「极致吞吐 + 大数据流处理管道」→ Kafka。** 只从 Kafka 迁一个业务消息场景过来，最容易翻车的两点是：retention 默认值（回溯无数据）和 Shared 订阅（顺序假设）。

---

## 十二、面试官会追问什么

- **Pulsar 和 Kafka 最本质的差别是什么？** → 存算分离。Kafka 的分区副本落在 Broker 本机，扩容要搬数据；Pulsar 把存储交给 BookKeeper，Broker 无状态，迁的是 topic 所有权。带来的附带好处是 Broker 可弹性伸缩、扇出共享一份数据、分层存储天然好做。
- **为什么 Pulsar 吞吐通常不如 Kafka？** → 写路径要经过 BookKeeper quorum 确认（跨节点、多副本 ack），换来的是「确认即持久」；Kafka 靠 OS 页缓存 + 顺序追加 + 零拷贝，把刷盘异步化，吞吐高但「acks=all 不等于落盘」。这是**可靠性实现方式的取舍，不是实现质量差异**。
- **四种订阅类型怎么选？** → 要并行选 Shared（但无序）；要同实体有序选 Key_Shared（生产端必须设 key）；要单实例严格有序选 Exclusive；要有序 + 热备选 Failover。⚠️ 高频错误是「用 Shared 又抱怨消息乱序」。
- **Pulsar 怎么做回溯？和 Kafka 的 offset 有何不同？** → 没有 offset，MessageId 是 `(ledgerId, entryId, partition)`，用 `seek(MessageId)` 或 `seekByTime(Instant)`；也可以开 Reader 自管位置。⚠️ 前提是命名空间 retention 大于 0，否则数据早被删了。
- **延迟消息怎么实现？** → 消息带 `deliverAt`/`deliverAfter`，Broker 用延迟 tracker 到点投递，粒度到秒/毫秒且每条独立；对比 RocketMQ 4.x 固定 18 级、Kafka 无。超过 `maxMessagesInDelayedDelivery` 阈值会退化。
- **怎么保证不丢？** → 生产端同步发送 + `sendTimeout` + 失败重试；存储端 BookKeeper E/W/Q ≥ 3/2/2；消费端先处理后 ack，`ackTimeout` 大于业务 P99，配死信兜底。三处缺一处。
- **重复消费怎么来的？** → `ackTimeout` 到期重投、`Nack`、Broker 重启后未 ack 消息重新派发、消费者重连。**中间件层面只能 At Least Once，正解是业务幂等**（唯一键 / 去重表 / 状态机 CAS）。
- **为什么说 Pulsar 运维重？** → 组件三类（Broker / Bookie / 元数据），且故障域互相牵连：bookie 磁盘故障会表现为消费报错，ledger 元数据问题要查 ZK，bundle 负载不均要会 `unload`/`split-bundle`。团队没有相应运维能力时，选型结论应该保守。

---

## 关联

- [消息队列选型.md](消息队列选型.md) — 五款 MQ 的横向对比与选型决策（含「契合语言」维度）
- [Kafka.md](Kafka.md) — 存算一体的对照路线：分区/ISR、acks、零拷贝为什么快
- [RocketMQ.md](RocketMQ.md) — 业务消息功能最全的另一条路线（半消息事务、18 级延迟）
- [Nats.md](Nats.md) — 轻量派：Core NATS + JetStream，Go 生态里 Pulsar 之外的另一选择
- [../../分布式/一致性与CAP.md](../../分布式/一致性与CAP.md) — BookKeeper quorum 写与多数派的对应关系
- [../../可观测性/可观测性选型.md](../../可观测性/可观测性选型.md) — 订阅积压指标的采集与告警口径

> 反向引用（本篇被下列文档引到）：[消息队列选型.md](消息队列选型.md)、[中间件选型.md](../中间件选型.md)、[Kafka.md](Kafka.md)、[RocketMQ.md](RocketMQ.md)、[Nats.md](Nats.md)、[RabbitMQ.md](RabbitMQ.md)、[ActiveMQ.md](ActiveMQ.md)、[目录.md](../../目录.md)
