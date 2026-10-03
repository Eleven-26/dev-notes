# Go 接入 Jaeger

> 内容整理自个人学习笔记 —— Go 侧接入 Jaeger 的完整手册，按**前提认知 → 依赖 → TracerProvider 初始化 → HTTP / SQL / MQ / 定时任务四类埋点 → context 传播铁律 → 日志关联 → 优雅退出 → 项目落地映射 → 验证排查**组织，并结合 [photography-server](https://github.com/Eleven-26/photography-server) 项目的实际接入点整理。
>
> Jaeger 的概念、架构、与 OpenTelemetry 的关系、部署（v2 + ClickHouse）与采样策略见 [../../06-工程实践/可观测性/Jaeger.md](../../06-工程实践/可观测性/Jaeger.md)；同一套埋点发往 SkyWalking OAP 的写法见 [接入Skywalking.md](接入Skywalking.md)。

---

## 一、前提认知：Jaeger 只是后端，埋点靠 OTel SDK ⭐

**本节要点**：Go 侧没有「Jaeger 客户端」这回事了。`jaeger-client-go` **已归档**，官方路线是「OTel SDK 埋点 → OTLP 导出 → Jaeger 做后端」。所以本篇写的其实全是 OTel 代码。

| 阶段 | 方案 | 现状 |
| --- | --- | --- |
| 旧 | `github.com/uber/jaeger-client-go` | ⚠️ **已归档，不再演进，新项目不要用** |
| 现在 | **OTel SDK 埋点 → OTLP（gRPC:4317）→ Jaeger v2** | ✅ 官方推荐路线 |

这带来一个很实际的结论：**本篇的埋点代码与「用哪个后端」无关**。把 `endpoint` 从 `jaeger:4317` 换成 `skywalking-oap:4317`（并在 OAP 侧开 OTLP receiver），同一套代码就上报到了 SkyWalking；换成 Tempo 同理。选型只影响部署，不影响业务代码。

⚠️ 也因此，**跨服务的传播头必须端到端统一**。OTel 默认注入 W3C `traceparent`；如果链路里有服务跑的是 SkyWalking 原生探针（只认 `sw8`），链路会断成两段互不相干的 trace。混跑时的处理见 [接入Skywalking.md](接入Skywalking.md) 第六节。

---

## 二、依赖

```bash
go get go.opentelemetry.io/otel go.opentelemetry.io/otel/sdk
go get go.opentelemetry.io/otel/exporters/otlp/otlptrace/otlptracegrpc
go get go.opentelemetry.io/contrib/instrumentation/github.com/gin-gonic/gin/otelgin
go get go.opentelemetry.io/contrib/instrumentation/net/http/otelhttp
```

按用到的组件再加：GORM 的 OTel 插件、NATS/Kafka 的 instrumentation 包。

---

## 三、初始化 TracerProvider

```go
// infrastructure/tracing.go
package infrastructure

import (
	"context"
	"time"

	"go.opentelemetry.io/otel"
	"go.opentelemetry.io/otel/attribute"
	"go.opentelemetry.io/otel/exporters/otlp/otlptrace/otlptracegrpc"
	"go.opentelemetry.io/otel/propagation"
	"go.opentelemetry.io/otel/sdk/resource"
	sdktrace "go.opentelemetry.io/otel/sdk/trace"
)

var tp *sdktrace.TracerProvider

// InitJaeger 初始化 OTLP gRPC 导出器，指向 Jaeger / OTel Collector 的 4317 端口。
// 进程内只能调用一次：TracerProvider 持有导出器与批量队列，是进程级单例资源。
func InitJaeger(ctx context.Context, endpoint, serviceName string) error {
	exp, err := otlptracegrpc.New(ctx,
		otlptracegrpc.WithEndpoint(endpoint), // 如 jaeger:4317
		otlptracegrpc.WithInsecure(),         // 内网明文；生产可换 TLS 凭证
	)
	if err != nil {
		return err
	}
	res, _ := resource.New(ctx, resource.WithAttributes(
		attribute.String("service.name", serviceName),
	))
	tp = sdktrace.NewTracerProvider(
		sdktrace.WithResource(res),
		sdktrace.WithBatcher(exp, sdktrace.WithBatchTimeout(5*time.Second)),             // 批量异步导出
		sdktrace.WithSampler(sdktrace.ParentBased(sdktrace.TraceIDRatioBased(0.1))),     // ⭐ 采样
	)
	// ⭐ 注册全局 TracerProvider 与传播器（W3C + Baggage），跨服务才能串起来
	otel.SetTracerProvider(tp)
	otel.SetTextMapPropagator(propagation.NewCompositeTextMapPropagator(
		propagation.TraceContext{}, propagation.Baggage{},
	))
	return nil
}

// Shutdown 刷出批量队列中尚未发送的 span，进程退出前必须调用
func Shutdown(ctx context.Context) error {
	if tp == nil {
		return nil
	}
	return tp.Shutdown(ctx)
}
```

三个必须注意的点：

1. ⚠️ **初始化顺序**：`InitJaeger` 必须在初始化数据库 / GORM **之前**。GORM 的 OTel 插件在安装时**捕获当时的全局 TracerProvider**，顺序颠倒会让 SQL span 走 noop provider、永远没有数据——而且不报错，只是 UI 里查不到 SQL。
2. ⚠️ **单例**：`tp` 是包级变量、`main` 里初始化一次。**禁止**在请求路径里 `NewTracerProvider`：每次新建都会起一套批处理协程与 gRPC 连接，直接协程泄漏 + 连接爆炸。未启用链路追踪时应该让中间件返回 `nil`（见第四节），走 noop provider，请求路径零开销。
3. ⚠️ **`ParentBased` 不能省**：它保证子 span 沿用父 span 的采样决策。只用 `TraceIDRatioBased` 会出现「上游采了、下游没采」，一条 trace 只剩半截，比不采更难排查。

采样率的取值与尾部采样方案见 [../../06-工程实践/可观测性/Jaeger.md](../../06-工程实践/可观测性/Jaeger.md) 第四节。

---

## 四、HTTP 入口与出站调用

```go
import (
	otelgin "go.opentelemetry.io/contrib/instrumentation/github.com/gin-gonic/gin/otelgin"
	"go.opentelemetry.io/contrib/instrumentation/net/http/otelhttp"
)

r := gin.New()
// ⭐ otelgin：为每个 HTTP 请求创建 entry span，并自动从 traceparent 头续接上游链路
// 需在其余业务中间件之前挂载，才能覆盖完整请求链路
r.Use(otelgin.Middleware("photography-server"))

// 出站调用用 otelhttp 包装 Transport，自动生成 client span 并注入 traceparent
client := &http.Client{Transport: otelhttp.NewTransport(http.DefaultTransport)}
```

两条要点：

- **中间件顺序**：`otelgin` 要挂在业务中间件**之前**。挂在后面的话，鉴权、限流这些中间件的耗时不会被计入 entry span，瀑布图上看不出「时间花在鉴权上了」。
- ⚠️ **出站必须包 Transport**：直接用 `http.DefaultClient` 发请求不会注入 `traceparent`，下游收到的就是一条全新 trace。这是「链路在服务边界断掉」最常见的原因。`http.Client` 同样要**复用单例**，别在请求里 `&http.Client{}`——每次新建都意味着一套新的连接池。

---

## 五、SQL：GORM 的 OTel 插件

```go
import "gorm.io/plugin/opentelemetry/tracing"

// 必须在 InitJaeger 之后执行，否则插件捕获到的是 noop provider
if err := tracing.NewPlugin(tracing.WithoutMetrics()).Install(db); err != nil {
	return err
}
```

装上后每条 SQL 会生成一个 client span，带上 `db.system`、`db.statement` 等属性，在 Jaeger UI 的瀑布图上能直接看到「这条请求的 800ms 里 700ms 花在哪条 SQL 上」。

⚠️ **已知限制**：SQL span 挂在 GORM 的 `Statement.Context` 下。**业务层没有把请求 ctx 透传到 repository 时，SQL span 会以独立 trace 入库**——SQL 语句与参数照样能查到，但不会挂在那条 HTTP 链路下面，表现为「UI 里有一堆孤立的 `SELECT` trace」。修法只有一个：所有 repository 方法都收 `ctx context.Context` 作为第一个参数，并 `db.WithContext(ctx)`。

⚠️ **别把敏感数据写进 span**：`db.statement` 默认带参数值，手机号、身份证会原样落到 ClickHouse。生产要么关掉参数记录，要么在 Collector 侧做属性脱敏。

---

## 六、手动埋点与 context 传播

```go
import (
	"context"

	"go.opentelemetry.io/otel"
	"go.opentelemetry.io/otel/attribute"
	"go.opentelemetry.io/otel/codes"
	"go.opentelemetry.io/otel/trace"
)

var tracer = otel.Tracer("photography-server")

func CreateOrder(ctx context.Context, req OrderReq) (Order, error) {
	// ⭐ 从 ctx 取当前 span 作为父，创建子 span —— 必须把返回的新 ctx 一路向下传
	ctx, span := tracer.Start(ctx, "service.CreateOrder",
		trace.WithAttributes(attribute.String("order.store_id", req.StoreID)),
	)
	defer span.End() // 确保 span 结束并上报

	// 把 ctx 透传给 repository，SQL span 才会挂在同一条链路下
	order, err := repo.Save(ctx, req)
	if err != nil {
		span.RecordError(err) // ⭐ 记录异常，UI 上该 span 标红
		span.SetStatus(codes.Error, err.Error())
		return Order{}, err
	}
	span.SetAttributes(attribute.String("order.id", order.ID))
	return order, nil
}
```

⭐ **context 传播三条铁律**：

1. 创建 span 后必须用**返回的新 ctx**（`ctx, span := tracer.Start(...)`）。继续用旧 ctx 等于没建父子关系——`tracer.Start` 不改传入的 ctx，父子关系全靠返回值承载。
2. 跨 goroutine / 跨服务都要**显式传 ctx**；⚠️ 别把 ctx 存进结构体字段或全局变量，那会让取消信号失效、并让 span 挂到错误的父上。
3. 下游进程用 `otel.GetTextMapPropagator().Extract(ctx, carrier)` 提取上游头，才续接同一条 trace。

跨 goroutine 的标准写法：

```go
// ctx 里已带 span context，直接传进 goroutine 即可续上链路
go func(ctx context.Context) {
	ctx, span := tracer.Start(ctx, "async.Notify")
	defer span.End()
	notify(ctx)
}(ctx)
```

⚠️ 注意父 span 的生命周期：如果父 span 在 goroutine 跑完前就 `End()` 了，子 span 仍会挂在同一条 trace 上，但**父的耗时不覆盖子的耗时**，瀑布图上会出现「子 span 超出父 span 右边界」。要覆盖就得让父等待（`sync.WaitGroup` / `errgroup`）。

---

## 七、MQ 与定时任务怎么串联？

**本节要点**：MQ 靠「生产端 Inject、消费端 Extract」；定时任务没有上游，要在任务入口创建**根 span** 独立成链。

### 7.1 消息队列（以 NATS 为例）

```go
// 生产端：把当前 span context 注入消息头
import "go.opentelemetry.io/otel/propagation"

type headerCarrier map[string][]string

func (c headerCarrier) Get(key string) string {
	if v := c[key]; len(v) > 0 {
		return v[0]
	}
	return ""
}
func (c headerCarrier) Set(key, value string) { c[key] = []string{value} }
func (c headerCarrier) Keys() []string {
	ks := make([]string, 0, len(c))
	for k := range c {
		ks = append(ks, k)
	}
	return ks
}

func publish(ctx context.Context, subject string, payload []byte) error {
	carrier := headerCarrier{}
	otel.GetTextMapPropagator().Inject(ctx, carrier) // ⭐ 注入 traceparent
	msg := nats.NewMsg(subject)
	msg.Data = payload
	for k, vs := range carrier {
		for _, v := range vs {
			msg.Header.Add(k, v)
		}
	}
	return nc.PublishMsg(msg)
}

// 消费端：提取上游头，续接同一条 trace
func handle(ctx context.Context, msg *nats.Msg) {
	carrier := headerCarrier{}
	for k, vs := range msg.Header {
		for _, v := range vs {
			carrier[k] = append(carrier[k], v)
		}
	}
	ctx = otel.GetTextMapPropagator().Extract(ctx, carrier)
	ctx, span := tracer.Start(ctx, "nats."+msg.Subject,
		trace.WithSpanKind(trace.SpanKindConsumer),
		trace.WithAttributes(
			attribute.String("messaging.system", "nats"),
			attribute.String("messaging.destination.name", msg.Subject),
		),
	)
	defer span.End()
	// ...业务处理
}
```

⚠️ 消息中间件必须支持**消息头**才能透传上下文。Kafka 用 record header、RabbitMQ 用 message properties、NATS 用 `Header`（需要 NATS 2.2+）。把 `traceparent` 塞进消息体是反模式——消费方要解析业务 payload 才能拿到链路信息，耦合且易错。

### 7.2 定时任务

定时任务没有上游请求，**不会自动产生 trace**。要在任务入口手动创建根 span，否则任务里的 SQL 会散落成一堆孤立 trace：

```go
// traced 包装任务处理函数，在入口创建根 span
func traced(handler string, fn func(ctx context.Context) error) func(ctx context.Context) error {
	return func(ctx context.Context) error {
		ctx, span := tracer.Start(ctx, "xxl-job."+handler,
			trace.WithSpanKind(trace.SpanKindInternal),
		)
		defer span.End()
		if err := fn(ctx); err != nil {
			span.RecordError(err)
			span.SetStatus(codes.Error, err.Error())
			return err
		}
		return nil
	}
}
```

---

## 八、把 trace_id 关联到日志与响应

**本节要点**：链路数据只有在能被「从日志/工单一键跳回 UI」时才有排障价值。做法是把当前 span 的 `trace_id` 同时写进响应头、响应体和日志字段。

```go
import "go.opentelemetry.io/otel/trace"

// TraceID 中间件：把 trace_id 回写响应头，供前端/客服/工单引用
func TraceID() gin.HandlerFunc {
	return func(c *gin.Context) {
		spanCtx := trace.SpanContextFromContext(c.Request.Context())
		if spanCtx.HasTraceID() {
			c.Header("X-Trace-Id", spanCtx.TraceID().String())
			c.Set("trace_id", spanCtx.TraceID().String())
		}
		c.Next()
	}
}
```

三处落地：

| 位置 | 做法 | 用途 |
| --- | --- | --- |
| 响应头 | `X-Trace-Id: <trace_id>` | 前端报错弹窗、客服工单直接引用 |
| 响应体 | `Body.trace_id` | 接口报错时用户能报出一串 ID |
| 日志字段 | 每条日志带 `trace_id` | 日志系统按 `trace_id` 聚合，从日志跳回 Jaeger UI |

⚠️ **5xx 时把堆栈写进 span**：`span.SetAttributes(attribute.String("exception.stacktrace", string(debug.Stack())))`，或用 `span.RecordError(err)`（OTel 会自动生成 `exception` event）。否则 Jaeger UI 上只能看到「这个 span 红了」，看不到为什么红。

⚠️ **请求参数写进 span 要设上限**：把整个请求体塞进 attribute 会让 span 体积爆炸（ClickHouse 存储成本与查询延迟都会涨），还可能带出敏感字段。实践做法是**截断到 4KB + 敏感字段脱敏**后再写。

---

## 九、优雅退出：别丢最后一批 span

`WithBatcher` 是异步批量导出，span 先进内存队列、攒够一批或到超时（上面设的 5s）才发出去。所以**进程直接退出会丢掉队列里的那一批**。

```go
// main.go 的退出路径
quit := make(chan os.Signal, 1)
signal.Notify(quit, syscall.SIGINT, syscall.SIGTERM)
<-quit

ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
defer cancel()

srv.Shutdown(ctx)              // 1. 先停 HTTP server，排空 in-flight 请求
infrastructure.Shutdown(ctx)   // 2. 再 flush span 队列
```

⚠️ **顺序很重要**：先 flush 再停 server，会把排空期间新产生的 span 漏掉。任何 `kill -9` 都等于放弃最后一批数据——K8s 侧要保证 `terminationGracePeriodSeconds` 大于「HTTP 排空 + span flush」的总时间（部署与生命周期见 [../../06-工程实践/部署/k8s/K8s部署与生命周期面试题.md](../../06-工程实践/部署/k8s/K8s部署与生命周期面试题.md)）。

---

## 十、项目落地映射（photography-server）

> 以下基于**实际读到的仓库文件**：`docker-compose.yml`、`docs/部署/*`、`docs/架构/02-系统架构图.md`、`internal/infrastructure/jaeger.go`、`internal/middleware/jaeger.go`、`internal/router/router.go`、`internal/infrastructure/{mysql,nats}.go`、`internal/mq/consumer.go`、`internal/job/*`、`config/jaeger.example.yaml`、`config/nacos/*.yaml`。

### 10.1 现状：两条链路通道，二选一

项目**同时**编排了 SkyWalking native 与 **OTel→Jaeger** 两套链路后端，**互不依赖、建议二选一**（同开会同一请求双 span / 双上报）。

| 通道 | 探针 | 上报路径 | 存储 | UI | 开关 |
| --- | --- | --- | --- | --- | --- |
| ① SkyWalking native | 编译期注入 `skywalking-go` agent | 直连 `skywalking-oap:11800` | BanyanDB（17912） | Horizon UI `:9080` | `SW_AGENT_ENABLE=true` 构建 + `jaeger.enable=false` |
| ② **OTel→Jaeger** | 不注入 agent，纯 **OTel SDK 埋点** | `jaeger:4317`（OTLP gRPC） | **ClickHouse**（9000） | **Jaeger UI `:16686`** | Nacos `jaeger.enable=true` + `endpoint=jaeger:4317` |

> 事实核对：compose 中 `jaeger` 镜像为 `jaegertracing/jaeger:2.20.0`，即 **Jaeger v2 单体（Collector+Query+UI）**；`config/jaeger.example.yaml` 说明 ClickHouse 为 v2.18 起官方原生存储（ADR-008）。
> 生产默认 `jaeger.enable: false`（`config/nacos/photography-server-prod.yaml`），dev / test / docker.dev 三 profile 为 `true`。

### 10.2 数据流（实际）

```text
前端 :8081/:8082/:8083 ── /api 剥前缀 ──▶ backend（Go + Gin，:8080）
   │ ① otelgin 生成 HTTP entry span（router.go 挂载 JaegerTrace）
   │ ② GORM OTel 插件生成 SQL client span（mysql.go，以 JaegerEnabled() 为条件安装）
   │ ③ NATS：生产端 traceMsg 注入 traceparent，消费端 Extract 续接（nats.go / mq/consumer.go）
   │ ④ xxl-job：任务入口创建根 span（job/traced）
   │ OTLP gRPC :4317
   ▼
jaeger（v2，collector+query 一体）──▶ ClickHouse（库 jaeger）──▶ Jaeger UI :16686
```

### 10.3 接入落点

| 层 | 文件 | 做了什么 |
| --- | --- | --- |
| 初始化 | `cmd/server/main.go` | `InitJaeger(&cfg.Jaeger)` 在 `InitMySQL` **之前**（GORM 插件会捕获全局 TracerProvider） |
| TracerProvider | `internal/infrastructure/jaeger.go` | `otlptracegrpc` + `BatchSpanProcessor(5s)` + W3C `TraceContext`/`Baggage` 传播器，单例 |
| HTTP 入口 | `internal/middleware/jaeger.go` | `otelgin.Middleware(service)`；**未启用返回 `nil`，请求路径零开销** |
| 中间件链 | `internal/router/router.go:50` | 启用时挂 `JaegerTrace → TraceID → TraceParams`；否则只挂 `TraceID`（给 native 用） |
| SQL | `internal/infrastructure/mysql.go:49` | `JaegerEnabled()` 时安装 GORM 官方 OTel 插件 |
| MQ 生产 | `internal/infrastructure/nats.go:127` | `otel...Inject(ctx, HeaderCarrier)` 注入 `traceparent` |
| MQ 消费 | `internal/mq/consumer.go:275` | `Extract` 上游头 → 创建 `nats.<subject>` 处理 span（`messaging.system=nats` 等） |
| 定时任务 | `internal/job/*` | `traced()` 在任务入口创建根 span `xxl-job.<handler>`，无上游 trace，独立成链 |
| 响应/日志 | `internal/middleware/traceid.go`、`internal/pkg/response/response.go` | `trace_id` 回写响应头 `X-Trace-Id` 与响应体 `Body.trace_id`；5xx 写 span 的 `exception.stacktrace` |

### 10.4 与部署目录的对应

| 部署侧 | 位置 |
| --- | --- |
| compose 定义 | 部署目录 `docker-compose.yml` 的 `jaeger` + `clickhouse` 两 service |
| Jaeger 配置挂载 | `./volume/jaeger/config.yaml` ← 由 `config/jaeger.example.yaml` 拷出（⚠️ 缺文件时 Docker 会把它建成**目录**，容器内报 `is a directory`） |
| 业务开关 | Nacos `data_id=photography-server-<profile>.yaml` 的 `jaeger.*` 段（`enable/endpoint/service/instance`），应急可用 `APP_JAEGER_*` 覆盖 |
| 端口 | 4317（OTLP gRPC）/ 4318（OTLP HTTP）/ 16686（UI） |

⚠️ 代码注释明确的已知限制：SQL span 挂在 GORM `Statement.Context` 下，**业务未透传请求 ctx 时会以独立 trace 入库**（第五节）；SkyWalking native 通道的 MQ 透传（`sw8` header）为待办。

---

## 十一、怎么逐层确认接入生效？

```bash
# 1. 后端可达：OTLP gRPC 端口
nc -vz jaeger 4317

# 2. 发一个请求，看响应头里有没有 trace_id
curl -sI http://localhost:8080/api/health | grep -i x-trace-id

# 3. 用这个 trace_id 直接查 Jaeger 的 API（不用打开 UI）
curl -s "http://localhost:16686/api/traces/<trace_id>" | head -c 500

# 4. 看有哪些 service 上报成功
curl -s "http://localhost:16686/api/services"
```

5. 打开 `http://localhost:16686`，选到服务名，确认：HTTP entry span 存在 → SQL span 挂在它下面（不是孤立 trace）→ 跨服务调用有下游 span。
6. 若 `api/services` 返回空，先确认 `service.name` 是否写对、`InitJaeger` 是否真的被调用（未启用时中间件返回 `nil`，请求路径零开销、UI 也永远为空）。

---

## 十二、常见故障速查

| 症状 | 大概率原因 | 处理 |
| --- | --- | --- |
| Jaeger UI 里一个 service 都没有 | `InitJaeger` 没调用；endpoint 端口填成 16686（UI 端口）而非 4317 | 确认调用与端口（第三节） |
| HTTP span 有、SQL span 全没有 | `InitJaeger` 在 `InitMySQL` **之后**调用，GORM 插件捕获到 noop provider | 调整初始化顺序（第三节） |
| SQL span 存在但都是孤立 trace | 业务层没把请求 ctx 透传到 repository | 所有 repo 方法收 `ctx` 并 `db.WithContext(ctx)`（第五节） |
| 链路在服务边界断成两段 | 出站用了 `http.DefaultClient`，没注入 `traceparent` | 用 `otelhttp.NewTransport` 包装（第四节） |
| 一条 trace 只有半截（有上游没下游） | 只用了 `TraceIDRatioBased`，父子采样决策不一致 | 换成 `ParentBased(TraceIDRatioBased(x))`（第三节） |
| 中间件耗时在瀑布图上看不到 | `otelgin` 挂在业务中间件之后 | 挪到中间件链最前（第四节） |
| 子 span 超出父 span 右边界 | 父 span 提前 `End()`，异步 goroutine 还在跑 | 用 `WaitGroup`/`errgroup` 让父等待（第六节） |
| MQ 消费端是全新 trace | 生产端没 `Inject`，或 MQ 不支持消息头 | 按 7.1 实现 Inject/Extract；NATS 需 2.2+ |
| 定时任务的 SQL 全是孤立 trace | 任务入口没创建根 span | 用 `traced()` 包装（7.2） |
| 重启后最后几秒的 trace 缺失 | 没调 `tp.Shutdown`，批量队列未 flush | 按第九节接入优雅退出 |
| ClickHouse 存储启动报错 | 漏了 `--feature-gates=storage.clickhouse` | 见 [../../06-工程实践/可观测性/Jaeger.md](../../06-工程实践/可观测性/Jaeger.md) 第四节 |
| 存储成本涨得离谱 | span attribute 里塞了完整请求体 / SQL 参数 | 4KB 截断 + 敏感字段脱敏；收紧采样率（第八节） |

---

## 延伸追问

- **为什么不用 `jaeger-client-go`？** → 已归档。它绑定私有的 `uber-trace-id` 头与私有 Thrift 协议，与 OTel 标准冲突；统一 OTel SDK + OTLP 后，**后端可换而埋点不动**——把 endpoint 从 Jaeger 换成 SkyWalking OAP 或 Tempo，业务代码一行不改。
- **`InitJaeger` 为什么必须在 GORM 之前？** → GORM 的 OTel 插件在 `Install` 时**捕获当时的全局 TracerProvider** 并存下来。顺序颠倒会让它拿到 noop provider，SQL span 永远不上报，且不报错。这是「初始化顺序依赖全局单例」的典型坑。
- **context 传播为什么必须用返回值？** → `tracer.Start(ctx, name)` 不改传入的 ctx，而是返回一个**带了新 span 的新 ctx**。父子关系全靠这个返回值承载；继续用旧 ctx 创建下游 span，会全部挂到同一个父上，树形结构塌成一层。
- **MQ 场景怎么串链路？** → 生产端发送前 `propagator.Inject(ctx, carrier)` 把 `traceparent` 写进**消息头**（不是消息体），消费端 `Extract` 后作为父上下文创建 `SpanKindConsumer` 的新 span。定时任务这类无上游入口的创建**根 span** 独立成链。
- **采样率怎么定？** → 开发全采；生产 `ParentBased(TraceIDRatioBased(1%~10%))` 保证父子决策一致；最优是在 Collector 侧做**尾部采样**——错误与慢请求 100% 保留、正常请求抽样，又省存储又不丢关键链路。
- **span 爆炸 / 性能开销怎么控？** → 四招：采样、批量异步导出（`WithBatcher`）、属性精简（大 body 截断 + 敏感字段脱敏）、未启用时中间件返回 `nil` 让请求路径零开销。
- **TraceID 怎么和日志关联？** → 把当前 span 的 `trace_id` 写进响应头（`X-Trace-Id`）、响应体和日志字段，日志系统按 `trace_id` 聚合，即可从日志一键跳回 UI 检索。
- **为什么 Jaeger v2 转向 OTel？** → OTel 已成事实标准。v2 直接以 OTel Collector 为运行时，配置就是 `receivers / processors / exporters / extensions` 那套，一个进程即 Collector + Query + UI，避免维护私有协议与 SDK。

---

## 十三、校验口径

> ✅ 本篇 Go 代码块的校验方式与范围（Go 1.26.5，windows/amd64）：
>
> - **真编译通过**（抽成独立模块，`go build ./...` + `go vet ./...` + `gofmt -l` 全绿）：第三节 `InitJaeger` / `Shutdown`、第四节 `otelgin.Middleware` 挂载与 `otelhttp.NewTransport` 包装、7.2 `traced` 包装器、第八节 `TraceID` 中间件。
> - **未独立编译**：第五节 `tracing.NewPlugin(...).Install(db)`（依赖项目内的 `*gorm.DB` 实例）、第六节 `CreateOrder`（`OrderReq` / `repo` 为项目内类型）、7.1 NATS 的 `Inject` / `Extract`（依赖 `nc` 与 `nats.Msg`）。这三段是**接入模式示例**而非可独立编译的完整程序，只核对 API 签名与调用约定。
> - **shell / curl 片段**：`bash -n` 语法校验；⚠️ 本机没有 Jaeger + ClickHouse 实例，**未实际请求**，文中不含伪造的返回结果。

| 依赖 | 实际解析版本 |
| --- | --- |
| `go.opentelemetry.io/otel`（含 `attribute` / `codes` / `propagation` / `trace`） | v1.46.0 |
| `go.opentelemetry.io/otel/sdk` | v1.46.0 |
| `go.opentelemetry.io/otel/exporters/otlp/otlptrace/otlptracegrpc` | v1.46.0 |
| `go.opentelemetry.io/contrib/instrumentation/github.com/gin-gonic/gin/otelgin` | v0.71.0 |
| `go.opentelemetry.io/contrib/instrumentation/net/http/otelhttp` | v0.70.0 |
| `github.com/gin-gonic/gin` | v1.12.0 |
| `gorm.io/plugin/opentelemetry` | 未编译（见上） |

---

## 关联

- [../../06-工程实践/可观测性/Jaeger.md](../../06-工程实践/可观测性/Jaeger.md) — Trace/Span 概念、与 OTel 的关系、v2 + ClickHouse 部署、采样策略、与 SkyWalking 的分工
- [接入Skywalking.md](接入Skywalking.md) — 同一套 OTel 埋点发往 SkyWalking OAP；以及编译期注入路线的对照
- [../java/接入Jaeger.md](../java/接入Jaeger.md) — Java 侧的 OTel Agent 与 Micrometer Tracing 两条路线
- [工程实践/context.md](工程实践/context.md) — ctx 传播、超时预算与级联取消的基础
- [../../02-计算机基础/网络/HTTP与gRPC.md](../../02-计算机基础/网络/HTTP与gRPC.md) — `traceparent` 在请求头里的传播格式
- [../../06-工程实践/部署/k8s/K8s部署与生命周期面试题.md](../../06-工程实践/部署/k8s/K8s部署与生命周期面试题.md) — 优雅停机与 `terminationGracePeriodSeconds`
- [../../06-工程实践/可观测性/可观测性选型.md](../../06-工程实践/可观测性/可观测性选型.md) — 链路后端与存储的选型对比
> 反向引用（本篇被下列文档引到）：[Prometheus直方图与分位数.md](../../06-工程实践/可观测性/Prometheus直方图与分位数.md)
