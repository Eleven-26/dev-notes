# Java 接入 Kafka

> 覆盖 Java 侧接入 Kafka 的完整手册：依赖坐标、原生 `KafkaProducer` / `KafkaConsumer`、Spring Kafka 的 `KafkaTemplate` 与 `@KafkaListener`、AckMode 与错误处理 / 死信，以及一组对三节点集群**真实编译并运行**的输出。
>
> 不覆盖：Kafka 的概念、分区与副本、可靠性参数与排障见 [Kafka 目录导读](../../../03-数据与中间件/中间件/消息队列/Kafka/README.md)（零件篇从 [核心概念与架构.md](../../../03-数据与中间件/中间件/消息队列/Kafka/核心概念与架构.md) 起读）。
>
> 内容整理自个人学习笔记。本篇**原生客户端**部分在 `maven:3.9-eclipse-temurin-17` + `kafka-clients 3.9.0` 下编译并运行通过，对本机三节点 KRaft 集群（`apache/kafka:3.9.0`）实测；**Spring Kafka 部分未编译验证**。

---

## 一、依赖坐标

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

## 二、原生 Producer

```java
import org.apache.kafka.clients.producer.*;
import org.apache.kafka.common.serialization.StringSerializer;
import java.util.Properties;

public class OrderProducerDemo {
    public static void main(String[] args) {
        Properties props = new Properties();
        props.put("bootstrap.servers", "kafka-lab-1:9092");
        props.put("key.serializer", StringSerializer.class.getName());
        props.put("value.serializer", StringSerializer.class.getName());
        props.put("acks", "all");                 // ISR 全部确认
        props.put("enable.idempotence", "true");  // 幂等生产者，防重试重复
        props.put("retries", Integer.MAX_VALUE);

        try (Producer<String, String> producer = new KafkaProducer<>(props)) {
            // key = 业务主键 → 同 key 同分区，该实体的事件在分区内有序
            ProducerRecord<String, String> record =
                    new ProducerRecord<>("order-events", "ORDER_2001", "{\"event\":\"created\"}");
            // 异步发送 + 回调：不阻塞主线程又能感知失败
            producer.send(record, (metadata, ex) -> {
                if (ex != null) {
                    System.err.println("send failed: " + ex.getMessage());
                } else {
                    System.out.printf("PRODUCE partition=%d offset=%d%n",
                            metadata.partition(), metadata.offset());
                }
            });
            producer.flush();   // 确保回调与未完成请求都处理完再退出
        }
    }
}
```

| 要注意的 | 说明 |
| --- | --- |
| **`acks` 默认是 `1`** | 要可靠性必须显式写 `all`；配 `min.insync.replicas≥2` 才有意义 |
| **`enable.idempotence=true` 会自动把 `acks` 拉成 `all`** | 并强制 `retries>0`、`max.in.flight≤5`，所以开幂等后不必再手工调这几个 |
| **`send()` 是异步的** | 不注册回调、也不 `flush()`，进程退出时未发出的批会直接丢 |
| **`flush()` 的位置** | 它只保证「已进入累加器的数据都发出去」，**不是事务提交**；真正的事务要走 `initTransactions` / `beginTransaction` |

### 2.1 生产者必须单例

`KafkaProducer` **线程安全**，内部维护连接池与后台 I/O 线程，整个应用只创建一个并复用；绝不要在每次请求里 `new`（每 `new` 一次都等于新建一套连接池 + 线程）。原生写法用静态持有：

```java
// 整个应用只建一个 Producer 并复用；不要在请求处理函数里每次 new。
public final class OrderProducers {
    private static final KafkaProducer<String, String> PRODUCER = new KafkaProducer<>(loadProps());
    public static KafkaProducer<String, String> get() { return PRODUCER; }
}
```

⚠️ Spring 部分不用手写：`KafkaTemplate` 由容器注入为**单例 Bean**，业务类里直接 `@Resource` 注入即可，不要每次 `new DefaultProducerFactory`。**消费者则相反，见 3.1。**

## 三、原生 Consumer

```java
import org.apache.kafka.clients.consumer.*;
import org.apache.kafka.common.serialization.StringDeserializer;
import java.time.Duration;
import java.util.*;

public class OrderConsumerDemo {
    public static void main(String[] args) {
        Properties props = new Properties();
        props.put("bootstrap.servers", "kafka-lab-1:9092");
        props.put("group.id", "order-consumer-group");   // 消费组
        props.put("enable.auto.commit", "false");        // ⭐ 关闭自动提交
        props.put("auto.offset.reset", "earliest");      // 无位点则从头消费
        props.put("key.deserializer", StringDeserializer.class.getName());
        props.put("value.deserializer", StringDeserializer.class.getName());

        try (KafkaConsumer<String, String> consumer = new KafkaConsumer<>(props)) {
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
        }
    }

    // 业务处理 + 幂等（唯一键去重 / 状态机 CAS）
    private static void handle(ConsumerRecord<String, String> r) { }
}
```

> ⚠️ `commitSync()` 提交的是**下次要读的位点**（本批最大 offset + 1），不是「本批最后一个 offset」。想按分区精确提交可传入 `Map<TopicPartition, OffsetAndMetadata>`。
>
> ⭐ `commitSync` 阻塞且失败自动重试，`commitAsync` 不阻塞但不重试——常见做法是循环内 `commitAsync`、关闭前 `commitSync` 兜底。

### 3.1 消费者不要做成单例

与生产者相反，`KafkaConsumer` **不是线程安全的**，也不能包成进程级全局单例：

- 每个消费线程各持一个实例（同一实例不能跨线程使用），由自己的 `poll` 循环驱动；
- 要提高并发，靠「多分区 + 按分区数配实例」，或交给 Spring 容器按 `listener.concurrency` 创建多个消费者线程；
- ⚠️ `commitSync` / `wakeup` / `close` 都作用于**单个实例**，共享实例会让并发模型直接失效。

> 一句话：**生产者单例（线程安全、省连接），消费者非单例（一个订阅 / 线程一份独立状态）。**

## 四、实测：对三节点集群跑一遍

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

## 五、Spring Kafka：KafkaTemplate + @KafkaListener

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

### 错误处理与死信

```java
// 重试 3 次仍失败则投递到 order-events.DLT
var recoverer = new DeadLetterPublishingRecoverer(kafkaTemplate,
        (rec, ex) -> new TopicPartition(rec.topic() + ".DLT", rec.partition()));
var errorHandler = new DefaultErrorHandler(recoverer, new FixedBackOff(1000L, 3));
errorHandler.addNotRetryableExceptions(IllegalArgumentException.class); // 非法参数直接进死信
containerFactory.setCommonErrorHandler(errorHandler);                   // ⭐ 挂到监听容器
```

> ⚠️ Spring Kafka 的 `@KafkaListener` 出错时默认会**一直重试**（旧版 `SeekToCurrentErrorHandler` 行为），上线就可能卡死整个分区；必须显式配置 `DefaultErrorHandler` + `FixedBackOff` + 死信兜底，否则**一条脏数据能堵死一个分区**。

## 六、排查入口

| 症状 | 先查什么 |
| --- | --- |
| `send failed: ... NotEnoughReplicasException` | 集群 `Isr` 是否少于 `min.insync.replicas`（见 [消息可靠性.md](../../../03-数据与中间件/中间件/消息队列/Kafka/消息可靠性.md) 第五节） |
| 消费组卡住不动 / 反复重平衡 | `max.poll.interval.ms` 是否被业务处理超时（见 [消费者组与重平衡.md](../../../03-数据与中间件/中间件/消息队列/Kafka/消费者组与重平衡.md) 第四节） |
| `@KafkaListener` 一直重试同一条 | 是否漏配 `DefaultErrorHandler` 与死信兜底 |
| 位点提交了但业务没做完 | `enable-auto-commit` 是否被设回 `true`，或 AckMode 是否落在 `BATCH` |

## 延伸追问

- **原生客户端和 Spring Kafka 怎么选？** → 只有一两个 Topic、要精确控制提交时机 → 原生；已经在 Spring 生态里、要批量并发、统一错误处理与死信 → Spring Kafka。两者底层是同一套 `kafka-clients`。
- **`enable.idempotence=true` 之后还要手调 `max.in.flight` 吗？** → 不用。开幂等后默认值已经是「保证不乱的区间」；⚠️ 这与 Go 的 `sarama` 不同——那边开幂等必须把 `Net.MaxOpenRequests` 设成 1。
- **`ack.acknowledge()` 之后还会重复消费吗？** → 会。提交成功前崩溃、重平衡后位点回退都会重复；`MANUAL_IMMEDIATE` 只是把「提交时点」提前，**不能消除重复**，业务侧幂等仍然必须。
- **`concurrency` 开大能提吞吐吗？** → 不能超过分区数：超出部分的线程分不到分区，只会让重平衡更频繁。
- **为什么 `kafka-clients` 版本要跟 Broker 对齐？** → 新客户端对旧 Broker 基本能兼容，但**旧客户端请求新 Broker 的消息格式可能触发 down-conversion**（broker 要改写字节），既费 CPU 又会让零拷贝失效。

## 关联

- **上游/总纲**：[Kafka 目录导读](../../../03-数据与中间件/中间件/消息队列/Kafka/README.md) — Kafka 的定位、阅读路线与实测坐标
- **对照/互补**：[核心概念与架构.md](../../../03-数据与中间件/中间件/消息队列/Kafka/核心概念与架构.md) — 同 key 同分区背后的分区路由与存储结构
- **对照/互补**：[消息可靠性.md](../../../03-数据与中间件/中间件/消息队列/Kafka/消息可靠性.md) — `acks` / 幂等 / 事务的服务端侧语义
- **对照/互补**：[消费者组与重平衡.md](../../../03-数据与中间件/中间件/消息队列/Kafka/消费者组与重平衡.md) — `concurrency` 与分区数的关系
- **对照/互补**：[接入Kafka.md](../../go/工程实践/接入Kafka.md) — Go 侧同一套语义的写法
- **对照/互补**：[Spring-boot核心.md](../Spring-boot核心.md) — `@KafkaListener` 依赖的容器与 Bean 生命周期基础
