# Kafka 消息与流平台

> 覆盖 Kafka 的定位与核心概念、分区/副本存储原理、端到端可靠性参数、消费者组与重平衡，以及 Go / Java 双端完整示例、部署配置与运维排障要点。
>
> 内容整理自个人学习笔记。四款消息队列的横向对比与选型见 [消息队列选型.md](消息队列选型.md)。

## 一、一句话定位与它凭什么

**Kafka 是分布式事件流平台（Distributed Event Stream Platform），不只是消息队列**——官方给它三重身份：**消息队列 + 存储系统 + 流处理平台**。一条消息写进来，既可以当队列消费，也可以当可重放、可长期留存的日志，还能被 Flink / Kafka Streams 直接做流式计算。

核心卖点：⭐ **高吞吐**（单机十几万 msg/s，集群百万级 QPS 量级）、⭐ **可持久化**（落盘保留，默认 7 天，而非消费即删）、⭐ **可重放**（位点由消费者管理，可任意回退重读）、**水平扩展**（加 Broker 分摊分区、加分区提并行度），以及作为 Flink / Spark / Debezium / Connect 事实标准数据总线的生态。

> **与传统 MQ 最本质的差异**：传统 MQ **消费即删**（队列只是暂存管道），Kafka **消费后不删除**，靠 Offset 标记进度，清理交给时间（`log.retention.hours`）或大小（`log.retention.bytes`）到期删除——因此数据模型是一条 append-only、不可变、可截断的分区日志，且保留了「可回退重放」的能力。
> ⭐ 一句话记忆：**Kafka 的 Topic 是一条只会追加、不会插队的日志；消费者不是「拿走」消息，而是「往前挪自己的书签」。**
> ⚠️ 也正因为这套设定，Kafka **没有原生延迟消息、没有消息级 TTL、也没有半消息事务回查**；选型时这三项最容易直接筛掉它，详见 [消息队列选型.md](消息队列选型.md)。

## 二、核心概念 ⭐

| 概念 | 说明 | 关键点 |
| --- | --- | --- |
| **Broker** | 一个 Kafka 服务节点（进程），负责收发与存储 | 多 Broker 组成集群，每台承载若干分区（Leader 或 Follower） |
| **Topic** | 消息的**逻辑分类**，订阅与发布的对象 | 只是逻辑概念，物理上由若干 Partition 承载 |
| **Partition（分区）** ⭐ | Topic 的**物理分片**，Kafka **并行与有序的基本单位** | 一份 append-only 日志，只能被同组内**一个**消费者消费 |
| **Offset** | 消息在**某个分区内**的**递增序号**（从 0 开始） | ⚠️ 只在分区内有意义，跨分区不可比 |
| **Producer** | 生产者，向 Topic 写消息 | 决定消息落哪个分区（key 哈希 / 轮询） |
| **Consumer** | 消费者，从分区拉取消息 | 拉模型（poll），消费者自己控制速率 |
| **Consumer Group** ⭐ | 消费组，组内**分摊**分区、组间**各自独立** | 一条消息在一个组内只被消费一次；不同组互不影响 |
| **Replica** | 分区副本，分 **Leader** 与 **Follower** | Leader 提供读写，Follower 只做同步备份 |
| **ISR** | In-Sync Replicas，与 Leader 保持同步的副本集合 | ⭐ `acks=all` 的「all」实际指 ISR，而非全部副本 |
| **Controller** | 集群「大脑」，负责分区 Leader 选举、Broker 上下线 | KRaft 时代由 Controller Quorum 承担，取代 ZooKeeper |
| **`__consumer_offsets`** | 内部 Topic，存各消费组的提交位点 | ⚠️ 默认 50 分区，组多、频繁提交会让它膨胀 |

### 铁律：⭐分区内有序，分区之间无序

| 事实 | 含义 |
| --- | --- |
| ⭐ 结论 | Kafka **只能保证「局部有序」**：单个 Partition 内按 append / offset 顺序严格 FIFO，**多个 Partition 之间无任何顺序保证** |
| 全局有序 | 需 Topic 只建 1 个分区，代价是并行度归零，一般不用 |
| 落地做法 | 用**业务 key**（如 orderId）做分区路由，`hash(key) % 分区数` 使同一实体永远落同一分区；生产端即 `new ProducerRecord<>(topic, key, value)`，key 不变 → 分区不变 → 该 key 有序 |

## 三、整体架构与存储

### 分区与副本的分布

每个分区 1 个 Leader + N 个 Follower，**Leader 读写、Follower 只拉取同步**（不对外服务）；分区与副本尽量**分散到不同 Broker**，单机宕机只丢该机上的 Leader（Controller 从 ISR 选新 Leader）；副本因子 `replication.factor` 决定冗余度，生产建议 **≥ 3**。

### 日志段（Segment）与稀疏索引

一个 Partition 的物理存储 = 一个目录，里面按**日志段**切分：

| 文件 | 作用 | 说明 |
| --- | --- | --- |
| `0000...000.log` | 真正的消息数据 | 文件名是**起始 offset**；写满 `log.segment.bytes`（默认 1 GiB）后滚动新段 |
| `0000...000.index` | **偏移量索引**（稀疏） | 存「相对 offset → 物理位置」，二分定位后再顺序扫 |
| `0000...000.timeindex` | **时间戳索引**（稀疏） | 支持按时间戳定位（位点按时间重置就靠它） |

> ⭐ **稀疏索引**是设计精髓：不记录每条消息的位置（太占空间），而是每隔若干字节记一条，查找时先用索引**二分定位到大致位置**，再在 `.log` 中**顺序扫描**，用极小的索引代价换取快速定位。

### ⭐ 为什么顺序写磁盘还能这么快

| 机制 | 原理 |
| --- | --- |
| **顺序写（append-only）** | 只往文件末尾追加，不做随机写；顺序磁盘写速度可媲美内存随机写 |
| **PageCache** | 写入先进 OS 页缓存即返回，内核异步刷盘；读也优先命中缓存（不占 JVM 堆、省 GC） |
| **零拷贝（sendfile）** | 消费时数据从 PageCache **直接**送网卡，跳过「内核 → 用户态 → 内核」两次拷贝与上下文切换 |
| **批量 + 压缩** | 生产者攒批（`batch.size` / `linger.ms`），整批落盘、整批压缩（snappy/lz4/zstd） |
| **分区并行** | 分区是多机并行的基本单位，吞吐随分区数 / Broker 数线性扩展 |

> ⚠️ 反过来：**分区数不是越多越好**。分区越多，文件句柄、内存索引、Controller 元数据、重平衡耗时都线性上升，单分区故障影响面也更大。

### ⚠️ ZooKeeper → KRaft 的演进

| 阶段 | 版本 | 说明 |
| --- | --- | --- |
| ZooKeeper 时代 | 0.8 ~ 2.x | 元数据、Controller 选举、ACL 全放 ZK；ZK 成为**扩展性瓶颈**（分区上万后重选举极慢） |
| 过渡期 | 2.8 / 3.0 ~ 3.x | 引入 **KRaft**（Kafka Raft），双轨运行，3.3 起生产可用 |
| KRaft 时代 | **4.0 起** | ⭐ **彻底移除 ZooKeeper**，元数据作为内部 Topic 用 Raft 管理，Controller 可多副本；组件少一半、变更更快，可支撑**百万级分区** |

## 四、消息可靠性 ⭐

端到端「不丢」必须在**生产者、Broker、消费者**三处同时配置，任何一处漏了都会丢。

### 4.1 生产者侧

| 配置 | 取值 | 语义 | 影响 |
| --- | --- | --- | --- |
| **`acks`** ⭐ | `0` | 发出即成功 | 最快、最可能丢 |
| | `1` | Leader 写入即成功 | 默认；Leader 刚写完就宕机则丢 |
| | `all`（=`-1`） | **ISR 全部同步完成**才成功 | 最可靠；须配合 `min.insync.replicas` |
| `retries` | 整数 | 可重试异常的重试次数 | 默认极大；由 `delivery.timeout.ms` 兜底 |
| **`enable.idempotence`** ⭐ | `true` | **幂等生产者**：按 `(PID, 分区, 序列号)` 去重 | 解决**重试导致的重复写**，是「不重」第一道防线 |
| 事务 | `transactional.id` | 跨分区**原子写** + 消费-处理-生产的 EOS | 有额外开销，主要用于流处理 |

> ⚠️ 三个易错点：① `acks=all` 只保证「ISR 都收到」，**不保证落盘**（是否刷盘由 OS 决定）；② 幂等只防**同一生产者实例的重试重复**，防不了业务重复提交与消费重复；③ `acks=all` 必须配 `min.insync.replicas≥2`，否则 ISR 只剩 Leader 时也满足「all」，形同 `acks=1`。

```java
// 生产端最稳组合（幂等 + 全 ISR 确认）
props.put("acks", "all");
props.put("enable.idempotence", true);   // 自动要求 acks=all 且 retries>0
props.put("max.in.flight.requests.per.connection", 5); // 开启幂等后可 >1 仍不乱序
```

### 4.2 Broker 侧

| 配置 | 作用 | 建议 |
| --- | --- | --- |
| **`replication.factor`** | 每分区副本总数 | 生产 **≥ 3**；单副本无冗余 |
| **`min.insync.replicas`** ⭐ | 写入时要求 ISR 至少多少副本 | **≥ 2**；ISR 不足时生产者抛 `NotEnoughReplicasException`（宁可拒写也不丢） |
| **`unclean.leader.election.enable`** ⚠️ | 是否允许**非 ISR 副本**当选 Leader | 默认 `false`；**改为 `true` 会丢数据**（落后副本顶替，其未同步消息永久丢失） |

> ⭐ 取舍口诀：**`acks=all` + `min.insync.replicas=2` + `replication.factor=3` + `unclean...=false` = 不丢但可能拒写；把 `unclean...` 开成 true = 不拒写但可能丢。**

### 4.3 消费者侧

| 配置 | 取值 | 语义 |
| --- | --- | --- |
| **`enable.auto.commit`** ⭐ | `false` | **手动提交**；默认为 `true` 会周期性自动提交，⚠️ 可能在业务处理**之前**就提交位点 → 处理失败即丢消息 |
| **`auto.offset.reset`** | `earliest` | 该组**无已提交位点**时，从头（最早）开始消费 |
| | `latest` | 无位点时只消费新消息（默认） |
| | `none` | 无位点直接抛异常，强制显式管理位点 |
| `isolation.level` | `read_committed` | 只读已提交的事务消息 |

> ⚠️ `auto.offset.reset` 只在**找不到已提交位点**时生效（新组、位点过期被清理、手动重置），**不是**「每次启动都从这里开始」——最高频的误解。

### 4.4 结论表：「不丢」与「不重」如何权衡 ⭐

| 目标 | 生产者 | Broker | 消费者 |
| --- | --- | --- | --- |
| **不丢（At Least Once）** | `acks=all` + `retries>0` + 确认回调 | `replication.factor≥3`、`min.insync.replicas≥2`、`unclean=false` | **先处理业务，成功后再 `commitSync`** |
| **不重（幂等）** | `enable.idempotence=true` | —— | **消费端幂等**：唯一键去重表 / Redis SETNX / 状态机 CAS |
| **恰好一次（流内近似）** | 事务 + `transactional.id` | `transaction.state.log.replication.factor≥3` | `isolation.level=read_committed` + 位点写入同一事务 |

> ⭐ 现实结论：Kafka 只能保证 At Least Once 的「不丢」，**「不重」必须靠消费端幂等**，「恰好一次」只在 Kafka 内部流处理链路（读 Topic → 写 Topic）严格成立；跨系统（写 MySQL / 发 HTTP）永远做不到，只能幂等等价实现。幂等的四种做法见 [消息队列选型.md](消息队列选型.md)。

## 五、消费者组与重平衡

### 分区分配策略

| 策略 | 原理 | 特点 |
| --- | --- | --- |
| **Range**（默认） | 按 Topic 逐一分区排序后均分 | ⚠️ 多 Topic 时**前面的消费者总多分**，负载不均衡 |
| **RoundRobin** | 所有订阅 Topic 的分区统一轮询分配 | 比 Range 均衡，但重平衡会打散已有分配 |
| **Sticky** | 尽量**保留原有分配**，只调整必要部分 | 减少重平衡后的「全量漂移」 |
| **CooperativeSticky** ⭐ | 粘性 + **增量协作式重平衡** | 只撤销需转移的分区，其他消费者**不停工**，当前推荐 |

> ⚠️ CooperativeSticky 需协议升级，升级期间会先经历一次 `EAGER`（全量）重平衡，之后才增量；老客户端连上来仍退回 EAGER。由 `partition.assignment.strategy` 配置。

### 重平衡触发条件与代价

| 触发条件 | 说明 |
| --- | --- |
| 组内成员**加入 / 离开** | 新实例启动、扩容、宕机、崩溃、优雅关闭 |
| **Topic 分区数变化** | 扩分区后必须重平衡才生效 |
| 消费者**心跳/拉取超时** | 被 Coordinator 判定为「死了」而踢出组 |

**为什么重平衡被称为「灾难」** ⚠️：① **Stop The World**——EAGER 下重平衡期间**全组停止消费**，组越大停顿越久；② **位点回退导致重复消费**——分区易主后从上次提交的位点继续，位点旧则重复处理；③ **消息积压**——停顿期间生产不停，结束后 LAG 陡增；④ **死循环风险**——消费者处理太慢反复超时被踢，形成「踢出 → 重平衡 → 又超时 → 又踢出」。

### ⚠️ `max.poll.interval.ms` vs `session.timeout.ms`（高频考点）

| 参数 | 默认值 | 含义 | 超时后果 |
| --- | --- | --- | --- |
| **`session.timeout.ms`** | 45s（3.0+） | **心跳**超时：多久没向 Coordinator 发心跳就算死 | 被移出组，触发重平衡 |
| **`max.poll.interval.ms`** | 5min | **两次 `poll()` 的最大间隔**，即单批消息处理时间上限 | 客户端**主动**离组，触发重平衡 |
| `heartbeat.interval.ms` | 3s | 心跳发送间隔 | 一般设为 `session.timeout.ms` 的 1/3 |

> ⭐ 一句话区分：**`session.timeout.ms` 管「你还活着吗」（心跳线程），`max.poll.interval.ms` 管「你的业务处理完了吗」（业务线程）。**
> ⚠️ 业务处理耗时长（批量入库、调外部接口）时最常踩 `max.poll.interval.ms`：调大它，或减小 `max.poll.records`，二者取其一。

### 如何减少重平衡

| 手段 | 说明 |
| --- | --- |
| **静态成员 `group.instance.id`** ⭐ | 给每个实例配稳定 ID，重启后在 `session.timeout.ms` 内回来可**保留原分配、不触发重平衡**（滚动发布神器） |
| 增大 `session.timeout.ms` | 容忍网络抖动与短暂 GC，代价是故障发现变慢 |
| 增大 `max.poll.interval.ms` / 减小 `max.poll.records` | 解决「处理慢被踢」 |
| 用 CooperativeSticky 策略 | 增量重平衡，不再全组停工 |

> 抗抖动 + 滚动发布不重平衡的配置要点：`group.instance.id=order-consumer-1`（每实例唯一且稳定）配合较大的 `session.timeout.ms` 与 `max.poll.interval.ms`，并把 `partition.assignment.strategy` 设为 `CooperativeStickyAssignor`。

## 六、部署

### docker-compose.yml（KRaft 模式，单节点）

```yaml
services:
  kafka:
    image: apache/kafka:3.9.0
    ports:
      - "9092:9092"
    environment:
      KAFKA_NODE_ID: 1                                        # 本节点同时是 broker 和 controller
      KAFKA_PROCESS_ROLES: broker,controller
      KAFKA_CONTROLLER_QUORUM_VOTERS: 1@kafka:9093
      KAFKA_CONTROLLER_LISTENER_NAMES: CONTROLLER
      KAFKA_LISTENERS: PLAINTEXT://:9092,CONTROLLER://:9093   # 9092 对外，9093 controller 内部
      KAFKA_ADVERTISED_LISTENERS: PLAINTEXT://localhost:9092
      KAFKA_LISTENER_SECURITY_PROTOCOL_MAP: CONTROLLER:PLAINTEXT,PLAINTEXT:PLAINTEXT
      KAFKA_OFFSETS_TOPIC_REPLICATION_FACTOR: 1               # ⚠️ 单节点内部 Topic 副本必须为 1
      KAFKA_TRANSACTION_STATE_LOG_REPLICATION_FACTOR: 1
      KAFKA_TRANSACTION_STATE_LOG_MIN_ISR: 1
      KAFKA_LOG_RETENTION_HOURS: 168                          # 消息保留 7 天
      KAFKA_NUM_PARTITIONS: 3                                 # 自动建 Topic 的默认分区数
      KAFKA_AUTO_CREATE_TOPICS_ENABLE: "true"                 # 生产环境建议 false
    volumes:
      - kafka-data:/var/lib/kafka/data

volumes:
  kafka-data:
```

启动后可用 `docker exec -it kafka /opt/kafka/bin/kafka-topics.sh --bootstrap-server localhost:9092 --list` 验证。

### 生产环境关键配置

| 配置 | 建议值 | 说明 |
| --- | --- | --- |
| `log.retention.hours` | `168`（7 天） | 保留时长；与 `log.retention.bytes`（按盘定）**先到者**触发删除 |
| `log.segment.bytes` | `1 GiB` | 段大小；决定删除的最小粒度，太小文件多、太大删除不灵活 |
| `log.cleanup.policy` | `delete` / `compact` | `delete` 按时间/大小删；`compact` 按 key 保留最新值（状态类 Topic） |
| `default.replication.factor` | `3` | 默认副本数，**生产必须 > 1** |
| `auto.create.topics.enable` | `false` | ⚠️ 生产关闭，避免拼错 Topic 名自动建垃圾 Topic |
| `min.insync.replicas` / `unclean.leader.election.enable` | `2` / `false` | 与 `acks=all` 配套，不允许非 ISR 副本上位 |

### 分区数怎么定 ⭐

| 原则 | 说明 |
| --- | --- |
| **上限由消费者数决定** | 分区数决定消费并行的天花板：**消费者实例数 > 分区数时，多出的实例拿不到分区、纯空闲** |
| 按目标吞吐反推 | 单分区吞吐参考约 10 MB/s，分区数 ≈ 目标吞吐 ÷ 单分区吞吐 |
| ⚠️ 别开太多 | 分区多则文件句柄、内存、Controller 元数据、重平衡耗时全部上升，故障影响面变大 |
| ⚠️ **只能增不能减** | 分区数**无法减少**；扩容后 key→分区 哈希映射会变（见第九节），顺序性可能被破坏；按「未来 1~2 年峰值」一次预估到位 |

## 七、使用一：Go ⭐

### 客户端三选一

| 客户端 | 依赖 | 幂等/事务 | 特点与取舍 |
| --- | --- | --- | --- |
| **`github.com/segmentio/kafka-go`** ⭐ | 纯 Go，**无 cgo** | 幂等支持有限 | API 简洁、静态二进制部署无痛；**推荐日常业务首选** |
| `github.com/IBM/sarama` | 纯 Go | ⭐ 支持幂等与事务 | 功能最全、社区大；⚠️ **原名 `Shopify/sarama`，已迁移到 `github.com/IBM/sarama`**，别再写过时路径 |
| `github.com/confluentinc/confluent-kafka-go` | **依赖 cgo + librdkafka** | ⭐ 支持幂等与事务 | 最贴近 Java 客户端、性能最好；⚠️ 交叉编译与部署要带 C 运行库，镜像变大 |

安装：`go get github.com/segmentio/kafka-go`；需要幂等/事务时改用 `github.com/IBM/sarama`，追求极致性能时用 `github.com/confluentinc/confluent-kafka-go/v2`。

### 生产者

```go
package main

import (
	"context"
	"log"
	"time"

	"github.com/segmentio/kafka-go"
)

func main() {
	w := &kafka.Writer{
		Addr:  kafka.TCP("127.0.0.1:9092"),
		Topic: "order-events",
		// Hash 均衡器：同 key 走同一分区，保证「同一实体的事件有序」
		Balancer:     &kafka.Hash{},
		RequiredAcks: kafka.RequireAll,      // 对应 acks=all（默认 RequireNone）
		BatchTimeout: 10 * time.Millisecond, // 攒批窗口，牺牲一点延迟换吞吐
	}
	defer w.Close()
	ctx := context.Background()
	if err := w.WriteMessages(ctx, // 同 key 的消息会被攒进同一批
		kafka.Message{Key: []byte("ORDER_1001"), Value: []byte(`{"event":"created"}`)},
		kafka.Message{Key: []byte("ORDER_1001"), Value: []byte(`{"event":"paid"}`)},
	); err != nil {
		log.Fatalf("write failed: %v", err) // acks=all 下失败即真失败，应重试
	}
}
```

> ⚠️ `kafka-go` 的 `Writer` 不暴露 `enable.idempotence`，**要严格的幂等/事务语义请换 sarama 或 confluent-kafka-go**；日常业务用失败重试 + 消费端幂等即可。

### 消费者（含消费者组 + 手动提交）

```go
package main

import (
	"context"
	"log"

	"github.com/segmentio/kafka-go"
)

func main() {
	// 配 GroupID 即「消费组模式」：组内自动分配分区、自动存位点
	r := kafka.NewReader(kafka.ReaderConfig{
		Brokers:        []string{"127.0.0.1:9092"},
		GroupID:        "order-consumer-group", // 留空则变「独立消费者」，需自行指定 Partition
		Topic:          "order-events",
		CommitInterval: 0,                 // ⭐ 0 = 关闭自动提交，改为手动
		StartOffset:    kafka.FirstOffset, // 组内无位点时从头消费（等价 earliest）
	})
	defer r.Close()

	ctx := context.Background()
	for {
		m, err := r.ReadMessage(ctx) // 内部 Fetch + 维护位点，配合下方手动 Commit
		if err != nil {
			log.Printf("read stopped: %v", err)
			break
		}
		// ⭐ 先处理业务，成功后提交位点，避免「提交了但没处理」丢消息
		if err := handle(m); err != nil {
			log.Printf("handle failed, will retry: %v", err)
			continue // 不提交 → 重启后重复消费，靠消费端幂等兜底
		}
		if err := r.CommitMessages(ctx, m); err != nil {
			log.Printf("commit failed: %v", err)
		}
	}
}

func handle(m kafka.Message) error { // 业务幂等处理：先查去重表 / 状态机校验，再落库
	log.Printf("partition=%d offset=%d key=%s", m.Partition, m.Offset, m.Key)
	return nil
}
```

> ⭐ `ReadMessage` 会**自动提交**（受 `CommitInterval` 控制），`FetchMessage` **不提交**；需要「处理成功再提交」时用 `CommitInterval: 0`。
> ⚠️ 生产环境应监听 `SIGTERM`，先停止拉取、处理完在途消息再 `r.Close()`（`Close` 会提交一次位点）优雅退出。

## 八、使用二：Java ⭐

### 依赖坐标（Maven）

```xml
<dependency>
  <groupId>org.apache.kafka</groupId>
  <artifactId>kafka-clients</artifactId>
  <version>3.9.0</version>   <!-- 版本尽量与 Broker 大版本对齐 -->
</dependency>
<dependency>
  <groupId>org.springframework.kafka</groupId>
  <artifactId>spring-kafka</artifactId>
  <version>3.3.0</version>
</dependency>
```

### 原生 Producer（send + 回调）

```java
import org.apache.kafka.clients.producer.*;
import java.util.Properties;

public class OrderProducerDemo {
    public static void main(String[] args) {
        Properties props = new Properties();
        props.put("bootstrap.servers", "127.0.0.1:9092");
        props.put("key.serializer", "org.apache.kafka.common.serialization.StringSerializer");
        props.put("value.serializer", "org.apache.kafka.common.serialization.StringSerializer");
        props.put("acks", "all");               // ISR 全部确认
        props.put("enable.idempotence", true);  // 幂等生产者，防重试重复
        props.put("retries", Integer.MAX_VALUE);

        try (Producer<String, String> producer = new KafkaProducer<>(props)) {
            // key = 业务主键 → 同 key 同分区，该实体的事件有序
            ProducerRecord<String, String> record =
                    new ProducerRecord<>("order-events", "ORDER_1001", "{\"event\":\"created\"}");
            // 异步发送 + 回调：不阻塞主线程又能感知失败，生产环境推荐
            producer.send(record, (metadata, ex) -> {
                if (ex != null) { System.err.println("send failed: " + ex.getMessage()); }
                else { System.out.printf("ok p=%d offset=%d%n", metadata.partition(), metadata.offset()); }
            });
            producer.flush();   // 确保回调与未完成请求都处理完再退出
        }
    }
}
```

### 原生 Consumer（poll 循环 + 手动提交）

```java
import org.apache.kafka.clients.consumer.*;
import java.time.Duration;
import java.util.*;

public class OrderConsumerDemo {
    public static void main(String[] args) {
        Properties props = new Properties();
        props.put("bootstrap.servers", "127.0.0.1:9092");
        props.put("group.id", "order-consumer-group");  // 消费组
        props.put("enable.auto.commit", "false");       // ⭐ 关闭自动提交
        props.put("auto.offset.reset", "earliest");     // 无位点则从头消费
        props.put("key.deserializer", "org.apache.kafka.common.serialization.StringDeserializer");
        props.put("value.deserializer", "org.apache.kafka.common.serialization.StringDeserializer");

        try (KafkaConsumer<String, String> consumer = new KafkaConsumer<>(props)) {
            consumer.subscribe(Collections.singletonList("order-events"));
            while (true) {
                ConsumerRecords<String, String> records = consumer.poll(Duration.ofMillis(500));
                for (ConsumerRecord<String, String> r : records) {
                    handle(r);   // 1) 先逐条处理业务（含幂等校验）
                }
                // 2) 整批处理成功后手动同步提交（提交的是「下一条要读的位点」）
                consumer.commitSync();
            }
        }
    }

    // 业务处理 + 幂等（唯一键去重 / 状态机 CAS）
    private static void handle(ConsumerRecord<String, String> r) { }
}
```

> ⚠️ `commitSync()` 提交的是**下次要读的位点**（本批最大 offset + 1），想按分区精确提交可传入 `Map<TopicPartition, OffsetAndMetadata>`；`commitSync` 阻塞且失败自动重试，`commitAsync` 不阻塞但不重试——常见做法是循环内 `commitAsync`、关闭前 `commitSync` 兜底。

### Spring Kafka：KafkaTemplate + @KafkaListener

```yaml
spring:
  kafka:
    bootstrap-servers: 127.0.0.1:9092
    producer:
      acks: all
      retries: 2147483647
      properties:
        enable.idempotence: true        # 开启幂等后不要再手动改 max.in.flight
    consumer:
      group-id: order-consumer-group
      enable-auto-commit: false         # ⭐ 交给容器按 AckMode 提交
      auto-offset-reset: earliest
    listener:
      ack-mode: manual_immediate        # ⭐ 手动提交，处理完立即提交
      concurrency: 3                    # 并发消费者线程数，不要超过分区数
```

```java
// 生产：KafkaTemplate 异步发送 + 回调
@Service
public class OrderProducerService {
    private final KafkaTemplate<String, String> kafkaTemplate;
    public OrderProducerService(KafkaTemplate<String, String> t) { this.kafkaTemplate = t; }
    public void publish(OrderDTO order) {
        template.send("order-events", order.getOrderId(), order.toJson())  // key=orderId ⇒ 同分区
                .whenComplete((result, ex) -> {
                    if (ex != null) { System.err.println("send failed: " + ex.getMessage()); }
                    else {
                        var md = result.getRecordMetadata();
                        System.out.printf("ok p=%d offset=%d%n", md.partition(), md.offset());
                    }
                });
    }
}

// 消费：@KafkaListener + 手动 ack（处理成功才提交位点）
@Service
public class OrderConsumerService {
    @KafkaListener(topics = "order-events", groupId = "order-consumer-group")
    public void onMessage(ConsumerRecord<String, String> record, Acknowledgment ack) {
        handle(record);        // 业务处理 + 幂等
        ack.acknowledge();     // ⭐ 处理成功才提交
    }
    private void handle(ConsumerRecord<String, String> record) { /* 幂等处理 */ }
}
```

并发消费由 `ConcurrentKafkaListenerContainerFactory#setConcurrency`（或 yaml 的 `listener.concurrency`）控制，**不要超过分区数**；手动提交则设 `ContainerProperties.AckMode.MANUAL_IMMEDIATE`，与下面的错误处理一起挂到监听容器。

```java
// 错误处理 + 死信：重试 3 次仍失败则投递到 order-events.DLT
var recoverer = new DeadLetterPublishingRecoverer(kafkaTemplate,
        (rec, ex) -> new TopicPartition(rec.topic() + ".DLT", rec.partition()));
var errorHandler = new DefaultErrorHandler(recoverer, new FixedBackOff(1000L, 3));
errorHandler.addNotRetryableExceptions(IllegalArgumentException.class); // 非法参数直接进死信
containerFactory.setCommonErrorHandler(errorHandler);                   // ⭐ 挂到监听容器
```

| AckMode | 提交时机 | 适用 |
| --- | --- | --- |
| `RECORD` | 每条消息处理完提交 | 简单可靠，提交频繁 |
| `BATCH`（默认） | 一批 `poll` 记录全部处理完再提交 | 吞吐好，⚠️ 批内失败会整批重复 |
| `MANUAL` | 调用 `acknowledge()` 后，**下次 poll 时**提交 | 需配合容器时序 |
| `MANUAL_IMMEDIATE` ⭐ | 调用 `acknowledge()` **立即**提交 | 最精确，配合手动 ack 使用 |

> ⚠️ Spring Kafka 的 `@KafkaListener` 出错时默认**无限重试**（旧版 `SeekToCurrentErrorHandler` 行为），上线即可能卡死分区；必须显式配置 `DefaultErrorHandler` + `FixedBackOff` + 死信兜底，否则一条脏数据能堵死整个分区。

## 九、运维与常见问题

### 分区扩容 ⚠️

| 事项 | 说明 |
| --- | --- |
| 只能增不能减 | ⚠️ Kafka **不支持减少分区数**（减少需重建 Topic + 迁移数据） |
| 增加分区会破坏映射 | 分区算法是 `hash(key) % 分区数`，**分母一变几乎全部 key 都会换分区** → 同 key 有序的消息被打散 |
| 影响 | ① 顺序性被破坏；② 同 key 的历史与新消息可能落不同分区；③ 立即触发重平衡 |
| 建议 | 尽量**一开始预估到位**；必须扩时接受顺序性损失，或改用「新 Topic 双写 + 逐步切换」 |

```bash
kafka-topics.sh --bootstrap-server localhost:9092 --alter --topic order-events --partitions 6
```

### 消息积压排查 ⭐

```bash
# ⭐ LAG = 未消费消息数，最核心的堆积指标
kafka-consumer-groups.sh --bootstrap-server localhost:9092 --describe --group order-consumer-group
# 输出：TOPIC / PARTITION / CURRENT-OFFSET / LOG-END-OFFSET / LAG / CONSUMER-ID / HOST / CLIENT-ID
```

| LAG 现象 | 可能原因 | 处理 |
| --- | --- | --- |
| 单分区 LAG 高 | 该分区 key 热点（某大客户订单集中） | 检查 key 设计，拆分热点 key |
| 所有分区 LAG 齐涨 | 消费能力不足 | 提并发（≤ 分区数）、批量消费、下游异步化 |
| LAG 忽高忽低 / 重平衡频繁且不降 | 慢调用、GC 抖动，或 `max.poll.interval.ms` 超时循环 | 查下游 RT 与 GC；调大该值或减小 `max.poll.records` |
| 消费者数为 0 | 实例全挂或位点过期被踢 | 查实例存活与 `session.timeout.ms` |

> ⚠️ 扩容顺序：**先确认分区数 > 消费者数**，否则加实例无效。

### 重复消费

Kafka 只保证 At Least Once，以下场景必然重复：消费者处理完但**提交位点前崩溃**、重平衡后位点回退、`auto.offset.reset` 误配。幂等必须落在**消费端**（唯一键去重表 / Redis SETNX / 状态机 CAS / DB 唯一索引，详见 [消息队列选型.md](消息队列选型.md)），且 ⚠️ 用**业务唯一键**做去重，不要用 offset 当幂等键。

### 顺序性被破坏的常见原因

| 原因 | 说明 |
| --- | --- |
| 生产端未指定 key（或 key 为空） | 消息轮询散落到各分区，同实体事件乱序 |
| 生产端重试 + `max.in.flight > 1` | ⚠️ 请求 1 失败重试、请求 2 成功，落盘顺序反了；开 `enable.idempotence=true` 可保证重试不乱序 |
| 消费端多线程 / 异步处理同一分区 | 单分区内被并发消费，顺序不可控；需有序时单分区**串行**处理 |
| 分区扩容 / 消费端失败后跳过消息 | `hash % n` 分母变化导致同 key 换分区；或失败被跳过、后续消息先被处理 |

### `__consumer_offsets` 膨胀与 retention

| 事项 | 说明 |
| --- | --- |
| 是什么 | 内部 Topic，存每个 `(消费组, 分区)` 的提交位点，默认 50 分区 |
| 膨胀原因 | 消费组多、提交过于频繁（每条消息都 commit）、`offsets.retention.minutes` 过长 |
| 表现 | 磁盘占用高、Controller 元数据变大、启动变慢 |
| 处理 | 调小 `offsets.retention.minutes`（默认 7 天）、减少提交频率、清理不用组；`compact` 策略按 key 保留最新位点 |

> ⚠️ 磁盘使用率超阈值会导致**拒绝写入**、生产者报错、LAG 飞涨；务必监控磁盘水位并预留 ≥ 30% 空间。

## 十、面试官会追问什么

1. **Kafka 为什么快？** 顺序写磁盘（append-only，免随机 IO）+ PageCache（写先落页缓存，读优先命中）+ 零拷贝 sendfile（数据从页缓存直送网卡）+ 批量发送与整批压缩 + 分区水平并行 + 稀疏索引快速定位。**只说「磁盘顺序写」不完整，要凑齐这条链。**
2. **ISR 是什么？为什么要它？** 与 Leader 保持同步（未落后超过 `replica.lag.time.max.ms`）的副本集合，**含 Leader 自身**。`acks=all` 的「all」指 ISR 而非全部副本，所以必须配 `min.insync.replicas≥2`。它是在**可用性与一致性**间折中：只从 ISR 选新 Leader，避免落后副本上位丢数据。
3. **`acks=all` 就不会丢消息吗？** 不一定。① 不保证**落盘**（OS 崩溃仍可能丢）；② 若 `min.insync.replicas=1` 且 ISR 只剩 Leader，等价 `acks=1`；③ 生产端未处理发送异常仍会丢。需三处齐配：`acks=all` + `min.insync.replicas≥2` + `replication.factor≥3` + `unclean=false` + 消费端先处理后提交。
4. **重平衡为什么被称为「灾难」？** EAGER 下是 **Stop The World**：全组停止消费、撤销分区再重分，期间消息持续堆积；分区易主后从旧位点继续导致重复消费；处理慢反复超时被踢会形成「踢出 → 重平衡 → 再超时」死循环。缓解：静态成员 `group.instance.id`、CooperativeSticky、合理设置两个超时参数。
5. **分区数怎么定？** ① 不低于预期消费者实例数（否则实例空闲）；② 按「目标吞吐 ÷ 单分区吞吐（约 10 MB/s）」反推；③ 不宜过多（句柄、内存、元数据、重平衡耗时随分区数上升）；④ 一次留余量；⑤ ⚠️ **只能增不能减**，扩容会破坏 key→分区映射、影响顺序性。
6. **Kafka 能保证全局有序吗？** 不能（除非 Topic 只有 1 个分区，代价是并行度归零），只保证**分区内有序**。做法：生产端用业务 key 哈希路由 + 消费端对该分区串行处理。⚠️ 生产端重试 + `max.in.flight>1` 也可能乱序，需开 `enable.idempotence=true`。
7. **`max.poll.interval.ms` 和 `session.timeout.ms` 有什么区别？** 前者是**两次 `poll()` 的最大间隔**（业务处理一批消息的最长时间，超时客户端主动离组，管「处理进度」）；后者是**心跳**超时（心跳线程报「我还活着」，超时被移出组，管「存活」）。业务处理慢时最先踩的是前者。
8. **`enable.auto.commit=true` 有什么风险？** 它按 `auto.commit.interval.ms` 周期提交，可能在**业务处理成功之前**就提交了位点；若此时消费者崩溃，重启后从已提交位点继续 → 那批消息永久丢失。所以高可靠场景一律 `enable.auto.commit=false` + 处理成功后手动 `commitSync`。

## 关联

- [消息队列选型.md](消息队列选型.md) — 四款 MQ 的横向对比与决策
- [RocketMQ.md](RocketMQ.md) — 存储与可靠性设计的另一种路线
- [../../数据存储/mysql/日志与落盘.md](../../数据存储/mysql/日志与落盘.md) — 顺序追加与页缓存
- [../../分布式/一致性与CAP.md](../../分布式/一致性与CAP.md) — ISR 与多数派确认的区别
