# Jaeger 链路追踪

> 一句话说明本文件覆盖什么
>
> 内容整理自个人学习笔记，并结合 photography-server 项目的部署目录与架构整理。同目录另见 [Skywalking.md](Skywalking.md)。

Jaeger 是 Uber 开源的**分布式链路追踪系统**，2017 年捐赠给 CNCF，2019 年毕业（Graduated），用于分布式/微服务架构下的**调用链追踪与性能分析**：一次请求经过了哪些服务、每个服务做了什么、耗时卡在哪一跳、失败发生在哪个环节。

覆盖内容：核心概念 → 架构与选型 → 与 OpenTelemetry 的协作 → 部署 → Go / Java 接入 → 与 photography-server 的落地映射 → 与 SkyWalking 分工 → 面试追问。

## 一、核心概念

| 概念 | 说明 |
|---|---|
| **Trace** | 一条完整调用链，由一组 Span 组成，`trace_id` 全局唯一 |
| **Span** | 一次操作（HTTP 请求 / 一条 SQL / 一次 RPC），含 `span_id`、起止时间、`parent_span_id` |
| **SpanContext** | 跨进程传播的最小信息集：`trace_id` + `span_id` + 采样标志。W3C 用 `traceparent` 头承载 |
| **Operation** | Span 名称（`GET /api/user`、`SELECT users`） |
| **Tag / Attribute** | Span 上的键值对（`http.status_code=500`），用于检索过滤 |
| **Log / Event** | Span 内的时间点事件；OTel 时代统一为 **Event**（`span.AddEvent`） |
| **Baggage** | 随链路透传的业务键值对（如 `tenant_id`），会跨服务传播，⚠️ 别塞敏感信息 |
| **Sampler** | 采样器：`const`（全采/全不采）、`probabilistic`（按比例）、`ratelimiting` |

⭐ 层级理解：**Trace 是树，Span 是节点，SpanContext 是跨进程的“接力棒”，Sampler 决定这棵树留不留档。**

### 1.1 与 OpenTelemetry 的关系（关键认知）

| 阶段 | 方案 | 现状 |
|---|---|---|
| 旧 | `jaeger-client-go` / `jaeger-client-java` | ⚠️ **已归档，不再演进，新项目不要用** |
| 过渡 | Jaeger 兼容 Zipkin / OTLP 上报 | 保留兼容，入口收敛到 OTLP |
| 现在 | **OTel SDK 埋点 → OTLP 导出 → Jaeger 做后端** | ✅ 官方推荐路线 |

⭐ **Jaeger v2 的运行时本身就是基于 OpenTelemetry Collector 构建的**：配置用 Collector 的 `receivers / processors / exporters / extensions` 结构，`jaegertracing/jaeger:2.x` 一个进程即 Collector（接收）+ Query（查询）+ 内置 UI。“Jaeger 后端 + OTel 埋点”不是拼凑，而是 v2 的设计方向。

## 二、架构

### 2.1 组件

| 组件 | 职责 | 备注 |
|---|---|---|
| **Collector** | 接收 span（OTLP/Zipkin/Thrift），批处理、采样、写出到存储 | v2 由 Collector 配置驱动 |
| **Query** | 查询服务 + 内置 UI（`:16686`），按 `trace_id`/service/operation/tag 检索 | v2 单进程内置 |
| **Storage** | **Badger（本地，dev）/ Cassandra / Elasticsearch / ClickHouse** | ⚠️ 生产勿用内存存储 |
| **Agent** | 1.x 时代每主机 sidecar，收 UDP 再转发 Collector | ❌ **v2 已移除**，应用直接走 `gRPC:4317` |

### 2.2 Jaeger vs SkyWalking vs Zipkin

| 维度 | **Jaeger** | **SkyWalking** | **Zipkin** |
|---|---|---|---|
| 探针方式 | OTel SDK / OTel Java Agent | Java Agent / 多语言 agent（含 Go native） | Brave / OTel SDK |
| 侵入性 | 中（SDK 显式埋点或 OTel Agent） | 低（字节码增强，几乎零改码） | 中 |
| 存储 | ES / Cassandra / ClickHouse / Badger | ES / BanyanDB / H2 / MySQL | ES / MySQL / 内存 |
| UI | 简洁，专注 trace 检索与 span 瀑布 | 丰富：拓扑、指标、告警、日志关联 | 极简 |
| 生态 | 与 **OpenTelemetry 深度绑定**（v2 即 OTel Collector） | Apache 顶级，自成体系（OAP + agent） | 老牌，功能收敛 |
| 适用 | 已用 OTel / 多语言 / 要可移植链路 | 要开箱即用 APM 大盘与拓扑 | 极简嵌入式 |

⭐ 口诀：**要多语言 + OTel 标准化 → Jaeger；要无侵入 + 开箱 APM 大盘 → SkyWalking。**

## 三、与 OpenTelemetry 的协作关系

现代可观测性是「**采集与协议标准化（OTel）** + **后端可替换（Jaeger / Tempo / SkyWalking …）**」。

```
应用 (OTel SDK 埋点) ──Trace/Span+Context 传播, OTLP(gRPC:4317/HTTP:4318)──▶
[可选] OTel Collector（统一接收/采样/多路导出） ──OTLP──▶
Jaeger (v2 = Collector + Query + UI) ──▶ Storage(ClickHouse/ES) ──▶ Jaeger UI :16686
                                                            （按 trace_id/service/tag 检索）
```

- **协议标准化**：应用只认 OTLP，后端换 Jaeger/Tempo/SkyWalking 不用改埋点。
- **传播标准化**：跨服务用 W3C `traceparent`，而非各家私有头（旧 Jaeger `uber-trace-id`、SkyWalking `sw8`）。
- **职责清晰**：OTel 管“怎么产生和传”，Jaeger 管“怎么存、怎么查”。

⚠️ 混用坑：链路头格式必须端到端一致。上游注入 `traceparent`、下游只认 `sw8`，链路会断成两段独立 trace。

## 四、部署

### 4.1 快速起步（all-in-one，仅开发）

`jaegertracing/all-in-one` 是 **Jaeger 1.x 的单体镜像**（Collector + Query + Agent + 内存存储），只适合本地调试。

```yaml
services:
  jaeger:
    image: jaegertracing/all-in-one:1.62.0
    environment:
      - COLLECTOR_OTLP_ENABLED=true   # 开启 OTLP 接收（4317/4318）
    ports:
      - "16686:16686"   # UI
      - "4317:4317"     # OTLP gRPC
      - "4318:4318"     # OTLP HTTP
```

```bash
docker compose up -d   # 打开 http://localhost:16686 即为 Jaeger UI
```

⚠️ all-in-one 默认**内存存储**，重启即丢；1.x 的 Agent 组件在 v2 中已移除。**生产请用 v2 + 外部存储。**

### 4.2 生产形态（Jaeger v2 + ClickHouse，⭐ 与本项目一致）

Jaeger **v2 起 ClickHouse 是官方原生存储**（ADR-008，不再依赖三方 grpc-plugin）。本项目正是这一形态。

```yaml
services:
  clickhouse:
    image: clickhouse/clickhouse-server:24.8
    environment:
      CLICKHOUSE_DB: jaeger           # Jaeger 不建库，库须预先存在
      CLICKHOUSE_USER: root
      CLICKHOUSE_PASSWORD: root
    volumes:
      - ./volume/clickhouse/data:/var/lib/clickhouse
    restart: unless-stopped

  jaeger:
    image: jaegertracing/jaeger:2.20.0
    depends_on:
      clickhouse: { condition: service_healthy }
    volumes:
      - ./volume/jaeger/config.yaml:/etc/jaeger/config.yaml:ro
    # ⚠️ ClickHouse 存储是实验特性，默认关，且**配置文件里没有该字段** —— 只能命令行显式开门
    command: ["--config", "/etc/jaeger/config.yaml", "--feature-gates=storage.clickhouse"]
    ports:
      - "4317:4317"     # OTLP gRPC
      - "4318:4318"     # OTLP HTTP
      - "16686:16686"   # UI
```

配套 `config.yaml`（Jaeger v2 即 OTel Collector 配置结构）：

```yaml
extensions:
  jaeger_storage:
    backends:
      clickhouse-storage:
        clickhouse:
          addresses: ["clickhouse:9000"]   # native 协议端口
          database: jaeger
          auth: { basic: { username: root, password: root } }
          create_schema: true              # 首次启动自动建表
  jaeger_query:
    storage: { traces: clickhouse-storage }
receivers:
  otlp:
    protocols:
      grpc: { endpoint: 0.0.0.0:4317 }
      http: { endpoint: 0.0.0.0:4318 }
processors:
  batch:
exporters:
  jaeger_storage_exporter:
    trace_storage: clickhouse-storage
service:
  extensions: [jaeger_storage, jaeger_query]
  pipelines:
    traces:
      receivers: [otlp]
      processors: [batch]
      exporters: [jaeger_storage_exporter]
```

> ⚠️ 漏掉 `--feature-gates=storage.clickhouse` 会直接启动失败：`ClickHouse storage is experimental and must be explicitly enabled`。
> 版本差异：v2.20 该 gate 为 Alpha；v2.23 起转 Stable 并更名 `jaeger.clickhouse`（旧 ID 保留为兼容别名）。

### 4.3 采样策略

| 策略 | OTel 配置 | 适用 |
|---|---|---|
| 全采 | `sdktrace.AlwaysSample()` | 开发、压测定位 |
| 概率 | `ParentBased(TraceIDRatioBased(0.05))` | 生产默认，1%~10% 视流量 |
| 限流 | Collector `probabilistic_sampler` | 高峰期削峰 |
| 尾部采样 | Collector `tail_sampling`（按错误/慢请求） | ⭐ 生产最优：**错误与慢请求 100% 保留** |

⭐ 生产推荐 `ParentBased(TraceIDRatioBased(x))`：保证**同一 trace 全链路采样决策一致**，避免下游有、上游无。

## 五、使用一：Go（⭐ 重点）

### 5.1 依赖

```bash
go get go.opentelemetry.io/otel go.opentelemetry.io/otel/sdk
go get go.opentelemetry.io/otel/exporters/otlp/otlptrace/otlptracegrpc
go get go.opentelemetry.io/contrib/instrumentation/github.com/gin-gonic/gin/otelgin
```

### 5.2 初始化 TracerProvider

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

// InitJaeger 初始化 OTLP gRPC 导出器，指向 Jaeger / OTel Collector 的 4317 端口
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
		sdktrace.WithBatcher(exp, sdktrace.WithBatchTimeout(5*time.Second)), // 批量异步导出
		sdktrace.WithSampler(sdktrace.ParentBased(sdktrace.TraceIDRatioBased(0.1))), // ⭐ 采样
	)
	// ⭐ 注册全局 TracerProvider 与传播器（W3C + Baggage），跨服务才能串起来
	otel.SetTracerProvider(tp)
	otel.SetTextMapPropagator(propagation.NewCompositeTextMapPropagator(
		propagation.TraceContext{}, propagation.Baggage{},
	))
	return nil
}
```

⚠️ **初始化顺序**：`InitJaeger` 必须在初始化数据库/GORM **之前**。GORM 的 OTel 插件在安装时**捕获全局 TracerProvider**，顺序颠倒会让 SQL span 走 noop provider、永远无数据。
⚠️ 服务优雅退出时记得调 `tp.Shutdown(ctx)` 刷出缓冲中的 span，否则最后一批数据会丢。

### 5.3 Gin + HTTP 拦截器

```go
import (
	otelgin "go.opentelemetry.io/contrib/instrumentation/github.com/gin-gonic/gin/otelgin"
	"go.opentelemetry.io/contrib/instrumentation/net/http/otelhttp"
)

r := gin.New()
// ⭐ otelgin：为每个 HTTP 请求创建 entry span，并自动从 traceparent 头续接上游链路
// 需在其余业务中间件之前挂载，才能覆盖完整请求链路
r.Use(otelgin.Middleware("photography-server"))

// 出站调用用 otelhttp 包装 Client，自动生成 client span 并注入 traceparent
client := &http.Client{Transport: otelhttp.NewTransport(http.DefaultTransport)}
```

### 5.4 手动埋点与 context 传播

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
	// ⭐ 从 ctx 取当前 span 作为父，创建子 span —— 必须把 ctx 一路向下传
	ctx, span := tracer.Start(ctx, "service.CreateOrder",
		trace.WithAttributes(attribute.String("order.store_id", req.StoreID)),
	)
	defer span.End() // 确保 span 结束并上报

	// 把 ctx 透传给 repository，SQL span 才会挂在同一条链路下
	order, err := repo.Save(ctx, req)
	if err != nil {
		span.RecordError(err)                      // ⭐ 记录异常
		span.SetStatus(codes.Error, err.Error())
		return Order{}, err
	}
	span.SetAttributes(attribute.String("order.id", order.ID))
	return order, nil
}
```

⭐ **context 传播三条铁律**：

1. 创建 span 后必须用**返回的新 ctx**（`ctx, span := tracer.Start(...)`），继续用旧 ctx 等于没建父子关系。
2. 跨 goroutine / 跨服务都要**显式传 ctx**；别把 ctx 存结构体字段或全局变量。
3. 下游进程用 `otel.GetTextMapPropagator().Extract(ctx, carrier)` 提取上游头，才续接同一条 trace。

⚠️ **历史方案**：`github.com/uber/jaeger-client-go` **已归档**，官方建议改用 OTel SDK，新项目不要再用。

## 六、使用二：Java（⭐ 附）

### 6.1 路线 A（推荐）：OTel Java Agent 无侵入自动埋点

零改码，字节码增强自动为 Spring MVC / JDBC / Redis / Kafka / HTTP Client 生成 span。

```bash
# 启动参数
java -javaagent:/opt/otel/opentelemetry-javaagent.jar \
     -Dotel.service.name=order-service \
     -Dotel.exporter.otlp.endpoint=http://jaeger:4317 \
     -Dotel.exporter.otlp.protocol=grpc \
     -Dotel.traces.sampler=parentbased_traceidratio -Dotel.traces.sampler.arg=0.1 \
     -jar app.jar

# 容器内用环境变量等价替代
OTEL_SERVICE_NAME=order-service
OTEL_EXPORTER_OTLP_ENDPOINT=http://jaeger:4317
OTEL_EXPORTER_OTLP_PROTOCOL=grpc
OTEL_TRACES_SAMPLER=parentbased_traceidratio
OTEL_TRACES_SAMPLER_ARG=0.1
JAVA_TOOL_OPTIONS=-javaagent:/opt/otel/opentelemetry-javaagent.jar
```

⭐ 优点：**零改码、覆盖面广**，适合快速接入与存量系统。⚠️ 缺点：agent 版本须与框架版本匹配，排障需看 agent 日志。

### 6.2 路线 B：Spring Boot 3 + Micrometer Tracing 手动埋点

Spring Boot 3 起用 **Micrometer Tracing**（底层桥接 OTel SDK）替代已停更的 Sleuth。

```xml
<dependency>
  <groupId>io.micrometer</groupId><artifactId>micrometer-tracing-bridge-otel</artifactId>
</dependency>
<dependency>
  <groupId>io.opentelemetry</groupId><artifactId>opentelemetry-exporter-otlp</artifactId>
</dependency>
```

```yaml
management:
  tracing:
    sampling:
      probability: 0.1                        # 采样率
  otlp:
    tracing:
      endpoint: http://jaeger:4318/v1/traces  # OTLP HTTP
```

```java
import io.micrometer.tracing.Tracer;

@Service
public class OrderService {
    private final Tracer tracer;

    public OrderService(Tracer tracer) { this.tracer = tracer; }

    public Order create(OrderReq req) {
        Span span = tracer.nextSpan().name("service.createOrder").start();
        try (Tracer.SpanInScope ws = tracer.withSpan(span)) {
            span.tag("order.store_id", req.getStoreId());
            return repo.save(req);
        } catch (Exception e) {
            span.error(e);                 // 记录异常
            throw e;
        } finally {
            span.end();
        }
    }
}
```

⚠️ `jaeger-client-java` **同样已归档**；不要再用 `io.jaegertracing:jaeger-client`。迁移路径：`jaeger-client` → `OTel SDK` + OTLP。

## 七、结合本项目（photography-server）的落地说明

> 以下基于**实际读到的仓库文件**：`docker-compose.yml`、`docs/部署/*`、`docs/架构/02-系统架构图.md`、`internal/infrastructure/jaeger.go`、`internal/middleware/jaeger.go`、`internal/router/router.go`、`internal/infrastructure/{mysql,nats}.go`、`internal/mq/consumer.go`、`internal/job/*`、`config/jaeger.example.yaml`、`config/nacos/*.yaml`。

### 7.1 现状：两条链路通道，二选一

项目**同时**编排了 SkyWalking native 与 **OTel→Jaeger** 两套链路后端，**互不依赖、建议二选一**（同开会同一请求双 span / 双上报）。

| 通道 | 探针 | 上报路径 | 存储 | UI | 开关 |
|---|---|---|---|---|---|
| ① SkyWalking native | 编译期注入 `skywalking-go` agent | 直连 `skywalking-oap:11800` | BanyanDB（17912） | Horizon UI `:9080` | `SW_AGENT_ENABLE=true` 构建 + `jaeger.enable=false` |
| ② **OTel→Jaeger** | 不注入 agent，纯 **OTel SDK 埋点** | `jaeger:4317`（OTLP gRPC） | **ClickHouse**（9000） | **Jaeger UI `:16686`** | Nacos `jaeger.enable=true` + `endpoint=jaeger:4317` |

> 事实核对：compose 中 `jaeger` 镜像为 `jaegertracing/jaeger:2.20.0`，即 **Jaeger v2 单体（Collector+Query+UI）**；`config/jaeger.example.yaml` 说明 ClickHouse 为 v2.18 起官方原生存储（ADR-008）。
> 生产默认 `jaeger.enable: false`（`config/nacos/photography-server-prod.yaml`），dev / test / docker.dev 三 profile 为 `true`。

### 7.2 数据流（实际）

```
前端 :8081/:8082/:8083 ── /api 剥前缀 ──▶ backend（Go + Gin，:8080）
   │ ① otelgin 生成 HTTP entry span（router.go 挂载 JaegerTrace）
   │ ② GORM OTel 插件生成 SQL client span（mysql.go，以 JaegerEnabled() 为条件安装）
   │ ③ NATS：生产端 traceMsg 注入 traceparent，消费端 Extract 续接（nats.go / mq/consumer.go）
   │ ④ xxl-job：任务入口创建根 span（job/traced）
   │ OTLP gRPC :4317
   ▼
jaeger（v2，collector+query 一体）──▶ ClickHouse（库 jaeger）──▶ Jaeger UI :16686
```

### 7.3 接入落点（与部署目录/架构一致）

| 层 | 文件 | 做了什么 |
|---|---|---|
| 初始化 | `cmd/server/main.go` | `InitJaeger(&cfg.Jaeger)` 在 `InitMySQL` **之前**（GORM 插件会捕获全局 TracerProvider） |
| TracerProvider | `internal/infrastructure/jaeger.go` | `otlptracegrpc` + `BatchSpanProcessor(5s)` + W3C `TraceContext`/`Baggage` 传播器，单例 |
| HTTP 入口 | `internal/middleware/jaeger.go` | `otelgin.Middleware(service)`；未启用返回 `nil`，请求路径零开销 |
| 中间件链 | `internal/router/router.go:50` | 启用时挂 `JaegerTrace → TraceID → TraceParams`；否则只挂 `TraceID`（给 native 用） |
| SQL | `internal/infrastructure/mysql.go:49` | `JaegerEnabled()` 时安装 GORM 官方 OTel 插件 |
| MQ 生产 | `internal/infrastructure/nats.go:127` | `otel...Inject(ctx, HeaderCarrier)` 注入 `traceparent` |
| MQ 消费 | `internal/mq/consumer.go:275` | `Extract` 上游头 → 创建 `nats.<subject>` 处理 span（`messaging.system=nats` 等） |
| 定时任务 | `internal/job/*` | `traced()` 在任务入口创建根 span `xxl-job.<handler>`，无上游 trace，独立成链 |
| 响应/日志 | `internal/middleware/traceid.go`、`internal/pkg/response/response.go` | `trace_id` 回写响应头 `X-Trace-Id` 与响应体 `Body.trace_id`；5xx 写 span 的 `exception.stacktrace` |

### 7.4 与部署目录的对应

| 部署侧 | 位置 |
|---|---|
| compose 定义 | 部署目录 `docker-compose.yml` 的 `jaeger` + `clickhouse` 两 service |
| Jaeger 配置挂载 | `./volume/jaeger/config.yaml` ← 由 `config/jaeger.example.yaml` 拷出（⚠️ 缺文件时 Docker 会把它建成**目录**，容器内报 `is a directory`） |
| 业务开关 | Nacos `data_id=photography-server-<profile>.yaml` 的 `jaeger.*` 段（`enable/endpoint/service/instance`），应急可用 `APP_JAEGER_*` 覆盖 |
| 端口 | 4317（OTLP gRPC）/ 4318（OTLP HTTP）/ 16686（UI） |

### 7.5 与现有可观测性栈的分工（⭐ 建议，非现状强制）

- **现状**：两套后端都已编排，但**规定二选一**；native 注入构建时 `jaeger.enable` 须为 `false`。
- **建议**（若要长期共存，按用途切分而非同请求双报）：**SkyWalking / Horizon** 看服务拓扑、指标大盘与告警；**Jaeger / ClickHouse** 看单条 trace 的精确检索（按 `trace_id` 直查、span 瀑布），ClickHouse 高基数低延迟更适合按 ID 精查；**勿同请求双报**——会双份存储、双份算力，且两个 trace_id 不一致，排障反而割裂。

⚠️ 代码注释明确的已知限制：SQL span 挂在 GORM `Statement.Context` 下，**业务未透传请求 ctx 时会以独立 trace 入库**（SQL 与参数可查，但不挂在 HTTP 链路下）；SkyWalking native 通道的 MQ 透传（`sw8` header）为待办。

## 八、与 SkyWalking 的分工

| 维度 | Jaeger（+ OTel） | SkyWalking |
|---|---|---|
| 定位 | 链路追踪后端 + 查询 UI | 完整 APM（链路 + 指标 + 拓扑 + 告警 + 日志关联） |
| 埋点协议 | OTLP（W3C `traceparent`） | native gRPC（`sw8` header），另有 OTel/Zipkin receiver |
| 侵入性 | SDK 显式埋点 或 OTel Agent | Agent 字节码增强，几乎零改码 |
| 拓扑/告警 | 无（需配合 Grafana 等） | 内置拓扑图、告警规则 |
| 存储 | ClickHouse / ES / Cassandra | BanyanDB / ES / H2 |
| 跨语言 | 天然（OTel 全语言） | 多语言 agent，Java/Go 最成熟 |

⭐ 建议：**二选一为主线，不要并行双报**。已用 OTel、多语言、要标准化 → **Jaeger 主链路**，指标告警另接 Prometheus + Grafana；要开箱即用 APM 大盘与拓扑告警 → **SkyWalking 主线**，Jaeger 仅在按 ID 精查时按需开。

## 九、面试官会追问什么

1. **采样率怎么定？** 开发全采；生产按流量 `ParentBased(TraceIDRatioBased(1%~10%))` 保证父子决策一致；最优是 Collector **尾部采样**——错误与慢请求 100% 保留、正常请求抽样，又省存储又不丢关键链路。
2. **TraceID 如何跨服务透传？** W3C `traceparent` 头（`version-traceid-spanid-flags`）随 HTTP/RPC 传递；MQ 放消息 Header；下游 `Extract` 提取后作为父上下文创建新 span。⚠️ 头格式全链路须统一，混用 `sw8`/`uber-trace-id` 会断链。
3. **异步 / MQ 怎么串联？** 生产端发送前 `Inject`（把当前 span 注入消息头），消费端 `Extract` 后续接同一 trace；定时任务这类无上游入口的创建**根 span** 独立成链。本项目已按此实现（`nats.go` 注入 / `consumer.go` 抽取）。
4. **Jaeger 存储怎么选？** 本地/CI 用 Badger 或内存；生产 ClickHouse（高基数、按 ID 精查、成本低，v2 官方原生）或 Elasticsearch（生态成熟、聚合强）；Cassandra 适合超大规模写入但运维要求高。
5. **为什么 Jaeger v2 转向 OTel？** OTel 已成事实标准：v2 直接以 OTel Collector 为运行时，统一接收/处理/导出模型，避免维护私有协议与 SDK，也让 Jaeger 成为生态里“可替换的后端”。
6. **为什么老 Jaeger SDK 不再用？** `jaeger-client-go/java` 已归档，私有 `uber-trace-id` 与私有 Thrift 协议与 OTel 标准冲突；统一 OTel SDK + OTLP 后，后端可换而埋点不动。
7. **Span 爆炸 / 性能开销怎么控？** 采样 + 批量异步导出（`BatchSpanProcessor`）+ 属性精简（避免把大 body/敏感字段写进 span，本项目 `TraceParams` 已做 4KB 截断与敏感字段脱敏）+ 关闭时零开销（中间件返回 `nil`）。
8. **TraceID 怎么和日志关联？** 把当前 span 的 `trace_id` 写入响应头与日志字段（本项目 `X-Trace-Id` + 响应体 `trace_id`），日志系统按 `trace_id` 聚合即可从日志跳回 UI 检索。
