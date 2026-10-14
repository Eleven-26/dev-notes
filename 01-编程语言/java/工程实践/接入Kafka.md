# Java 接入 Kafka

> 覆盖：Java 侧接入 Kafka 的完整手册 —— 依赖坐标、初始化、单例边界、初始化顺序、原生 `KafkaProducer` / `KafkaConsumer`、Spring Kafka 的 `KafkaTemplate` 与 `@KafkaListener`、跨进程传播、优雅退出、验证与排障。
>
> 不覆盖：Kafka 的概念、分区与副本、可靠性参数与排障见 [Kafka 目录导读](../../../03-数据与中间件/中间件/消息队列/Kafka/README.md)（零件篇从 [核心概念与架构.md](../../../03-数据与中间件/中间件/消息队列/Kafka/核心概念与架构.md) 起读）；Go 侧写法见 [接入Kafka.md](../../go/工程实践/接入Kafka.md)。
>
> 内容整理自个人学习笔记。本篇**原生客户端**部分在 `maven:3.9-eclipse-temurin-17` + `kafka-clients 3.9.0` 下编译并运行通过，对本机三节点 KRaft 集群（`apache/kafka:3.9.0`）实测；**Spring Kafka 部分未编译验证**。

---

## 一、依赖坐标

**本节要点**：只有两个坐标 —— 原生用 `kafka-clients`，Spring 用 `spring-kafka`；版本与 Broker 大版本对齐。

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

> ⚠️ `kafka-clients` 的传递依赖是 `lz4-java` / `snappy-java` / `zstd-jni` / `slf4j-api`（实测下载到的就是这 4 个）。只加 `kafka-clients` 而不加日志实现时，启动会打印
> `SLF4J: Failed to load class "org.slf4j.impl.StaticLoggerBinder"` —— 属正常提示，不影响收发。

## 二、前提

**本节要点**：客户端不创建任何服务端资源，连上之前集群那侧要先齐全；缺一样都有对应的报错。

| 依赖 | 要先准备好什么 | 漏了会报什么 |
| --- | --- | --- |
| **Broker** | `kafka-lab-1:9092` 可达 | `TimeoutException: Topic ... not present in metadata after ... ms` |
| **Topic** | 已建，或集群开 `auto.create.topics.enable` | 发送超时；自动创建要等一次元数据请求触发 |
| **分区数** | ≥ `listener.concurrency`（并发消费者线程数） | 多出的线程分不到分区、空转 |
| **消费组** | 配 `group.id`；无需预建 | 不配 → 只能 `assign` 手动指定分区，失去组内自动分配 |
| **端口** | `9092`（PLAINTEXT） | 连接被拒 |
| **版本对齐** | `kafka-clients` 大版本与 Broker 对齐 | 旧客户端请求新消息格式会触发 **down-conversion**，费 CPU 又让零拷贝失效 |

## 三、初始化

**本节要点**：生产者初始化 = 「填 `Properties` + `new KafkaProducer`」。⚠️ 与 Go 的惰性连接不同，**`KafkaProducer` 构造时就会连 Broker 拉元数据**，所以 Broker 不可达时 `new` 这一步就会卡住。

```java
Properties props = new Properties();
props.put("bootstrap.servers", "kafka-lab-1:9092");
props.put("key.serializer", StringSerializer.class.getName());
props.put("value.serializer", StringSerializer.class.getName());
props.put("acks", "all");                 // ISR 全部确认
props.put("enable.idempotence", "true");  // 幂等生产者，防重试重复
props.put("retries", Integer.MAX_VALUE);

Producer<String, String> producer = new KafkaProducer<>(props);
```

**在哪调**：整个应用建一次（静态持有，或交给 Spring 容器）；**不要**在请求里每次 `new KafkaProducer`。写一次性工具时用 try-with-resources，随用随关。

## 四、单例边界 ⭐

**本节要点**：判「要不要单例」只看一件事——**这个对象内部有没有长活的资源（连接池、后台 I/O 线程）**：有 → 进程级；没有、且绑定一次业务操作的 → 请求级。

| 对象 | 单例？ | 为什么 |
| --- | --- | --- |
| `KafkaProducer` | ✅ 进程级 | **线程安全**，内部维护连接池与后台 I/O 线程；每次 `new` 都新建一套连接池 + 线程 |
| `KafkaConsumer` | ❌ 非单例（且**线程不安全**） | 绑定「消费组 + 订阅」，每个消费线程各持一个，由自己的 `poll` 循环驱动 |
| `KafkaTemplate`（Spring） | ✅ 进程级（单例 Bean） | 内部持有一个 `ProducerFactory`；容器注入，别每次 `new DefaultProducerFactory` |
| `ProducerRecord` / `ConsumerRecord` | ❌ 请求级 | 每条独立，跨请求复用会串内容 |
| `Acknowledgment`（手动 ack） | ❌ 请求级 | 绑定本次 `poll` 的那批记录 |

**写错的后果**：

- 生产者每次请求 `new` → 连接池与 I/O 线程随 QPS 线性膨胀；
- 消费者做成全局单例或跨线程共享 → 并发模型直接失效（`commitSync` / `wakeup` / `close` 都作用于**单个实例**），且 `KafkaConsumer` 不是线程安全的，跨线程使用会抛 `ConcurrentModificationException`。

> 一句话：**生产者单例（线程安全、省连接），消费者非单例（一个订阅 / 线程一份独立状态）。**

## 五、初始化顺序 ⭐

**本节要点**：⭐ 判据是「**谁在初始化时捕获了全局单例**」——凡是有这个特征的下游组件（各类 instrumentation、SDK 全局 hook），都必须排在被捕获者**之后**初始化，否则它拿到的是初始化那一刻的旧值（常常是 noop），且**不报错**。

Java 侧三条实序：

1. **`ProducerFactory` → `KafkaTemplate` 由容器按依赖顺序装配**。业务类一旦自己 `new KafkaTemplate(...)`，就绕开了容器的单例与观测 / 事务基础设施（见第六节）。
2. **消费位点配置只在「组内无位点」时生效**：`auto.offset.reset=earliest` 只在消费组**没有已提交位点**时起作用；已有位点的组重启后从位点继续，改它无效。
3. **`@KafkaListener` 容器晚于 `KafkaTemplate` 起步**：监听容器在启动时会 `subscribe` 并触发重平衡，此时生产侧的基础设施应已就绪（否则启动瞬间的业务回调可能调不到 Bean）。

> ⭐ 这个判据的经典例子在链路追踪栈：**instrumentation 在初始化时取一次全局实例**——若它在全局 Tracer 注册**之前**完成初始化，span 就走 noop provider，**永远不上报且不报错**。详见 [接入Jaeger.md](../可观测性/接入Jaeger.md)。

## 六、单例与依赖注入的封装

**本节要点**：原生写法用**静态持有**兜底（本机编译通过）；Spring 侧不用手写——`KafkaTemplate` 由容器装配为单例 Bean。

原生（可直接抄）：

```java
// 整个应用只建一个 Producer 并复用；不要在请求处理函数里每次 new。
public final class OrderProducers {
    private static final KafkaProducer<String, String> PRODUCER = new KafkaProducer<>(props());

    private OrderProducers() { }

    private static Properties props() {
        Properties p = new Properties();
        p.put("bootstrap.servers", "kafka-lab-1:9092");
        p.put("key.serializer", StringSerializer.class.getName());
        p.put("value.serializer", StringSerializer.class.getName());
        p.put("acks", "all");
        p.put("enable.idempotence", "true");
        return p;
    }

    public static KafkaProducer<String, String> get() { return PRODUCER; }
}
```

Spring：

```java
@Service
public class OrderProducerService {
    private final KafkaTemplate<String, String> template;   // 容器注入的单例 Bean
    public OrderProducerService(KafkaTemplate<String, String> t) { this.template = t; }
}
```

> ⚠️ 别每次 `new DefaultProducerFactory` / `new KafkaTemplate`——那等于绕开容器的单例与观测基础设施。

## 七、实际调用

**本节要点**：同一个单例 Producer，按场景换「key / 回调 / 手动提交」即可；Spring 侧统一用 `KafkaTemplate` + `@KafkaListener`。

### 7.1 原生 Producer：异步发送 + 回调

```java
// key = 业务主键 → 同 key 同分区，该实体的事件在分区内有序
ProducerRecord<String, String> record =
        new ProducerRecord<>("order-events", "ORDER_2001", "{\"event\":\"created\"}");
// 异步发送 + 回调：不阻塞主线程又能感知失败
producer.send(record, (metadata, ex) -> {
    if (ex != null) {
        System.err.println("send failed: " + ex.getMessage());
    } else {
        System.out.printf("PRODUCE partition=%d offset=%d%n", metadata.partition(), metadata.offset());
    }
});
producer.flush();   // 确保回调与未完成请求都处理完再退出
```

| 要注意的 | 说明 |
| --- | --- |
| **`acks` 默认是 `1`** | 要可靠性必须显式写 `all`；配 `min.insync.replicas≥2` 才有意义 |
| **`enable.idempotence=true` 会自动把 `acks` 拉成 `all`** | 并强制 `retries>0`、`max.in.flight≤5`，所以开幂等后不必再手工调这几个 |
| **`send()` 是异步的** | 不注册回调、也不 `flush()`，进程退出时未发出的批会直接丢 |
| **`flush()` 的位置** | 它只保证「已进入累加器的数据都发出去」，**不是事务提交**；真正的事务要走 `initTransactions` / `beginTransaction` |

### 7.2 原生 Consumer：处理完再手动提交

```java
consumer.subscribe(Collections.singletonList("order-events"));
while (true) {
    ConsumerRecords<String, String> records = consumer.poll(Duration.ofMillis(500));
    for (ConsumerRecord<String, String> r : records) {
        handle(r);            // 1) 先逐条处理业务（含幂等校验）
    }
    if (!records.isEmpty()) {
        consumer.commitSync(); // 2) 整批成功后手动同步提交
    }
}
```

> ⚠️ `commitSync()` 提交的是**下次要读的位点**（本批最大 offset + 1），不是「本批最后一个 offset」。想按分区精确提交可传入 `Map<TopicPartition, OffsetAndMetadata>`。
>
> ⭐ `commitSync` 阻塞且失败自动重试，`commitAsync` 不阻塞但不重试——常见做法是循环内 `commitAsync`、关闭前 `commitSync` 兜底。

### 7.3 Spring Kafka：KafkaTemplate + @KafkaListener

```yaml
spring:
  kafka:
    bootstrap-servers: kafka-lab-1:9092
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
    private final KafkaTemplate<String, String> template;
    public OrderProducerService(KafkaTemplate<String, String> t) { this.template = t; }
    public void publish(OrderDTO order) {
        template.send("order-events", order.getOrderId(), order.toJson())   // key=orderId ⇒ 同分区
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

并发消费由 `ConcurrentKafkaListenerContainerFactory#setConcurrency`（或 yaml 的 `listener.concurrency`）控制，**不要超过分区数**。

| AckMode | 提交时机 | 适用 |
| --- | --- | --- |
| `RECORD` | 每条消息处理完提交 | 简单可靠，提交频繁 |
| `BATCH`（默认） | 一批 `poll` 记录全部处理完再提交 | 吞吐好，⚠️ 批内失败会整批重复 |
| `MANUAL` | 调用 `acknowledge()` 后，**下次 poll 时**提交 | 需配合容器时序 |
| `MANUAL_IMMEDIATE` ⭐ | 调用 `acknowledge()` **立即**提交 | 最精确，配合手动 ack 使用 |

### 7.4 错误处理与死信

```java
// 重试 3 次仍失败则投递到 order-events.DLT
var recoverer = new DeadLetterPublishingRecoverer(kafkaTemplate,
        (rec, ex) -> new TopicPartition(rec.topic() + ".DLT", rec.partition()));
var errorHandler = new DefaultErrorHandler(recoverer, new FixedBackOff(1000L, 3));
errorHandler.addNotRetryableExceptions(IllegalArgumentException.class); // 非法参数直接进死信
containerFactory.setCommonErrorHandler(errorHandler);                   // ⭐ 挂到监听容器
```

> ⚠️ Spring Kafka 的 `@KafkaListener` 出错时默认会**一直重试**（旧版 `SeekToCurrentErrorHandler` 行为），上线就可能卡死整个分区；必须显式配置 `DefaultErrorHandler` + `FixedBackOff` + 死信兜底，否则**一条脏数据能堵死一个分区**。

## 八、跨进程与跨协程传播

**本节要点**：跨进程用 **record headers** 做 Inject / Extract；跨线程用「每线程一个消费者」或容器的并发监听。

- **Inject（生产端）**：`record.headers().add(k, v)`；
- **Extract（消费端）**：`r.headers().lastHeader(k)`。

```java
// 生产端：把业务头写进 record headers（不是消息体）
ProducerRecord<String, String> rec =
        new ProducerRecord<>("java-props", "ORDER_JAVA_9001", "java-props-1");
rec.headers().add("x-biz-id", "ORDER_JAVA_9001".getBytes(StandardCharsets.UTF_8));
rec.headers().add("x-trace-id", "trace-java-abc123".getBytes(StandardCharsets.UTF_8));
producer.send(rec).get();
```

```java
// 消费端：Extract 出来
Header biz = r.headers().lastHeader("x-biz-id");
Header trace = r.headers().lastHeader("x-trace-id");
System.out.printf("JAVA-PROPS-CONSUME partition=%d offset=%d biz=%s trace=%s body=%s%n",
        r.partition(), r.offset(),
        biz == null ? "" : new String(biz.value(), StandardCharsets.UTF_8),
        trace == null ? "" : new String(trace.value(), StandardCharsets.UTF_8),
        r.value());
```

本机实测（头完整往返）：

```text
JAVA-PROPS-PRODUCE ok topic=java-props
JAVA-PROPS-CONSUME partition=1 offset=0 biz=ORDER_JAVA_9001 trace=trace-java-abc123 body=java-props-1
JAVA-PROPS DONE
```

**跨线程**：

- 原生客户端：`KafkaConsumer` **不能跨线程**；要并发就多实例（每线程一个），不要共享；
- Spring：`@KafkaListener` 的 `concurrency` 由容器按分区建多个消费者线程，上下文（含 trace）由 Spring 传递；
- ⚠️ 别把请求级数据存进单例 `KafkaProducer` / `KafkaTemplate` 的字段——它们被多线程共享，立刻串数据。上下文要**随消息走**（headers），不能随对象走。

## 九、优雅退出

**本节要点**：退出顺序 = 反初始化顺序：**先停入口 → 再停消费者（处理完在途并提交位点）→ 最后关生产者（flush）**。

```java
consumer.wakeup();     // 打断阻塞中的 poll()，让循环退出
consumer.close();      // 触发一次位点提交并离开消费组
producer.flush();      // 把累加器里未发出的批发完
producer.close();      // 关连接、停 I/O 线程
```

| 顺序 | 为什么 |
| --- | --- |
| `wakeup()` 打断 `poll` | 否则关闭信号要等下一次 `poll` 返回才生效 |
| 先关消费者 | 提交位点、触发重平衡；在途未提交的那批会在重启后重复消费（靠幂等兜底） |
| 最后清生产者 | `flush()` 发出缓冲中的批，`close()` 停 I/O 线程 |

**漏了会丢什么**：

- 消费者不 `close` → 位点不提交、组内成员不退出，重平衡要等到会话超时；
- 生产者不 `flush` / `close` → 累加器里「已 send 未发出」的批直接丢，I/O 线程泄漏。

> ⚠️ Spring 侧：容器关闭时会自动停监听器并 `close` 其生产者。用 `sh -c` 起 JVM 时要 `exec java ...`（或 `tini`），否则 SIGTERM 打不到 JVM，等价于强杀（同一问题见 [接入Jaeger.md](../可观测性/接入Jaeger.md) 2.3）。

## 十、验证与排查

**本节要点**：先确认「接入真的生效」（位点与 LAG），再用故障速查表定位。

### 10.1 先确认生效

环境：`maven:3.9-eclipse-temurin-17`（JDK 17）+ `kafka-clients 3.9.0`，容器接入 `kafka-lab-net`；目标集群三节点 KRaft（`apache/kafka:3.9.0`），Topic `java-lab`（3 分区 3 副本）。程序交替用 `ORDER_2001` / `ORDER_2002` 发 3 轮共 6 条，再以消费组手动提交读回。

```text
COMPILE_OK
PRODUCE partition=0 offset=0 key=ORDER_2001
PRODUCE partition=0 offset=1 key=ORDER_2001
PRODUCE partition=0 offset=2 key=ORDER_2001
PRODUCE partition=1 offset=0 key=ORDER_2002
PRODUCE partition=1 offset=1 key=ORDER_2002
PRODUCE partition=1 offset=2 key=ORDER_2002
CONSUME partition=0 offset=0 key=ORDER_2001 value={"event":"created","seq":1}
CONSUME partition=0 offset=1 key=ORDER_2001 value={"event":"created","seq":2}
CONSUME partition=0 offset=2 key=ORDER_2001 value={"event":"created","seq":3}
CONSUME partition=1 offset=0 key=ORDER_2002 value={"event":"created","seq":1}
CONSUME partition=1 offset=1 key=ORDER_2002 value={"event":"created","seq":2}
CONSUME partition=1 offset=2 key=ORDER_2002 value={"event":"created","seq":3}
CONSUME done: 6 messages, group=java-lab-group, commit=manual
KEY-MAP key=ORDER_2001 -> partition=0
KEY-MAP key=ORDER_2002 -> partition=1

# kafka-consumer-groups.sh --describe --group java-lab-group
GROUP            TOPIC      PARTITION  CURRENT-OFFSET  LOG-END-OFFSET  LAG
java-lab-group   java-lab   1          3               3               0
java-lab-group   java-lab   0          3               3               0
```

| 结论 | 证据 |
| --- | --- |
| ⭐ **同 key 落同一分区** | `ORDER_2001` 三条全在 `partition=0`，`ORDER_2002` 三条全在 `partition=1` |
| ⭐ **分区内按写入顺序** | 两个分区的 `offset` 都是 `0,1,2`，与 `seq` 一致 |
| ⭐ **手动提交生效** | 消费组位点停在各分区末尾（`3`），`LAG=0`，组内已无活跃成员 |

### 10.2 常见故障速查

| 症状 | 先查什么 |
| --- | --- |
| `send failed: ... NotEnoughReplicasException` | 集群 `Isr` 是否少于 `min.insync.replicas`（见 [消息可靠性.md](../../../03-数据与中间件/中间件/消息队列/Kafka/消息可靠性.md) 第五节） |
| 消费组卡住不动 / 反复重平衡 | `max.poll.interval.ms` 是否被业务处理超时（见 [消费者组与重平衡.md](../../../03-数据与中间件/中间件/消息队列/Kafka/消费者组与重平衡.md) 第四节） |
| `TimeoutException: Topic ... not present in metadata` | Topic 没建且未开自动创建；clients 版本与 Broker 是否对齐（第二节） |
| `@KafkaListener` 一直重试同一条 | 是否漏配 `DefaultErrorHandler` 与死信兜底（7.4） |
| 位点提交了但业务没做完 | `enable-auto-commit` 是否被设回 `true`，或 AckMode 是否落在 `BATCH` |
| 加了实例但分不到分区 | `listener.concurrency` 是否超过分区数 |

| 排查项 | 命令 |
| --- | --- |
| 消费堆量 / 位点 | `kafka-consumer-groups.sh --describe --group java-lab-group` |
| 各分区位点 | `kafka-topics.sh --describe --topic java-lab` |

## 十一、延伸追问

- **原生客户端和 Spring Kafka 怎么选？** → 只有一两个 Topic、要精确控制提交时机 → 原生；已经在 Spring 生态里、要批量并发、统一错误处理与死信 → Spring Kafka。两者底层是同一套 `kafka-clients`。
- **`enable.idempotence=true` 之后还要手调 `max.in.flight` 吗？** → 不用。开幂等后默认值已经是「保证不乱的区间」；⚠️ 这与 Go 的 `sarama` 不同——那边开幂等必须把 `Net.MaxOpenRequests` 设成 1。
- **`ack.acknowledge()` 之后还会重复消费吗？** → 会。提交成功前崩溃、重平衡后位点回退都会重复；`MANUAL_IMMEDIATE` 只是把「提交时点」提前，**不能消除重复**，业务侧幂等仍然必须。
- **`concurrency` 开大能提吞吐吗？** → 不能超过分区数：超出部分的线程分不到分区，只会让重平衡更频繁。
- **为什么 `kafka-clients` 版本要跟 Broker 对齐？** → 新客户端对旧 Broker 基本能兼容，但**旧客户端请求新 Broker 的消息格式可能触发 down-conversion**（broker 要改写字节），既费 CPU 又会让零拷贝失效。

## 十二、校验口径

> ✅ 本篇校验方式与范围（`maven:3.9-eclipse-temurin-17`，JDK 17 + `kafka-clients 3.9.0`）：

- **真编译 + 运行通过**：第一节依赖坐标、第三节 `KafkaProducer` 构造、7.1 异步发送 + 回调、7.2 `poll` + `commitSync`、第八节 `headers()` 的 Inject / Extract。
- **只编译、未运行**：第六节 `OrderProducers` 的静态持有（随 `javac` 一起编译通过；运行时主流程走的是 `KafkaLab`）。
- **未编译验证**：7.3 Spring Kafka 的 yaml、`KafkaTemplate` / `@KafkaListener`、7.4 `DefaultErrorHandler` + 死信（本机未起 Spring 上下文，按官方文档写法整理）；第九节优雅退出片段。
- **实测环境**：`apache/kafka:3.9.0`，三节点 KRaft 集群（网络 `kafka-lab-net`），Topic `java-lab`（3 分区 3 副本）。
- **未实测**：事务（`initTransactions` / `beginTransaction`）；SASL / TLS；跨机房集群。

| 依赖 | 实际解析版本 |
| --- | --- |
| `org.apache.kafka:kafka-clients` | 3.9.0 |
| `lz4-java` | 1.8.0（传递依赖） |
| `snappy-java` | 1.1.10.5（传递依赖） |
| `zstd-jni` | 1.5.6-4（传递依赖） |
| `slf4j-api` | 1.7.36（传递依赖） |
| `org.springframework.kafka:spring-kafka` | 未编译（见上） |

## 关联

- **上游/总纲**：[Kafka 目录导读](../../../03-数据与中间件/中间件/消息队列/Kafka/README.md) — Kafka 的定位、阅读路线与实测坐标
- **对照/互补**：[接入Kafka.md](../../go/工程实践/接入Kafka.md) — Go 侧同一套语义的写法与差异
- **对照/互补**：[接入RocketMQ.md](接入RocketMQ.md) — Java 接 RocketMQ 的对照手册
- **下游机制**：[核心概念与架构.md](../../../03-数据与中间件/中间件/消息队列/Kafka/核心概念与架构.md) — 同 key 同分区背后的分区路由与存储结构
- **下游机制**：[消息可靠性.md](../../../03-数据与中间件/中间件/消息队列/Kafka/消息可靠性.md) — `acks` / 幂等 / 事务的服务端侧语义
- **下游机制**：[消费者组与重平衡.md](../../../03-数据与中间件/中间件/消息队列/Kafka/消费者组与重平衡.md) — `concurrency` 与分区数的关系
- **运维/配置**：[Spring-boot核心.md](../Spring-boot核心.md) — `@KafkaListener` 依赖的容器与 Bean 生命周期基础
