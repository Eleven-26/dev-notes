# NATS 消息系统

> NATS 的定位与核心概念、Core NATS / JetStream 两套语义、部署与鉴权、Go 与 Java 双端示例、可靠性设计要点。
>
> 内容整理自个人学习笔记，并结合 photography-server 项目的实际用法整理。消息队列整体选型见 [消息队列选型.md](消息队列选型.md)。

## 一、一句话定位

NATS 是 **云原生、轻量级、高性能**的发布订阅消息系统，CNCF 毕业项目。核心只有一个 `nats-server` 二进制（Go 编写、无外部依赖），设计哲学是「**简单 + 快 + 低延迟**」。

| 维度 | NATS | Kafka / RocketMQ |
| --- | --- | --- |
| 部署形态 | ⭐ 单二进制直接跑；集群靠 gossip 自动组网 | 依赖 ZooKeeper / KRaft / NameServer |
| 核心抽象 | Subject（轻量字符串）+ 通配符订阅 | Topic + Partition / MessageQueue |
| 默认语义 | Core NATS 是 At-Most-Once 纯内存转发 | 默认落盘、可回溯 |
| 延迟 | ⭐ 微秒~亚毫秒（Core NATS） | 毫秒级 |
| 定位 | 服务间通信 / 服务治理 / IoT / 边缘 | 大数据管道 / 日志流 / 削峰堆积 |

> 一句话记忆：**Kafka 是「分布式提交日志」，NATS 是「分布式消息总线」**。前者为吞吐与回溯而生，后者为低延迟与极简运维而生。

## 二、核心概念

| 概念 | 说明 | 备注 |
| --- | --- | --- |
| **Subject** | 消息地址，形如 `photography.order.created`，用 `.` 分层 | ⭐ 支持 `*`（匹配一层）与 `>`（匹配剩余全部） |
| **Publish / Subscribe** | 发布者向 Subject 发、订阅者按 Subject 收；发布者不关心有无订阅者 | 默认广播：无队列组时每个订阅者都收到一份 |
| **Queue Group** | ⭐ 同一 Subject + 同一队列组名的订阅者**竞争消费**，一条消息只投给组内一个实例 | 即负载均衡；组与组之间互不影响 |
| **Request-Reply** | 请求方 `Request(subj, data, timeout)`，响应方回写 `msg.Reply` | ⭐ 内建模式，不必自己约定 reply 主题 |
| **JetStream** | ⭐ 持久化与流处理层：Stream 落盘、ACK 重投、重放、KV、Object Store | 服务端须以 `-js` 启动 |
| **Stream / Consumer** | Stream 是「消息集合 + 保留策略」，Consumer 是消费视图（游标） | JetStream 独有，Core NATS 没有 |
| **Leaf Node** | 叶子节点挂到远端 Hub，只同步关注的 Subject 子集 | 边缘 / 多租户 / DMZ |

**通配符**：`photography.order.created` 精确匹配；`photography.*.created` 匹配 `photography.order.created` 但不匹配 `photography.order.a.created`；`photography.>` 匹配其下全部层级。

**Request-Reply 流程**：请求方发消息并把 `reply` 设为自动生成的收件箱 `INBOX.xyz` → 响应方处理完向 `INBOX.xyz` 回写 → 收到即返回，超时返回 `nats.ErrTimeout`。`INBOX` 由客户端自动生成。

> ⚠️ **发起方必须保持连接**才能收到响应；请求方掉线或超时，响应就丢。

## 三、消息模型对照 ⭐

Core NATS 与 JetStream 是同一个连接上的两种用法，这是理解 NATS 的关键。

| 维度 | Core NATS | JetStream |
| --- | --- | --- |
| 投递语义 | ⭐ **At-Most-Once**（可能丢） | ⭐ **At-Least-Once**（默认）/ **Exactly-Once**（配 `Msg-Id` 去重） |
| 持久化 | ❌ 无，纯内存转发 | ✅ 落盘（File / Memory），可配副本数 |
| 订阅者不在线 | ⚠️ **消息直接丢弃** | ✅ Stream 保留，上线后从游标继续 |
| 重放 / 回溯 | ❌ 不支持 | ✅ `DeliverAll` / `DeliverLast` / 按时间或 Seq |
| 背压 | ⚠️ 靠订阅端 pending 队列，写满即 SlowConsumer 丢消息 | ✅ 服务端 `MaxAckPending` 限流 |
| 延迟 | 微秒~亚毫秒 | 略高（多一次落盘 + ACK 往返） |
| 典型用途 | 服务间 RPC / 事件通知 / 心跳 / 服务发现 | 订单状态流转、支付回调等可靠异步链路 |

**与 Kafka 的概念映射**：

| Kafka | NATS JetStream | 说明 |
| --- | --- | --- |
| Topic | Stream | 一个 Stream 可绑多个 Subject |
| Partition | ❌ 无直接对应 | NATS Stream 不分区，靠 Subject 分层与多 Stream 分散 |
| Consumer Group | Consumer（Durable + Queue Group） | Durable 名 = 消费进度载体；队列组 = 组内负载均衡 |
| Offset | Consumer 游标（`DeliverPolicy` + Ack） | NATS 用 ACK 驱动游标，而非纯 offset 提交 |
| Retention | `MaxAge` / `MaxMsgs` / `MaxBytes` | 均为「到点 / 超限即删」 |
| Replication | Stream `Replicas` | 需 3 节点集群才具备真正容错 |

> ⭐ 最大认知差异：**Kafka 消费组「共享一个 offset」且分区与成员绑定；NATS 队列组「共享一个投递队列」由服务端随机派发**。因此 NATS 不保证「同一 key 稳定落到同一消费者」，也就没有分区内顺序可言。

## 四、整体架构

| 形态 | 说明 | 适用 |
| --- | --- | --- |
| 单机 | 一个 `nats-server` 进程 | 开发、单机小流量 |
| 集群（Cluster） | 多节点通过 **route** 组成全网状 mesh，客户端连任一节点即可 | 高可用、水平扩容 |
| Super Cluster | 多集群用 **gateway** 互联 | 跨区域容灾 |
| Leaf Node | 叶子节点挂到 Hub，只同步关注的 Subject 子集 | 边缘计算、多租户、DMZ |

**为什么 NATS 不需要外部队列协调组件** ⭐

| 组件 | Kafka | RocketMQ | NATS |
| --- | --- | --- | --- |
| 元数据存储 | ZooKeeper / KRaft | NameServer | ⭐ **无**，节点间 gossip 自发现 |
| 集群成员发现 | 依赖上述组件 | 依赖 NameServer 地址列表 | ⭐ 配 `routes` 即可，mesh 自动收敛 |
| 消费进度 | Broker 内 `__consumer_offsets` | Broker 内 ConsumerOffset | Consumer 状态**就在 Stream 副本里** |
| 运维复杂度 | 高（多组件协同） | 中 | ⭐ 极低，一个二进制 |

关键点：**NATS 把路由、成员发现、共识全部内建在 Server 里**——集群靠 gossip 收敛成全网状 route，JetStream 用内嵌 Raft 组维护 Stream 副本与 Consumer 状态。因此没有 ZooKeeper / NameServer 这类「第二个要运维的系统」。

## 五、部署

```yaml
# docker-compose.yml
services:
  nats:
    image: nats:2.10-alpine
    container_name: nats
    command: ["-c", "/etc/nats/nats.conf", "-js", "-sd", "/data"]  # -js 开 JetStream；-sd 落盘目录
    ports:
      - "4222:4222"   # 客户端连接
      - "8222:8222"   # HTTP 监控 / 健康检查
      - "6222:6222"   # 集群路由（单机可不开）
    volumes: ["./nats.conf:/etc/nats/nats.conf:ro", "./data:/data"]
    healthcheck:      # 依赖 nats.conf 里的 http_port: 8222，否则探针恒失败
      test: ["CMD-SHELL", "wget -q -O /dev/null http://127.0.0.1:8222/healthz || exit 1"]
      interval: 5s
      timeout: 3s
      retries: 12
    restart: unless-stopped
```

```conf
# nats.conf：令牌鉴权 + JetStream
port: 4222
http_port: 8222

authorization {
  token: "s3cret-token"     # 客户端侧：nats.Token("s3cret-token")
}

jetstream {
  store_dir: /data
  max_memory_store: 512MB
  max_file_store: 2GB
}
```

> ⚠️ `/healthz` 探针依赖配置里的 `http_port: 8222`；若没开监控端口，探针恒失败并把容器标为 unhealthy——photo-server 的 compose 注释专门点了这个坑。

**三种鉴权方式**：

| 方式 | 配置 | 客户端（Go） | 适用 |
| --- | --- | --- | --- |
| Token | `authorization { token: "xxx" }` | `nats.Token("xxx")` | 内网最小成本 |
| User / Password | `users = [ { user, password, permissions } ]` | `nats.UserInfo("app", "pwd")` | ⭐ 可配 Subject 级权限 |
| NKey / JWT | `users = [ { nkey: "UD..." } ]` + `nsc` 账号体系 | `nats.Nkey(pub, sigCB)` | 生产多租户，Server 不存密钥 |

用户名密码方式可顺手做出最小权限（如 `permissions: { publish: ["photography.>"], subscribe: ["photography.>"] }`）。集群模式再加 `cluster { name, listen: 0.0.0.0:6222, routes: ["nats://nats-1:6222", ...] }` 即可。

> ⚠️ `StreamConfig.Replicas` 只有在**集群节点数 ≥ Replicas** 时才有意义；单机部署必须写 `Replicas: 1`，否则建流直接报错。

## 六、使用一：Go ⭐

photography-server 用的是 `github.com/nats-io/nats.go v1.53.1`。

### 6.1 连接与重连选项

```go
nc, err := nats.Connect("nats://127.0.0.1:4222",
	nats.MaxReconnects(10),            // -1 表示无限重连
	nats.ReconnectWait(2*time.Second), // 两次重连之间的等待
	nats.Token("s3cret-token"),        // 或 nats.UserInfo("app", "app-pass")
	nats.DisconnectErrHandler(func(_ *nats.Conn, err error) { log.Printf("disconnected: %v", err) }),
	nats.ReconnectHandler(func(nc *nats.Conn) { log.Printf("reconnected: %s", nc.ConnectedUrl()) }),
)
if err != nil {
	log.Fatalf("nats connect failed: %v", err)
}
defer nc.Drain() // 优雅关闭，见 6.6
```

> ⭐ 项目实际写法：`InitNATS` 用 `sync.Once` 保证单例，且**只在首次连接成功时**构建 JetStream 客户端——`nc.JetStream()` 失败就打告警降级为「仅 Core NATS」，而不是让整个服务起不来。

### 6.2 发布 / 订阅

```go
// 发布：Core NATS 即发即忘，不等待任何确认
_ = nc.Publish("photography.order.created", []byte(`{"id":1}`))
_ = nc.Flush() // 需要确认「已写入 socket」时调用，Publish 本身只写客户端缓冲

// 订阅：同步注册、异步回调
sub, err := nc.Subscribe("photography.order.created", func(m *nats.Msg) {
	log.Printf("subject=%s data=%s", m.Subject, string(m.Data))
})
if err != nil {
	return err
}
defer sub.Unsubscribe()
```

带 Header 的消息（项目里用来透传链路上下文）用 `nc.PublishMsg(&nats.Msg{Subject: s, Header: hdr, Data: data})`。

### 6.3 队列组（Queue Group）

```go
// 同一队列组内的多个实例竞争消费：一条消息只被其中一个处理
sub, err := nc.QueueSubscribe("order.status.change", "notify-workers",
	func(m *nats.Msg) { log.Printf("[worker] %s", string(m.Data)) })
```

> ⚠️ 队列组只做**随机派发式**负载均衡，不保证同一业务键（如 order_id）稳定落到同一实例。业务依赖顺序时（订单状态流转、支付回调），应按业务键分片到不同 Subject，而不是加并发。

### 6.4 Request-Reply

```go
// 响应方
_, err := nc.Subscribe("svc.echo", func(m *nats.Msg) {
	// m.Reply 是请求方自动生成的收件箱 Subject，必须向它回写
	_ = nc.Publish(m.Reply, append([]byte("echo: "), m.Data...))
})

// 请求方：超时未响应返回 nats.ErrTimeout；需要带 Header 时用 nc.RequestMsg(msg, timeout)
reply, err := nc.Request("svc.echo", []byte("hello"), 2*time.Second)
if err != nil {
	return err // errors.Is(err, nats.ErrTimeout) 即对端不可用 / 处理超时
}
log.Printf("reply=%s", string(reply.Data))
```

### 6.5 JetStream：持久化、ACK 与两种消费模式

```go
js, err := nc.JetStream() // 服务端未开 -js 时会报错
if err != nil {
	return err
}

// 1) 建流：把 photography.> 收进 PHOTOGRAPHY
_, err = js.AddStream(&nats.StreamConfig{
	Name: "PHOTOGRAPHY", Subjects: []string{"photography.>"},
	Storage: nats.FileStorage, Retention: nats.LimitsPolicy, // 落盘 + 达限淘汰
	MaxMsgs: -1, MaxBytes: -1, MaxAge: 24 * time.Hour,       // -1 = 不限；保留 24h
	Replicas: 1, NoAck: false,                               // ⚠️ 单机必须 Replicas=1
})
// 2) Push 消费：服务端推、回调自动触发
_, err = js.Subscribe("photography.order.created.persistent",
	func(m *nats.Msg) {
		if err := handle(m.Data); err != nil { _ = m.Nak(); return } // 否定应答 → 立即重投
		_ = m.Ack()
	},
	nats.Durable("photography_order_created_persistent"),
	nats.ManualAck(), nats.DeliverAll(), nats.MaxDeliver(3), // ⭐ 手动 ACK / 从最早投 / 最多 3 次
	nats.AckWait(30*time.Second), nats.MaxAckPending(64),    // ⭐ 在途未 ACK 上限 → 服务端限流
)

// 3) Pull 消费：客户端拉取，天然背压
pullSub, _ := js.PullSubscribe("photography.order.pull", "photography_order_pull",
	nats.DeliverAll(), nats.MaxDeliver(3), nats.AckWait(30*time.Second), nats.MaxAckPending(64))
for {
	msgs, err := pullSub.Fetch(32, nats.MaxWait(500*time.Millisecond))
	if errors.Is(err, nats.ErrTimeout) { continue }      // 空队列属正常
	if err != nil { time.Sleep(time.Second); continue }  // 退避，避免错误风暴空转
	for _, m := range msgs { // ⭐ 批内串行处理，保留同 Subject 顺序
		if err := handle(m.Data); err != nil { _ = m.Nak(); continue }
		_ = m.Ack()
	}
}

// 4) 队列组 + JetStream：持久化且负载均衡
_, err = js.QueueSubscribe("photography.order.pull", "order-workers", handler,
	nats.Durable("order_workers"), nats.ManualAck())

// 5) 服务端去重（Exactly-Once 的近似实现）：相同 Msg-Id 在窗口（默认 2min）内只存一次
_, err = js.PublishMsg(&nats.Msg{
	Subject: "photography.payment.callback.persistent",
	Header:  nats.Header{nats.MsgIdHdr: []string{"pay-20260924-0001"}},
	Data:    payload,
}, nats.AckWait(5*time.Second))
```

### 6.6 优雅关闭：Drain 与 Close 的区别 ⭐

| 方法 | 行为 | 适用 |
| --- | --- | --- |
| `nc.Close()` | ⭐ **立即**取消所有订阅、丢弃在途回调与待发缓冲、断开连接 | 进程被强杀 / 只想立刻断开 |
| `nc.Drain()` | ⭐ **先**取消订阅（不再收新消息）→ **等在途回调跑完** → 冲刷待发缓冲 → 关闭连接 | 服务优雅停机 |

> ⚠️ 生产停机务必用 `Drain()`：`Close()` 会打断正在处理的消息，JetStream 消息因未 ACK 会在 `AckWait` 后被重投（At-Least-Once 下不算错，但造成重复消费与日志噪声）。
>
> ⚠️ `Drain()` 是**阻塞**的，别在 HTTP handler 里直接调用；应放在关停流程中并配超时兜底（例如 10s 后强制 `Close()`）。项目里的 `CloseNATS()` 用的是 `nc.Close()`，调用前**必须先 `mq.Consumer.Stop()`** 停掉消费循环，否则回调可能正跑就被掐断。

### 6.7 贴近项目的完整初始化示例

```go
package infrastructure

import (
	"context"
	"sync"
	"time"

	"github.com/nats-io/nats.go"
	"go.opentelemetry.io/otel"
	"go.opentelemetry.io/otel/propagation"
)

const defaultStream, defaultSubject = "PHOTOGRAPHY", "photography.>"

var (
	natsConn *nats.Conn
	jsCtx    nats.JetStreamContext
	once     sync.Once
	initErr  error
)

// InitNATS 单例初始化：连接 + 幂等建流；JetStream 不可用时降级为仅 Core NATS，不阻断启动。
func InitNATS(url string) error {
	once.Do(func() {
		natsConn, initErr = nats.Connect(url,
			nats.MaxReconnects(10),
			nats.ReconnectWait(2*time.Second),
			nats.DisconnectErrHandler(func(_ *nats.Conn, err error) { log.Printf("disconnected: %v", err) }),
			nats.ReconnectHandler(func(nc *nats.Conn) { log.Printf("reconnected: %s", nc.ConnectedUrl()) }),
		)
		if initErr != nil {
			return
		}
		js, err := natsConn.JetStream()
		if err != nil {
			log.Printf("jetStream unavailable, fallback to core nats: %v", err)
			return // 降级
		}
		jsCtx = js
		ensureStream(defaultStream, defaultSubject)
	})
	return initErr
}

// ensureStream 幂等建流：不存在则 Add，Subject 配置不一致则 Update。
func ensureStream(name, subject string) {
	cfg := &nats.StreamConfig{
		Name: name, Subjects: []string{subject},
		Storage: nats.FileStorage, Retention: nats.LimitsPolicy,
		MaxMsgs: -1, MaxBytes: -1, MaxAge: 24 * time.Hour, Replicas: 1, NoAck: false,
	}
	info, err := jsCtx.StreamInfo(name)
	switch {
	case err != nil:
		jsCtx.AddStream(cfg)
	case len(info.Config.Subjects) == 0 || info.Config.Subjects[0] != subject:
		jsCtx.UpdateStream(cfg)
	}
}

// traceMsg 生产端 Inject：把 ctx 里的 W3C TraceContext 注入消息 Header，供消费端 Extract 续接链路。
func traceMsg(ctx context.Context, subject string, data []byte) *nats.Msg {
	hdr := make(nats.Header)
	otel.GetTextMapPropagator().Inject(ctx, propagation.HeaderCarrier(hdr))
	return &nats.Msg{Subject: subject, Header: hdr, Data: data}
}
```

发布：非持久化走 `natsConn.PublishMsg(traceMsg(ctx, subject, data))`，持久化走 `jsCtx.PublishMsg(...)` 并返回 `PubAck`（Stream 被误删时重建后重试一次）。
消费端做链路续接（Extract）：`otel.GetTextMapPropagator().Extract(ctx, propagation.HeaderCarrier(msg.Header))` 取出父 span，再 `tracer.Start(parent, "nats."+msg.Subject)` 派生处理 span，业务内 SQL / RPC 即自动挂到同一 trace。

## 七、使用二：Java ⭐

```xml
<!-- Maven 依赖；仅在 NKey 鉴权时需要额外引入 net.i2p.crypto:eddsa:0.3.0 -->
<dependency>
  <groupId>io.nats</groupId>
  <artifactId>jnats</artifactId>
  <version>2.20.5</version>
</dependency>
```

```java
import io.nats.client.*;
import io.nats.client.api.*;
import java.nio.charset.StandardCharsets;
import java.time.Duration;
import java.util.List;
import java.util.concurrent.TimeUnit;

// 连接（鉴权 + 重连 + 事件监听）
Options options = new Options.Builder()
        .server("nats://127.0.0.1:4222")
        .token("s3cret-token")                 // 或 .userInfo("app", "app-pass")
        .maxReconnects(10)                     // -1 = 无限重连
        .reconnectWait(Duration.ofSeconds(2))
        .connectionListener((conn, type) -> System.out.println("nats event=" + type))
        .build();
Connection nc = Nats.connect(options);

// Publish / Subscribe
nc.publish("photography.order.created", "{\"id\":1}".getBytes(StandardCharsets.UTF_8));
nc.flush(Duration.ofSeconds(2));               // 确认写入 socket
Dispatcher dispatcher = nc.createDispatcher(msg -> System.out.println(new String(msg.getData(), StandardCharsets.UTF_8)));
dispatcher.subscribe("photography.order.created");

// QueueSubscribe：同一队列组内竞争消费
Dispatcher workers = nc.createDispatcher(msg -> System.out.println("[worker] " + new String(msg.getData())));
workers.subscribe("order.status.change", "notify-workers");

// Request-Reply
Dispatcher svc = nc.createDispatcher(msg -> nc.publish(msg.getReplyTo(), "echo".getBytes()));
svc.subscribe("svc.echo");
Message reply = nc.request("svc.echo", "hello".getBytes(StandardCharsets.UTF_8))
        .get(2, TimeUnit.SECONDS);             // 超时抛 TimeoutException

// JetStream：建流（已存在则 update）
JetStream js = nc.jetStream();
StreamConfiguration sc = StreamConfiguration.builder()
        .name("PHOTOGRAPHY").subjects("photography.>")
        .storageType(StorageType.File)         // File / Memory
        .retentionPolicy(RetentionPolicy.Limits)
        .maxAge(Duration.ofHours(24))
        .replicas(1)                           // ⚠️ 单机必须为 1
        .build();
try {
    nc.jetStreamManagement().addStream(sc);
} catch (JetStreamApiException e) {
    nc.jetStreamManagement().updateStream(sc);
}

// 持久化发布，拿 PubAck
PublishAck ack = js.publish("photography.order.created", "{\"id\":1}".getBytes(StandardCharsets.UTF_8));
System.out.println("stream=" + ack.getStream() + " seq=" + ack.getSeqno());

// JetStream Push 订阅 + 手动 ACK（等价 Go 的 nats.Durable + nats.ManualAck）
JetStreamSubscription sub = js.subscribe("photography.order.created",
        PushSubscribeOptions.builder().durable("photography_order_created").build());
Message m = sub.nextMessage(Duration.ofSeconds(1));
if (m != null) { try { m.ack(); } catch (Exception e) { m.nak(); } }  // 处理失败 → 否定应答重投

// JetStream QueueSubscribe：持久化 + 组内负载均衡
Dispatcher jsWorkers = nc.createDispatcher(msg -> msg.ack());
js.subscribe("photography.order.created", "order-workers", jsWorkers, false,
        PushSubscribeOptions.builder().durable("order_workers").build());

// JetStream Pull 消费：客户端拉取，天然背压
PullSubscribeOptions pullOpts = PullSubscribeOptions.builder().durable("photography_order_pull").build();
JetStreamSubscription pullSub = js.subscribe("photography.order.pull", pullOpts);
List<Message> batch = pullSub.fetch(32, Duration.ofMillis(500));
for (Message pm : batch) {
    pm.ack();
}

// 关闭：drain 优雅（冲空在途回调后关闭），close 强制立即断开
nc.drain(Duration.ofSeconds(10));
nc.close();
```

## 八、可靠性设计要点

### 8.1 Core NATS 不持久化 ⭐

> ⚠️ **Core NATS 的 `Publish` 在「无订阅者」或「订阅者离线」时消息直接丢弃，没有任何回执。** 即使订阅者在线，若其客户端 pending 队列写满（nats.go 默认 65536 条 / 64MB），库会报 `SlowConsumer` 并**丢消息**。

判断标准很简单——「这条消息丢了，要不要报警 / 补数据？」要，就上 JetStream：服务发现、心跳、指标上报归 Core NATS；订单状态流转、支付回调、需重试回溯的异步链路归 JetStream。

### 8.2 消息去重

| 手段 | 做法 | 边界 |
| --- | --- | --- |
| 服务端去重 | 发布带 `nats.MsgIdHdr`（Java 侧 `NatsMessage.builder().id(...)`），Stream 按去重窗口（默认 2min）滤重 | ⚠️ 只覆盖窗口内，窗口外重发仍入库 |
| 消费端幂等 | 唯一键 / 去重表 / 状态机（「仅当状态为上一步时才推进」） | ⭐ 唯一可靠的做法，跨系统适用 |

### 8.3 ACK 策略与重投

| 选项 | 语义 | 用在哪 |
| --- | --- | --- |
| `ManualAck()` | 业务代码自行 `m.Ack()` | ⭐ 默认推荐，处理成功才 ACK |
| 自动 ACK | 回调返回即 ACK | ⚠️ 处理失败也会被 ACK，等于丢消息 |
| `m.Nak()` | 否定应答 → 立即重投 | 可重试的失败（下游限流、临时超时） |
| `m.Term()` | 终止投递，不再重试 | 不可恢复的错误（如报文格式非法） |
| `m.InProgress()` | 延长 `AckWait` | 长耗时任务，避免被误判超时重投 |

重投节奏由 `AckWait`（默认 30s）+ `MaxDeliver`（如 3）共同决定：**ACK 超时或 NAK → 重投 → 超过 MaxDeliver → 不再自动投递**。

> ⚠️ JetStream 的 `MaxDeliver` **没有内建死信队列**，超限后消息只是停在 Stream 里（可订阅 `$JS.EVENT.ADVISORY.CONSUMER.MAX_DELIVERIES.*` 感知）。落地时必须**自己把该事件转存到死信 Stream 或落库告警**，否则坏消息静默沉底。
>
> ⚠️ 项目对 handler panic 的处理正是：**就地 `recover` + `msg.Nak()`**。旧实现 recover 后重新 panic，会把 nats 内部读循环 goroutine 打崩——单条坏消息即可导致整个服务重启，且消息因未 ACK 被反复重投。

### 8.4 流量控制与背压

| 层级 | 机制 | 说明 |
| --- | --- | --- |
| Stream | `MaxMsgs` / `MaxBytes` / `MaxAge` | 超限按 `Retention` 淘汰，防磁盘打满 |
| Consumer | ⭐ `MaxAckPending` / `RateLimit` | 在途未 ACK 上限，达到即**停止投递**；`RateLimit` 限投递速率 |
| 客户端 | pending 队列大小 | Core NATS 侧唯一缓冲，写满即 SlowConsumer 丢消息 |
| 模式 | 重负载下 Pull 优于 Push | 拉取天然背压，可批量、可暂停 |

同一个 Subject 上的消息按订阅串行处理（nats.go 每订阅一个 goroutine），**一条慢消息会阻塞该 Subject 的后续消息**，不同 Subject 互不影响。所以「每条消息起一个 goroutine 导致爆炸」并不存在；真实风险是**队头阻塞**与 **pending 队列写满丢消息**。提吞吐的正确姿势是**按业务键分片到不同 Subject / Stream**，而不是无脑加并发（会破坏顺序语义）。

## 九、使用场景与选型

**适合**：⭐ 微服务间低延迟通信（微秒级、无需额外组件，天然 RPC / 事件总线）；⭐ IoT / 边缘（Leaf Node 只同步关心的 Subject 子集，弱网断连自动重连）；服务治理（内建 Request-Reply 可做轻量服务发现 / 健康探测）；需要极简运维（单二进制，K8s 里一个 Deployment 搞定）；实时推送 / 在线状态。

**不适合**：⚠️ 海量堆积 + 长期回溯的日志流（NATS 不分区、无顺序读存储优化）→ 选 **Kafka**；⚠️ 强顺序 + 分区级严格有序（队列组随机派发，不保证同 key 同消费者）→ 选 **Kafka / RocketMQ**；⚠️ 延迟消息 / 事务消息（无内置延迟级别与半消息事务）→ 选 **RocketMQ**；⚠️ 需要 AMQP / MQTT / STOMP 全协议适配 → 选 **RabbitMQ**。

| 维度 | NATS | Kafka | RocketMQ | RabbitMQ |
| --- | --- | --- | --- | --- |
| 外部依赖 | ⭐ 无 | ZooKeeper / KRaft | NameServer | Erlang Mnesia |
| 延迟 | ⭐ 微秒~亚毫秒 | 毫秒 | 毫秒 | ⭐ 微秒~毫秒 |
| 吞吐 | 百万级 | ⭐ 十万~百万级 | 十万级 | 万~十万级 |
| 持久化 / 回溯 | JetStream（较弱） | ⭐ 极强 | ⭐ 强 | 中等 |
| 顺序消息 | ❌ 不保证 | ⭐ 分区内有序 | ⭐ 队列级有序 | 单队列有序 |
| 延迟消息 | ❌ 无内置 | ❌ 无内置 | ⭐ 原生支持 | 靠 TTL + DLX |
| 事务消息 | ❌ | 提供（偏流处理） | ⭐ 半消息 + 回查 | ❌ |
| 运维成本 | ⭐ 极低 | 高 | 中 | 中 |

> 选型一句话：**要低延迟 + 极简运维 → NATS；要吞吐 + 回溯 → Kafka；要延迟消息 + 事务消息 → RocketMQ；要灵活路由 + 协议适配 → RabbitMQ。**

## 十、面试官会追问什么

1. **Core NATS 会丢消息吗？** 会。消息发到无人订阅（或订阅者离线）的 Subject 直接丢弃，发布端无回执；订阅者在线但 pending 队列写满（默认 65536 条 / 64MB）也会被判 SlowConsumer 丢消息。**要可靠必须用 JetStream**。
2. **Queue Group 与 Kafka 消费者组的区别？** Kafka 消费组是「分区分配给成员、共享一个 offset」，分区内严格有序、成员变动触发 rebalance；NATS 队列组是「服务端把每条消息随机派给组内一个订阅者」，**不绑定分区、不保证同 key 同实例、不保证顺序**，但也没有 rebalance 停顿。
3. **JetStream 的 ACK 与重投怎么工作？** 消费端必须在 `AckWait`（默认 30s）内 ACK；未 ACK 或 `Nak()` 即重投；累计到 `MaxDeliver` 后停止自动投递。`MaxAckPending` 限在途未 ACK 数，达到即暂停投递（服务端限流）；长耗时任务用 `InProgress()` 续期。
4. **NATS 为什么不需要外部队列协调组件？** 路由、成员发现、元数据与副本一致性全部内建在 Server：集群靠 gossip 收敛成全网状 route，JetStream 用内嵌 Raft 组维护 Stream 副本与 Consumer 状态。对比 Kafka 的 ZooKeeper / KRaft、RocketMQ 的 NameServer，NATS 只需一个二进制。
5. **`Drain()` 和 `Close()` 的区别？** `Close()` 立即取消订阅、丢弃在途回调、断开连接；`Drain()` 先取消订阅（不再收新消息）→ 等在途回调执行完 → 冲刷待发缓冲 → 再关闭。**生产停机用 Drain**，否则正在处理的消息被掐断，JetStream 消息因未 ACK 会在 `AckWait` 后被重投，造成重复消费。
6. **Core NATS 和 JetStream 怎么共存？** 两者是同一 `nats.Conn` 上的两种用法：`nc.Publish/Subscribe` 走纯内存通道，`nc.JetStream()` 拿到的 `JetStreamContext` 走持久化通道。可对同一 Subject 同时使用——Core 订阅做实时广播，JetStream Consumer 做可靠兜底，互不影响。
7. **同一 Subject 上的消息如何保证顺序？** NATS 不提供跨消息顺序保证。要顺序只能：① 单订阅 + 串行回调（牺牲吞吐）；② 按业务键（如 `order_id`）分片到不同 Subject / Stream；③ 消费端状态机校验，容忍乱序。**不要用加并发提吞吐**——会破坏顺序。
8. **At-Least-Once 下如何做到「业务上只执行一次」？** 两条腿：① 发布端带 `Msg-Id`，靠 Stream 去重窗口（默认 2min）做服务端过滤；② 消费端幂等（唯一键 / 去重表 / 状态机条件更新），这是跨系统唯一可靠的手段。切勿依赖中间件的 Exactly-Once。

## 关联

- [消息队列选型.md](消息队列选型.md) — 什么时候该选 NATS
- [Kafka.md](Kafka.md) — 日志型流平台的对照
- [../../数据存储/redis/发布订阅.md](../../数据存储/redis/发布订阅.md) — 轻量 Pub/Sub 的边界
