# Java 接入 Jaeger

> 内容整理自个人学习笔记 —— Java 侧接入 Jaeger 的完整手册，按**前提认知 → 路线 A（OTel Java Agent 无侵入）→ 路线 B（Spring Boot 3 + Micrometer Tracing）→ 两条路线取舍 → 日志关联 → 跨线程 → 优雅停机 → 从 jaeger-client 迁移 → 验证排查**组织。
>
> Jaeger 的概念、架构、与 OpenTelemetry 的关系、部署（v2 + ClickHouse）与采样策略见 [Jaeger.md](../../03-数据与中间件/中间件/可观测性/Jaeger.md)；SkyWalking 路线见 [接入Skywalking.md](接入Skywalking.md)。

---

## 一、前提认知：Java 侧有两条路，都走 OTLP ⭐

**本节要点**：和 Go 一样，**`jaeger-client-java` 已归档**，新项目不要再用 `io.jaegertracing:jaeger-client`。现在只有两条路，差别是「无侵入 agent」还是「框架内手动埋点」。

| | 路线 A：OTel Java Agent | 路线 B：Micrometer Tracing |
| --- | --- | --- |
| 形态 | `-javaagent` 挂字节码增强 agent | Spring Boot 3 起步器 + 依赖 |
| 改码量 | **零** | 自动埋点也基本零改码，手动埋点需注入 `Tracer` |
| 覆盖范围 | 最广（Spring MVC / JDBC / Redis / Kafka / HTTP Client / 线程池…） | Spring 生态内最贴合，非 Spring 部分要自己写 |
| 前提 | 无（任何 JVM 应用） | Spring Boot **3.x**（2.x 的 Sleuth 已停更） |
| 适合 | 存量系统、快速接入、多框架混用 | Spring Boot 3 新项目、要与 Micrometer 指标统一 |

两条路最终都是 **OTel SDK → OTLP**，所以 Jaeger 后端与部署方式完全一致（见 [Jaeger.md](../../03-数据与中间件/中间件/可观测性/Jaeger.md) 第四节）。

⚠️ **两个 agent 不能同时挂**：OTel Java Agent 与 SkyWalking agent 同时 `-javaagent` 会在同一个入口生成两份 span，Jaeger/SkyWalking 两侧各自收到一条链路，指标翻倍、`trace_id` 还不一致。选一条主线（分工见 [可观测性选型对比.md](../../03-数据与中间件/中间件/可观测性/可观测性选型对比.md)）。

---

## 二、路线 A：OTel Java Agent（无侵入自动埋点）

**本节要点**：零改码，字节码增强自动为 Spring MVC / JDBC / Redis / Kafka / HTTP Client 生成 span，并自动注入/提取 W3C `traceparent`。

### 2.1 启动参数

```bash
java -javaagent:/opt/otel/opentelemetry-javaagent.jar \
     -Dotel.service.name=order-service \
     -Dotel.exporter.otlp.endpoint=http://jaeger:4317 \
     -Dotel.exporter.otlp.protocol=grpc \
     -Dotel.traces.sampler=parentbased_traceidratio \
     -Dotel.traces.sampler.arg=0.1 \
     -jar app.jar
```

### 2.2 环境变量等价写法（容器里更常用）

```bash
OTEL_SERVICE_NAME=order-service
OTEL_EXPORTER_OTLP_ENDPOINT=http://jaeger:4317
OTEL_EXPORTER_OTLP_PROTOCOL=grpc
OTEL_TRACES_SAMPLER=parentbased_traceidratio
OTEL_TRACES_SAMPLER_ARG=0.1
JAVA_TOOL_OPTIONS=-javaagent:/opt/otel/opentelemetry-javaagent.jar
```

`JAVA_TOOL_OPTIONS` 的好处是**不用改启动脚本**，JVM 自己会读；代价是它对所有 JVM 进程生效（同容器里的 `jcmd`、`jmap` 也会挂上 agent），生产建议还是显式写在启动命令里。

⚠️ 端口别填错：`4317` 是 OTLP gRPC、`4318` 是 OTLP HTTP、`16686` 是 **Jaeger UI**。把 endpoint 填成 16686 是最常见的接入失败原因，且失败是静默的。

### 2.3 Dockerfile

```dockerfile
FROM eclipse-temurin:17-jre

# 从官方 agent 镜像拷出 agent，避免把 jar 塞进代码仓库
COPY --from=ghcr.io/open-telemetry/opentelemetry-java-instrumentation/javaagent:2.11.0 \
     /javaagent.jar /opt/otel/opentelemetry-javaagent.jar

ENV OTEL_SERVICE_NAME=order-service \
    OTEL_EXPORTER_OTLP_ENDPOINT=http://jaeger:4317 \
    OTEL_EXPORTER_OTLP_PROTOCOL=grpc \
    OTEL_TRACES_SAMPLER=parentbased_traceidratio \
    OTEL_TRACES_SAMPLER_ARG=0.1

WORKDIR /app
COPY target/app.jar /app/app.jar

ENTRYPOINT ["sh", "-c", "exec java -javaagent:/opt/otel/opentelemetry-javaagent.jar -jar /app/app.jar"]
```

> ⚠️ `exec java ...` 里的 `exec` 不是可有可无：`ENTRYPOINT` 走 `sh -c` 时，PID 1 是 shell，K8s 的 SIGTERM 只打到 shell 上、**不会转发给 JVM**，于是 OTel 的批量导出队列来不及 flush，每次发布都丢掉最后几秒的 span（同一问题的详细推导见 [接入Skywalking.md](../php/接入Skywalking.md) 第五节）。

### 2.4 K8s 注入方式

| 方式 | 做法 | 适用 |
| --- | --- | --- |
| 镜像内置 | 如上，`COPY --from` 把 agent 打进镜像 | 镜像可控、版本要钉死 |
| initContainer + emptyDir | init 容器把 agent 拷到共享卷，主容器挂 `-javaagent` | ⭐ **业务镜像不动**，agent 版本由平台统一升级 |
| OTel Operator 自动注入 | 给 Pod 打 `instrumentation.opentelemetry.io/inject-java: "true"` 注解 | 有 Operator、要全集群统一治理 |

配置一律走 Deployment 的 `env`（`OTEL_*`），同一个镜像跨环境复用；并给 agent 预留额外内存（`resources.limits.memory` 多留 200~500MB）。

### 2.5 覆盖范围与排查

自动埋点覆盖 Spring MVC / WebFlux、JDBC / HikariCP、Redis（Lettuce/Jedis）、Kafka / RabbitMQ、`HttpClient` / OkHttp、gRPC、`ExecutorService` 等。**版本匹配是关键**：agent 版本与框架版本不兼容时，表现为「进程正常、无报错、但那个库的 span 全没有」，排障要看 agent 自己的日志：

```bash
-Dotel.javaagent.debug=true     # 打开 agent debug 日志，会打印每个 instrumentation 是否 apply
```

---

## 三、路线 B：Spring Boot 3 + Micrometer Tracing

**本节要点**：Spring Boot 3 起用 **Micrometer Tracing**（底层桥接 OTel SDK）替代已停更的 Sleuth。自动埋点覆盖 Spring 生态，手动埋点注入 `Tracer` 即可。

### 3.1 依赖

```xml
<dependency>
  <groupId>org.springframework.boot</groupId>
  <artifactId>spring-boot-starter-actuator</artifactId>
</dependency>
<dependency>
  <groupId>io.micrometer</groupId>
  <artifactId>micrometer-tracing-bridge-otel</artifactId>
</dependency>
<dependency>
  <groupId>io.opentelemetry</groupId>
  <artifactId>opentelemetry-exporter-otlp</artifactId>
</dependency>
```

⚠️ `micrometer-tracing-bridge-otel` 是**桥接到 OTel**；另有 `micrometer-tracing-bridge-brave`（桥接 Zipkin Brave）。两者 API 相同但**不能同时引**，选 OTel 桥才能发到 Jaeger 并保持与 W3C `traceparent` 一致。

### 3.2 配置

```yaml
management:
  tracing:
    sampling:
      probability: 0.1                        # 采样率；1.0 全采，仅开发用
  otlp:
    tracing:
      endpoint: http://jaeger:4318/v1/traces  # ⚠️ OTLP HTTP，注意 /v1/traces 路径
spring:
  application:
    name: order-service                       # 即 service.name，UI 上显示的名字
```

⚠️ 两个易错点：① 走 HTTP 时 endpoint 必须带 `/v1/traces` 路径，端口是 **4318**；走 gRPC 则用 `4317` 且不带路径。② `probability` 是**头部采样**，父子决策由 Spring 保证一致，但它无法「只保留错误请求」——要那个能力得在 Collector 侧做尾部采样（见 [Jaeger.md](../../03-数据与中间件/中间件/可观测性/Jaeger.md) 4.3）。

### 3.3 自动埋点

引入依赖后，Spring MVC 的请求 span、`RestClient` / `RestTemplate` / `WebClient` 的出站 span、`@Scheduled` 任务 span、以及 `spring-boot-starter-data-jpa` 的 SQL span 都会自动生成，**无需写代码**。

### 3.4 手动埋点

```java
package com.example.order;

import io.micrometer.tracing.Span;
import io.micrometer.tracing.Tracer;
import org.springframework.stereotype.Service;

@Service
public class OrderService {

    private final Tracer tracer;
    private final OrderRepository repo;

    // Tracer 由 Spring 单例注入：不要在方法里 new，也不要每次请求重建
    public OrderService(Tracer tracer, OrderRepository repo) {
        this.tracer = tracer;
        this.repo = repo;
    }

    public Order create(OrderReq req) {
        Span span = tracer.nextSpan().name("service.createOrder").start();
        // ⭐ withSpan 把 span 设为当前上下文，块内创建的子 span 才会挂在它下面
        try (Tracer.SpanInScope ws = tracer.withSpan(span)) {
            span.tag("order.store_id", req.storeId());
            return repo.save(req);
        } catch (RuntimeException e) {
            span.error(e);            // 记录异常，UI 上该 span 标红
            throw e;
        } finally {
            span.end();               // 必须结束，否则 span 不上报
        }
    }
}
```

三条约束：

1. **`Tracer` 必须是注入的单例 Bean**。它是进程级资源（背后是 OTel 的 TracerProvider），在请求路径里 `new` 或每次重建会导致导出器与批处理线程泄漏。
2. **`try (SpanInScope ws = ...)` 不能省**。`tracer.nextSpan()` 只是创建 span，`withSpan` 才把它放进当前线程上下文；漏了这一步，块内的 SQL span 会挂到**上一层**的 span 上，树形结构错位。
3. **`span.end()` 放在 `finally`**。忘记 end 的 span 永远不会上报，且不报错。

取当前 traceId（写日志、回响应头都用它）：

```java
Tracer tracer; // 注入

String traceId = tracer.currentSpan() != null ? tracer.currentSpan().context().traceId() : "";
```

---

## 四、把 trace_id 关联到日志与响应

**本节要点**：链路数据只有能「从日志/工单一键跳回 UI」才有排障价值。Spring Boot 3 会自动把 `traceId` / `spanId` 放进 MDC，日志 pattern 里引用即可。

### 4.1 日志（Logback）

```xml
<appender name="STDOUT" class="ch.qos.logback.core.ConsoleAppender">
  <encoder>
    <!-- Spring Boot 3 自动注入 traceId / spanId 到 MDC -->
    <pattern>%d{yyyy-MM-dd HH:mm:ss.SSS} [%thread] [traceId=%X{traceId},spanId=%X{spanId}] %-5level %logger{36} - %msg%n</pattern>
  </encoder>
</appender>
```

`logging.pattern.level` 也可以直接在 `application.yml` 里改，把 traceId 塞进 level 字段位置：

```yaml
logging:
  pattern:
    level: "%5p [traceId=%X{traceId},spanId=%X{spanId}]"
```

### 4.2 响应头

```java
package com.example.order;

import io.micrometer.tracing.Tracer;
import jakarta.servlet.FilterChain;
import jakarta.servlet.ServletException;
import jakarta.servlet.http.HttpServletRequest;
import jakarta.servlet.http.HttpServletResponse;
import org.springframework.stereotype.Component;
import org.springframework.web.filter.OncePerRequestFilter;

import java.io.IOException;

@Component
public class TraceIdFilter extends OncePerRequestFilter {

    private final Tracer tracer;

    public TraceIdFilter(Tracer tracer) {
        this.tracer = tracer;
    }

    @Override
    protected void doFilterInternal(HttpServletRequest request, HttpServletResponse response,
                                    FilterChain chain) throws ServletException, IOException {
        var span = tracer.currentSpan();
        if (span != null) {
            response.setHeader("X-Trace-Id", span.context().traceId());
        }
        chain.doFilter(request, response);
    }
}
```

⚠️ **异常堆栈要写进 span**：`span.error(e)`（Micrometer）或 `span.recordException(e)` + `setStatus(ERROR, ...)`（OTel API）。否则 Jaeger UI 上只能看到「这个 span 红了」，看不到为什么红。

⚠️ **别把请求体整体塞进 attribute**：会让 span 体积暴涨（ClickHouse 存储成本与查询延迟都涨），还可能带出敏感字段。要记就截断 + 脱敏。

---

## 五、跨线程与异步

| 场景 | 路线 A（OTel Agent） | 路线 B（Micrometer） |
| --- | --- | --- |
| `ExecutorService` / 线程池 | agent 自动增强，**无需处理** | 用 `TaskDecorator` 或 Micrometer 的 `ContextPropagatingTaskDecorator` 包装 |
| Spring `@Async` | 自动 | 自动（Spring Boot 3 已内置传播） |
| `CompletableFuture` | 自动 | 需要显式传 `Context`，或用 Spring 的 `@Async` 替代 |
| Reactor / WebFlux | 自动（需 `io.micrometer:context-propagation`） | 加 `context-propagation` 依赖并开 `Hooks.enableAutomaticContextPropagation()` |

```java
// Spring 线程池配置：让提交的任务带上当前链路上下文
@Bean
public ThreadPoolTaskExecutor traceExecutor(ContextPropagatingTaskDecorator decorator) {
    ThreadPoolTaskExecutor executor = new ThreadPoolTaskExecutor();
    executor.setCorePoolSize(8);
    executor.setTaskDecorator(decorator);
    executor.initialize();
    return executor;
}
```

⚠️ **症状与判据**：接入后「HTTP 请求有 trace，但异步那部分查不到」，或日志里 `%X{traceId}` 是空——十有八九是线程池没做上下文传播。这与 SkyWalking 侧的 `@TraceCrossThread` 是同一类问题（见 [接入Skywalking.md](接入Skywalking.md) 第九节）。

---

## 六、优雅停机：别丢最后一批 span

OTel 的导出是**异步批量**的（`BatchSpanProcessor`），span 先进内存队列、攒够一批或到超时才发出去。所以进程被强杀时，队列里那一批就没了。

OTel Java Agent 与 SDK 都注册了 **ShutdownHook**，JVM 正常退出时 flush 队列。

| 退出方式 | 是否触发 ShutdownHook | 结果 |
| --- | --- | --- |
| `SIGTERM`（K8s 删 Pod、`docker stop`） | ✅ 触发 | 队列 flush，数据完整 |
| `kill -9` / `SIGKILL` | ❌ 不触发 | **最后一批 span 丢失** |
| OOMKilled | ❌ 不触发 | 同上，且丢掉故障时刻最关键的数据 |

两个必要条件：

1. **PID 1 要能收到 SIGTERM**：`ENTRYPOINT` 走 `sh -c` 时必须 `exec java ...`，否则信号只打到 shell（见 2.3）。
2. **宽限期要够**：`terminationGracePeriodSeconds` 必须大于「HTTP 请求排空 + span flush」的总时间。

路线 B 若自己持有 `SdkTracerProvider`，退出前要显式关闭：

```java
@PreDestroy
void shutdownTracing() {
    // Spring 容器关闭时 flush 导出队列
    tracerProvider.close();
}
```

---

## 七、从 `jaeger-client` 迁移

⚠️ `io.jaegertracing:jaeger-client` 与 `jaeger-client-java` **已归档**，不要再用于新项目。

| 旧写法 | 新写法 |
| --- | --- |
| `io.jaegertracing.Configuration` 构建 Tracer | OTel Java Agent（零改码）或 Micrometer `Tracer` 注入 |
| `tracer.buildSpan("x").start()` | `tracer.nextSpan().name("x").start()`（Micrometer）/ `tracer.spanBuilder("x").startSpan()`（OTel API） |
| `span.setTag(k, v)` | `span.tag(k, v)`（Micrometer）/ `span.setAttribute(k, v)`（OTel） |
| `span.log(event)` | `span.event(name)`（Micrometer）/ `span.addEvent(name)`（OTel） |
| 私有 `uber-trace-id` 头 | W3C `traceparent`（OTel 默认，无需配置） |
| Jaeger Thrift / UDP agent | **OTLP**（gRPC 4317 / HTTP 4318） |

迁移时最容易漏的是**传播头**：存量服务发的是 `uber-trace-id`，新服务发的是 `traceparent`，两边混跑会让链路断成两段。要么全量迁移，要么在过渡期让 Jaeger 同时开 `jaeger` receiver 兼容旧协议（部署见 [Jaeger.md](../../03-数据与中间件/中间件/可观测性/Jaeger.md) 第四节）。

---

## 八、怎么逐层确认接入生效？

```bash
# 1. agent 是否真的挂上了（路线 A）
ps -ef | grep java | grep -- -javaagent

# 2. 后端可达：Jaeger 的 OTLP gRPC 端口
nc -vz jaeger 4317

# 3. 发一个请求，看响应头里有没有 trace_id
curl -sI http://localhost:8080/api/health | grep -i x-trace-id

# 4. 看有哪些 service 上报成功（不用打开 UI）
curl -s "http://localhost:16686/api/services"

# 5. 用 trace_id 直查
curl -s "http://localhost:16686/api/traces/<trace_id>" | head -c 500
```

6. 路线 A 若 `api/services` 返回空，加 `-Dotel.javaagent.debug=true` 看 agent 日志里 exporter 是否连上、instrumentation 是否 apply。
7. 路线 B 打开 `management.endpoints.web.exposure.include: health,metrics` 后访问 `/actuator/metrics`，能看到 `http.server.requests` 说明 Micrometer 在工作；再确认 `spring.application.name` 与 UI 上的服务名一致。

---

## 九、常见故障速查

| 症状 | 大概率原因 | 处理 |
| --- | --- | --- |
| Jaeger UI 里一个 service 都没有 | endpoint 填成 16686（UI 端口）而非 4317/4318 | 核对端口（2.1、3.2） |
| 路线 B 报 404 / 上报失败 | OTLP HTTP endpoint 漏了 `/v1/traces` 路径 | 补全路径（3.2） |
| 进程正常但完全没有 span | 路线 A：agent 版本与框架版本不兼容 | `-Dotel.javaagent.debug=true` 查 instrumentation（2.5） |
| HTTP span 有、SQL span 全没有 | 路线 B 没引 JPA/JDBC 的观测起步器；或数据源被自定义包装绕过了代理 | 引 `spring-boot-starter-data-jpa`；确认 `DataSource` 是 Spring 托管的 Bean |
| 异步任务里的调用查不到、日志 traceId 为空 | 线程池未做上下文传播 | 按第五节配 `TaskDecorator` |
| 一条 trace 只有半截 | 采样是头部采样且父子不一致，或链路中间有服务没接入 | 确认全链路都接入；要「只保留错误」得上尾部采样 |
| 指标翻倍、两条 trace_id 对不上 | OTel Agent 与 SkyWalking agent 同时挂着 | **只保留一个 `-javaagent`**（第一节） |
| 每次发布最后几秒的 span 缺失 | `sh -c` 未 `exec`，SIGTERM 没到 JVM；或宽限期太短 | 加 `exec`、调大 `terminationGracePeriodSeconds`（2.3、第六节） |
| 加了 agent 就 OOMKilled | 容器内存 limit 没给 agent 留余量 | `limits.memory` 多留 200~500MB（2.4） |
| 从旧项目迁来后链路断裂 | 存量服务发 `uber-trace-id`，新服务发 `traceparent` | 全量迁移，或过渡期开 Jaeger receiver 兼容（第七节） |

---

## 延伸追问

- **为什么不用 `jaeger-client-java`？** → 已归档。它绑定私有的 `uber-trace-id` 头与 Thrift 协议，与 OTel 标准冲突。统一 OTel SDK + OTLP 后，**后端可换而埋点不动**——把 endpoint 从 Jaeger 换成 SkyWalking OAP 或 Tempo，业务代码一行不改。
- **OTel Java Agent 和 SkyWalking agent 能同时挂吗？** → ⚠️ 不能。两者都会在同一个入口/出口创建 span，结果是双份上报、CPM/Apdex 等指标翻倍、告警阈值失真，且两套 `trace_id` 互不相干，排障反而割裂。选一条主线。
- **OTel Java Agent 与 Micrometer Tracing 怎么选？** → 存量系统、多框架混用、要零改码 → Agent；Spring Boot 3 新项目、要与 Micrometer 指标体系统一 → Micrometer。两者底层都是 OTel SDK，**不要同时启用**。
- **`try (SpanInScope ws = tracer.withSpan(span))` 为什么不能省？** → `nextSpan()` 只创建 span，`withSpan` 才把它放进当前线程上下文。漏了它，块内新建的子 span（包括自动埋点的 SQL span）会挂到**上一层** span 上，瀑布图的树形结构错位。
- **`span.end()` 忘了会怎样？** → span 永远不上报，且**不报错**——UI 里就是「这一段莫名其妙消失了」。所以必须放 `finally`。
- **跨线程怎么传？** → 路线 A 由 agent 自动增强线程池；路线 B 用 `ContextPropagatingTaskDecorator` 包装 `ThreadPoolTaskExecutor`，Reactor 则加 `context-propagation` 并开自动传播。判据是异步部分的日志里 `%X{traceId}` 是否为空。
- **采样率怎么定？** → 开发 `1.0` 全采；生产 `0.1` 甚至更低，且必须是 `parentbased_*` 保证父子决策一致。最优是在 Collector 侧做**尾部采样**——错误与慢请求 100% 保留、正常请求抽样。
- **怎么保证停机不丢数据？** → OTel 靠 ShutdownHook flush 批量队列。两个必要条件：PID 1 能收到 SIGTERM（`sh -c` 要 `exec java`）、`terminationGracePeriodSeconds` 大于排空 + flush 总时间。`kill -9` 与 OOMKilled 都不触发 hook。
- **trace_id 怎么和日志关联？** → Spring Boot 3 自动把 `traceId`/`spanId` 放进 MDC，日志 pattern 里 `%X{traceId}` 引用；同时回写 `X-Trace-Id` 响应头，让前端报错与客服工单能直接报出 ID。

---

## 十、校验口径

> ✅ 本篇校验方式与范围（本机 Maven 3.8.1 + JBR 17，网络可达 repo1.maven.org）：
>
> - **第三节 `OrderService`、第四节 `TraceIdFilter`**：逐字抽成独立 Maven 工程 `mvn -B clean compile` **真编译通过**（`Order` / `OrderReq` / `OrderRepository` 为项目内类型，用最小桩替代）。实际解析版本见下表。⚠️ **只编译、未运行**——本机没有 Jaeger 实例，也不启动完整 Spring 上下文。
> - **第五~七节**：`ContextPropagatingTaskDecorator`、`tracerProvider.close()` 等为配置/生命周期片段，核对 API 签名与官方文档写法，未独立编译。
> - **bash / Dockerfile / K8s YAML / pom 片段**：`bash -n` 语法校验 + 结构与坐标核对。
> - 文中不含伪造的启动日志与 UI 输出。

| 依赖 | 实际解析版本 | 说明 |
| --- | --- | --- |
| `org.springframework.boot:spring-boot-dependencies`（BOM） | 3.2.5 | 统一管理下面各项版本 |
| `org.springframework.boot:spring-boot-starter-web` | 3.2.5 | 提供 Spring MVC 与 `OncePerRequestFilter` |
| `org.springframework.boot:spring-boot-starter-actuator` | 3.2.5 | Micrometer 观测入口 |
| `io.micrometer:micrometer-tracing-bridge-otel` | 1.2.5 | `Tracer` / `Span` API 与 OTel 桥接 |
| `io.opentelemetry:opentelemetry-exporter-otlp` | 1.31.0 | OTLP 导出器 |
| `io.opentelemetry:opentelemetry-api` | 1.31.0 | 传递依赖，OTel 核心 API |

---

## 关联

- [Jaeger.md](../../03-数据与中间件/中间件/可观测性/Jaeger.md) — Trace/Span 概念、与 OTel 的关系、v2 + ClickHouse 部署、采样策略、与 SkyWalking 的分工
- [接入Skywalking.md](接入Skywalking.md) — javaagent 路线的对照（同为字节码增强，但协议是 `sw8`）
- [接入Jaeger.md](../go/可观测性/接入Jaeger.md) — 同一套 OTel 埋点在 Go 侧的写法
- [可观测性选型对比.md](../../03-数据与中间件/中间件/可观测性/可观测性选型对比.md) — 链路后端与「契合语言」维度的横向对比
- [K8s部署与生命周期面试题.md](../../06-工程实践/部署/k8s/K8s部署与生命周期面试题.md) — 优雅停机、SIGTERM 与宽限期
- [镜像构建与缓存.md](../../06-工程实践/部署/docker/镜像构建与缓存.md) — `COPY --from` 与 initContainer 两种 agent 分发方式
- [JVM与垃圾回收.md](运行时/JVM与垃圾回收.md) — agent 带来的额外内存开销与 GC 影响
> 反向引用（本篇被下列文档引到）：[接入gRPC.md](接入gRPC.md)
