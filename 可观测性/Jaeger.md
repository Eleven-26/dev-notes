# Jaeger 链路追踪

> Trace / Span 与采样率的核心概念、Jaeger 的四组件架构、与 OpenTelemetry 的协作关系、部署方式与采样策略、与 SkyWalking 的分工。
>
> 内容整理自个人学习笔记。同目录另见 [Skywalking.md](Skywalking.md)；接入代码按语言拆成分册，见第五节。

Jaeger 是 Uber 开源的**分布式链路追踪系统**，2017 年捐赠给 CNCF，2019 年毕业（Graduated），用于分布式/微服务架构下的**调用链追踪与性能分析**：一次请求经过了哪些服务、每个服务做了什么、耗时卡在哪一跳、失败发生在哪个环节。

覆盖内容：核心概念 → 架构与选型 → 与 OpenTelemetry 的协作 → 部署与采样 → 使用方法（分册）→ 与 SkyWalking 分工 → 常见追问。结合 photography-server 项目的落地映射见 [../go/接入Jaeger.md](../go/接入Jaeger.md) 第十节。

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

```text
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

## 五、使用方法

本篇只讲概念、架构、部署与选型；接入代码按语言拆成分册，与 [../php/接入Skywalking.md](../php/接入Skywalking.md) 同一命名口径：

| 语言 | 分册 | 覆盖内容 |
| --- | --- | --- |
| Go | [../go/接入Jaeger.md](../go/接入Jaeger.md) | OTel SDK 初始化、Gin / GORM / NATS / xxl-job 四类埋点、context 传播三条铁律、trace_id 关联日志与响应、优雅退出、photography-server 落地映射 |
| Java | [../java/接入Jaeger.md](../java/接入Jaeger.md) | OTel Java Agent 无侵入路线、Spring Boot 3 + Micrometer Tracing、日志 MDC 与响应头、跨线程传播、从 `jaeger-client` 迁移 |

> ⚠️ `jaeger-client-go` / `jaeger-client-java` **均已归档**，新项目一律用 OTel SDK + OTLP，不要再用私有客户端与私有 `uber-trace-id` 头。

## 六、与 SkyWalking 的分工

| 维度 | Jaeger（+ OTel） | SkyWalking |
|---|---|---|
| 定位 | 链路追踪后端 + 查询 UI | 完整 APM（链路 + 指标 + 拓扑 + 告警 + 日志关联） |
| 埋点协议 | OTLP（W3C `traceparent`） | native gRPC（`sw8` header），另有 OTel/Zipkin receiver |
| 侵入性 | SDK 显式埋点 或 OTel Agent | Agent 字节码增强，几乎零改码 |
| 拓扑/告警 | 无（需配合 Grafana 等） | 内置拓扑图、告警规则 |
| 存储 | ClickHouse / ES / Cassandra | BanyanDB / ES / H2 |
| 跨语言 | 天然（OTel 全语言） | 多语言 agent，Java/Go 最成熟 |

⭐ 建议：**二选一为主线，不要并行双报**。已用 OTel、多语言、要标准化 → **Jaeger 主链路**，指标告警另接 Prometheus + Grafana；要开箱即用 APM 大盘与拓扑告警 → **SkyWalking 主线**，Jaeger 仅在按 ID 精查时按需开。

## 七、延伸追问

- **采样率怎么定？** 开发全采；生产按流量 `ParentBased(TraceIDRatioBased(1%~10%))` 保证父子决策一致；最优是 Collector **尾部采样**——错误与慢请求 100% 保留、正常请求抽样，又省存储又不丢关键链路。
- **TraceID 如何跨服务透传？** W3C `traceparent` 头（`version-traceid-spanid-flags`）随 HTTP/RPC 传递；MQ 放消息 Header；下游 `Extract` 提取后作为父上下文创建新 span。⚠️ 头格式全链路须统一，混用 `sw8`/`uber-trace-id` 会断链。
- **异步 / MQ 怎么串联？** 生产端发送前 `Inject`（把当前 span 注入消息头），消费端 `Extract` 后续接同一 trace；定时任务这类无上游入口的创建**根 span** 独立成链。落地代码见 [../go/接入Jaeger.md](../go/接入Jaeger.md) 第七节。
- **Jaeger 存储怎么选？** 本地/CI 用 Badger 或内存；生产 ClickHouse（高基数、按 ID 精查、成本低，v2 官方原生）或 Elasticsearch（生态成熟、聚合强）；Cassandra 适合超大规模写入但运维要求高。
- **为什么 Jaeger v2 转向 OTel？** OTel 已成事实标准：v2 直接以 OTel Collector 为运行时，统一接收/处理/导出模型，避免维护私有协议与 SDK，也让 Jaeger 成为生态里“可替换的后端”。
- **为什么老 Jaeger SDK 不再用？** `jaeger-client-go/java` 已归档，私有 `uber-trace-id` 与私有 Thrift 协议与 OTel 标准冲突；统一 OTel SDK + OTLP 后，后端可换而埋点不动。
- **Span 爆炸 / 性能开销怎么控？** 采样 + 批量异步导出（`BatchSpanProcessor`）+ 属性精简（避免把大 body/敏感字段写进 span，实践做法是 4KB 截断与敏感字段脱敏）+ 关闭时零开销（中间件返回 `nil`）。
- **TraceID 怎么和日志关联？** 把当前 span 的 `trace_id` 写入响应头（`X-Trace-Id`）、响应体与日志字段，日志系统按 `trace_id` 聚合即可从日志跳回 UI 检索。

## 关联

- [../go/接入Jaeger.md](../go/接入Jaeger.md) — Go 侧 OTel SDK 埋点的完整接入手册（含 photography-server 落地映射）
- [../java/接入Jaeger.md](../java/接入Jaeger.md) — Java 侧 OTel Agent 与 Micrometer Tracing 两条路线
- [Skywalking.md](Skywalking.md) — 探针式 APM 的另一条路线与分工
- [../php/接入Skywalking.md](../php/接入Skywalking.md) — 多语言探针接入的对照
- [../网络/HTTP与gRPC.md](../网络/HTTP与gRPC.md) — trace 上下文在请求头里的传播
- [../部署/k8s/K8s部署与生命周期面试题.md](../部署/k8s/K8s部署与生命周期面试题.md) — 采集组件的部署形态
- [可观测性选型.md](可观测性选型.md) — 链路追踪的完整选型表（含指标、日志、存储、可视化的选型）
