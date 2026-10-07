# Java 接入 SkyWalking

> 内容整理自个人学习笔记 —— Java 侧接入 SkyWalking 的完整手册，按**javaagent 为什么能零改码 → 接入清单 → 配置优先级 → 插件目录 → Spring Boot / Dockerfile / K8s → 日志关联 → 跨线程 → 优雅停机 → 验证排查**组织。
>
> SkyWalking 本体原理（javaAgent 机制、ByteBuddy 织入、轻量级队列内核、Dubbo 插件生命周期）、OAP 架构与 UI 六大面板见 [Skywalking.md](../../06-工程实践/可观测性/Skywalking.md)；同命名口径的另两篇是 [接入Skywalking.md](../go/可观测性/接入Skywalking.md) 与 [接入Skywalking.md](../php/接入Skywalking.md)。

---

## 一、为什么 Java 能做到「零改码」？⭐

**本节要点**：所有 Java 侧的行为（装插件只要拷 jar、运行期能一键摘除、`kill -9` 会丢数据）都能从这条原理推出来，不用背。

JVM 从 JDK 1.5 起提供 `java.lang.instrument`，允许外部程序**在类加载时拿到 `Instrumentation` 实例并修改字节码**。启动命令上加 `-javaagent:/path/skywalking-agent.jar`，JVM 会在 `main` 方法执行**之前**调用 agent 的 `premain`。

SkyWalking 的 agent 正是靠它工作的：

```text
JVM 启动 → premain(agentArgs, Instrumentation)
   ↓ 解析 agent.config / 环境变量
   ↓ PluginBootstrap 扫描 plugins/ + bootstrap-plugins/，读各 jar 的 skywalking-plugin.def
   ↓ 构建 PluginFinder（插件定义 + 拦截器 + witness 类）
   ↓ 建立到 OAP 的 gRPC 通道，注册服务与实例
   ↓ Instrumentation.addTransformer(...) 注册字节码转换器
类加载期 → transformer 匹配目标类 → ByteBuddy 在方法前/后/异常分支织入拦截器
运行期   → 拦截器创建 Entry/Exit/Local span → 写入轻量级队列 → 消费线程批量 gRPC 上报 OAP
JVM 退出 → ShutdownHook 触发：先停消费者线程并 flush 队列，再关 gRPC 通道
```

三个直接推论：

1. **装/卸插件不用重新编译业务代码**。插件就是 jar，拷进 `plugins/` 即生效、删掉即失效；也可以用 `plugin.exclude_plugins` 排除。
2. **配置在运行期读**。改环境变量重启即可，不像 Go 那样要重新编译（对照见 [接入Skywalking.md](../go/可观测性/接入Skywalking.md) 第一节）。
3. **`kill -9` 会丢最后一批数据**。flush 依赖 ShutdownHook，而 `SIGKILL` 不触发任何 hook（见第十节）。

⚠️ 代价也要说清：字节码增强带来 CPU/内存开销；agent 与 **JDK 版本、框架版本、其他 agent** 存在兼容风险；探针异常理论上可能影响业务进程。所以生产要**灰度**，并保留「一键摘除」的能力（删 jar / 排除插件 / 摘掉 `-javaagent` 重启）。

---

## 二、接入前检查清单

| 检查项 | 要求 | 不满足的后果 |
| --- | --- | --- |
| agent 包 | 解压后目录含 `skywalking-agent.jar` + `config/` + `plugins/` + `bootstrap-plugins/` | 路径写错时 JVM 直接启动失败（`Error opening zip file or JAR manifest missing`） |
| JDK 版本 | 与 agent 版本匹配（查官方 Support-Version-Matrix） | 高版本 JDK 上可能织入失败，表现为「进程正常但没数据」 |
| 服务名 | `-Dskywalking.agent.service_name` 或 `SW_AGENT_NAME` | UI 上出现一批 `Your_ApplicationName`，多服务混在一起 |
| OAP 地址 | `collector.backend_service` 指向 **gRPC 11800** | 填成 UI 的 8080 会连接失败，agent 日志里刷重连错误 |
| 插件覆盖 | 用到的框架在 `plugins/` 里有对应插件，且版本在支持区间内 | 该框架的 span 静默缺失（HTTP 有、SQL 没有） |
| 内存预留 | 容器 `resources.limits.memory` 比裸跑多留 200~500MB | agent 元空间与队列占用导致 OOMKill |
| 优雅停机 | 用 `SIGTERM`，不用 `kill -9` | 最后一批链路数据丢失 |

---

## 三、最简接入

```bash
# 1. 下载并解压 agent（以 8.x 为例），目录结构：
#    /opt/skywalking-agent/{skywalking-agent.jar,config/,plugins/,optional-plugins/,bootstrap-plugins/}
tar -zxvf apache-skywalking-java-agent-8.x.x.tgz -C /opt/

# 2. 只需在启动命令上加一个 -javaagent，业务代码零改动
java -javaagent:/opt/skywalking-agent/skywalking-agent.jar \
     -Dskywalking.agent.service_name=order-service \
     -Dskywalking.collector.backend_service=oap.example.com:11800 \
     -jar order-service.jar
```

---

## 四、关键配置项与优先级

配置有三种写法，**优先级：启动参数 `-Dxxx` > 环境变量 > `config/agent.config` 默认值**。

| 配置项 | 环境变量 | 说明 |
| --- | --- | --- |
| `agent.service_name` | `SW_AGENT_NAME` | 服务名，UI 上显示的名字，也是拓扑图节点名。默认 `Your_ApplicationName` |
| `collector.backend_service` | `SW_AGENT_COLLECTOR_BACKEND_SERVICES` | OAP 的 gRPC 地址（默认端口 11800），多个用逗号分隔，默认 `127.0.0.1:11800` |
| `agent.sample_n_per_3_secs` | `SW_AGENT_SAMPLE` | 采样率。`-1` 表示全采样（默认）；正数表示「每 3 秒最多采样多少条 trace」 |
| `logging.level` | `SW_LOGGING_LEVEL` | agent 自身日志级别（`DEBUG/INFO/WARN/ERROR`），默认 `INFO`，排查探针问题才开 `DEBUG` |
| `agent.ignore_suffix` | `SW_AGENT_IGNORE_SUFFIX` | 忽略的静态资源后缀，避免 `.js/.css/.png` 请求产生大量无意义链路 |
| `plugin.exclude_plugins` | `SW_AGENT_EXCLUDE_PLUGINS` | 排除指定插件（如 `tomcat-7.x`），用于规避兼容问题 |

推荐做法：**`agent.config` 保持原样不动，全部用环境变量覆盖**，这样同一个 agent 包 / 同一个镜像可在不同环境复用。

⚠️ **采样语义与 Go 不同，别照抄**：Java 是「**每 3 秒最多多少条**」（`-1` 全采），Go 是「**0~1 的浮点概率**」。把 Go 的 `0.1` 填进 Java 会变成「每 3 秒 0 条」，等于全不采——不报错，只是 UI 永远为空。

---

## 五、插件目录机制

| 目录 | 作用 | 是否默认加载 |
| --- | --- | --- |
| `plugins/` | 常规插件（Tomcat、Spring MVC、Dubbo、MySQL、HttpClient 等） | 是，JVM 启动时全部扫描 |
| `optional-plugins/` | 可选项插件（如 `apm-spring-annotation-plugin`、`apm-cloud-gateway`、日志上报插件、`apm-trace-ignore-plugin`） | 否，**需手动拷到 `plugins/`** |
| `bootstrap-plugins/` | 需要增强 JDK/JVM 自身类（线程池、`java.net`、日志框架）的插件，必须在 agent core 之前生效 | 是，先于 `plugins/` 加载 |

使用要点：

- **装插件只需要拷贝 jar，不重新编译业务代码**；想关掉某个插件，直接不放/删掉对应 jar，或用 `plugin.exclude_plugins` 排除。
- 插件支持的框架版本范围在 `skywalking-plugin.def` 里声明，选型时以官方 **Support-Version-Matrix** 为准。
- **witness 类机制**：插件会声明一个「宿主应用必须真的加载了某类」的判据（如 Dubbo 插件要求加载了 `org.apache.dubbo.rpc.Invoker`），只有命中才生效。这避免了增强一个根本没用的框架、平白增加启动开销——也解释了「明明拷了插件 jar 却没生效」：宿主没加载 witness 类。
- 想用**方法级埋点**（给普通业务方法自动生成 span），要从 `optional-plugins/` 拷 `apm-spring-annotation-plugin`，再用 `@Trace` / `@Tag` 注解标注。

---

## 六、Spring Boot 启动参数

```bash
# 方式一：直接启动 jar
java -javaagent:/opt/skywalking-agent/skywalking-agent.jar \
     -Dskywalking.agent.service_name=order-service \
     -Dskywalking.collector.backend_service=oap.observability:11800 \
     -Dskywalking.logging.level=INFO \
     -jar order-service.jar --spring.profiles.active=prod

# 方式二：Maven 本地调试；IDEA 则把同样内容填到运行配置的 VM options
mvn spring-boot:run -Dspring-boot.run.jvmArguments="-javaagent:/opt/skywalking-agent/skywalking-agent.jar -Dskywalking.agent.service_name=order-service"
```

Spring Boot 无需任何额外配置：Tomcat、Spring MVC、RestTemplate/WebClient、MyBatis/JDBC、Redis、Kafka 等都有现成插件。

⚠️ `-javaagent` 必须写在 `-jar` **之前**，且属于 JVM 参数而非应用参数——写到 `--spring.profiles.active` 那一侧完全不生效。

---

## 七、Dockerfile 与 K8s

官方提供了带 agent 的镜像，最省事的方式是直接 `--from` 拷贝，避免把 tgz 塞进镜像仓库：

```dockerfile
FROM eclipse-temurin:17-jre
# 1. 从官方 agent 镜像里拷出 agent（tag 按官方发布选择）
COPY --from=apache/skywalking-java-agent:8.16.0-java17 /skywalking/agent /skywalking/agent
# 2. 用环境变量注入配置，不改 agent.config
ENV SW_AGENT_NAME=order-service \
    SW_AGENT_COLLECTOR_BACKEND_SERVICES=oap.observability:11800 \
    SW_AGENT_SAMPLE=1000 \
    SW_LOGGING_LEVEL=INFO

WORKDIR /app
COPY target/order-service.jar /app/order-service.jar

# 3. 启动时挂载 agent
ENTRYPOINT ["sh", "-c", "java -javaagent:/skywalking/agent/skywalking-agent.jar -jar /app/order-service.jar"]
```

K8s 侧的三个要点：

1. **配置交给 Deployment 的 `env`**（同一个镜像跨环境复用），服务名用 `metadata.name` 注入：

   ```yaml
   env:
     - name: SW_AGENT_NAME
       valueFrom:
         fieldRef: { fieldPath: metadata.labels['app'] }
     - name: SW_AGENT_COLLECTOR_BACKEND_SERVICES
       value: oap.observability:11800
   ```

2. **给 agent 预留额外内存**：`resources.limits.memory` 要比裸跑多留 200~500MB（agent 的元空间 + 队列缓冲）。不预留的典型症状是「加了探针就 OOMKilled」。
3. ⚠️ **优雅停机**：`terminationGracePeriodSeconds` 要大于业务排空时间，且用 SIGTERM 触发 ShutdownHook（第十节）。`ENTRYPOINT` 走 `sh -c` 时，注意 shell 是否会转发信号——必要时用 `exec java ...` 或引入 `tini`（PHP 侧同一问题的详细讨论见 [接入Skywalking.md](../php/接入Skywalking.md)）。

---

## 八、日志怎么关联到同一条 trace？

**本节要点**：日志与链路是两套数据，靠 `traceId` 串起来。两种做法：把 traceId 写进日志字段（自己查），或把日志直接上报到 OAP（UI 里按 Trace ID 反查）。

### 8.1 方式一：日志里带 traceId（最常用，零依赖）

用 toolkit 提供的 `TraceContext.traceId()` 拿当前 traceId，配到日志 pattern 里。

Logback（`logback-spring.xml`）：

```xml
<dependency>
  <!-- pom.xml 里加 toolkit -->
  <!-- <groupId>org.apache.skywalking</groupId> -->
  <!-- <artifactId>apm-toolkit-logback-1.x</artifactId> -->
</dependency>
```

```xml
<appender name="STDOUT" class="ch.qos.logback.core.ConsoleAppender">
  <encoder class="ch.qos.logback.core.encoder.LayoutWrappingEncoder">
    <layout class="org.apache.skywalking.apm.toolkit.log.logback.v1.x.TraceIdPatternLogbackLayout">
      <Pattern>%d{yyyy-MM-dd HH:mm:ss.SSS} [%tid] [%thread] %-5level %logger{36} - %msg%n</Pattern>
    </layout>
  </encoder>
</appender>
```

`%tid` 由 toolkit 的 layout 解析成当前 traceId；**agent 没挂上时会输出 `TID: N/A`**，这本身就是一个很好的「探针是否生效」判据。

代码里也可以直接取：

```java
import org.apache.skywalking.apm.toolkit.trace.TraceContext;

String traceId = TraceContext.traceId();  // 未接入 agent 时返回 ""
```

### 8.2 方式二：日志上报到 OAP，UI 里按 Trace ID 反查

从 `optional-plugins/` 拷日志上报插件到 `plugins/`，再加对应 toolkit 依赖（`apm-toolkit-logback-1.x` 的 `LogbackAppender`）。上报后在 UI 的**日志面板**按 Trace ID 查询，能直接捞出这一次请求的所有日志。

标准排查姿势：**Trace 找慢/失败的 span → 用 traceId 查日志 → 看业务日志里当时在做什么**（日志面板用法见 [Skywalking.md](../../06-工程实践/可观测性/Skywalking.md) 2.5 节）。

⚠️ 日志上报走的是**与链路同一条 gRPC 通道**，所以「链路有、日志没有」通常是日志侧开关或消息体大小超限，而不是网络问题。大对象日志要在打印前裁剪，超限会被静默丢弃。

---

## 九、跨线程与异步：最容易断链的地方 ⭐

**本节要点**：span 上下文默认**跟线程走**。一旦交给线程池 / `@Async` / `CompletableFuture`，子线程拿不到 traceId，链路就断在那里。

| 场景 | 处理方式 |
| --- | --- |
| 自建线程池 | 用 `bootstrap-plugins/` 里的线程池插件（增强 `ThreadPoolExecutor`），或用 `RunnableWrapper` / `CallableWrapper` 包一层 |
| Spring `@Async` | 拷 `apm-spring-async-plugin`（在 `optional-plugins/` 里）到 `plugins/` |
| 手动传递 | `ContextManager.capture()` 拿快照 → 子线程 `ContextManager.continued(snapshot)` |
| 注解式 | `@TraceCrossThread` 标注需要跨线程追踪的类/方法（需 `apm-spring-annotation-plugin`） |

```java
import org.apache.skywalking.apm.toolkit.trace.TraceCrossThread;

@TraceCrossThread
public class ExportTask implements Runnable {
    @Override
    public void run() {
        // 这里的 SQL / HTTP 调用会挂在提交任务那条链路上
    }
}
```

⚠️ **症状与判据**：接入后「HTTP 请求有 trace，但异步导出的那部分查不到」，或日志里 `%tid` 输出 `N/A`——十有八九是跨线程没处理。这是 Java 侧接入后**最常见**的问题。

⚠️ **响应式 / 虚拟线程要额外验证**：Reactor、WebFlux、Loom 虚拟线程的调度模型与平台线程不同，上下文传播依赖对应版本的插件支持，升级前先查 Support-Version-Matrix。

---

## 十、优雅停机：别丢最后一批数据

上报是**异步批量**的：拦截器产生的 span 先进内存队列（无锁环形队列 `DataCarrier`），消费线程按批 gRPC 发给 OAP。所以进程被强杀时，队列里还没发出去的那一批就没了。

agent 启动时注册了 **ShutdownHook**，JVM 正常退出时会：先停消费者线程并 **flush 队列中还未发送的数据**，再关闭 gRPC 通道。

| 退出方式 | 是否触发 ShutdownHook | 结果 |
| --- | --- | --- |
| `SIGTERM`（K8s 删 Pod、`docker stop`） | ✅ 触发 | 队列 flush，数据完整 |
| `kill -9` / `SIGKILL` | ❌ 不触发 | **最后一批链路数据丢失** |
| OOMKilled | ❌ 不触发 | 同上，且会连带丢掉故障时刻最关键的数据 |

K8s 侧要保证 `terminationGracePeriodSeconds` 大于「业务请求排空 + 队列 flush」的总时间。这与 PHP 侧靠 `exec` 转发 SIGTERM、Go 侧靠 `tp.Shutdown(ctx)` 是**同一个道理**（对照见 [接入Skywalking.md](../php/接入Skywalking.md) 第五节、[接入Jaeger.md](../go/可观测性/接入Jaeger.md) 第九节）。

⚠️ 顺带一个设计取向：队列满时 SkyWalking 默认走 `IF_POSSIBLE` 策略——**宁可丢数据，也不阻塞业务线程**。所以高 QPS 下「链路偶发缺失」是设计取舍，不是 bug；要提高完整度得调大队列并评估 OAP 的写入能力。

---

## 十一、怎么逐层确认接入生效？

```bash
# 1. agent 是否真的挂上了（能看到 -javaagent 参数）
ps -ef | grep java | grep -- -javaagent

# 2. agent 自身日志有没有报错（连接 OAP 失败、插件加载异常）
tail -n 100 /opt/skywalking-agent/logs/skywalking-api.log

# 3. 网络层：确认能连到 OAP 的 gRPC 端口
nc -vz oap.observability 11800

# 4. 日志里的 %tid 是否已有真实值（不是 TID: N/A）
grep -m1 'TID' /path/to/app.log
```

5. 打开 SkyWalking UI 的 **Generals / Service** 列表找 `agent.service_name` 对应的服务名：有服务 → 拓扑图有边 → Trace 里能看到 SQL / Redis span → 日志能按 Trace ID 关联，即为接入完成。
6. 拓扑图上**该有的边没出现**，通常意味着上下文没透传（如经过 Nginx/网关时 `sw8` header 被裁剪），而不是探针没生效。

---

## 十二、常见故障速查

| 症状 | 大概率原因 | 处理 |
| --- | --- | --- |
| JVM 启动失败：`Error opening zip file or JAR manifest missing` | `-javaagent` 路径写错，或 jar 不完整 | 核对解压目录结构（第二节） |
| 进程正常，UI 里完全没这个服务 | OAP 地址端口填成 8080（UI 端口）而非 11800 | 改 `collector.backend_service`（第四节） |
| 服务名显示 `Your_ApplicationName` | `SW_AGENT_NAME` 没注入到运行环境 | 检查 Deployment 的 `env`（第七节） |
| HTTP span 有、SQL span 全没有 | 对应插件不在 `plugins/`，或框架版本超出支持区间 | 拷插件 / 核对 Support-Version-Matrix（第五节） |
| 拷了插件仍不生效 | witness 类未命中（宿主没加载那个框架的核心类） | 确认框架真的被用到；或改手动埋点（第五节） |
| 异步任务里的调用查不到、日志 `%tid` 是 `N/A` | 跨线程上下文未传播 | 按第九节四种方式之一处理 |
| 采样率调了没反应，或 UI 直接空了 | 把 Go 的浮点概率填进了 Java 的「每 3 秒 N 条」 | 按第四节的语义表核对 |
| 加了探针就 OOMKilled | 容器内存 limit 没给 agent 留余量 | `limits.memory` 多留 200~500MB（第七节） |
| 指标翻倍、Apdex 失真 | SkyWalking agent 与 OTel Java Agent 同时挂着 | **只保留一个 `-javaagent`**（对照 [接入Jaeger.md](接入Jaeger.md)） |
| 每次发布最后几秒的请求查不到 | `kill -9` / OOMKilled 跳过 ShutdownHook，或宽限期太短 | 用 SIGTERM + 调大 `terminationGracePeriodSeconds`（第十节） |
| 拓扑图缺边 | 网关/Nginx 裁掉了 `sw8` 头 | 放行 `sw8` / `sw8-correlation` 请求头 |

---

## 延伸追问

- **javaagent 为什么能不改一行业务代码就拿到链路？** → JVM 的 `java.lang.instrument` 允许在**类加载时**拿到 `Instrumentation` 改字节码。`-javaagent` 让 JVM 在 `main` 之前调用 `premain`，agent 注册 `ClassFileTransformer`，之后每个匹配的类被加载时由 ByteBuddy 在方法前/后/异常分支织入拦截器，拦截器创建 Entry/Exit/Local span。
- **`plugins/` 与 `bootstrap-plugins/` 有什么区别？** → `bootstrap-plugins/` 增强的是 **JDK/JVM 自身的类**（线程池、`java.net`、日志框架），由 BootstrapClassLoader 加载，必须在 agent core 之前生效；`plugins/` 是常规框架插件。另有 `optional-plugins/` 默认不加载，要手动拷过去。
- **witness 类是干什么的？** → 插件声明「宿主必须真的加载了某个类」作为生效判据（Dubbo 插件的 witness 是 `org.apache.dubbo.rpc.Invoker`）。命中才织入，避免增强根本没用的框架、平白增加启动开销。
- **上报会不会阻塞业务线程？** → 不会。span 进的是**无锁环形队列**（`DataCarrier` = Channel + 多个 Buffer，每个 Buffer 绑一个消费线程），默认 `IF_POSSIBLE` 策略在队列满时**直接丢弃而不阻塞**；消费线程批量 gRPC 上报。代价是高 QPS 下会丢数据。
- **跨线程为什么会断链，怎么修？** → span 上下文存在 ThreadLocal 里，线程池 / `@Async` / `CompletableFuture` 换了线程就拿不到。修法：线程池插件、`apm-spring-async-plugin`、`ContextManager.capture()/continued()` 手动传快照、或 `@TraceCrossThread` 注解。
- **采样率怎么配？** → Java 是 `agent.sample_n_per_3_secs`（每 3 秒最多 N 条，`-1` 全采）；Go 是 0~1 浮点概率，**两者语义不同不能照抄**。生产更优的是在 Collector 侧做尾部采样，错误与慢请求 100% 保留。
- **怎么保证停机不丢数据？** → 靠 ShutdownHook 在 JVM 退出时 flush 队列。`SIGTERM` 触发、`kill -9` 与 OOMKilled 都不触发；`terminationGracePeriodSeconds` 必须大于业务排空 + flush 的总时间。
- **无侵入的代价是什么？** → CPU/内存开销（要给容器多留内存）、与 JDK/框架/其他 agent 的兼容风险、探针异常理论上影响业务进程。所以生产要灰度、要能一键摘除（删 jar / 排除插件 / 摘 `-javaagent`）。
- **和 OTel Java Agent 怎么选？** → 两个 `-javaagent` **不能同时挂**（同一入口双 span，CPM/Apdex 翻倍）。要开箱即用的 APM 大盘、拓扑与告警，且 Java 为主 → SkyWalking；已统一 OTel、多语言、要后端可替换 → OTel Agent（见 [接入Jaeger.md](接入Jaeger.md)）。

---

## 十三、校验口径

> ✅ 本篇校验方式与范围（本机 Maven 3.8.1 + JBR 17，网络可达 repo1.maven.org）：
>
> - **第八、九节 Java 代码块**：`TraceContext.traceId()` 与 `@TraceCrossThread` 逐字抽成独立 Maven 工程，配 `org.apache.skywalking:apm-toolkit-trace:9.3.0` 后 `mvn -B clean compile` **真编译通过**（导入包名为 `org.apache.skywalking.apm.toolkit.trace`，不带 `agent` 段）。⚠️ **只编译、未挂载 agent 运行**，因此不产生真实链路数据，`TraceContext.traceId()` 在未接入时返回空串。
> - **shell / bash 片段**：`bash -n` 语法校验通过。
> - **Dockerfile / K8s YAML / logback XML / pom 片段**：结构与字段名核对（`COPY --from`、`env.valueFrom.fieldRef`、`TraceIdPatternLogbackLayout` 均为官方文档写法），未实际构建镜像与加载 logback。
> - 本机没有 SkyWalking OAP 实例，因此**只校验语法与编译、未实际接入运行**，文中不含伪造的 UI 输出与 agent 日志。

| 依赖 | 实际解析版本 | 说明 |
| --- | --- | --- |
| `org.apache.skywalking:apm-toolkit-trace` | 9.3.0 | `TraceContext` / `@TraceCrossThread` / `@Trace` 等注解与只读 API |
| `org.apache.skywalking:apm-toolkit-logback-1.x` | 未编译（XML 片段） | 提供 `TraceIdPatternLogbackLayout`（`%tid`）与日志上报 appender |

---

## 关联

- [Skywalking.md](../../06-工程实践/可观测性/Skywalking.md) — javaAgent 与 ByteBuddy 织入原理、轻量级队列内核、Dubbo 插件生命周期、OAP 架构、UI 六大面板
- [接入Jaeger.md](接入Jaeger.md) — OTel Java Agent / Micrometer Tracing 两条路线的对照
- [接入Skywalking.md](../go/可观测性/接入Skywalking.md) — 编译期注入路线，对照「运行期字节码增强 vs 编译期 AST 注入」的差异
- [接入Skywalking.md](../php/接入Skywalking.md) — PHP 扩展路线，对照多进程 + 共享内存的上报模型与 `exec` 信号转发
- [可观测性选型.md](../../06-工程实践/可观测性/可观测性选型.md) — 链路追踪五方案横向对比与「契合语言」维度
- [K8s部署与生命周期面试题.md](../../06-工程实践/部署/k8s/K8s部署与生命周期面试题.md) — 优雅停机、SIGTERM 与宽限期
- [镜像构建与缓存.md](../../06-工程实践/部署/docker/镜像构建与缓存.md) — `COPY --from` 多阶段构建与镜像瘦身
