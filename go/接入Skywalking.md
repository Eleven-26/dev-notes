# Go 接入 SkyWalking

> 内容整理自个人学习笔记 —— Go 侧接入 SkyWalking 的完整手册，按**为什么只能编译期注入 → 路线一（skywalking-go 注入）→ 手动埋点 → 跨 goroutine → 日志与指标 → 路线二（OTel + OTLP）→ 两条路线取舍 → 验证排查**组织。
>
> SkyWalking 本体原理、OAP 架构与 UI 面板见 [../可观测性/Skywalking.md](../可观测性/Skywalking.md)；OTel 路线的后端部署见 [../可观测性/Jaeger.md](../可观测性/Jaeger.md)；同命名口径的另两篇是 [../java/接入Skywalking.md](../java/接入Skywalking.md) 与 [../php/接入Skywalking.md](../php/接入Skywalking.md)。

---

## 一、为什么 Go 只能编译期注入？⭐

**本节要点**：这一节决定了 Go 侧所有配置的生效时机。记住「埋点在编译期就固化进二进制了」，后面每一条限制（`-a` 不能省、`-config` 只在编译时读、运行期无法开关插件）都不用背。

Java 能靠 `java.lang.instrument` 在**类加载时**改字节码，所以 `-javaagent` 一个启动参数就够。Go 编译出来的是**静态机器码，没有 classloader、没有运行期反射式改指令的口子**，因此所有「运行时无侵入」的方案在 Go 上都不成立：

| 方案 | 在 Go 上为什么不成立 |
| --- | --- |
| 运行时字节码增强（Java agent 那套） | Go 没有字节码，产物是 AOT 编译的机器码 |
| eBPF 全量覆盖 | 只能抓 syscall / 少数 uprobe 点，拿不到函数参数、SQL 语句、业务 span 名，做不到全量 |
| 动态链接 hook | Go 默认静态链接，且内联后连符号都找不到 |

所以 `skywalking-go` 选的是**编译期注入**：在 `go build` 过程中通过 `-toolexec` 劫持每一次编译器调用，分析 AST 并在目标调用点前后插入埋点代码，产物是「**已经埋好点的二进制**」，运行期不再做任何指令改写。

三个直接推论：

1. **`-a` 不能省**。注入发生在编译期，Go 的构建缓存里存的是**没注入过的旧包**；不加 `-a` 强制全量重编译，缓存命中后成品里就没有埋点代码——这是「配了 agent 却一条 trace 都没有」最常见的原因。
2. **`-config` 只在编译时生效**。配置文件里的插件开关（`plugin.excluded`）是编译期决策，运行期改 yaml 再重启**不会**生效，必须重新编译。
3. **运行期无法一键摘除插件**。想临时停掉只能靠环境变量让 reporter 空转（见第七节），不能像 Java 那样删个 jar 就行。

⚠️ 反过来，**服务名、上报地址、采样率这些是运行期读的**：agent 默认配置写成 `${SW_AGENT_NAME:Your_ApplicationName}` 这种形式，先读环境变量、读不到才用默认值。所以同一个二进制可以跨环境复用，只有「插件集」被钉死在编译产物里。

---

## 二、路线一：skywalking-go（编译期注入）

### 2.1 接入前检查清单

| 检查项 | 要求 | 不满足的后果 |
| --- | --- | --- |
| agent 二进制 | 下载与**项目 Go 版本匹配**的 skywalking-go 发布包 | 版本不匹配时注入器解析 AST 失败，编译直接报错 |
| 注入步骤 | `skywalking-go-agent -inject` 已执行 | `go.mod` 里没有 `github.com/apache/skywalking-go`，编译期无埋点可织入 |
| 编译参数 | `-toolexec="skywalking-go-agent"` **且** `-a` | 缺 `-toolexec` 完全不注入；缺 `-a` 命中缓存、注入被跳过 |
| 服务名 | `SW_AGENT_NAME` 已设置 | UI 上出现一批 `Your_ApplicationName`，多服务混在一起分不清 |
| OAP 地址 | `SW_AGENT_REPORTER_GRPC_BACKEND_SERVICE` 指向 **gRPC 11800** | 填成 UI 的 8080 会连接失败，且失败是静默的 |
| 与 OTel 互斥 | 已关掉 OTel SDK 上报 | 同请求双 span，CPM/Apdex 指标翻倍、告警阈值全失真 |

### 2.2 安装 agent

```bash
# 从官网下载 Go Agent 发布包（https://skywalking.apache.org/downloads/#GoAgent）
tar -zxvf skywalking-go-<version>-linux-amd64.tar.gz -C /opt/
export PATH=$PATH:/opt/skywalking-go/bin   # 目录内含 skywalking-go-agent 可执行文件
```

### 2.3 注入依赖

```bash
# 方式 A：用注入器改写依赖（推荐，会自动改 go.mod 并植入初始化代码）
/opt/skywalking-go/bin/skywalking-go-agent -inject /path/to/your/project        # 只注入主模块
/opt/skywalking-go/bin/skywalking-go-agent -inject /path/to/your/project -all   # 连子模块一起注入

# 方式 B：手工加依赖（不想让工具改代码时）
go get github.com/apache/skywalking-go
# 并在 main 包里空白导入触发 agent 初始化：
#   import _ "github.com/apache/skywalking-go"
```

注入器做的事：往 `go.mod` 加 `github.com/apache/skywalking-go` 依赖，并在入口包插入空白导入，让 agent 的 `init()` 在 `main()` 之前跑起来。

### 2.4 编译

```bash
# 关键：给 go build 加上 -toolexec，以及 -a 强制全量重编译
go build -toolexec="/opt/skywalking-go/bin/skywalking-go-agent" -a -o bin/order-service ./cmd/server

# 需要自定义配置时把配置路径交给它
go build -toolexec="/opt/skywalking-go/bin/skywalking-go-agent -config agent.yaml" -a -o bin/order-service ./cmd/server
```

`-toolexec` 让 Go 把编译过程中的每个工具调用（`compile` / `link`）都先交给 agent 过一遍，agent 借此分析 AST 并注入埋点代码。集成到 Makefile 后与普通构建无异：

```makefile
GO_AGENT ?= /opt/skywalking-go/bin/skywalking-go-agent
CONFIG   ?= agent.yaml

build:
	CGO_ENABLED=0 GOOS=linux go build \
		-toolexec="$(GO_AGENT) -config $(CONFIG)" -a \
		-ldflags "-s -w" -o bin/order-service ./cmd/server
```

> ⚠️ `-a` 会让**整个标准库也重编译**，构建时间从几秒变成几分钟。CI 上可以把注入产物缓存起来，但缓存 key 必须包含 agent 版本与 `agent.yaml` 的哈希，否则改配置后拿到的是旧产物。

### 2.5 配置文件

agent 的默认配置主要项如下，大部分值都是 `${ENV:default}` 形式，意味着**运行期先读环境变量，读不到才用默认值**：

```yaml
agent:
  service_name: ${SW_AGENT_NAME:Your_ApplicationName}
  sampler: ${SW_AGENT_SAMPLE:1}          # 0~1 的浮点采样率，1 表示全采样
  ignore_suffix: ${SW_AGENT_IGNORE_SUFFIX:.jpg,.jpeg,.js,.css,.png,.ico,.mp4,.html,.svg}
reporter:
  grpc:
    backend_service: ${SW_AGENT_REPORTER_GRPC_BACKEND_SERVICE:127.0.0.1:11800}
    max_send_queue: ${SW_AGENT_REPORTER_GRPC_MAX_SEND_QUEUE:5000}
```

⚠️ **与 Java 的采样语义不同，别照抄**：

| | 配置项 | 语义 |
| --- | --- | --- |
| Go | `agent.sampler` | **0~1 的浮点概率**，`1` 全采、`0.1` 采 10% |
| Java | `agent.sample_n_per_3_secs` | **每 3 秒最多采多少条**，`-1` 全采 |

把 Java 的 `1000` 填进 Go 的 `sampler`，结果是「采样率 1000」被当成恒真，等于全采——不会报错，只会悄悄把存储打满。

### 2.6 自动埋点覆盖的框架

编译期注入**只对「编译时能识别到调用点」的第三方库生效**，因此插件支持范围是选型的关键。当前覆盖的主要有：

- **HTTP**：Server 侧 `net/http`（原生）、`gin`、`go-restful v3`、`mux`、`iris`、`fasthttp`、`fiber`、`echo v4`、`goFrame`；Client 侧 `net/http`、`fasthttp`。
- **RPC**：`gRPC`、`dubbo-go`、`kratos v2`、`go-micro v4`。
- **数据与缓存**：`GORM`（MySQL/PostgreSQL 驱动）、`database/sql`（MySQL、pgx stdlib）、MongoDB、go-elasticsearch v8、`go-redis v9`。
- **消息队列**：rocketmq-client-go、AMQP（RabbitMQ）、pulsar-client-go、segmentio-kafka。
- **指标 / 日志**：`runtime/metrics`（原生运行时指标）；`logrus`、`zap`（自动把 trace 上下文写进日志并支持上报日志）。

也就是说：**用 Gin + GORM + gRPC + go-redis 这类主流组合，零代码改动即可拿到完整链路**；一旦用了自研 RPC 或未被覆盖的库，就需要第三节的手动 API。

⚠️ 每个插件都有**支持的版本区间**（声明在插件定义里）。升级框架大版本前先核对官方 Support-Version-Matrix，否则表现为「编译通过、运行不报错、但那个库的 span 全没了」。

---

## 三、手动埋点 API（`toolkit/trace`）

**本节要点**：自动插件覆盖不到的地方才需要手写。核心是三类 span（Entry / Exit / Local）与「必须在同一个 goroutine 里按 LIFO 结束」这条约束。

```go
import "github.com/apache/skywalking-go/toolkit/trace"

// 1) 本地 span（最常用：给关键业务方法加一段可观测的耗时）
span, err := trace.CreateLocalSpan("OrderService.Refund")
if err == nil {
	defer span.End() // 必须在创建 span 的同一个 goroutine 里、LIFO 顺序结束
	trace.SetTag("biz.type", "refund")
	trace.AddEvent(trace.WarnEventType, "refund amount exceeded threshold")
}

// 2) 入口 span：自研 HTTP / RPC 服务端从请求头提取上下文
span, err = trace.CreateEntrySpan("EntrySpan", func(headerKey string) (string, error) {
	return r.Header.Get(headerKey), nil
})

// 3) 出口 span：调下游时把上下文写进请求头，第二个参数是对端地址
span, err = trace.CreateExitSpan("ExitSpan", request.Host, func(headerKey, headerValue string) error {
	request.Header.Add(headerKey, headerValue)
	return nil
})
trace.StopSpan() // 结束当前上下文中的 span；也可用 span.End() 精确结束某个 SpanRef

// 4) 读上下文（只读 API，仅在当前 goroutine 被追踪时有值）与设置组件 ID
traceId := trace.GetTraceID() // 另有 GetSegmentID() / GetSpanID()
trace.SetComponent(componentID) // int32，对应 OAP 的 component-libraries.yml

// 5) 自定义关联数据（随链路传播，最多 3 个 key、每个 value 最长 128 字符）
trace.SetCorrelation("tenantId", "1001")
tenantId := trace.GetCorrelation("tenantId")
```

三条必须记住的约束：

1. **同 goroutine + LIFO**。span 上下文存在 goroutine 本地，跨 goroutine 直接用会拿到空上下文；嵌套 span 必须后进先出地 `End()`，乱序会让父子关系错乱。
2. **`err != nil` 要判**。`CreateLocalSpan` 在「当前 goroutine 未被追踪」时返回错误而不是 panic——比如被采样掉了、或者 agent 没注入成功。忽略 `err` 会让埋点静默失效。
3. **correlation 有硬限制**（3 个 key、value ≤ 128 字符）。它是随链路透传的业务上下文，别拿来塞大对象；⚠️ 也**别塞敏感信息**，它会跨服务传播并落到存储里。

> 备注：早期版本的手动埋点 API 与当前 `toolkit/trace` 包的写法并不一致（如 `agent.Init()` / `agent.CreateSpan()` 这类风格），主线版本已统一收敛到 `toolkit/trace`；存量代码升级时以所用版本的官方文档为准。

---

## 四、跨 goroutine 怎么续上链路？⭐

**本节要点**：Go 侧最高频的断链场景。两种写法对应两种意图——「等它做完」用 `PrepareAsync` / `AsyncFinish`，「不等、但要把父子关系接上」用上下文快照。

span 上下文绑定在 goroutine 上，所以 `go func(){...}()` 里的调用默认**脱离链路**，表现为「HTTP 请求有 span，异步写的那部分查不到」。

```go
// 写法一：父 span 要等异步任务结束（异步是本次请求耗时的一部分）
span.PrepareAsync()
go func() {
	defer span.AsyncFinish()
	doAsyncWork()
}()

// 写法二：父 span 不等，但子 goroutine 仍要挂在同一条链路上
snapshot := trace.CaptureContext() // 当前 goroutine 拍快照
go func() {
	defer trace.StopSpan()
	trace.ContinueContext(snapshot) // 目标 goroutine 恢复快照
	doWork()
}()
```

| | 写法一：`PrepareAsync` / `AsyncFinish` | 写法二：`CaptureContext` / `ContinueContext` |
| --- | --- | --- |
| 父 span 是否等待 | **等**，异步完成才结束父 span | 不等，父 span 按自己节奏结束 |
| 适用 | 异步结果影响本次响应（如异步落库后才返回） | fire-and-forget（发消息、写审计、预热缓存） |
| 风险 | 忘记 `AsyncFinish` → 父 span **永远不结束**，链路悬挂 | 忘记 `ContinueContext` → 子 goroutine 独立成链 |

⚠️ **协程池要额外当心**：worker 是常驻的，一个 worker 会先后处理多个请求的快照。每轮处理必须 `ContinueContext(本轮快照)` + `defer trace.StopSpan()` 成对出现，否则上一个请求的上下文会串到下一个请求上，UI 里表现为「一条 trace 里混进了不相干的业务」。

同理，**用了 `context.Context` 传参的代码路径不要另起一套**：如果项目已经在用 OTel SDK（第六节），跨 goroutine 直接传 `ctx` 就够了，不要再叠 skywalking-go 的快照机制。

---

## 五、日志与指标怎么关联到同一条 trace？

**本节要点**：`logrus` / `zap` 插件会自动把 trace 上下文写进日志，所以日志侧**通常零改动**；真正要动的是「日志里有没有 traceId 字段」这件事。

装上 `logrus` / `zap` 插件后，agent 会：

1. 在每条日志里注入当前 `traceId` / `segmentId` / `spanId`（字段名由插件决定）；
2. 按配置把日志本身也上报给 OAP，从而在 UI 的日志面板里按 Trace ID 反查。

排查用法与 Java 侧一致：**Trace 找慢/失败的 span → 拿 traceId 查日志 → 看业务日志里当时在做什么**（UI 面板部分见 [../可观测性/Skywalking.md](../可观测性/Skywalking.md) 第三节）。

指标侧：agent 会采集 `runtime/metrics` 的原生运行时指标（GC、goroutine 数、内存），这部分**不需要写代码**。业务指标 SkyWalking 侧能力有限，要完整的指标大盘与告警建议另接 Prometheus（分工见 [../可观测性/可观测性选型.md](../可观测性/可观测性选型.md)）。

⚠️ 别把大对象整体打进日志再指望它上报：日志与链路共用上报通道，超限的消息会被**静默丢弃**，排障时最需要的恰好是那条被丢掉的大日志。打印前裁剪（PHP 侧同一问题的量化讨论见 [../php/接入Skywalking.md](../php/接入Skywalking.md)）。

---

## 六、路线二：OpenTelemetry SDK + OTLP

**本节要点**：不动编译链路，改在应用内初始化 OTel SDK，通过 OTLP 直发 OAP。代价是要写初始化代码、且上下文头从 `sw8` 变成 W3C `traceparent`。

如果团队已经在用 OpenTelemetry（统一了 collector、在做 OTel 规范治理），可以不碰 `-toolexec`，改由**应用内初始化 OTel SDK，通过 OTLP 把 trace 直接发给 SkyWalking OAP**。前提是 OAP 侧开启 OTLP receiver（OTLP gRPC 默认 4317、HTTP 默认 4318）：

```yaml
# OAP 的 application.yml
receiver-otel:
  selector: ${SW_OTEL_RECEIVER:default}
  default:
    enabledHandlers: ${SW_OTEL_RECEIVER_ENABLED_HANDLERS:"otlp-traces,otlp-metrics"}
```

Go 侧最小示例：

```go
package infrastructure

import (
	"context"

	"go.opentelemetry.io/otel"
	"go.opentelemetry.io/otel/exporters/otlp/otlptrace/otlptracegrpc"
	"go.opentelemetry.io/otel/propagation"
	"go.opentelemetry.io/otel/sdk/resource"
	sdktrace "go.opentelemetry.io/otel/sdk/trace"
	semconv "go.opentelemetry.io/otel/semconv/v1.26.0"
)

// InitTracer 初始化 OTLP gRPC 导出器，指向 SkyWalking OAP 的 4317 端口。
// 返回的 shutdown 必须在进程退出前调用，否则缓冲中的最后一批 span 会丢。
func InitTracer(ctx context.Context, endpoint, serviceName string) (func(context.Context) error, error) {
	exp, err := otlptracegrpc.New(ctx,
		otlptracegrpc.WithEndpoint(endpoint),
		otlptracegrpc.WithInsecure(), // 内网明文；生产换 TLS 凭证
	)
	if err != nil {
		return nil, err
	}

	// service.name 决定 UI 上的服务名，必须写对
	res, err := resource.New(ctx, resource.WithAttributes(
		semconv.ServiceName(serviceName), semconv.ServiceVersion("1.0.0")))
	if err != nil {
		return nil, err
	}

	tp := sdktrace.NewTracerProvider(
		sdktrace.WithBatcher(exp),
		sdktrace.WithResource(res),
		sdktrace.WithSampler(sdktrace.ParentBased(sdktrace.TraceIDRatioBased(0.1))),
	)
	// ⭐ 注册全局 TracerProvider 与 W3C 传播器，跨服务才能串起来
	otel.SetTracerProvider(tp)
	otel.SetTextMapPropagator(propagation.NewCompositeTextMapPropagator(
		propagation.TraceContext{}, propagation.Baggage{},
	))
	return tp.Shutdown, nil
}
```

⚠️ 这条路线有四个必踩的坑：

1. **初始化顺序**：`InitTracer` 必须在初始化数据库 / GORM **之前**。GORM 的 OTel 插件在安装时**捕获当时的全局 TracerProvider**，顺序颠倒会让 SQL span 走 noop provider、永远没有数据。
2. **TracerProvider 必须单例**：它是进程级资源（持有导出器与批量队列）。放在包级变量里由 `main` 初始化一次，**禁止**在请求路径里 `NewTracerProvider`——每次新建都会起一套批处理协程，直接协程泄漏 + 连接爆炸。
3. **传播头变了**：OTel 默认用 W3C `traceparent`，而 SkyWalking 原生探针用 `sw8`。与 Java / PHP 探针混跑时，要让 OAP 侧能解析两套头，或显式统一传播器，否则链路会断成两段独立 trace。
4. **退出要 flush**：`defer shutdown(ctx)` 挂到优雅退出流程上，`kill -9` 等于放弃最后一批数据。

埋点用 OTel 生态的库：`otelhttp`（`otelhttp.NewHandler` / `otelhttp.NewTransport`）包一层 `net/http`，Gin 用 `otelgin`，GORM 用 `otelgorm`，业务埋点用 `tracer.Start(ctx, "xxx")`。完整写法见 [接入Jaeger.md](接入Jaeger.md)——**同一套埋点代码，只换 endpoint 就能在 SkyWalking OAP 与 Jaeger 之间切换**，这正是 OTLP 标准化的价值。

---

## 七、两条路线怎么选？⚠️ 不要同时开启

### 7.1 对比

| 维度 | 路线一：skywalking-go（编译期注入） | 路线二：OpenTelemetry SDK + OTLP |
| --- | --- | --- |
| **侵入性** | 低。只需 `-inject` + 改 `go build` 参数，业务代码可零改动（自研框架除外） | 中。需在 `main` 里初始化 SDK、注册 TracerProvider，并用 OTel 库包装框架 |
| **生效时机** | **编译期**写入二进制，运行期无法开关或增删插件（`plugin.excluded` 也只作用于编译期） | 运行期。采样率、导出地址、启停都由配置控制，改配置重启即可 |
| **构建成本** | `-a` 全量重编译，CI 时间明显变长 | 无额外构建成本 |
| **框架覆盖** | 官方插件集（Gin/gRPC/GORM/go-redis/Dubbo-Go/Kratos/MQ 等），覆盖主流但有限，且有版本范围限制 | OTel contrib 生态最广（含云厂商 SDK、自定义 exporter），新库适配通常更快 |
| **与 Java 侧一致性** | **高**。同一套 OAP 原生 Segment/span 模型、同一套 `sw8` 上下文，Java ↔ Go ↔ PHP 可直接串链 | 中。链路经 `receiver-otel` 转换进入 OAP，服务命名依赖 `service.name`；跨语言串链需统一传播头 |
| **拓扑图 / 告警兼容** | 直接复用 Java 侧已有的告警规则、Apdex、Slow Endpoint 能力 | 拓扑与指标依赖 OAP 的 OTLP 转换规则，部分原生指标（如 JVM 类指标）不适用 |
| **指标与日志** | 原生 runtime 指标采集 + logrus/zap 日志上报，与 trace 天然关联 | 指标走 OTLP metrics（需在 OAP 配 `otel-rules`）；日志需另建 pipeline |
| **后端可替换性** | 低。绑定 SkyWalking 协议与 OAP | ⭐ 高。换 Jaeger / Tempo 只改 endpoint，埋点不动 |

**结论**：

- **以 SkyWalking 为统一 APM（尤其 Java 为主、Go 为辅）** → 选**路线一**，与 Java 侧体验一致、`sw8` 上下文直接互通、拓扑与告警零适配。
- **已有 OTel 基础设施 / 多语言 / 不想被单一后端锁定** → 选**路线二**，避免维护两套埋点体系；此时链路后端选 Jaeger 还是 SkyWalking 只是 endpoint 的差别（见 [../可观测性/可观测性选型.md](../可观测性/可观测性选型.md)）。

### 7.2 为什么不能同时开

⚠️ **skywalking-go 与 OTel SDK 不要同时开启上报**。两者都会在同一个入口/出口创建 span，结果是：OAP 侧收到两条重复链路，**调用量、CPM、Apdex 等指标翻倍**，告警阈值全部失真；上下文头混用（`sw8` + `traceparent`）还可能让上下游接上两条不同的 trace，拓扑图出现「幽灵边」。

切换时用以下方式彻底关掉其中一条：

```bash
# 关闭已注入的 Go agent（保留埋点代码但不发数据）—— 这是运行期唯一能关路线一的办法
SW_AGENT_REPORTER_DISCARD=true

# 或编译期排除插件（只在编译阶段生效）：agent.yaml 中 plugin.excluded: gin,grpc,gorm
```

```go
// OTel 侧则用采样器全关（比删代码安全，回滚只需改配置）
tp := sdktrace.NewTracerProvider(sdktrace.WithSampler(sdktrace.NeverSample()))
```

---

## 八、在 Dockerfile 里怎么构建？

官方提供了内置 agent 的基础镜像，把「注入 + 编译」都放进镜像构建阶段：

```dockerfile
# 1. 用官方 Go agent 镜像作为构建基础镜像（tag 里的 go 版本要匹配你的项目）
FROM apache/skywalking-go:<version>-go<go-version> AS builder
COPY . /workspace
WORKDIR /workspace

# 2. 注入依赖并编译（镜像里 skywalking-go-agent 已在 PATH 下）
RUN skywalking-go-agent -inject /workspace && \
    go build -toolexec="skywalking-go-agent" -a -o /workspace/bin/order-service ./cmd/server

# 3. 运行阶段只带二进制，agent 不需要进最终镜像（埋点已在编译期固化）
FROM alpine:3.20
COPY --from=builder /workspace/bin/order-service /usr/local/bin/order-service
ENV SW_AGENT_NAME=order-service \
    SW_AGENT_REPORTER_GRPC_BACKEND_SERVICE=oap.observability:11800
ENTRYPOINT ["/usr/local/bin/order-service"]
```

⭐ **多阶段构建的关键认知**：agent 只在 **builder 阶段**需要，运行阶段不用带——因为埋点是编译期固化进二进制的，运行期不改指令。但**注入和编译必须发生在同一次构建里**：分两次构建（先注入提交代码，再另起镜像编译）会让 `go.mod` 与产物脱节。

K8s 里把 `SW_AGENT_*` 交给 Deployment 的 `env`（同一个镜像跨环境复用）。与 Java 不同，**Go agent 几乎不吃额外内存**（没有 JVM agent 那套元空间开销），但 `reporter.grpc.max_send_queue` 默认 5000 条是常驻内存的，高 QPS 服务要留意。

---

## 九、怎么逐层确认接入生效？

**本节要点**：按「构建 → 进程 → 网络 → 后端」四层依次确认，能直接定位到哪一环断掉，而不是对着 UI 猜。

```bash
# 1. 构建期：注入是否真的发生了（产物里应能搜到 agent 的符号/字符串）
go build -toolexec="skywalking-go-agent" -a -x -o bin/app ./cmd/server 2>&1 | grep -c skywalking-go-agent
#    输出 0 说明 -toolexec 没生效（路径写错、或 go.mod 里没有依赖）

# 2. 产物层：确认 go.mod 里有依赖
grep skywalking-go go.mod

# 3. 运行期：确认配置展开正确（服务名不是默认值、地址端口是 11800）
env | grep SW_AGENT

# 4. 网络层：确认能连到 OAP 的 gRPC 端口
nc -vz oap.observability 11800
```

5. 打开 agent 的 debug 日志（`SW_AGENT_LOGGING_LEVEL=debug`），看有没有「连接 OAP 失败」「segment 入队失败」的记录。
6. 最后到 SkyWalking UI 的 Service 列表找 `SW_AGENT_NAME` 对应的服务名，有服务、拓扑图有边、Trace 能看到 SQL span，即为接入完成。

---

## 十、常见故障速查

| 症状 | 大概率原因 | 处理 |
| --- | --- | --- |
| 编译通过，UI 里完全没这个服务 | 漏了 `-a`，命中构建缓存、注入被跳过 | 补 `-a` 全量重编译（第一节） |
| 同上，且 `-a` 已加 | `-inject` 没执行，`go.mod` 里没有 agent 依赖 | 先 `-inject` 再编译（2.3） |
| 服务名显示 `Your_ApplicationName` | `SW_AGENT_NAME` 未注入到运行环境 | 检查 Deployment 的 `env`（2.5） |
| HTTP span 有、SQL span 全没有 | GORM/驱动版本超出插件支持区间 | 核对 Support-Version-Matrix，降版本或改手动埋点（2.6） |
| 采样率调了没反应 | 改的是 `agent.yaml` 但没重新编译；或把 Java 的「每 3 秒 N 条」填进了 Go 的浮点 `sampler` | 配置改动要重编译；采样语义按 2.5 的表核对 |
| 异步 goroutine 里的调用查不到 | span 上下文绑定 goroutine，没做快照传递 | 用第四节两种写法之一 |
| 一条 trace 里混进了不相干业务 | 协程池 worker 复用，`ContinueContext` / `StopSpan` 没成对 | 每轮处理都恢复本轮快照并 defer 结束（第四节） |
| 指标翻倍、Apdex 失真 | skywalking-go 与 OTel SDK 同时开着 | 按 7.2 关掉其中一条 |
| 拓扑图有「幽灵边」/ 链路断成两段 | 上下游传播头不一致（`sw8` vs `traceparent`） | 统一路线；混跑时让 OAP 同时解析两套头（第六节） |
| 重启后最后几秒的 trace 缺失 | 进程被 `kill -9`，队列未 flush | 优雅停机；`terminationGracePeriodSeconds` 要大于业务排空时间 |

---

## 延伸追问

- **Go 为什么不能像 Java 那样运行时无侵入埋点？** → Go 是 AOT 编译成静态机器码，没有 classloader 也没有运行期改指令的口子；eBPF 只能抓 syscall 与少数 uprobe，拿不到参数与业务语义。所以 skywalking-go 走**编译期 AST 注入**（`-toolexec`），代价是配置与插件集被固化进二进制、且 `-a` 全量重编译拖慢 CI。
- **`-toolexec` 具体做了什么？** → 让 Go 把每次 `compile`/`link` 调用先转交给指定程序。agent 借此在编译每个包时解析 AST，匹配插件定义的调用点，在前后织入埋点代码，再把改写后的源码交给真正的编译器。
- **为什么必须加 `-a`？** → 注入发生在编译期，而 Go 构建缓存里存的是**未注入的旧包**；不加 `-a` 会命中缓存直接复用，成品里没有埋点代码，且**不报任何错**。
- **跨 goroutine 怎么传上下文？** → 两种：父 span 要等异步结果用 `PrepareAsync` + `AsyncFinish`；fire-and-forget 用 `CaptureContext` 拍快照、子 goroutine `ContinueContext` 恢复。⚠️ 协程池必须在每轮处理里成对恢复/结束，否则上下文会串到下一个请求。
- **两条路线（native / OTel）怎么选？** → 以 SkyWalking 为统一 APM、要与 Java 侧 `sw8` 直接互通 → native 注入；已有 OTel 基础设施、要后端可替换 → OTel + OTLP。**绝不能同开**：双份 span 让 CPM/Apdex 翻倍、告警阈值失真，两套传播头还会让链路断裂。
- **采样率怎么配？** → Go 是 0~1 浮点概率（Java 是「每 3 秒 N 条」，别混）。生产建议 `ParentBased(TraceIDRatioBased(1%~10%))` 保证同一 trace 全链路决策一致；更优是在 Collector 侧做**尾部采样**，错误与慢请求 100% 保留。
- **探针会不会拖慢业务？** → 三处成本：埋点本身的 CPU、上下文跨进程传播、上报 IO。skywalking-go 用有界队列 + 批量异步上报，队列满时**宁可丢数据也不阻塞业务**；所以「链路偶发缺失」在高 QPS 下是设计取舍，不是 bug。
- **怎么保证停机不丢数据？** → 优雅退出时 flush 上报队列；OTel 路线要显式调 `TracerProvider.Shutdown(ctx)`。任何 `kill -9` 都等于放弃最后一批数据，`terminationGracePeriodSeconds` 必须大于业务排空时间。

---

## 十一、校验口径

> ✅ 本篇 Go 代码块的校验方式与范围（Go 1.26.5，windows/amd64）：
>
> - **真编译通过**（抽成独立模块，`go build ./...` + `go vet ./...` + `gofmt -l` 全绿）：第六节 `InitTracer`（含 `semconv/v1.26.0` 的 `ServiceName` / `ServiceVersion`）、7.2 节 `sdktrace.NeverSample()` 全关写法。
> - **未编译**：第三节 `toolkit/trace` 手动埋点、第四节 `CaptureContext` / `ContinueContext` 快照传递。原因是 `github.com/apache/skywalking-go/toolkit/trace` 的符号只在**注入后的构建**里存在（未注入时是空实现桩），无法在未跑 `-toolexec` 的临时模块里真编译，因此只核对 API 签名与调用约定。
> - **shell / makefile / dockerfile / yaml 片段**：`bash -n` 语法校验 + 结构核对，未实际执行注入构建与镜像构建。
> - 本机没有 SkyWalking OAP 实例，因此**只校验语法与编译、未实际接入运行**，文中不含伪造的 UI 输出。

| 依赖 | 实际解析版本 |
| --- | --- |
| `go.opentelemetry.io/otel`（含 `propagation`） | v1.46.0 |
| `go.opentelemetry.io/otel/sdk` | v1.46.0 |
| `go.opentelemetry.io/otel/exporters/otlp/otlptrace/otlptracegrpc` | v1.46.0 |
| `go.opentelemetry.io/otel/semconv/v1.26.0` | 随 otel v1.46.0 提供 |
| `github.com/apache/skywalking-go/toolkit/trace` | 未编译（见上） |

---

## 关联

- [../可观测性/Skywalking.md](../可观测性/Skywalking.md) — 探针原理（javaAgent / 轻量级队列内核 / PHP SAPI 生命周期）、OAP 架构、UI 六大面板
- [接入Jaeger.md](接入Jaeger.md) — 同一套 OTel 埋点、换 endpoint 即换后端的对照写法
- [../java/接入Skywalking.md](../java/接入Skywalking.md) — Java agent 路线，对照「运行期字节码增强 vs 编译期注入」的差异
- [../php/接入Skywalking.md](../php/接入Skywalking.md) — PHP 扩展路线，对照多进程 + 共享内存的上报模型
- [../可观测性/可观测性选型.md](../可观测性/可观测性选型.md) — 链路追踪五方案横向对比与「契合语言」维度
- [../go/工程实践/context.md](工程实践/context.md) — OTel 路线下 ctx 传播的基础
- [../部署/docker/镜像构建与缓存.md](../部署/docker/镜像构建与缓存.md) — `-a` 全量重编译与构建缓存的取舍
