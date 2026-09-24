# RabbitMQ 消息代理

> 覆盖 RabbitMQ 的定位与核心概念、AMQP 四种交换机路由模型、可靠性与确认机制、死信/延迟队列、集群高可用、Docker 部署，以及 Go（amqp091-go）与 Java（amqp-client / Spring AMQP）双端完整示例。
>
> 内容整理自个人学习笔记。四款消息队列的横向对比与选型见 [消息队列选型.md](消息队列选型.md)。

## 一、一句话定位

RabbitMQ 是基于 **AMQP 0-9-1** 协议的开源消息代理，由 **Erlang** 编写（借鉴了 Erlang 的 Actor 模型与轻量进程，天然高并发、低延迟）。它以**灵活的路由能力**（交换机 + 绑定 + 路由键三方解耦）、成熟稳定、插件生态丰富著称。吞吐量中等（单机**万级到十万级** msg/s），胜在**微秒~毫秒级低延迟与路由灵活**，非常适合**业务系统解耦**；不擅长海量日志管道与超大规模回溯重放。选型细节见 [消息队列选型.md](消息队列选型.md)。

| 特征 | 落点 |
|---|---|
| 协议 | AMQP 0-9-1（另有 MQTT / STOMP / AMQP 1.0 插件） |
| 语言 | Erlang，单机吞吐万级~十万级，延迟最低 |
| 路由模型 | ⭐ Exchange + Binding + RoutingKey，支持 direct/fanout/topic/headers |
| 可靠性 | durable 队列 + 持久化消息 + Publisher Confirm + 手动 ack + Quorum 队列 |
| 典型场景 | 业务解耦、微服务异步、延迟/死信、优先级队列、RPC over MQ |
| 不擅长 | 海量日志、大数据管道、按 offset 任意回溯（消费即删） |

## 二、核心概念 ⭐

| 概念 | 说明 | 关键点 |
|---|---|---|
| **Producer** | 消息生产者，只与 Exchange 打交道、不直接投队列 | 发消息必须带 exchange + routingKey |
| **Consumer** | 消息消费者，只从 Queue 取消息、不知道 Exchange 存在 | 同一队列可多个消费者竞争消费（P2P 负载均衡） |
| **Connection** | 客户端与 Broker 之间的 **TCP 长连接** | 一个进程通常只需一条 Connection |
| **Channel** | ⚠️ **复用同一条 TCP 连接上的虚拟连接**，绝大部分 API 都在 Channel 上 | ⚠️ **Channel 不是线程安全的**，多线程要么各自建 Channel，要么加锁；Channel 数量本身很轻量，不要共用 |
| **Exchange（交换机）** | 接收生产者消息并按规则路由到一个或多个队列 | 本文核心，见第三节 |
| **Queue（队列）** | 实际存储消息的实体，FIFO | 消费 ack 后消息即被删除（消费即删，不能回溯） |
| **Binding（绑定）** | Exchange 与 Queue 之间的连接关系 | 绑定时可带 BindingKey / 参数，是路由的依据 |
| **RoutingKey / BindingKey** | 生产者发送时指定的路由键 / 绑定时声明的匹配键 | direct 要求两者相等；topic 按通配匹配 BindingKey |
| **VHost（虚拟主机）** | 逻辑隔离单元，含自己的 Exchange/Queue/Binding | ⭐ 权限也以 vhost 为粒度分配；默认 `/` |

**Virtual Host vs 数据库**：vhost 之于 RabbitMQ，类似 database 之于 MySQL——同一个 Broker 内可用 `/prod`、`/test` 隔离不同环境或租户，资源互不可见；但注意：**vhost 之间完全隔离**，跨 vhost 无法直接绑定，连接字符串里的路径段就是 vhost（`amqp://user:pwd@host:5672/%2Fprod`，其中 `/` 需 URL 编码为 `%2F`）。

## 三、四种交换机（本文核心）⭐

生产者从不直接发消息给队列，而是发给 Exchange，由 Exchange 按 **Binding + RoutingKey** 决定投递到哪些队列。这就是 RabbitMQ 与其他 MQ（Topic 直接对应分区）最大的区别。

| 类型 | 路由规则 | 典型场景 | 备注 |
|---|---|---|---|
| **direct**（默认行为） | RoutingKey 与 BindingKey **完全相等** | 点对点、按业务类型分流（`order.created` / `order.paid`） | 最常用、语义最清晰 |
| **fanout** | **忽略 RoutingKey**，广播到所有与它绑定的队列 | 广播通知、缓存刷新、多系统同步 | 一条消息复制到 N 个队列（每队列一份） |
| **topic** | RoutingKey 按 **`*`（一个词）** 与 **`#`（零到多个词）** 通配匹配 BindingKey | 按业务维度灵活订阅（`order.*.created`、`order.#`） | 通配符只用于 BindingKey，路由键用 `.` 分词 |
| **headers** | 按消息头（headers）**键值匹配**，`x-match: all/any` | 复杂条件路由（无法用路由键表达时） | ⚠️ 性能差、可读性低，**极少使用**，能用 topic 就别用它 |

> **topic 通配规则**：`*` 匹配恰好一个词，`#` 匹配零个或多个词。`order.*.created` 能匹配 `order.pay.created`，不匹配 `order.created`；`order.#` 能匹配 `order`、`order.pay.created`、`order.a.b.c`。

**系统与默认交换机**：

| 交换机 | 说明 |
|---|---|
| **默认交换机 `""`**（空字符串） | 每个队列创建时**自动绑定**到默认交换机，BindingKey 就是队列名。所以 `basicPublish("", "queueName", ...)` 等价于「直接投该队列」，不走自定义路由 |
| `amq.direct` / `amq.fanout` / `amq.topic` / `amq.headers` | Broker 内置的**预声明交换机**，可直接用；`amq.rabbitmq.trace` 用于消息轨迹 |
| `amq.` 前缀 | ⚠️ **保留前缀**，不要自定义以 `amq.` 开头的交换机/队列，否则报 `ACCESS_REFUSED` |

**路由模型对照**（RabbitMQ 的 Exchange 模型 vs Kafka/RocketMQ 的 Topic 模型）：

| 维度 | RabbitMQ（Exchange 模型） | Kafka / RocketMQ（Topic 模型） |
|---|---|---|
| 寻址方式 | Producer → Exchange →（Binding/RoutingKey）→ Queue | Producer → Topic（→ 分区/队列） |
| 路由时机 | **Broker 端**由交换机按绑定规则路由 | 生产者端选择分区，Broker 不参与路由 |
| 二级过滤 | 靠 RoutingKey 通配 + headers | Kafka 无原生 Tag；RocketMQ 有 Tag（Broker 端过滤） |
| 一条消息的多播 | 用 fanout/topic 让一条消息进多个队列，各自独立消费 | 靠多个 Consumer Group 各消费一次 |
| 队列数上限 | 单机万级队列会明显影响性能 | RocketMQ 万级队列无压力；Kafka 分区多则文件句柄与随机 IO 压力大 |

## 四、消息可靠性与确认机制 ⭐

「消息不丢」要**同时**管住生产、存储、消费三段，任何一段缺失都会丢。

### 4.1 生产者侧：Publisher Confirm vs 事务

| 机制 | 原理 | 性能 | 结论 |
|---|---|---|---|
| **Publisher Confirm** ⭐ | Channel 开启 `confirm` 模式后，每条消息得到一个唯一 `deliveryTag`，Broker 落盘后异步回 `basic.ack` / `basic.nack` | 高，批量 confirm 更快 | **生产推荐**，异步确认 + 失败重发 |
| **AMQP 事务**（`txSelect`/`txCommit`/`txRollback`） | 显式事务包裹发布操作，提交后才生效 | ⚠️ **极差**（每条消息同步等待，吞吐量断崖式下降） | 几乎不用，除非特殊场景 |

```java

channel.confirmSelect();  // 开启 Confirm
channel.basicPublish("ex", "rk", MessageProperties.PERSISTENT_TEXT_PLAIN, body);
channel.waitForConfirmsOrDie(5000);   // 同步等待（简单但慢）；异步用 addConfirmListener + SortedSet 缓存未确认消息
```

> Go 端同理：`ch.Confirm(false)` 开启确认模式，再用 `ch.NotifyPublish` / `ch.NotifyReturn` 接收回执（见 8.2）。

### 4.2 Broker 侧：持久化与队列类型

| 维度 | 配置 | 说明 |
|---|---|---|
| 队列持久化 | `durable=true` | 队列元数据写磁盘，重启后队列仍在 |
| 消息持久化 | `DeliveryMode=2`（`amqp.Persistent` / `MessageProperties.PERSISTENT_TEXT_PLAIN`） | 消息写入磁盘；⚠️ 与队列 durable 必须**同时满足**，只设一个仍会丢 |
| 惰性队列 | `x-queue-mode: lazy` | 消息尽量留在磁盘、减少内存占用，适合大堆积场景 |

**镜像队列 vs Quorum 队列**：

| 维度 | 镜像队列（Mirrored，⚠️ 已淘汰） | **Quorum 队列** ⭐ |
|---|---|---|
| 复制机制 | 基于 Erlang 的 Mnesia，主从镜像 | 基于 **Raft 共识**，多数派确认 |
| 推荐版本 | 3.8 前的默认高可用方案 | **3.8 起官方推荐**，**4.0 已移除镜像队列** |
| 一致性 | 弱（脑裂易丢数据） | 强（多数派写入成功才 ack） |
| 声明方式 | policy 配置 | `x-queue-type: quorum` |
| 代价 | 同步阻塞、性能随副本增加下降 | 要求 ≥3 节点、不支持部分特性（如优先级、惰性模式） |

### 4.3 消费者侧：ack / nack / reject 与 prefetch

| 操作 | 语义 | 何时用 |
|---|---|---|
| `basicAck(deliveryTag, multiple)` | 确认消费成功，消息从队列删除 | 业务处理成功后 |
| `basicNack(deliveryTag, multiple, requeue)` | 拒绝，可一次拒多条；`requeue=true` 重新入队 | 处理失败需要重试 |
| `basicReject(deliveryTag, requeue)` | 拒绝单条，语义同 nack 的单条版 | 单条失败 |

- **`prefetch`（`basic.qos` / `channel.Qos`）**：限制**每个消费者未 ack 的消息上限**，防止消费者被压垮、实现「能者多劳」的公平分发。⚠️ 不设 `prefetch`（默认无限制）时，Broker 会一次性把队列消息全推给消费者，内存瞬间被打爆。
- ⚠️ **`requeue` 陷阱**：失败后 `requeue=true` 会把消息**重新放回队头**，若消费者反复失败，会陷入**无限重入队死循环**（消息卡在队头、CPU 空转）。正确做法是**配置死信队列** + 限制重试次数，或 `requeue=false` 直接进 DLX。

### 4.4 消息不丢的三个必要条件 ⭐

| 环节 | 条件 | 缺一不可 |
|---|---|---|
| 生产端 | ✅ Publisher Confirm（异步确认 + 失败重发） | 否则 Broker 未收到也以为成功 |
| Broker 端 | ✅ 队列持久化 `durable` **+** 消息持久化 `delivery_mode=2` **+** Quorum 队列（多副本） | 只持久化/只多副本都不够 |
| 消费端 | ✅ **先处理业务，再手动 ack**（`autoAck=false`） | 先 ack 后处理失败则消息已丢 |

## 五、死信队列与延迟队列 ⭐

### 5.1 DLX（Dead Letter Exchange，死信交换机）

当消息在队列中「无法被正常消费」时，可被投递到指定的死信交换机，再由死信队列兜底处理。**触发场景有三种**：

| 触发场景 | 说明 |
|---|---|
| **被 reject / nack 且 `requeue=false`** | 消费者明确拒绝且不重新入队 |
| **消息 TTL 过期** | 消息或队列设置了 `x-message-ttl` / `x-expires` 且到期未消费 |
| **队列达到最大长度** | 触发 `x-max-length` / `x-max-length-bytes` 后，队头消息被挤出 |

声明时通过队列参数指定死信目标：

```java

Map<String, Object> args = new HashMap<>();
args.put("x-dead-letter-exchange", "dlx.exchange");       // 死信交换机
args.put("x-dead-letter-routing-key", "dlx.routing");      // 可选：改写路由键
args.put("x-message-ttl", 60_000);                         // 可选：消息 TTL（毫秒）
args.put("x-max-length", 100000);                          // 可选：队列最大长度
channel.queueDeclare("biz.queue", true, false, false, args);
```

> ⚠️ `x-message-ttl` 是**消息存活时间**；`x-expires` 是**队列空闲存活时间**（队列无消费者且未访问达到时间则整队删除），两者别混。

### 5.2 延迟队列的两种实现

**方案 A：TTL + DLX（不依赖插件）**

思路：给业务队列设 TTL，消息过期后自动进入 DLX → 死信队列，死信队列的消费者即为「延迟到期后」的处理者。

⚠️ **队头阻塞（Head-of-Line Blocking）**：消息 TTL 是**队列级别**时，队列只检查**队头**消息是否过期，若队头未过期，后面即使已过期的消息也不会被投递到死信队列——导致实际延迟远大于预期。规避方式是为**每个延迟粒度建独立队列**（如 1min/5min/30min 各一条），但延迟时间无法动态变化。

**方案 B：`rabbitmq_delayed_message_exchange` 插件（推荐）**

安装后可使用类型为 `x-delayed-message` 的交换机，消息通过 header `x-delay`（毫秒）指定延迟，到期后由交换机投递到目标队列。支持**任意延迟时间**、无需为每个粒度建队列，是当前延迟消息的主流做法。

| 维度 | TTL + DLX | 延迟插件 |
|---|---|---|
| 延迟精度 | 受队头阻塞影响，偏大且不精确 | 较高，接近设定值 |
| 任意延迟 | ❌ 需为每个延迟粒度建一条队列 | ✅ 支持任意毫秒值 |
| 额外依赖 | 无（原生） | 需安装插件并重启 Broker |
| 消息存储 | 存于队列（有 DLX 兜底，相对稳） | ⚠️ 存于**交换机**内，不落常规队列，**单节点宕机可能丢**（集群下每个节点各持有一份） |
| 适用 | 延迟粒度少、体量小 | 需要任意延迟、可接受插件依赖 |

```bash

# 安装延迟插件（Docker 方式见第七节）
rabbitmq-plugins enable rabbitmq_delayed_message_exchange
```

## 六、集群与高可用

| 形态 | 复制内容 | 高可用 | 说明 |
|---|---|---|---|
| **普通集群** | 仅同步**元数据**（Exchange/Queue/Binding、vhost 配置） | ❌ 队列数据只在**创建它的单节点**上，节点挂则该队列不可用 | 提高元数据可靠性与接入能力，不提供数据冗余 |
| **Quorum 队列** | 队列数据基于 **Raft** 复制到多节点，**多数派确认**后才 ack | ✅ 少数派节点故障不影响可用性 | 3.8 起推荐，4.0 起唯一推荐的复制队列类型；要求 ≥3 节点（奇数） |

> ⚠️ 经典「镜像队列」在 **4.0 已移除**，新系统一律用 Quorum 队列。

**网络分区（脑裂）处理策略**（`cluster_partition_handling`，需在配置中三选一）：

| 策略 | 行为 | 适用 |
|---|---|---|
| `pause_minority` ⭐ | 少数派节点**自我暂停**（停止服务），等恢复通信后再加入 | 推荐，优先保数据一致 |
| `autoheal` | 分区恢复后，选一个「获胜」分区，**丢弃其他分区状态** | 更看重可用性、可接受丢数据 |
| `ignore` | 不做处理，交由人工介入 | 极少数明确知道自己在做什么的场景 |

**负载均衡与接入**：客户端应连**负载均衡器**而非固定节点，常见用 **HAProxy / Nginx / 云 LB** 对 5672 做 TCP 转发（或 LVS）；管理台可用 Keepalived + VIP。⚠️ 由于队列数据与节点绑定，LB 只是分摊**连接**，不能解决「队列在哪个节点」的问题，Quorum 队列才能真正故障切换。

## 七、部署（Docker Compose）

```yaml

# docker-compose.yml —— 生产需在此基础上加固
services:
  rabbitmq:
    image: rabbitmq:3-management
    container_name: rabbitmq
    hostname: rabbitmq                 # ⚠️ 集群时 hostname 必须可解析且唯一
    ports:
      - "5672:5672"                    # AMQP 协议端口（应用连接）
      - "15672:15672"                  # 管理控制台（浏览器访问）
    environment:
      RABBITMQ_DEFAULT_USER: admin     # ⚠️ 生产必须改掉默认 guest
      RABBITMQ_DEFAULT_PASS: CHangeMe_Str0ng
      RABBITMQ_DEFAULT_VHOST: /prod
      RABBITMQ_VM_MEMORY_HIGH_WATERMARK: "0.6"   # 内存高水位，触发流控
    volumes:
      - rabbitmq_data:/var/lib/rabbitmq          # 持久化数据目录
      - ./enabled_plugins:/etc/rabbitmq/enabled_plugins  # 如需启插件
    restart: unless-stopped

volumes:
  rabbitmq_data:
```

```bash

docker compose up -d
# 管理台：http://localhost:15672   默认账号 guest/guest
# ⚠️ guest 仅允许从 localhost 登录，生产必须新建用户并授予 vhost 权限：
docker exec rabbitmq rabbitmqctl add_user admin 'CHangeMe_Str0ng'
docker exec rabbitmq rabbitmqctl set_permissions -p /prod admin ".*" ".*" ".*"
docker exec rabbitmq rabbitmqctl set_user_tags admin administrator
docker exec rabbitmq rabbitmqctl delete_user guest
```

**生产必须做的几件事**：

| 事项 | 做法 |
|---|---|
| 改默认用户 | 删除 `guest`，建专用账号并按 vhost 最小授权 |
| 开持久化 | 队列 `durable=true` + 消息 `delivery_mode=2` + 数据卷挂载 |
| 用 Quorum 队列 | 声明 `x-queue-type: quorum`，集群部署 ≥3 节点 |
| 限制 prefetch | 消费者设 `basicQos`（如 30~100），避免一次推爆内存 |
| 开监控 | 暴露 Prometheus 插件指标、配置内存/磁盘/连接数告警 |
| 配死信 | 核心队列配 DLX + 队列长度上限，防 requeue 死循环 |
| 资源上限 | 调 `vm_memory_high_watermark`、`disk_free_limit`，避免磁盘写满拖垮 Broker |

## 八、使用一：Go（amqp091-go）⭐

> ⭐ 使用官方维护的 **`github.com/rabbitmq/amqp091-go`**。⚠️ 旧路径 `github.com/streadway/amqp` **已归档停止维护**，不要再在新项目中使用。

### 8.1 声明（交换机 / 队列 / 绑定）

```go

package mq

import (
	"context"
	"log"
	"time"

	amqp "github.com/rabbitmq/amqp091-go"
)

const (
	ExchangeName = "order.exchange"
	QueueName    = "order.queue"
	RoutingKey   = "order.created"
)

// Conn 封装连接与 channel，供生产者/消费者复用
type Conn struct {
	conn *amqp.Connection
	ch   *amqp.Channel
}

func NewConn(uri string) (*Conn, error) {
	conn, err := amqp.Dial(uri) // 例：amqp://admin:pwd@127.0.0.1:5672/%2Fprod
	if err != nil {
		return nil, err
	}
	ch, err := conn.Channel() // ⚠️ Channel 非线程安全，多 goroutine 需各自 Channel
	if err != nil {
		_ = conn.Close()
		return nil, err
	}
	// 1) 声明交换机：定义在交换机上，队列侧无需重复
	if err := ch.ExchangeDeclare(
		ExchangeName, // name
		"direct",     // kind: direct / fanout / topic / headers
		true,         // durable：持久化
		false,        // autoDelete
		false,        // internal
		false,        // noWait
		nil,          // args
	); err != nil {
		return nil, err
	}
	// 2) 声明队列：可附带死信、TTL 等参数
	args := amqp.Table{
		"x-dead-letter-exchange": "dlx.exchange",
	}
	if _, err := ch.QueueDeclare(
		QueueName, // name
		true,      // durable
		false,     // autoDelete
		false,     // exclusive
		false,     // noWait
		args,      // args
	); err != nil {
		return nil, err
	}
	// 3) 绑定：把队列绑到交换机，指定 BindingKey
	if err := ch.QueueBind(
		QueueName,    // queue
		RoutingKey,   // key（BindingKey）
		ExchangeName, // exchange
		false,        // noWait
		nil,          // args
	); err != nil {
		return nil, err
	}
	return &Conn{conn: conn, ch: ch}, nil
}
```

### 8.2 发布（含持久化消息 + Confirm）

```go

func (c *Conn) Publish(ctx context.Context, body []byte) error {
	// 开启 Confirm 模式：Broker 确认后才认为发送成功
	if err := c.ch.Confirm(false); err != nil {
		return err
	}
	confirms := c.ch.NotifyPublish(make(chan amqp.Confirmation, 1))

	if err := c.ch.PublishWithContext(
		ctx,
		ExchangeName, // exchange
		RoutingKey,   // routingKey
		false,        // mandatory：无匹配队列时退回
		false,        // immediate
		amqp.Publishing{
			ContentType:  "application/json",
			DeliveryMode: amqp.Persistent, // ⭐ 2：消息持久化，须配合 durable 队列
			Body:         body,
		},
	); err != nil {
		return err
	}

	select {
	case cf := <-confirms:
		if !cf.Ack {
			return err // Broker 明确拒绝，需重发
		}
		log.Printf("publish confirmed, tag=%d", cf.DeliveryTag)
	case <-time.After(5 * time.Second):
		log.Printf("publish confirm timeout，请重发")
	}
	return nil
}
```

### 8.3 消费（Qos 预取 + 手动 ack + 优雅关闭）

```go

func (c *Conn) Consume(ctx context.Context, handler func(body []byte) error) error {
	// ⭐ 预取限流：每个消费者未 ack 的消息不超过 30 条，公平分发 + 防内存爆
	if err := c.ch.Qos(
		30,    // prefetchCount
		0,     // prefetchSize，0 表示不限制字节
		false, // global：false=每消费者独立
	); err != nil {
		return err
	}

	msgs, err := c.ch.Consume(
		QueueName, // queue
		"",        // consumer tag，空则自动生成
		false,     // autoAck：⚠️ 必须 false，手动确认
		false,     // exclusive
		false,     // noLocal
		false,     // noWait
		nil,       // args
	)
	if err != nil {
		return err
	}

	for {
		select {
		case <-ctx.Done(): // 优雅关闭：处理完在途消息后退出
			log.Println("context canceled, stop consuming")
			return nil
		case d, ok := <-msgs:
			if !ok {
				return nil // channel 关闭（连接断开等）
			}
			if err := handler(d.Body); err != nil {
				// ⚠️ requeue=false：失败进死信队列；requeue=true 会无限重入队
				_ = d.Nack(false, false)
				continue
			}
			_ = d.Ack(false) // 业务成功后确认
		}
	}
}
```

> ⚠️ 生产环境还应监听 `c.conn.NotifyClose(make(chan *amqp.Error))`，在连接抖动时**自动重连并重建 Channel、重声明资源**，否则消费者会静默失效。

## 九、使用二：Java（amqp-client / Spring AMQP）⭐

### 9.1 原生 amqp-client

```xml

<dependency>
  <groupId>com.rabbitmq</groupId>
  <artifactId>amqp-client</artifactId>
  <version>5.21.0</version>
</dependency>
```

```java

public class NativeDemo {
    public static void main(String[] args) throws Exception {
        ConnectionFactory factory = new ConnectionFactory();
        factory.setHost("127.0.0.1");
        factory.setVirtualHost("/prod");
        factory.setUsername("admin");
        factory.setPassword("CHangeMe_Str0ng");
        factory.setAutomaticRecoveryEnabled(true);   // ⭐ 自动重连 + 恢复 Channel/Consumer

        try (Connection conn = factory.newConnection();
             Channel ch = conn.createChannel()) {   // ⚠️ Channel 非线程安全，勿跨线程共享
            ch.exchangeDeclare("order.exchange", BuiltinExchangeType.TOPIC, true);
            ch.queueDeclare("order.queue", true, false, false, null);
            ch.queueBind("order.queue", "order.exchange", "order.*.created");

            // 发布：PERSISTENT_TEXT_PLAIN 等价 delivery_mode=2
            ch.basicPublish("order.exchange", "order.pay.created",
                    MessageProperties.PERSISTENT_TEXT_PLAIN,
                    "{\"orderId\":\"1001\"}".getBytes(StandardCharsets.UTF_8));

            // 消费：关闭自动 ack（第 2 个参数 false），设定 prefetch
            ch.basicQos(30);
            DeliverCallback onDelivery = (tag, delivery) -> {
                try {
                    System.out.println("recv=" + new String(delivery.getBody()));
                    ch.basicAck(delivery.getEnvelope().getDeliveryTag(), false);
                } catch (Exception e) {
                    // ⚠️ requeue=false：进死信；true 会无限重入队
                    ch.basicNack(delivery.getEnvelope().getDeliveryTag(), false, false);
                }
            };
            ch.basicConsume("order.queue", false, onDelivery, tag -> { });
            Thread.sleep(Long.MAX_VALUE);
        }
    }
}
```

### 9.2 Spring AMQP / Spring Boot

```xml

<dependency>
  <groupId>org.springframework.boot</groupId>
  <artifactId>spring-boot-starter-amqp</artifactId>
</dependency>
```

```yaml

# application.yml
spring:
  rabbitmq:
    host: 127.0.0.1
    port: 5672
    username: admin
    password: CHangeMe_Str0ng
    virtual-host: /prod
    publisher-confirm-type: correlated   # ⭐ 开启 Publisher Confirm
    listener:
      simple:
        acknowledge-mode: manual         # ⭐ 手动 ack
        prefetch: 30                     # ⭐ 预取限流
        retry:
          enabled: true
          max-attempts: 3                # 本地重试 3 次后按 reject 处理
```

```java

// 片段：发送（自动序列化对象；交换机/队列/绑定在下方 @RabbitListener 中用 @QueueBinding 声明）
@Service
public class OrderProducer {
    private final RabbitTemplate rabbitTemplate;

    public OrderProducer(RabbitTemplate rabbitTemplate) {
        this.rabbitTemplate = rabbitTemplate;
    }

    public void send(OrderDTO order) {
        // 默认用 SimpleMessageConverter（JDK 序列化）；生产建议换 Jackson2JsonMessageConverter
        rabbitTemplate.convertAndSend("order.exchange", "order.pay.created", order);
    }
}
```

```java

// 片段：消费 —— 注解声明交换机和队列（无需上面的 @Bean），手动 ack
@Component
public class OrderConsumer {

    @RabbitListener(bindings = @QueueBinding(
            value = @Queue(value = "order.queue", durable = "true",
                    arguments = {
                        @Argument(name = "x-dead-letter-exchange", value = "dlx.exchange"),
                        @Argument(name = "x-message-ttl", value = "60000", type = "java.lang.Long")
                    }),
            exchange = @Exchange(value = "order.exchange", type = "topic", durable = "true"),
            key = "order.*.created"))
    public void onMessage(OrderDTO order, Message message, Channel channel) throws IOException {
        long tag = message.getMessageProperties().getDeliveryTag();
        try {
            // 业务处理（务必幂等）
            channel.basicAck(tag, false);              // ⭐ 处理成功后手动确认
        } catch (Exception e) {
            // 第三个参数 requeue=false，失败进死信队列，避免无限重入队
            channel.basicNack(tag, false, false);
        }
    }
}
```

> **重试与死信**：Spring 的 `RetryTemplate`（`spring.rabbitmq.listener.simple.retry.*`）在**本地内存**重试；重试耗尽后若配置了 `RepublishMessageRecoverer` 可自动转发到死信交换机，否则按 `default-requeue-rejected=false` 拒绝进死信。⚠️ 本地重试会阻塞该消费者预取配额，次数不宜过大。

## 十、常见问题与坑 ⚠️

| 坑 | 现象 / 原因 | 应对 |
|---|---|---|
| **Channel 非线程安全** | 多线程共用同一 Channel，出现帧错乱、`IllegalStateException`、消息串包 | 每线程/每 goroutine 独立 Channel，或用连接池（如 `rabbitmq:amqp-client` 的 `CachingConnectionFactory` 线程池语义） |
| **prefetch 不设** | 队列一次性把全部消息推给消费者，客户端 OOM、其他消费者空闲 | 设 `basicQos`，一般 30~100，视处理耗时调整 |
| **消息堆积触发流控** | 内存超 `vm_memory_high_watermark`（默认 40%）后 Broker 对**所有连接**执行 `blocking`，生产者被阻塞 | 扩消费者提高消费速度、设消息/队列 TTL、开惰性队列、扩大内存或调高水位 |
| **连接抖动导致反复重连** | 网络不稳，消费者断连重连，期间消息可能重复 | 开启自动恢复（Java `setAutomaticRecoveryEnabled(true)`；Go 手动重连）+ 消费端幂等 |
| **管理台看不到队列** | 登录后选错 **vhost**，默认 `/` 与业务 vhost 不同 | 右上角切换 vhost，或 URL 指定 vhost |
| **`PRECONDITION_FAILED`** ⚠️ | 用**不同的 durable/autoDelete/args** 重复声明同名队列/交换机 | 声明参数必须完全一致；已存在的旧队列需**先删重建**或改新名字。⚠️ 该异常是 **channel 级**异常，会导致**整个 Channel 关闭**，而非单次操作失败 |
| **延迟消息不准** | TTL + DLX 的队头阻塞 | 换用延迟插件，或按延迟粒度分队列 |

## 十一、面试官会追问什么 ⭐

1. **四种交换机的区别？** direct 按 RoutingKey 精确匹配；fanout 忽略路由键广播到所有绑定队列；topic 按 `*`（一个词）/`#`（零到多个词）通配；headers 按消息头键值匹配（`x-match: all/any`），性能差极少用。默认交换机 `""` 会把消息直接投给同名队列，`amq.*` 是 Broker 预置的系统交换机。
2. **如何保证消息不丢？** 三段都要管：生产端用 Publisher Confirm（异步确认 + 失败重发）而非事务；Broker 端队列 `durable` + 消息 `delivery_mode=2` + Quorum 队列多副本；消费端 `autoAck=false`，**先处理业务再 ack**，处理异常用 nack 并配死信兜底。
3. **如何实现延迟队列、有什么坑？** 两种方案：① TTL + DLX，不依赖插件但存在**队头阻塞**（队列级 TTL 只检查队头），延迟不准且每个粒度要建独立队列；② `rabbitmq_delayed_message_exchange` 插件，用 `x-delay` 头支持任意延迟、推荐，但消息暂存在交换机中、节点宕机有丢失风险。
4. **Quorum 队列和镜像队列的区别？** 镜像队列基于 Mnesia 异步主从镜像、一致性弱、易脑裂丢数据，**4.0 已被移除**；Quorum 队列基于 **Raft**、多数派确认才 ack、强一致，**3.8 起官方推荐**，代价是需 ≥3 节点且不支持优先级等部分特性。
5. **prefetch（basic.qos）的作用？** 限制每个消费者**未 ack 的消息数量上限**，实现公平分发（能者多劳）并防止消费者内存被打爆。不设时 Broker 会一次性把队列消息全部推给消费者。
6. **消息重复消费怎么办？** RabbitMQ 只保证 **at-least-once**（网络抖动、ack 丢失、重连都会重投），中间件不做去重，只能消费端幂等：DB 唯一索引（插入型业务）、去重表（同事务写入已处理标记）、Redis SETNX、业务状态机 CAS 更新；⚠️ 去重键用**业务唯一键**而非消息 ID。
7. **requeue 有什么坑？** 消费者处理失败 `requeue=true` 会把消息**重新放回队头**，若失败原因与消息内容相关（脏数据、反序列化失败），会陷入**无限重入队死循环**，消息卡队头、CPU 空转。应改为 `requeue=false` 进死信队列，并限制重试次数、配 DLX + 告警。
8. **什么场景选 RabbitMQ 而不是 Kafka / RocketMQ？** 中小规模业务解耦、需要**灵活路由**（多维度订阅、广播、headers 条件路由）、**低延迟**、消息量在万级~十万级、运维要轻量时选 RabbitMQ；海量日志管道与回溯重放选 Kafka，需要事务消息/任意延迟/严格顺序的大型业务选 RocketMQ（详见 [消息队列选型.md](消息队列选型.md)）。

## 关联

- [消息队列选型.md](消息队列选型.md) — 路由能力在选型中的权重
- [Kafka.md](Kafka.md)、[RocketMQ.md](RocketMQ.md) — 另外两条技术路线
- [../../分布式/分布式事务.md](../../分布式/分布式事务.md) — 事务消息与最终一致
