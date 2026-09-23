# RocketMQ 消息中间件

> 覆盖 RocketMQ 的定位与架构、消息类型语义、Java/Go 双端使用示例、可靠性机制与部署运维要点。
>
> 内容整理自个人学习笔记。消息队列的通用价值与选型对比见 [消息队列选型.md](消息队列选型.md)。

## 一、一句话定位与适用场景

RocketMQ 是阿里 2012 年开源、2016 年捐给 Apache（2017 年成为顶级项目）的分布式消息中间件，纯 Java 实现，单机十万级 TPS、支持万级队列；**原生支持延迟消息、事务消息、消息轨迹**，这是它与 Kafka / RabbitMQ 最直接的差异点。选型细节见 [消息队列选型.md](消息队列选型.md)。

| 场景 | 落点 |
|---|---|
| 削峰填谷 | 大促流量写 Topic，消费者按自身能力匀速拉取，堆积由 Broker 承担 |
| 系统解耦 | 上游只发消息，下游各自订阅同一 Topic，新增下游不改上游代码 |
| 异步处理 | 下单后发消息，积分/短信/风控异步消费，主链路 RT 大幅下降 |
| 最终一致性 | 事务消息把「本地事务」与「消息投递」绑定为最终一致 |
| 延迟消息 | 订单 30 分钟未支付自动关单、延迟重试、定时通知，不必写扫表定时任务 |

## 二、核心架构

### 四大角色

| 角色 | 职责 | 状态 | 关键点 |
|---|---|---|---|
| **NameServer** | 路由注册中心：Broker 上报 Topic/队列信息，客户端按 Topic 拉路由 | ❌ 无状态，数据在内存 | 节点之间**互不通信**，可任意扩容；默认端口 9876 |
| **Broker** | 消息存储与转发：CommitLog 落盘、ConsumeQueue 索引、长轮询投递 | ✅ 落盘 | 分 Master/Slave（`brokerId=0` 为 Master，>0 为 Slave）；默认端口 10911 |
| **Producer** | 取路由、按策略选队列发送、失败换 Broker 重试 | — | 与业务同进程；ProducerGroup 事务消息必需 |
| **Consumer** | 拉取消息并消费、提交位点 | 位点存 Broker | PushConsumer 本质是「长轮询 + 回调」，并非真正推送 |

消息流转：`Producer → NameServer 取路由 → Broker 写 CommitLog → ConsumeQueue 建索引 → Consumer 长轮询拉取 → 提交位点`。发送策略默认**轮询**，开启 `sendLatencyFaultEnable` 后变为「延迟故障规避 + 随机」，对慢 Broker 自动降权。

### 核心概念关系

| 概念 | 说明 | 类比 Kafka |
|---|---|---|
| **Topic** | 消息的逻辑分类，由多个队列组成 | Topic |
| **MessageQueue（Queue）** | Topic 的最小并行单元，`queueId` 从 0 开始；**一个队列同一时刻只能被同消费组内一个消费者消费** | Partition |
| **Tag / Key** | Tag 是二级分类（Broker 端过滤，只有一个）；Key 是业务唯一标识（建索引供 `mqadmin queryMsgByKey` 排障） | ❌ 无 |
| **Offset** | 队列内消费位点；集群模式存 Broker，广播模式存消费者本地 | Offset |
| **ConsumerGroup** | 集群模式下组内平分队列，一条消息只被组内一个实例消费 | Group |

### 为什么 NameServer 比 ZooKeeper 轻

| 维度 | NameServer | ZooKeeper（Kafka 旧版用法） |
|---|---|---|
| 一致性模型 | AP，最终一致（客户端本地缓存 + 30s 定期拉取） | CP，ZAB 协议强一致 |
| 节点关系 | 各节点独立无通信、无主无选举，信息纯内存不落盘 | 需选主，半数以上存活才可写，ZNode + 事务日志 |
| 故障影响 | 单节点挂掉不影响其余节点，客户端用本地缓存路由继续收发 | 半数以下存活则整体不可用 |
| 运维成本 | 无状态，部署即用，可任意加节点 | 需维护集群、磁盘、会话、GC |

⭐ 设计取舍：路由数据**允许短暂不一致**（Broker 上下线延迟几十秒可接受），所以不需要一致性协议。Broker 每 30s 向**所有** NameServer 注册心跳，NameServer 每 10s 扫描 `brokerLiveTable`，超过 120s 未心跳则摘除。Kafka 也正因 ZK 在分区数增大时成为瓶颈，才在 3.0 后用 KRaft 取代 ZK。

## 三、消息类型全景 ⭐

| 类型 | 核心语义 | 消费方式 | 关键 API |
|---|---|---|---|
| **普通消息** | 无顺序保证，吞吐最高 | `MessageListenerConcurrently` | `producer.send(msg)` |
| **顺序消息** | 同 key 严格 FIFO，分区顺序 / 全局顺序 | `MessageListenerOrderly` | `send(msg, selector, arg)` |
| **延迟消息** | 4.x 18 个固定级别；5.x 任意时间 | 同普通消息 | `setDelayTimeLevel(n)` / `setDelayTimeMs()` |
| **事务消息** | 本地事务与消息投递最终一致 | 同普通消息 | `TransactionMQProducer` |
| **批量消息** | 多条小消息合并发送，减少 RPC 次数 | 同普通消息 | `send(Collection<Message>)` |
| **消息轨迹** | 生产/存储/消费全链路轨迹，写系统 Topic | — | `setEnableMsgTrace(true)` |

### 顺序消息

- **全局顺序**：Topic 只建 1 个队列，Producer 单线程发、Consumer 单线程收（吞吐低，仅强合规场景用）。
- **分区顺序（生产常用）**：队列数 > 1，用 `MessageQueueSelector` 把**同一业务 key**（如 orderId）路由到**同一队列**，消费者用 `MessageListenerOrderly` 按队列加锁消费。
- ⚠️ 限制：① 队列数确定后不能再改（扩容会让同一 key 落不同队列）；② 顺序消息**不支持广播模式**；③ 必须用 `send(msg, selector, arg)`，普通 `send` 会破坏顺序；④ `MessageListenerOrderly` 消费失败默认**无限重试并阻塞该队列**（`SUSPEND_CURRENT_QUEUE_A_MOMENT`）以保证不跳号，脏数据会卡死整条队列。

### 延迟消息：4.x 与 5.x 的分水岭

**4.x：18 个固定延迟级别**（level 从 1 开始，无 level 0，可通过 Broker 配置 `messageDelayLevel` 自定义）。实现上消息先写 CommitLog，再改投到系统 Topic `SCHEDULE_TOPIC_XXXX`（每个级别一个队列），`ScheduleMessageService` 按级别定时扫描，到期后写回真实 Topic。

| Level | 1 | 2 | 3 | 4 | 5 | 6 | 7 | 8 | 9 | 10 | 11 | 12 | 13 | 14 | 15 | 16 | 17 | 18 |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| 延迟 | 1s | 5s | 10s | 30s | 1m | 2m | 3m | 4m | 5m | 6m | 7m | 8m | 9m | 10m | 20m | 30m | 1h | 2h |

**5.x：任意时间定时消息**（RIP-43），系统 Topic 为 `rmq_sys_wheel_timer`，基于 TimerLog + 多级时间轮：

```java
Message msg = new Message("TopicDelay", body);
msg.setDelayTimeSec(10);                                     // 10s 后投递
msg.setDelayTimeMs(10_000L);                                 // 毫秒版，效果同上
msg.setDeliverTimeMs(System.currentTimeMillis() + 10_000L);  // 定时到某个时间点投递
```

| 维度 | 4.x 固定级别 | 5.x 任意时间 |
|---|---|---|
| 灵活性 / 精度 | 只能选 18 个档位，秒级 | 毫秒级任意时间，默认精度 `timerPrecisionMs=1000` |
| 上限 | 2h（改 `messageDelayLevel`） | `timerMaxDelaySec`，源码默认 3 天，超出报 `timer message illegal` |
| 实现 / 客户端 | `SCHEDULE_TOPIC_XXXX` + 定时扫描；`setDelayTimeLevel` | `rmq_sys_wheel_timer` + 时间轮；`setDelayTimeSec/Ms`、`setDeliverTimeMs`（需 5.0.0+ 客户端 + 5.x Broker） |

⚠️ 4.x 的 `setDelayTimeLevel(0)` 会抛异常；5.x 用 `setDelayTimeMs` 时 Broker 需开启定时消息能力，否则消息立即投递。

### 事务消息（两阶段 + 回查）

1. Producer 发送**半消息（Half Message）**：对消费者不可见，写入系统 Topic `RMQ_SYS_TRANS_HALF_TOPIC`；
2. Broker ACK 后 Producer 执行**本地事务**，再按结果发送 **Commit / Rollback**：Commit 把消息转入真实 Topic 并可见，Rollback 删除半消息；
3. 若 Broker 长时间收不到结果（Producer 回 `UNKNOW`、宕机或断网），`TransactionalMessageCheckService` **每 60s 扫描**超时半消息，回查 Producer 的 `checkLocalTransaction`，默认最多 **15 次**，仍未决则丢弃并打日志。

⭐ 三个本地事务状态：`COMMIT_MESSAGE`（提交，消息可见）、`ROLLBACK_MESSAGE`（回滚，半消息删除）、`UNKNOW`（等 Broker 回查）。
⚠️ 事务消息是**最终一致**而非强一致：回查耗尽仍可能丢消息，关键业务要对账补偿；也不支持延迟与批量。

### 批量消息、过滤与消息轨迹

- **批量**：`producer.send(Collection<Message>)`，同批次 Topic 必须一致，**不支持延迟与事务**；受 Broker `maxMessageSize`（默认 4 MiB）约束，官方建议单批不超过 1 MiB。
- **过滤**：Tag 过滤在 Broker 端完成（ConsumeQueue 存 tagCode，性能好）；SQL92 过滤需 Broker 开 `enablePropertyFilter=true`，用法 `MessageSelector.bySql("age > 18")`，⚠️ 代价是拉取放大。
- **消息轨迹**：Broker 开 `traceTopicEnable=true`，生产/消费端 `setEnableMsgTrace(true)`，轨迹写 `RMQ_SYS_TRACE_TOPIC`，可在 Dashboard 看全链路耗时。

## 四、使用一：Java ⭐

### 依赖坐标

```xml
<dependency>
  <groupId>org.apache.rocketmq</groupId>
  <artifactId>rocketmq-client</artifactId>
  <version>5.3.1</version>   <!-- 仍在 4.x Broker 上可退用 4.9.7 -->
</dependency>
<!-- Spring 集成：org.apache.rocketmq:rocketmq-spring-boot-starter:2.3.1（内部依赖 client 5.x） -->
<!-- 命令行工具：org.apache.rocketmq:rocketmq-tools:5.3.1（提供 mqadmin） -->
```

⭐ **5.x 客户端可连 4.9.4+ Broker**（兼容协议），但任意时间延迟等新特性必须「5.x 客户端 + 5.x Broker」同时满足。

### 普通消息：生产（同步 / 异步 / 单向）

```java
public class NormalProducerDemo {
    public static void main(String[] args) throws Exception {
        DefaultMQProducer producer = new DefaultMQProducer("producer_group_demo");
        producer.setNamesrvAddr("127.0.0.1:9876");
        producer.setRetryTimesWhenSendFailed(2);  // 失败重试 2 次（共 3 次尝试）
        producer.start();

        Message msg = new Message("TopicTest", "TagA", "key-1001", "hello rocketmq".getBytes());
        // 1) 同步发送：有返回值，可靠性最高
        SendResult r = producer.send(msg);
        // 2) 异步发送：不阻塞主线程，回调处理结果，适合高吞吐
        producer.send(msg, new SendCallback() {
            @Override public void onSuccess(SendResult result) { }
            @Override public void onException(Throwable e) { }
        });
        // 3) 单向发送：只写 socket 不等响应，最快但可能丢，适合日志采集
        producer.sendOneWay(msg);
        producer.shutdown();
    }
}
```

异常处理：`MQClientException`（未 start / 路由为空 / 消息体超限 / Topic 未创建）、`RemotingException`（网络断连）、`MQBrokerException`（Broker 错误码，如 `SERVICE_NOT_AVAILABLE`）、`InterruptedException`（线程中断）。
⚠️ 重试只发生在**发送阶段**且是「换队列/换 Broker 重发」；Broker 已成功但客户端超时同样会重发，因此**消费端幂等必须有**。

### 普通消息：消费（并发监听）

```java
// 片段：方法体内
DefaultMQPushConsumer consumer = new DefaultMQPushConsumer("consumer_group_demo");
consumer.setNamesrvAddr("127.0.0.1:9876");
consumer.setConsumeThreadMax(32);   // ⚠️ 超过队列总数即无意义
consumer.setMaxReconsumeTimes(3);   // 重试 3 次后进死信队列
consumer.subscribe("TopicTest", MessageSelector.byTag("TagA || TagB"));

consumer.registerMessageListener((MessageListenerConcurrently) (msgs, context) -> {
    for (MessageExt msg : msgs) {
        try {
            System.out.printf("msgId=%s body=%s%n", msg.getMsgId(), new String(msg.getBody()));
        } catch (Exception e) {
            return ConsumeConcurrentlyStatus.RECONSUME_LATER;   // 稍后重投 %RETRY%group
        }
    }
    return ConsumeConcurrentlyStatus.CONSUME_SUCCESS;
});
consumer.start();
```

| 配置项 | 默认值 | 说明 |
|---|---|---|
| `consumeFromWhere` | `CONSUME_FROM_LAST_OFFSET` | 消费组首次启动位点；已有位点时该配置无效 |
| `messageModel` | `CLUSTERING` | 集群模式；`BROADCASTING` 每个实例消费全量，位点存本地文件 |
| `consumeThreadMin/Max` | 20 / 64 | 消费线程池，受队列数上限约束（一队列只分配一个线程） |
| `maxReconsumeTimes` | 16（并发） | 超限后进 `%DLQ%{group}` 死信队列 |

### 顺序消息完整示例

```java
// 片段：生产者，同一 orderId 固定落同一队列
DefaultMQProducer producer = new DefaultMQProducer("order_producer_group");
producer.setNamesrvAddr("127.0.0.1:9876");
producer.start();
String orderId = "ORDER_20260924_001";
Message msg = new Message("TopicOrder", "CREATE", orderId, ("下单:" + orderId).getBytes());
// 业务 key 取模选队列：队列数不变时同一 orderId 永远落同一队列
MessageQueueSelector selector = (mqs, m, arg) -> mqs.get(Math.abs(arg.hashCode()) % mqs.size());
producer.send(msg, selector, orderId);

// 片段：消费者，顺序消费
DefaultMQPushConsumer consumer = new DefaultMQPushConsumer("order_consumer_group");
consumer.setNamesrvAddr("127.0.0.1:9876");
consumer.subscribe("TopicOrder", "*");
consumer.registerMessageListener((MessageListenerOrderly) (msgs, context) -> {
    for (MessageExt msg : msgs) {
        try {
            System.out.printf("queueId=%d body=%s%n", msg.getQueueId(), new String(msg.getBody()));
        } catch (Exception e) {
            return ConsumeOrderlyStatus.SUSPEND_CURRENT_QUEUE_A_MOMENT;   // 挂起队列重试，不跳号
        }
    }
    return ConsumeOrderlyStatus.SUCCESS;
});
consumer.start();
```

⚠️ 顺序消费失败无限重试，必须在**有限次后落库告警**，否则脏数据卡死队列。

### 事务消息完整示例

```java
public class TxProducerDemo {
    public static void main(String[] args) throws Exception {
        TransactionMQProducer producer = new TransactionMQProducer("tx_producer_group");
        producer.setNamesrvAddr("127.0.0.1:9876");
        producer.setExecutorService(Executors.newFixedThreadPool(4));  // 回查线程池，勿与发送共用
        producer.setTransactionListener(new OrderTxListener());
        producer.start();

        Message msg = new Message("TopicTx", "PAY", "ORDER_1001", "支付成功通知".getBytes());
        msg.putUserProperty("orderId", "ORDER_1001");   // 回查时靠它反查本地事务
        TransactionSendResult result = producer.sendMessageInTransaction(msg, "ORDER_1001");
        System.out.println("tx state=" + result.getLocalTransactionState());
        producer.shutdown();
    }
}

class OrderTxListener implements TransactionListener {
    // 第二阶段：Broker 收到半消息后回调，执行本地事务并返回提交/回滚
    @Override
    public LocalTransactionState executeLocalTransaction(Message msg, Object arg) {
        try {
            return payOrder(String.valueOf(arg)) ? LocalTransactionState.COMMIT_MESSAGE
                                                 : LocalTransactionState.ROLLBACK_MESSAGE;
        } catch (Exception e) {
            return LocalTransactionState.UNKNOW;   // 交给 Broker 60s 后回查
        }
    }

    // 回查阶段：Producer 宕机重启后 Broker 会反复回调，必须幂等反查
    @Override
    public LocalTransactionState checkLocalTransaction(MessageExt msg) {
        String orderId = msg.getUserProperty("orderId");
        return isPaid(orderId) ? LocalTransactionState.COMMIT_MESSAGE
                               : LocalTransactionState.ROLLBACK_MESSAGE;
    }

    private boolean payOrder(String orderId) { return true; }   // 本地数据库事务
    private boolean isPaid(String orderId) { return true; }
}
```

⭐ 三个约束：① `checkLocalTransaction` 必须能**幂等反查**（查本地事务表/订单状态）；② 回查有次数上限（默认 15 次），最坏情况丢消息，关键链路加对账；③ ProducerGroup 全局唯一，事务消息**不能与延迟/批量混用**。

### Spring Boot 集成

依赖坐标：`org.apache.rocketmq:rocketmq-spring-boot-starter:2.3.1`（内部已依赖 rocketmq-client 5.x）。

```yaml
rocketmq:
  name-server: 127.0.0.1:9876
  producer:
    group: my-producer-group
    retry-times-when-send-failed: 2
```

```java
// 片段：发送，destination 语法为 "topic:tag"
@Service
public class OrderProducerService {
    @Resource private RocketMQTemplate rocketMQTemplate;

    public void sendCreate(OrderDTO order) {
        rocketMQTemplate.syncSend("order-topic:CREATE", order);              // 同步
        rocketMQTemplate.sendOneWay("order-topic:LOG", order);               // 单向
        // 异步：rocketMQTemplate.asyncSend("order-topic:CREATE", order, callback);
        rocketMQTemplate.syncSend("order-topic:DELAY",                       // 延迟级别（4.x 语义）
                MessageBuilder.withPayload(order).build(), 3000, 5);
        // 事务：rocketMQTemplate.sendMessageInTransaction("order-topic:CREATE", msg, arg);
    }
}

// 片段：消费，抛异常即重投（方法体内用 RocketMQTemplate 时无需该注解）
@Service
@RocketMQMessageListener(topic = "order-topic", selectorExpression = "CREATE",
        consumerGroup = "order-consumer-group",
        consumeMode = ConsumeMode.CONCURRENTLY,   // ORDERLY 为顺序消费
        messageModel = MessageModel.CLUSTERING)
public class OrderConsumer implements RocketMQListener<OrderDTO> {
    @Override public void onMessage(OrderDTO order) { /* 业务处理 */ }
}
```

⭐ 事务用法：`@RocketMQTransactionListener` 实现 `executeLocalTransaction` / `checkLocalTransaction`，配合 `rocketMQTemplate.sendMessageInTransaction("topic:tag", msg, arg)`。
⚠️ `RocketMQTemplate` 发送的是序列化对象，消费端泛型必须与生产端一致，否则反序列化失败会一直重试到进死信队列。

## 五、使用二：Go

### 依赖与版本选择

```bash
# 老版 remoting 协议客户端（对应 4.x Broker，社区最常用）
go get github.com/apache/rocketmq-client-go/v2@v2.1.0
# 5.x 官方新客户端（gRPC 协议，需 Broker/Proxy 开启 gRPC 端口，默认 8081）
go get github.com/apache/rocketmq-clients/golang/v5
```

| 客户端 | 协议 | 适用 Broker | 能力边界 |
|---|---|---|---|
| `rocketmq-client-go/v2` | remoting（私有 TCP） | 4.x 完全匹配；5.x 兼容可用 | 普通/顺序/延迟级别消息可用；**无任意延迟**；v2.1.0 后基本停更 |
| `rocketmq-clients/golang/v5` | gRPC | 5.x（需 proxy） | 支持任意延迟等 5.x 新特性 |

⚠️ 用 Go 老客户端连 5.x 集群时，`WithDelayTimeLevel` 在 5.x Broker 上仍按 18 个固定档位生效，**不会**升级为任意延迟。

### 生产者

```go
package main

import "context"
import "fmt"
import "github.com/apache/rocketmq-client-go/v2"
import "github.com/apache/rocketmq-client-go/v2/primitive"
import "github.com/apache/rocketmq-client-go/v2/producer"

func main() {
	p, err := rocketmq.NewProducer(
		producer.WithNameServer([]string{"127.0.0.1:9876"}),
		producer.WithGroupName("producer_group_demo"),
		producer.WithRetry(2), // 发送失败重试次数
	)
	if err != nil {
		panic(err)
	}
	if err = p.Start(); err != nil {
		panic(err)
	}
	defer p.Shutdown()

	msg := primitive.NewMessage("TopicTest", []byte("hello rocketmq"))
	msg.WithTag("TagA")
	msg.WithKeys([]string{"key-1001"})
	msg.WithDelayTimeLevel(3) // 4.x 语义：level 3 = 10s 后投递

	res, err := p.SendSync(context.Background(), msg)
	if err != nil {
		fmt.Println("send error:", err)
		return
	}
	fmt.Printf("send ok: msgId=%s queueId=%d\n", res.MsgID, res.MessageQueue.QueueId)
}
```

### 消费者

```go
package main

import "context"
import "fmt"
import "github.com/apache/rocketmq-client-go/v2"
import "github.com/apache/rocketmq-client-go/v2/consumer"
import "github.com/apache/rocketmq-client-go/v2/primitive"

func main() {
	c, err := rocketmq.NewPushConsumer(
		consumer.WithNameServer([]string{"127.0.0.1:9876"}),
		consumer.WithGroupName("consumer_group_demo"),
		consumer.WithConsumerModel(consumer.Clustering),
		consumer.WithConsumerOrder(true), // 顺序消费：组内按队列加锁
	)
	if err != nil {
		panic(err)
	}
	// 必须先 Subscribe 再 Start；一次回调可能拿到多条消息
	err = c.Subscribe("TopicTest",
		consumer.MessageSelector{Type: consumer.Tag, Expression: "TagA || TagB"},
		func(ctx context.Context, msgs ...*primitive.MessageExt) (consumer.ConsumeResult, error) {
			for i := range msgs {
				fmt.Printf("msgId=%s tag=%s body=%s\n", msgs[i].MsgId, msgs[i].GetTags(), string(msgs[i].Body))
				if len(msgs[i].Body) == 0 {
					return consumer.ConsumeRetryLater, fmt.Errorf("empty body")
				}
			}
			return consumer.ConsumeSuccess, nil
		})
	if err != nil {
		panic(err)
	}
	if err = c.Start(); err != nil { // Subscribe 之后才能 Start
		panic(err)
	}
	select {} // 阻塞；生产环境应监听信号后 c.Shutdown() 优雅退出
}
```

⭐ Go 客户端回调签名为 `func(context.Context, ...*primitive.MessageExt)`；`consumer.ConsumeRetryLater` 等价 Java 的 `RECONSUME_LATER`，同样受 16 次重试上限约束，最终进 `%DLQ%{group}`。

## 六、可靠性机制

### 刷盘策略 × 主从复制

| 组合 | 配置 | 可靠性 | 性能 | 适用 |
|---|---|---|---|---|
| 异步刷盘 + 异步复制 | `ASYNC_FLUSH` + `ASYNC_MASTER`（**默认**） | 最低：OS 宕机丢 PageCache，Master 挂丢未同步数据 | 最高 | 日志、埋点等可容忍少量丢失 |
| 同步刷盘 + 异步复制 | `SYNC_FLUSH` + `ASYNC_MASTER` | 单机不丢；Master 挂可能丢未同步副本 | 中 | 消息不可丢、可接受 RT 上升 |
| 异步刷盘 + 同步复制 | `ASYNC_FLUSH` + `SYNC_MASTER` | Master 挂可切换，OS 宕机仍可能丢 | 中 | 高可用优先 |
| 同步刷盘 + 同步复制 | `SYNC_FLUSH` + `SYNC_MASTER` | 最高：落盘 + 从节点确认后才 ACK | 最低 | 支付、订单等金融场景 |

### 消息重试与死信

| 阶段 | 机制 | 关键参数 |
|---|---|---|
| 生产者重试 | 同步发送失败换队列/Broker 重发 | `retryTimesWhenSendFailed=2`（共 3 次） |
| 消费者重试（并发） | 返回 `RECONSUME_LATER` → 进 `%RETRY%{group}`，延迟递增重投 | `maxReconsumeTimes=16` |
| 消费者重试（顺序） | `SUSPEND_CURRENT_QUEUE_A_MOMENT` 挂起队列，**默认无限重试** | 顺序消费默认 `Integer.MAX_VALUE` |
| 死信 | 重试超限进 `%DLQ%{group}`，只能人工通过 Dashboard / `mqadmin` 重投 | 需监控 DLQ 条数 |

### 消息幂等（必须消费端做）

RocketMQ 只保证 **at least once**：网络抖动、超时重发、位点提交失败都会导致重复消费，**Broker 不做去重**，去重只能落在消费端：

| 方案 | 实现 | 优点 | 缺点 |
|---|---|---|---|
| 唯一索引去重表 | 消费前插入去重表（`msg_key` 唯一索引），冲突即已消费 | 强一致、实现简单 | 每次消费一次 DB 写，需定期清理 |
| Redis 去重 | `SET key:msgKey 1 NX EX 86400`，返回 0 则跳过 | 性能高、天然过期 | 需容忍 Redis 故障（可降级放行） |
| 业务状态机 | `UPDATE order SET status='PAID' WHERE id=? AND status='UNPAID'`，影响行数 0 即重复 | 无额外存储、最贴近业务 | 仅适用有状态流转的场景 |

⚠️ 去重 key 优先用**业务唯一键**（`msg.getKeys()`、订单号），不要用 `msgId`：消息进重试队列后 msgId 与 offsetMsgId 都会重新生成。

### 消息堆积与消费位点

| 事项 | 说明 |
|---|---|
| 堆积根因 | 消费能力 < 生产速度：消费逻辑慢、线程不足、队列数不足、下游阻塞 |
| 扩容误区 | ⚠️ 消费者实例数 > 队列数时，多出的实例**完全分不到队列**，必须先扩队列再加机器 |
| 应对手段 | 提高 `consumeThreadMax`、批量消费、下游异步化、加队列后扩实例、必要时转存离线 |
| 位点管理 | 查看 `mqadmin consumerProgress -g {group}`（`Diff Total` 即堆积量）；重置 `mqadmin resetOffsetByTime`，⚠️ 会引发重复消费；集群模式位点存 Broker 的 `consumerOffset.json`，广播模式存本地 `~/.rocketmq_offsets` |

## 七、部署与运维要点

### 端口与集群形态

NameServer 用 **9876**；Broker 主通道 **10911**（`listenPort`）、HA 端口 **10912**（`listenPort + 1`）、`fastRemotingPort` **10909**（VIP 通道，5.x 已废弃）；rocketmq-dashboard 默认 **8080**。

```bash
# 单机
nohup sh bin/mqnamesrv &
nohup sh bin/mqbroker -n 127.0.0.1:9876 -c conf/broker.conf &
# 生产集群：2 台 NameServer + 2m-2s（或 DLedger 自动主从切换）
sh bin/mqadmin clusterList -n 127.0.0.1:9876
```

```properties
# conf/broker.conf
brokerClusterName=DefaultCluster
brokerName=broker-a
brokerId=0                      # 0=Master，>0=Slave
namesrvAddr=127.0.0.1:9876
listenPort=10911
brokerRole=ASYNC_MASTER         # SYNC_MASTER / SLAVE
flushDiskType=ASYNC_FLUSH       # SYNC_FLUSH
autoCreateTopicEnable=false     # ⚠️ 生产必须关，防止乱建 Topic
fileReservedTime=72             # 消息保留 72 小时
diskMaxUsedSpaceRatio=75        # 磁盘使用率超 75% 拒绝写入
```

### 控制台与 PageCache

- **rocketmq-dashboard**（原名 rocketmq-console-ng）：`java -jar rocketmq-dashboard-2.0.0.jar --server.port=8080 --rocketmq.config.namesrvAddr=127.0.0.1:9876`，可看队列分布、消费堆积与 TPS、主从状态、消息轨迹、按 Key/MsgId 查消息、DLQ 重投。
- **PageCache**：CommitLog 用 **mmap 映射（单文件固定 1 GiB，`mappedFileSizeCommitLog`）**，写入先落 PageCache 再由刷盘线程落盘，因此异步刷盘下 `send` 成功 ≠ 已落盘；PageCache 不足会触发 `osPageCacheBusyTimeOutMills`（默认 1000ms）超时，本质是磁盘写入跟不上。要求磁盘预留 ≥30%、生产用 SSD，并把 `fileReservedTime` 与磁盘容量匹配。
- ⚠️ 优雅下线顺序：先停 Producer → 再停 Consumer → 最后 `sh bin/mqshutdown broker`，避免进程退出丢 PageCache 数据。

### 常见故障

| 故障 | 影响 | 处理 |
|---|---|---|
| NameServer 挂（含全挂） | 已运行的客户端用**本地缓存路由**继续收发；新客户端启动、Topic 变更、Broker 上下线失效 | 无状态，直接重启任一节点；生产部署 2~3 台 |
| Broker 从节点只读 | Slave 默认不提供写；能否读 Slave 由 `slaveReadEnable` 决定（默认 false，开启后仅 Master 内存高水位时才从 Slave 拉取） | 4.x 主从为**异步**复制，Master 挂后 Slave 只读且可能丢最后一段数据；要自动切换需 DLedger |
| Broker 磁盘满 | `diskMaxUsedSpaceRatio` 触发后拒绝写入，生产者报 `SERVICE_NOT_AVAILABLE` | 清理过期 CommitLog 或扩盘；调小 `fileReservedTime` |
| 位点丢失 | 集群模式位点在 Broker，数据目录被清空则位点归零，引发大量重复消费 | 备份 `config/consumerOffset.json`，用 `resetOffsetByTime` 校正 |

## 八、面试官会追问什么

1. **RocketMQ 为什么不支持任意延迟（4.x）？** 4.x 用固定 `delayLevel`，每个级别对应 `SCHEDULE_TOPIC_XXXX` 下一条队列 + 定时任务扫描，实现简单、无排序成本；任意延迟需要海量定时任务或时间轮支撑，5.x 才用 TimerLog + 多级时间轮（`rmq_sys_wheel_timer`）做到毫秒级任意延迟。
2. **事务消息的回查是怎么触发的？** 半消息写入 `RMQ_SYS_TRANS_HALF_TOPIC`；Producer 未回 Commit/Rollback（返回 UNKNOW、宕机、断网）时，Broker 的 `TransactionalMessageCheckService` 每 60s 扫描超时半消息，反查 `checkLocalTransaction`，默认最多 15 次，因此回查逻辑必须幂等且能反查本地事务。
3. **顺序消息如何保证不丢、不跳？** 生产端同 key 固定队列；消费端 `MessageListenerOrderly` 按队列加锁单线程消费，失败返回 `SUSPEND_CURRENT_QUEUE_A_MOMENT` 无限重试并阻塞队列，所以不会跳过；代价是队列卡死风险，需告警兜底。
4. **消息堆积怎么办？** 先定位是消费慢还是生产突增；手段为提消费线程（≤ 队列数）、批量消费、下游异步化、先扩队列再扩实例；⚠️ 队列数不变时加消费者实例无效。
5. **与 Kafka 的核心差异？** ① 元数据：NameServer（无状态 AP）vs ZooKeeper/KRaft；② 存储：统一 CommitLog + ConsumeQueue（单机可挂万级队列）vs 每分区独立目录（分区多则文件数爆炸）；③ 功能：RocketMQ 原生延迟消息、事务消息、Tag/SQL 过滤、消息轨迹与消息查询，Kafka 靠分区吞吐与流式生态取胜。
6. **如何保证消息不丢、不重复？** 不丢：生产端同步发送 + 重试 + 事务消息，Broker 端 `SYNC_FLUSH` + `SYNC_MASTER`，消费端处理成功后再提交位点；不重复：RocketMQ 只保证 at-least-once，只能消费端幂等（去重表 / Redis SETNX / 业务状态机），去重 key 用业务唯一键而非 msgId。
7. **为什么 RocketMQ 单机能支持数万队列，而 Kafka 分区多了会变差？** CommitLog 顺序写 + ConsumeQueue 轻量索引（每条约 20 字节，30 万条仅约 6MB）让存储与队列数解耦；Kafka 每个分区一套日志段与索引文件，分区数增大带来随机 IO 与文件句柄压力。
