# ActiveMQ 与 JMS 消息

> 覆盖 ActiveMQ Classic 的定位与现状、JMS 术语表、KahaDB 存储与高可用模型、端到端可靠性语义，以及 Go（STOMP）与 Java（官方客户端 + Spring JMS）双端示例、部署配置与运维排障要点。
>
> 内容整理自个人学习笔记。六款消息队列的横向对比与选型见 [消息队列选型.md](消息队列选型.md)；同类技术路线的对照篇见 [Kafka.md](Kafka.md)、[RocketMQ.md](RocketMQ.md)、[RabbitMQ.md](RabbitMQ.md)、[Pulsar.md](Pulsar.md)、[Nats.md](Nats.md)。

---

## 一、一句话定位与它凭什么

**ActiveMQ Classic 是 Apache 的 JMS broker**（2004 年起、2007 年毕业为顶级项目），它当年被广泛采用的理由只有一个：**协议覆盖最全**——一个 broker 同时开 OpenWire（JMS 原生）、STOMP、AMQP、MQTT 四种接入协议，Java 侧直接实现 JMS 规范，是 Spring `JmsTemplate` / `@JmsListener` 的默认落地实现。

| 维度 | ActiveMQ Classic 的事实 |
| --- | --- |
| 协议 | OpenWire（61616）/ STOMP（61613）/ AMQP 1.0（5672）/ MQTT（1883），Web 控制台 8161 |
| 存储 | 默认 **KahaDB**（文件型 journal + 索引），可换 JDBC / 纯内存 |
| 高可用 | 主从（共享存储 / 租约）与 Network of Brokers 级联，**没有 Raft 类自动选主** |
| 消费模型 | JMS 语义：Queue 竞争消费、Topic 发布订阅（含持久订阅）、selector 服务端过滤 |
| 现状 | ⚠️ Apache 官方后继是**独立重写的 ActiveMQ Artemis**；Classic 社区活跃度已明显下降，新项目的默认答案不再是它 |

> ⭐ 一句话记忆：**ActiveMQ 是「JMS 规范的参考实现 + 协议瑞士军刀」，不是吞吐型流平台**。它的价值在兼容与生态，不在性能。
> ⚠️ 选型口径：**维护存量、JMS 生态绑定、多协议（尤其 MQTT/STOMP）网关**才选它；数据管道选 Kafka，业务消息选 RocketMQ，云原生多租户选 Pulsar，Go 系轻量通信选 NATS（见 [消息队列选型.md](消息队列选型.md)）。

---

## 二、核心概念：JMS 术语表 ⭐

ActiveMQ 的文档与报错全是 JMS 词汇，先把这套词和「通用 MQ 词汇」对齐，否则看配置像看天书：

| JMS / ActiveMQ 术语 | 通用 MQ 对应物 | 说明 |
| --- | --- | --- |
| Destination（Queue / Topic） | Topic / Exchange+Queue | Queue 竞争消费；Topic 每订阅者一份，**离线即丢**（除非持久订阅） |
| Connection | 客户端连接 | 重量对象，内含 IO 线程；**进程内只建一次** |
| Session | 会话 / 事务边界 | 生产与确认的最小单位；`createSession(true, SESSION_TRANSACTED)` 开启本地事务 |
| MessageProducer / MessageConsumer | Producer / Consumer | 由 Session 创建，绑定一个 Destination |
| DeliveryMode.PERSISTENT | 持久化消息 | 非持久消息只进内存目的地，broker 重启即丢 |
| Durable Subscription | 持久订阅 | Topic 上「离线期间消息也给我留着」的订阅，用客户端 ID + 订阅名标识 |
| Selector | 服务端过滤 | SQL92 子集，按消息属性过滤；**被过滤掉的消息仍占队列空间** |
| AcknowledgeMode | ack 语义 | `AUTO_ACKNOWLEDGE` / `CLIENT_ACKNOWLEDGE` / `DUPS_OK_ACKNOWLEDGE` 三档 |
| `ActiveMQ.DLQ` | 死信队列 | 重投超过 `maximumRedeliveries`（默认 6）后进这里 |
| Temporary Queue | 临时队列 | 连接断开自动删除，是 Request-Reply 的标准载体 |
| Advisory Message | 系统事件 | broker 自身事件（消费者上下线、慢消费者）以消息形式发布，可关 |

> ⚠️ **铁律：JMS 是规范，行为由 broker 决定。** 同一个 `receive(5000)`、同一个 `acknowledge()`，在 ActiveMQ / Artemis / IBM MQ 上的超时语义、重投时机、`JMSXDeliveryCount` 是否自动填充都可能不同——跨 broker 迁移代码时，这些「规范没写死」的地方才是坑。

---

## 三、架构与存储：一个 broker 一个 store 目录

### 3.1 写路径：KahaDB 是全局单 store

ActiveMQ Classic 的持久化默认是 **KahaDB**：所有持久化目的地**共用一个 journal 目录**（`data/kahadb`），journal 顺序追加 + 内存索引 + 定期 checkpoint。

这带来 ActiveMQ 运维最典型的事故模型：

- **单队列膨胀会拖垮整个 broker**。某个队列堆积 10GB，journal 无法回收（数据还被引用），磁盘与索引一起涨，所有目的地一起变慢——不像 Kafka「分区各自一个目录」那样能隔离。
- **大消息是 KahaDB 的天敌**。journal 按页（默认 32MB）追加，单条消息接近页大小时会触发整页重写；业务里塞 Base64 附件进消息体，是 ActiveMQ 集群最常见的性能事故。
- 内存侧有 `systemUsage` 三档水位（memory / store / temp），**超过 memory 水位 broker 会反向阻塞生产者**（producer flow control），表象是「发送卡住但没报错」。

### 3.2 高可用：主从 + 级联，没有自动选主

| 方案 | 机制 | 代价 |
| --- | --- | --- |
| Shared-Store Master/Slave | 主从挂同一份存储（NFS / SAN），从节点抢锁接管 | 依赖共享存储的可靠性；切换后客户端要重连 |
| 租约型 Master/Slave（JDBC / 文件锁） | 主节点周期性续租，从节点抢租 | 脑窗存在，需配合 `failover:` 传输重连 |
| Network of Brokers | 多个独立 broker 用 networkConnector 级联，消息可跨 broker 流动 | ⚠️ 消息在桥两端**各存一份**；队列消费者不会跨 broker 自动均衡；advisory 消息易形成风暴 |

> ⚠️ 对比记忆：Kafka 靠**分区副本 + ISR** 横向扩，Pulsar 靠 **BookKeeper quorum + bundle 迁移**，ActiveMQ Classic 只有「主从 + 级联」——**单队列的吞吐与容量上限就是单 broker 的上限**，这是它不适合数据管道的根因。

### 3.3 与 Artemis 的关系

ActiveMQ Artemis 是 Apache **另起炉灶**的实现（源自 HornetQ 捐赠）：核心协议换成自研 Core Protocol + 地址模型（Address/Queue 分离）、存储换成分页 journal、集群用 Raft 无关的 quorum 复制。**两者配置文件、地址语义、集群方式互不兼容**——把 Classic 的 `activemq.xml` 抄进 Artemis 是新人最常见的事故。

---

## 四、消息可靠性 ⭐

| 环节 | 机制 | 会丢 / 会重的场景 |
| --- | --- | --- |
| 生产 | `DeliveryMode.PERSISTENT` + KahaDB journal 落盘；`useAsyncSend=true` 时 send 返回**不等于**已落盘 | 异步发送 + broker 崩溃 = 丢最后一批；非持久消息重启即丢 |
| 存储 | journal 顺序写 + checkpoint；`systemUsage` 水位保护 | store 写满后 broker 拒收持久消息（不是丢，是停） |
| 消费 | `CLIENT_ACKNOWLEDGE` 显式确认；事务会话 `commit/rollback` | `AUTO_ACKNOWLEDGE` 下「已投递未处理」崩溃即丢；`DUPS_OK` 明确允许重复 |
| 重投 | `redeliveryPolicy`：默认 6 次、可指数退避；超限进 `ActiveMQ.DLQ` | DLQ 无人消费会一直占 store，反过来拖垮 broker |
| 幂等 | JMS **没有** exactly-once；`JMSXDeliveryCount` 只能当重投计数参考 | 任何 ack 前崩溃都可能重复投递，业务必须幂等 |
| 顺序 | 单队列 + 单消费者有序；多消费者并发即乱序 | 要同 key 有序只能同队列单消费者，或业务侧分组 |

> ⭐ 结论：ActiveMQ 的可靠性是「**JMS 语义下的 at-least-once**」，和 RabbitMQ 的 confirm+ack、RocketMQ 的同步刷盘是同一档；它不提供 Kafka 那种「日志保留期内任意重放」，**ack 掉的消息就没了**。

---

## 五、差异化能力清单

| 能力 | 说明 | 对比 |
| --- | --- | --- |
| ⭐ **协议覆盖最广** | OpenWire / STOMP / AMQP 1.0 / MQTT 同进程开放 | Kafka 只有自有协议 + REST，Pulsar 多协议但组件重 |
| ⭐ **Request-Reply 原生** | Temporary Queue + `reply-to` 头，调用方自建应答队列即可 | Kafka / RocketMQ 都要自建关联表 |
| ⭐ **Selector 服务端过滤** | SQL92 子集按消息属性过滤，消费者只收匹配消息 | Kafka 无服务端过滤，只能客户端丢 |
| **通配符目的地 / 虚拟消费者** | `Consumer.*.>` 这类订阅可把老代码的目的地名零改造映射走 | 迁移期的独门工具 |
| **延迟投递** | `AMQ_SCHEDULED_DELAY/PERIOD/REPEAT/CRON` 消息属性（毫秒），无需插件 | RocketMQ 靠级别表，Kafka 无原生 |
| ⚠️ 无回放 | ack 即删，没有 retention 重放语义 | Kafka / Pulsar 的核心卖点它没有 |
| ⚠️ 无水平扩展 | 单队列不能拆分区 | 吞吐上限 = 单 broker |

---

## 六、使用一：Go（STOMP）⭐

### 客户端与依赖

| 项 | 说明 |
| --- | --- |
| 官方 Go 客户端 | **没有**。ActiveMQ 官方客户端只有 Java（JMS） |
| 实践路线 | ⭐ **STOMP**：`github.com/go-stomp/stomp/v3`（纯 Go，协议完整）；IoT 场景用 MQTT：`github.com/eclipse/paho.mqtt.golang` |
| 注意 | AMQP 侧 ActiveMQ 开的是 **1.0**，RabbitMQ 生态的 `amqp091-go`（0-9-1）**不通用**，别拿错库 |
| 局限 | JMS 特性在 STOMP 侧靠 header 表达：selector 用 `activemq.selector` 扩展头、延迟用 `AMQ_SCHEDULED_*`；事务只覆盖同一连接上的 SEND |

安装：`go get github.com/go-stomp/stomp/v3@v3.1.5`

### 生产者

```go
package main

import (
	"log"
	"sync"
	"time"

	"github.com/go-stomp/stomp/v3"
)

// ⭐ 连接单例：STOMP 连接是带状态的长连接（订阅、事务、心跳都挂在它上面），进程内只建一次
var conn = sync.OnceValue(func() *stomp.Conn {
	c, err := stomp.Dial("tcp", "127.0.0.1:61613",
		stomp.ConnOpt.Login("admin", "admin"), // ActiveMQ 默认账号，生产改 conf/activemq.xml 里的 simpleAuthenticationPlugin
		stomp.ConnOpt.Host("/"),               // STOMP 1.1+ 要求 host 头，ActiveMQ 不校验取值，给 "/" 即可
		stomp.ConnOpt.AcceptVersion(stomp.V12),
		stomp.ConnOpt.HeartBeat(10*time.Second, 10*time.Second), // 保活，同时快速发现半开连接
	)
	if err != nil {
		log.Fatalf("dial activemq: %v", err)
	}
	return c
})

func main() {
	c := conn()
	defer c.Disconnect()

	// destination 前缀决定语义：/queue 是点对点（竞争消费），/topic 是发布订阅（每个订阅者一份）
	if err := c.Send("/queue/order-events", "application/json", []byte(`{"event":"created"}`),
		stomp.SendOpt.Header("order-key", "ORDER_1001")); err != nil { // 自定义头，供消费端 selector 过滤
		log.Fatalf("send: %v", err)
	}

	// 延迟投递靠 AMQ_SCHEDULED_* 头（单位毫秒）：60 秒后首次投递，之后每 30 秒重复 3 次
	if err := c.Send("/queue/order-events", "application/json", []byte(`{"event":"timeout-check"}`),
		stomp.SendOpt.Header("AMQ_SCHEDULED_DELAY", "60000"),
		stomp.SendOpt.Header("AMQ_SCHEDULED_PERIOD", "30000"),
		stomp.SendOpt.Header("AMQ_SCHEDULED_REPEAT", "3")); err != nil {
		log.Fatalf("send delayed: %v", err)
	}

	// 事务发送：一批 SEND 要么全进要么全不出
	tx, err := c.BeginWithError()
	if err != nil {
		log.Fatalf("begin tx: %v", err)
	}
	if err := tx.Send("/queue/order-events", "application/json", []byte(`{"event":"in-tx"}`)); err != nil {
		if abortErr := tx.Abort(); abortErr != nil {
			log.Printf("abort tx: %v", abortErr)
		}
		log.Fatalf("tx send: %v", err)
	}
	if err := tx.Commit(); err != nil {
		log.Fatalf("commit tx: %v", err)
	}
}
```

### 消费者

```go
package main

import (
	"log"
	"sync"
	"time"

	"github.com/go-stomp/stomp/v3"
)

// ⭐ 连接单例：STOMP 连接是带状态的长连接（订阅、事务、心跳都挂在它上面），进程内只建一次
var conn = sync.OnceValue(func() *stomp.Conn {
	c, err := stomp.Dial("tcp", "127.0.0.1:61613",
		stomp.ConnOpt.Login("admin", "admin"), // ActiveMQ 默认账号，生产改 conf/activemq.xml 里的 simpleAuthenticationPlugin
		stomp.ConnOpt.Host("/"),               // STOMP 1.1+ 要求 host 头，ActiveMQ 不校验取值，给 "/" 即可
		stomp.ConnOpt.AcceptVersion(stomp.V12),
		stomp.ConnOpt.HeartBeat(10*time.Second, 10*time.Second), // 保活，同时快速发现半开连接
	)
	if err != nil {
		log.Fatalf("dial activemq: %v", err)
	}
	return c
})

func main() {
	c := conn()
	defer c.Disconnect()

	sub, err := c.Subscribe("/queue/order-events", stomp.AckClientIndividual,
		stomp.SubscribeOpt.Header("activemq.selector", "order-key = 'ORDER_1001'")) // broker 侧按消息头过滤
	if err != nil {
		log.Fatalf("subscribe: %v", err)
	}
	defer sub.Unsubscribe()

	for i := 0; i < 10; i++ {
		msg, err := sub.Read() // 无超时参数；要控制等待时间就套一层 goroutine + select
		if err != nil {
			log.Printf("read stopped: %v", err)
			return
		}
		if msg.Err != nil { // broker 主动下发的 ERROR（鉴权失败、目的地非法等）
			log.Printf("broker error: %v", msg.Err)
			continue
		}
		log.Printf("dest=%s messageId=%s body=%s", msg.Destination, msg.Header.Get("message-id"), msg.Body)
		if err := c.Ack(msg); err != nil { // ⭐ 先处理业务，成功后再 ack
			log.Printf("ack failed: %v", err)
		}
		// 业务失败时改成 c.Nack(msg)：按 redeliveryPolicy 重投，超过上限进 ActiveMQ.DLQ
	}
}
```

### 请求-应答（Temporary Queue 思路）

```go
package main

import (
	"log"
	"sync"
	"time"

	"github.com/go-stomp/stomp/v3"
)

// ⭐ 连接单例：STOMP 连接是带状态的长连接（订阅、事务、心跳都挂在它上面），进程内只建一次
var conn = sync.OnceValue(func() *stomp.Conn {
	c, err := stomp.Dial("tcp", "127.0.0.1:61613",
		stomp.ConnOpt.Login("admin", "admin"), // ActiveMQ 默认账号，生产改 conf/activemq.xml 里的 simpleAuthenticationPlugin
		stomp.ConnOpt.Host("/"),               // STOMP 1.1+ 要求 host 头，ActiveMQ 不校验取值，给 "/" 即可
		stomp.ConnOpt.AcceptVersion(stomp.V12),
		stomp.ConnOpt.HeartBeat(10*time.Second, 10*time.Second), // 保活，同时快速发现半开连接
	)
	if err != nil {
		log.Fatalf("dial activemq: %v", err)
	}
	return c
})

func main() {
	c := conn()
	defer c.Disconnect()

	// 请求-应答：ActiveMQ 认 STOMP 的 reply-to 头，应答队列由调用方自己命名并先订阅好
	replyQueue := "/queue/reply-order-service"
	sub, err := c.Subscribe(replyQueue, stomp.AckAuto)
	if err != nil {
		log.Fatalf("subscribe reply queue: %v", err)
	}
	defer sub.Unsubscribe()

	if err := c.Send("/queue/order-query", "application/json", []byte(`{"orderId":"ORDER_1001"}`),
		stomp.SendOpt.Header(stomp.ReplyToHeader, replyQueue),
		stomp.SendOpt.Header("correlation-id", "req-1")); err != nil {
		log.Fatalf("send request: %v", err)
	}

	msg, err := sub.Read() // 阻塞直到应答到达
	if err != nil {
		log.Fatalf("read reply: %v", err)
	}
	log.Printf("correlationId=%s reply=%s", msg.Header.Get("correlation-id"), msg.Body)
}
```

> ✅ **校验口径**：以上三段 Go 示例用 `go1.26.5` + `github.com/go-stomp/stomp/v3 v3.1.5` 逐字通过 `go build` / `go vet` / `gofmt -l`；本机没有 ActiveMQ Broker 实例，因此**只编译校验、未实际连集群运行**，文中不含伪造的输出。
> ⚠️ `activemq.selector` 是 ActiveMQ 的 STOMP 扩展头（部分版本也接受标准 `selector` 头），接入前用一条测试消息确认过滤真的在 broker 侧生效。

---

## 七、使用二：Java（官方客户端 + Spring JMS）⭐

### 客户端与依赖

| 项 | 说明 |
| --- | --- |
| 坐标 | `org.apache.activemq:activemq-client:6.3.2`（Classic 主线） |
| JMS 包名 | ⚠️ **5.17 及以前是 `javax.jms`（geronimo-jms_1.1_spec），5.18 起切到 `jakarta.jms-api`**，6.x 延续 jakarta；升级 Spring Boot 3 系必须用 5.18+/6.x |
| Spring | `spring-jms` + `spring-context` 6.2.x（`JmsTemplate` / `@JmsListener`） |
| 端口 | OpenWire **61616**（Java 客户端默认协议），不是 STOMP 的 61613 |

### 连接单例

```java
import jakarta.jms.Connection;
import jakarta.jms.JMSException;
import org.apache.activemq.ActiveMQConnectionFactory;
import org.apache.activemq.RedeliveryPolicy;

/**
 * 连接单例：ConnectionFactory 内含线程池与重连逻辑，Connection 承载 session 与 consumer，
 * 两者都只在建一次，业务侧一律复用。
 */
public final class ActiveMqClient {

    private static final ActiveMQConnectionFactory FACTORY = buildFactory();
    private static volatile Connection connection;

    private ActiveMqClient() {
    }

    private static ActiveMQConnectionFactory buildFactory() {
        ActiveMQConnectionFactory factory = new ActiveMQConnectionFactory(
                "admin", "admin", "tcp://127.0.0.1:61616"); // STOMP 是 61613，openwire 是 61616
        factory.setUseAsyncSend(true);           // 异步发送换吞吐；代价是 send() 返回不等于 broker 已落盘
        factory.setAlwaysSessionAsync(true);
        factory.setWatchTopicAdvisories(false);  // 不订阅 advisory 消息，否则消费者上下线会多推通知
        RedeliveryPolicy redelivery = new RedeliveryPolicy();
        redelivery.setInitialRedeliveryDelay(1000L);
        redelivery.setMaximumRedeliveries(3);    // 超过 3 次进 ActiveMQ.DLQ
        redelivery.setUseExponentialBackOff(true);
        redelivery.setBackOffMultiplier(2);
        factory.setRedeliveryPolicy(redelivery);
        return factory;
    }

    public static Connection connection() throws JMSException {
        Connection current = connection;
        if (current == null) {
            synchronized (ActiveMqClient.class) {
                current = connection;
                if (current == null) {
                    current = FACTORY.createConnection();
                    current.start(); // Connection 建好默认是 stopped，不 start 消费者收不到消息
                    connection = current;
                }
            }
        }
        return current;
    }
}
```

### 生产者

```java
import jakarta.jms.DeliveryMode;
import jakarta.jms.MessageProducer;
import jakarta.jms.Queue;
import jakarta.jms.Session;
import jakarta.jms.TextMessage;

public class OrderProducerDemo {

    public static void main(String[] args) throws Exception {
        // 第一个参数 true = 事务会话：一批 send 要么全提交要么全回滚
        try (Session txSession = ActiveMqClient.connection().createSession(true, Session.SESSION_TRANSACTED)) {
            Queue queue = txSession.createQueue("order-events");
            try (MessageProducer producer = txSession.createProducer(queue)) {
                producer.setDeliveryMode(DeliveryMode.PERSISTENT); // 默认即 PERSISTENT；NON_PERSISTENT 只进内存，重启就丢
                producer.setTimeToLive(60_000L);                   // 60 秒内没投出去就丢弃，不占用队列
                producer.send(txSession.createTextMessage("{\"event\":\"in-tx\"}"));
                txSession.commit(); // 不 commit 就 close，这批消息等于没发
            }
        }

        try (Session session = ActiveMqClient.connection().createSession(false, Session.AUTO_ACKNOWLEDGE)) {
            Queue queue = session.createQueue("order-events");
            try (MessageProducer producer = session.createProducer(queue)) {
                producer.setDeliveryMode(DeliveryMode.PERSISTENT);

                TextMessage created = session.createTextMessage("{\"event\":\"created\"}");
                created.setStringProperty("order-key", "ORDER_1001"); // 自定义属性，供消费端 selector 过滤
                producer.send(created);

                // 延迟投递：ActiveMQ 靠 AMQ_SCHEDULED_DELAY 属性（毫秒），不需要额外插件
                TextMessage timeoutCheck = session.createTextMessage("{\"event\":\"timeout-check\"}");
                timeoutCheck.setLongProperty("AMQ_SCHEDULED_DELAY", 60_000L);
                producer.send(timeoutCheck);
            }
        }
    }
}
```

### 消费者

```java
import jakarta.jms.Message;
import jakarta.jms.MessageConsumer;
import jakarta.jms.Queue;
import jakarta.jms.Session;
import jakarta.jms.TextMessage;

public class OrderConsumerDemo {

    public static void main(String[] args) throws Exception {
        // CLIENT_ACKNOWLEDGE：由业务代码显式 acknowledge，配合「先处理再 ack」才能做到不丢
        try (Session session = ActiveMqClient.connection().createSession(false, Session.CLIENT_ACKNOWLEDGE)) {
            Queue queue = session.createQueue("order-events");
            try (MessageConsumer consumer = session.createConsumer(queue, "order-key = 'ORDER_1001'")) {
                for (int i = 0; i < 10; i++) {
                    Message message = consumer.receive(5_000L); // 毫秒超时；返回 null 表示这段时间没有消息
                    if (message == null) {
                        continue;
                    }
                    try {
                        handle(message);
                        message.acknowledge(); // ⭐ 先处理业务，成功后再 ack
                    } catch (Exception e) {
                        // 不 ack：会话关闭时未确认的消息按 redeliveryPolicy 重投，超过上限进 ActiveMQ.DLQ
                        System.err.println("handle failed, will be redelivered: " + e.getMessage());
                    }
                }
            }
        }
    }

    private static void handle(Message message) throws Exception {
        if (message instanceof TextMessage text) {
            System.out.printf("id=%s redelivery=%d text=%s%n",
                    message.getJMSMessageID(), message.getIntProperty("JMSXDeliveryCount"), text.getText());
        }
    }
}
```

### Spring JMS：JmsTemplate + @JmsListener

```java
import jakarta.jms.ConnectionFactory;
import jakarta.jms.DeliveryMode;
import org.apache.activemq.ActiveMQConnectionFactory;
import org.springframework.context.annotation.Bean;
import org.springframework.context.annotation.Configuration;
import org.springframework.jms.annotation.EnableJms;
import org.springframework.jms.config.DefaultJmsListenerContainerFactory;
import org.springframework.jms.core.JmsTemplate;

@Configuration
@EnableJms
public class ActiveMqSpringConfig {

    // ⭐ 单例 Bean：容器只建一次，业务侧一律注入使用，绝不在方法里 new ConnectionFactory
    @Bean
    public ConnectionFactory jmsConnectionFactory() {
        ActiveMQConnectionFactory factory = new ActiveMQConnectionFactory(
                "admin", "admin", "tcp://127.0.0.1:61616");
        factory.setWatchTopicAdvisories(false);
        return factory;
    }

    @Bean
    public JmsTemplate jmsTemplate(ConnectionFactory connectionFactory) {
        JmsTemplate template = new JmsTemplate(connectionFactory);
        template.setDeliveryMode(DeliveryMode.PERSISTENT);
        template.setExplicitQosEnabled(true); // 不开这行，setDeliveryMode / setTimeToLive 不会真的发给 broker
        return template;
    }

    @Bean
    public DefaultJmsListenerContainerFactory orderListenerContainerFactory(ConnectionFactory connectionFactory) {
        DefaultJmsListenerContainerFactory factory = new DefaultJmsListenerContainerFactory();
        factory.setConnectionFactory(connectionFactory);
        factory.setSessionTransacted(false);
        factory.setConcurrency("5-10"); // 每个 @JmsListener 维持 5~10 个 session（= 并发消费度）
        factory.setErrorHandler(t -> System.err.println("listener error, message will be redelivered: " + t.getMessage()));
        return factory;
    }
}
```

```java
import jakarta.jms.JMSException;
import jakarta.jms.TextMessage;
import org.springframework.jms.annotation.JmsListener;
import org.springframework.stereotype.Component;

@Component
public class OrderJmsListener {

    // selector 在 broker 侧过滤，被过滤掉的消息仍占队列空间，直到过期或被消费才清掉
    @JmsListener(destination = "order-events",
            selector = "order-key = 'ORDER_1001'",
            containerFactory = "orderListenerContainerFactory")
    public void onOrderEvent(TextMessage message) throws JMSException {
        System.out.printf("id=%s redelivery=%d text=%s%n",
                message.getJMSMessageID(), message.getIntProperty("JMSXDeliveryCount"), message.getText());
        // 抛异常 = 不确认：ActiveMQ 按 redeliveryPolicy 重投，超过 maximumRedeliveries 进 ActiveMQ.DLQ
    }
}
```

> ✅ **校验口径**：以上五段 Java 示例（单例 + 原生生产者/消费者 + Spring 配置/监听器）用 JBR 21 + Maven 3.8.1 逐字抽成工程后 `mvn -B clean compile` 通过，实际解析到 `org.apache.activemq:activemq-client:6.3.2` 与 `org.springframework:spring-jms:6.2.19`（`<release>17</release>`）。本机无 ActiveMQ Broker，因此**只编译、未运行**，文中不含伪造输出。

---

## 八、部署

### 8.1 单机起一个 broker

```bash
# 官方镜像名 apache/activemq-classic（标签未在本机核验，按 Maven 主线 6.x 选对应 tag）
docker run -d --name activemq \
  -p 61616:61616 -p 61613:61613 -p 5672:5672 -p 1883:1883 -p 8161:8161 \
  apache/activemq-classic:latest
# 控制台 http://<host>:8161/admin ，默认账号 admin/admin —— 上线前必改
```

### 8.2 生产关键配置（conf/activemq.xml）

| 配置点 | 作用 | 不配的后果 |
| --- | --- | --- |
| `simpleAuthenticationPlugin` | 替换默认 admin/admin | 控制台与 broker 全裸奔 |
| `systemUsage` 的 memory/store/temp 三档 | 生产者流控水位 | 默认值偏小，大流量下生产者被静默阻塞 |
| `policyEntry` 的 `memoryLimit` / `maxPageSize` | 单目的地内存上限 | 一个队列吃光全局内存，拖垮所有目的地 |
| `deadLetterStrategy` | 自定义 DLQ 名与是否保留非持久消息 | 默认全进 `ActiveMQ.DLQ`，难按业务分流 |
| KahaDB `journalMaxFileLength` / `enableIndexWriteAsync` | journal 页大小与索引写策略 | 大消息场景页重写放大 IO |
| `transportConnector` 的 `maximumConnections` | 单协议连接上限 | 连接泄漏时把 broker 打满 |

> ⚠️ 本节配置项名称来自 ActiveMQ Classic 的公开配置模型，**未在本机实机验证**；改之前先在测试环境用 `bin/activemq console` 前台起一次看启动日志。

---

## 九、运维与常见问题

```bash
# 队列水位与消费速率（dstat 每秒刷新：队列大小 / 入队 / 出队 / 在途）
bin/activemq dstat

# 窥探队列里的消息（不消费）：browse 指定队列
bin/activemq browse MY.QUEUE

# 控制台 REST/Jolokia：QueueSize、ConsumerCount、MemoryPercentUsage 等指标都从这里取
curl -s -u admin:admin "http://127.0.0.1:8161/api/jolokia/read/org.apache.activemq:type=Broker,brokerName=localhost,destinationType=Queue,destinationName=order-events"
```

| 症状 | 大概率原因 | 处理 |
| --- | --- | --- |
| 生产者「卡住」但不报错 | 触发 producer flow control：memory/store 水位满 | 看 `MemoryPercentUsage` / `StorePercentUsage`；先消费或调水位，别盲目重启 |
| 整个 broker 变慢、所有队列一起积压 | 单队列/大消息把 KahaDB journal 撑满 | 清掉或消费膨胀队列；消息体裁剪；必要时拆 broker |
| 消费者收到大量「消费者上线/下线」消息 | advisory 消息默认开启 | `setWatchTopicAdvisories(false)` 或关 advisory 目的地 |
| 级联集群里消息「消失又出现」 | Network of Brokers 桥断开后消息回退 | 桥是级联不是复制，重连期消息留在原 broker；评估改主从或换 MQ |
| DLQ 越积越多、store 报警 | 死信无人消费 | 给 `ActiveMQ.DLQ` 配消费/归档任务，或按业务拆 deadLetterStrategy |
| 重启后非持久消息全没 | `DeliveryMode.NON_PERSISTENT` | 业务消息一律 PERSISTENT |
| 同一消息处理了两次 | ack 前崩溃重投（at-least-once） | 业务幂等 + `JMSXDeliveryCount` 打点观察重投率 |

**观测指标优先级**：`QueueSize`（积压）→ `ConsumerCount`（消费者是否掉线）→ `EnqueueCount - DequeueCount`（净增长）→ `MemoryPercentUsage / StorePercentUsage / TempPercentUsage`（水位）→ `AverageMessageSize`（大消息预警）。

---

## 十、ActiveMQ / Artemis / Kafka 一页决策表

| 诉求 | 选谁 | 一句话理由 |
| --- | --- | --- |
| 维护存量 JMS 系统、Spring JMS 绑定 | **ActiveMQ Classic** | 规范实现 + 生态兼容，迁移成本最低 |
| 新项目要 JMS 语义但想要现代存储与集群 | **ActiveMQ Artemis** | 同规范、新内核，分页存储与复制集群 |
| 数据管道、日志、可回放 | **Kafka** | 分区日志 + retention，吞吐与生态无可替代 |
| 业务消息、事务/延迟/顺序全家桶 | **RocketMQ** | 半消息事务与延迟级别是业务侧刚需 |
| IoT 网关、多协议接入但量不大 | **ActiveMQ Classic / EMQX** | MQTT/STOMP/AMQP 同进程；量上来换专用 MQTT broker |
| Go 系服务间轻量通信 | **NATS** | 单二进制、微秒级，见 [Nats.md](Nats.md) |

---

## 十一、延伸追问

- **ActiveMQ 和 Artemis 是什么关系？** → 不是版本升级，是**两套实现**：Artemis 源自 HornetQ 捐赠，核心协议、地址模型、存储与集群方式都重写；Classic 的 `activemq.xml` 在 Artemis 里不认。把两者混为一谈是常见误判。
- **为什么 ActiveMQ 不适合做数据管道？** → 存储是「一个 broker 一个 KahaDB 目录」，单队列不能拆分区，**吞吐上限 = 单 broker 上限**；且 ack 即删、无 retention 重放。Kafka 的分区日志恰好解决这两点。
- **KahaDB 为什么怕大消息？** → journal 按固定页顺序追加，单条消息接近页大小时触发整页重写，IO 放大；再叠加「全局单 store」，一个队列的大消息会拖慢所有目的地。
- **`useAsyncSend=true` 的代价是什么？** → send 返回只代表进了客户端发送队列，**不代表 broker 落盘**；broker 崩溃丢最后一批。要「发了就算数」就用同步发送或带 receipt。
- **怎么保证不丢消息？** → PERSISTENT + CLIENT_ACKNOWLEDGE（先处理再 ack）+ redeliveryPolicy 兜底重投 + DLQ 有人消费 + 业务幂等；任何一环缺了，丢的形态都不一样。
- **selector 过滤是在哪一侧做的？** → broker 侧匹配、但**消息仍留在队列里**直到被消费或过期——用 selector 做「多租户隔离」会白白占存储，这是它和 Kafka 消费组过滤的本质区别。

---

## 关联

- [消息队列选型.md](消息队列选型.md) — 六款 MQ 的横向对比与选型决策（含「契合语言」维度）
- [Kafka.md](Kafka.md) — 分区日志路线的对照：为什么数据管道不选 ActiveMQ
- [RabbitMQ.md](RabbitMQ.md) — 同为「业务消息 broker」，路由模型与 confirm 语义的对照
- [RocketMQ.md](RocketMQ.md) — 事务/延迟消息的另一种实现（半消息 vs AMQ_SCHEDULED_*）
- [Pulsar.md](Pulsar.md) — 存算分离路线：把 KahaDB 单 store 换成 BookKeeper
- [Nats.md](Nats.md) — Go 系轻量通信的对照选项
- [../../可观测性/Skywalking.md](../../可观测性/Skywalking.md) — 消息链路的 trace 埋点思路相通
