# Go 接入 RocketMQ

> 覆盖 Go 侧接入 RocketMQ 的完整手册：两种客户端怎么选、生产者（同步 / Tag / Key / 延迟级别）、消费者（Tag 过滤）、顺序消息的 `HashQueueSelector`，以及一组对真实单机集群**编译并运行**的输出。
>
> 不覆盖：RocketMQ 的概念、存储与可靠性机制见 [RocketMQ 目录导读](../../../03-数据与中间件/中间件/消息队列/RocketMQ/README.md)（零件篇从 [核心概念与架构.md](../../../03-数据与中间件/中间件/消息队列/RocketMQ/核心概念与架构.md) 起读）；Java 侧写法见 [接入RocketMQ.md](../../java/接入RocketMQ.md)。
>
> 内容整理自个人学习笔记。本篇代码在 `golang:1.26-alpine`（Go 1.26.8）+ `rocketmq-client-go/v2` 下编译运行通过，对本机单机集群（`apache/rocketmq:5.3.1`，1 NameServer + 1 Broker）实测。

---

## 一、客户端二选一

```bash
# 老版 remoting 协议客户端（对应 4.x Broker，社区最常用）
go get github.com/apache/rocketmq-client-go/v2@v2.1.2
# 5.x 官方新客户端（gRPC 协议，需 Broker/Proxy 开启 gRPC 端口，默认 8081）
go get github.com/apache/rocketmq-clients/golang/v5
```

| 客户端 | 协议 | 适用 Broker | 能力边界 |
| --- | --- | --- | --- |
| `rocketmq-client-go/v2` | remoting（私有 TCP） | 4.x 完全匹配；5.x 兼容可用 | 普通 / 顺序 / 延迟级别消息可用；**无任意延迟**；v2.1.x 后基本停更 |
| `rocketmq-clients/golang/v5` | gRPC | 5.x（需 proxy） | 支持任意延迟等 5.x 新特性 |

⚠️ 用 Go 老客户端连 5.x 集群时，`WithDelayTimeLevel` 在 5.x Broker 上仍按 18 个固定档位生效，**不会**升级为任意延迟（见 [消息类型与语义.md](../../../03-数据与中间件/中间件/消息队列/RocketMQ/消息类型与语义.md) 第三节）。

> ⚠️ **两个实测踩到的坑**：
>
> 1. **NameServer 地址必须是 IP**。`producer.WithNameServer([]string{"rmq-namesrv:9876"})` 写容器名会直接 panic：`new Namesrv failed.: IP addr error`——这个客户端在构造 `Namesrv` 时要求地址能被解析成 IP，**不支持纯主机名**。跨容器实测时把 NameServer 与 Broker 都固定成 IP 最省事（Java 客户端没有这个限制）。
> 2. **`consumer.Tag` 不存在**，Tag 过滤的常量是 `consumer.TAG`（全大写），写错直接编译不过。

## 二、生产者

```go
package main

import (
	"context"
	"fmt"

	"github.com/apache/rocketmq-client-go/v2"
	"github.com/apache/rocketmq-client-go/v2/primitive"
	"github.com/apache/rocketmq-client-go/v2/producer"
)

func main() {
	p, err := rocketmq.NewProducer(
		producer.WithNameServer([]string{"192.168.48.2:9876"}), // ⚠️ IP，不是主机名
		producer.WithGroupName("lab_go_producer"),
		producer.WithRetry(2), // 发送失败重试次数
	)
	if err != nil {
		panic(err)
	}
	if err = p.Start(); err != nil {
		panic(err)
	}
	defer p.Shutdown()

	msg := primitive.NewMessage("lab-go", []byte("go-sync-1"))
	msg.WithTag("TagA")
	msg.WithKeys([]string{"ORDER_GO_1001"})
	msg.WithDelayTimeLevel(3) // 4.x 语义：level 3 = 10s 后投递

	res, err := p.SendSync(context.Background(), msg)
	if err != nil {
		fmt.Println("send error:", err)
		return
	}
	fmt.Printf("GO-SYNC status=%v queueId=%d msgId=%s\n",
		res.Status, res.MessageQueue.QueueId, res.MsgID)
}
```

> ⚠️ **格式化动词**：`res.Status` 是命名类型 `primitive.SendStatus`，用 `%s` 会被 `go vet` 报 `format %s has arg res.Status of wrong type`——必须用 `%v`（本机实测被 vet 抓到）。

## 三、消费者

```go
c, err := rocketmq.NewPushConsumer(
	consumer.WithNameServer([]string{"192.168.48.2:9876"}),
	consumer.WithGroupName("lab_go_consumer"),
	consumer.WithConsumerModel(consumer.Clustering),
)
if err != nil {
	panic(err)
}
// ⚠️ 必须先 Subscribe 再 Start；Tag 过滤常量是 consumer.TAG（全大写）
err = c.Subscribe("lab-go",
	consumer.MessageSelector{Type: consumer.TAG, Expression: "TagA || TagB"},
	func(ctx context.Context, msgs ...*primitive.MessageExt) (consumer.ConsumeResult, error) {
		for i := range msgs {
			m := msgs[i]
			fmt.Printf("GO-CONSUME msgId=%s queue=%d offset=%d tag=%s body=%s\n",
				m.MsgId, m.Queue.QueueId, m.QueueOffset, m.GetTags(), string(m.Body))
		}
		return consumer.ConsumeSuccess, nil
	})
if err != nil {
	panic(err)
}
if err = c.Start(); err != nil {
	panic(err)
}
// 生产环境应监听信号后 c.Shutdown() 优雅退出
time.Sleep(10 * time.Second)
c.Shutdown()
```

⭐ 回调签名是 `func(context.Context, ...*primitive.MessageExt)`；`consumer.ConsumeRetryLater` 等价 Java 的 `RECONSUME_LATER`，同样受 16 次重试上限约束，最终进 `%DLQ%{group}`。

## 四、顺序消息

```go
p, _ := rocketmq.NewProducer(
	producer.WithNameServer([]string{"192.168.48.2:9876"}),
	producer.WithGroupName("lab_go_order_producer"),
	producer.WithQueueSelector(producer.NewHashQueueSelector()), // ⭐ 按 ShardingKey 哈希选队列
)
p.Start()
defer p.Shutdown()

for i := 1; i <= 4; i++ {
	msg := primitive.NewMessage("lab-go-order", []byte(fmt.Sprintf("go-seq-%d", i)))
	msg.WithShardingKey("ORDER_GO_SEQ_777") // 同一 Key → 同一队列
	msg.WithTag("CREATE")
	res, _ := p.SendSync(context.Background(), msg)
	fmt.Printf("GO-ORDER seq=%d queueId=%d\n", i, res.MessageQueue.QueueId)
}
```

⭐ `NewHashQueueSelector()` 对 `ShardingKey` 取哈希，同一 Key 恒定落同一队列；消费端仍要用 `consumer.WithConsumerOrder(true)`（或对应的顺序监听）按队列加锁消费，顺序才成立（见 [消息类型与语义.md](../../../03-数据与中间件/中间件/消息队列/RocketMQ/消息类型与语义.md) 2.1 节）。

## 五、验证与排查（本机实测）

```bash
# 环境：golang:1.26-alpine（Go 1.26.8）+ rocketmq-client-go/v2 v2.1.2
gofmt -l .        # 无输出
go vet ./...      # 通过
go build -o /tmp/rmqlab .
```

```text
GO-SYNC status=0 queueId=1 msgId=C0A8300406C3000000003ec146f00001
GO-SYNC status=0 queueId=2 msgId=C0A8300406C3000000003ec146f00002
GO-SYNC status=0 queueId=3 msgId=C0A8300406C3000000003ec146f00003
GO PRODUCE DONE
GO-DELAY status=0 queueId=0
GO DELAY DONE
GO-ORDER seq=1 queueId=2
GO-ORDER seq=2 queueId=2
GO-ORDER seq=3 queueId=2
GO-ORDER seq=4 queueId=2
GO ORDER DONE
GO-CONSUME msgId=C0A8300406C3000000003ec146f00003 queue=3 offset=1 tag=TagA body=go-sync-3
GO-CONSUME msgId=C0A8300406C3000000003ec146f00001 queue=1 offset=1 tag=TagA body=go-sync-1
GO-CONSUME msgId=C0A8300406C3000000003ec146f00002 queue=2 offset=1 tag=TagA body=go-sync-2
GO CONSUME DONE
```

⭐ 读法：

- **`status=0` 就是成功**。`primitive.SendStatus` 是 `int` 命名类型且没有 `String()`，`%v` 打出的是**数字**（`0 = SEND_OK`），不是字符串 `SEND_OK`——与 Java 侧打印 `SEND_OK` 观感不同，别误判成「状态异常」。
- **普通发送被轮询打散**到 `queueId 1/2/3`；**顺序消息的 4 条全落 `queueId=2`**（`HashQueueSelector` 对 `ORDER_GO_SEQ_777` 取哈希的结果），`topicStatus` 里该队列位点 = 4。
- **消费位点归零**：`mqadmin consumerProgress -g lab_go_consumer` 显示三个队列 `Consumer Offset = 1`、`Diff Total = 0`、`Inflight = 0`。
- **延迟消息 12s 后已投递**：`lab-go-delay` 位点从 0 变为 1（level 3 = 10s）。

| 排查项 | 命令 |
| --- | --- |
| 消费堆量 / 位点 | `mqadmin consumerProgress -n <ns> -g lab_go_consumer` |
| Topic 各队列位点 | `mqadmin topicStatus -n <ns> -t lab-go` |
| 按业务 Key 查消息 | `mqadmin queryMsgByKey -n <ns> -t lab-go -k ORDER_GO_1001` |

> ⚠️ **采集脚本的小坑**：这个客户端的 logrus 日志默认写 stderr 且很啰嗦，直接 `2>&1 | tail` 会把业务输出挤出窗口（本机第一次就踩到，只看到 `GO CONSUME DONE`）。取业务输出时用 `2>/dev/null` 过滤掉客户端日志。

## 延伸追问

- **为什么 Go 客户端写容器名会 panic？** 它在构造 `Namesrv` 时要求地址能解析成 IP，`producer.WithNameServer` 传主机名会 `panic: new Namesrv failed.: IP addr error`；Java 客户端没有这个限制，所以「Java 能连、Go 连不上」时先怀疑这里。（第一节）
- **`WithRetry(2)` 和 Java 的 `setRetryTimesWhenSendFailed(2)` 语义一样吗？** 都是「失败后重试 N 次」的发送侧重试，且都是换队列 / 换 Broker 重发；重试会放大重复，消费端幂等不能省。
- **`WithDelayTimeLevel(3)` 在 5.x Broker 上是任意延迟吗？** 不是。老客户端传的还是 4.x 的固定级别（level 3 = 10s），要任意时间必须换 `rocketmq-clients/golang/v5`（gRPC）并让 Broker / Proxy 开 gRPC 端口。（第一节）
- **`go vet` 报 `format %s has arg ... of wrong type` 怎么修？** `res.Status` 是 `primitive.SendStatus` 命名类型，不是 string，改成 `%v`。
- **消费者代码跑完 JVM / 进程不退出？** Go 侧 `Shutdown()` 之后 `main` 若还在阻塞（如 `select {}`）自然不会退出；`time.Sleep` 到点后正常返回即可。Java 侧相反——客户端留下非守护线程，必须显式 `System.exit(0)`。

## 关联

- **上游/总纲**：[RocketMQ 目录导读](../../../03-数据与中间件/中间件/消息队列/RocketMQ/README.md) — 组件本体（概念 / 存储 / 可靠性 / 排障）
- **对照/互补**：[接入RocketMQ.md](../../java/接入RocketMQ.md) — Java 侧同一组场景的写法与差异
- **对照/互补**：[接入Kafka.md](接入Kafka.md) — Go 接 Kafka 的对照手册（三种客户端取舍）
- **对照/互补**：[消息类型与语义.md](../../../03-数据与中间件/中间件/消息队列/RocketMQ/消息类型与语义.md) — 顺序 / 延迟消息的机制与限制
- **对照/互补**：[部署与配置.md](../../../03-数据与中间件/中间件/消息队列/RocketMQ/部署与配置.md) — `brokerIP1` 与容器网络
