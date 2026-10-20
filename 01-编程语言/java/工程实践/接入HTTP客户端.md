# 接入HTTP客户端

> Java 侧的 HTTP 客户端接入：JDK 自带 `java.net.http.HttpClient` 与 OkHttp 两套怎么选、为什么都必须是单例、池参数与超时分档怎么写，以及与 Go 侧 `http.Transport` 的逐项对照。
>
> 不覆盖：HTTP/1.1 与 HTTP/2 的机制差异见 [HTTP1.1与HTTP2的区别.md](../../../02-计算机基础/网络/应用层/HTTP1.1与HTTP2的区别.md)；HTTP 与 gRPC 的分工见 [HTTP与gRPC.md](../../../02-计算机基础/网络/应用层/HTTP与gRPC.md)；Go 侧的同题见 [HTTP客户端与连接池.md](../../go/网络编程/HTTP客户端与连接池.md)。
>
> 内容整理自个人学习笔记，素材见 [素材清单](../../../素材清单.md)。
>
> ⚠️ **实测口径**：本篇 Java 代码按 JDK 17 + OkHttp 4.12 书写，**未做编译与运行校验**——本机 `java` 不在 PATH 中、也没有 `mvn`，未搭 Java 编译环境。文中参数取值与结论来自官方文档与源码语义，**不给任何耗时或吞吐读数**；对照表里 Go 侧的读数来自 [HTTP客户端与连接池.md](../../go/网络编程/HTTP客户端与连接池.md) 的容器实测，不是本篇跑的。

---

## 一、JDK 自带的 `HttpClient` 和 OkHttp 该怎么选？

**本节要点**：JDK 的够用但**不可调**，OkHttp 可调且有观测钩子——两者的选择标准不是"哪个快"，而是"你要不要控池"。

| 维度 | JDK `java.net.http.HttpClient` | OkHttp 4.x |
|---|---|---|
| 引入成本 | **零依赖**（JDK 11+） | 一个第三方依赖 |
| 连接池参数 | ❌ **不暴露**，只能靠系统属性 | ✅ `ConnectionPool(maxIdle, keepAlive, unit)` |
| 逐阶段观测 | ❌ 无钩子 | ✅ `EventListener`（细到 DNS / 建连 / TLS / 首字节） |
| 拦截器 | ❌ 无 | ✅ `Interceptor`（请求/响应层） |
| HTTP/2 | ✅ 优先协商 | ✅ `protocols()` 指定 ALPN 偏好 |
| 异步 | `CompletableFuture` | `enqueue(Callback)` |

一句话判据：**只调几个接口、不关心池 → JDK 自带；要控池、要观测、要统一拦截 → OkHttp。**

## 二、JDK `HttpClient`：单例 + HTTP/2 优先

**本节要点**：`HttpClient` 内部**同时持有连接池和线程池**，每请求 new 一个是双重泄漏。

```java
package notes.appproto;

import java.net.URI;
import java.net.http.HttpClient;
import java.net.http.HttpClient.Version;
import java.net.http.HttpRequest;
import java.net.http.HttpResponse;
import java.time.Duration;

public class JdkHttp {

    // ⚠️ HttpClient 内部持有连接池与线程池：**必须是单例**。
    // 每次请求 new 一个 client，除了重新握手，还会泄漏它的 executor（JDK 的已知痛点）。
    private static final HttpClient CLIENT = HttpClient.newBuilder()
            .version(Version.HTTP_2)          // JDK 11+ 才支持 h2；这是"优先"，不是"强制"
            .connectTimeout(Duration.ofSeconds(3))
            .followRedirects(HttpClient.Redirect.NORMAL)
            .build();

    public static String get(String url) throws Exception {
        HttpRequest req = HttpRequest.newBuilder(URI.create(url))
                .timeout(Duration.ofSeconds(10)) // 请求级超时：对应 Go 的 ctx timeout
                .GET()
                .build();
        // ofString 会读完 body；要流式处理用 ofInputStream + 自己保证关闭
        HttpResponse<String> resp = CLIENT.send(req, HttpResponse.BodyHandlers.ofString());
        return resp.body();
    }

    // 异步：对应 Go 侧"每请求一个 goroutine"，JDK 用 CompletableFuture，
    // 好处是天然支持"一条 h2 连接上多流并发"，不需要开 N 个线程
    public static java.util.concurrent.CompletableFuture<String> getAsync(String url) {
        HttpRequest req = HttpRequest.newBuilder(URI.create(url)).timeout(Duration.ofSeconds(10)).GET().build();
        return CLIENT.sendAsync(req, HttpResponse.BodyHandlers.ofString())
                .thenApply(HttpResponse::body);
    }
}
```

两条必须记住的边界：

- `.version(Version.HTTP_2)` 是**偏好**不是强制——对端或中间设备不支持 h2 时会自动退回 1.1，且**不会报错**。要确认实际协商结果，看 `resp.version()`。
- ⚠️ JDK `HttpClient` **没有暴露连接池参数**（`maxIdleConnections` / keep-alive 时长只能靠系统属性 `jdk.httpclient.connectionPoolSize`、`jdk.httpclient.keepalive.timeout`）。需要精确控池时，工程上通常换 OkHttp 或直接上 gRPC / Netty。

## 三、OkHttp：池参数怎么配、和 Go 侧怎么对应

**本节要点**：OkHttp 的 `ConnectionPool` 与 Go 的 `http.Transport` 是**同一个东西的两种拼写**，参数能逐项对上。

```java
package notes.appproto;

import java.time.Duration;
import okhttp3.ConnectionPool;
import okhttp3.Interceptor;
import okhttp3.OkHttpClient;

public class OkHttpSingleton {

    // 与 Go 的 http.Transport 逐项对应 —— 记住这张表就够了
    private static final ConnectionPool POOL = new ConnectionPool(
            64,                    // maxIdleConnections  ≈ Go 的 MaxIdleConnsPerHost（Go 默认 2，太小）
            5,
            java.util.concurrent.TimeUnit.MINUTES); // keepAliveDuration ≈ IdleConnTimeout

    private static final OkHttpClient CLIENT = new OkHttpClient.Builder()
            .connectionPool(POOL)
            .protocols(java.util.List.of(okhttp3.Protocol.HTTP_2, okhttp3.Protocol.HTTP_1_1)) // ALPN 偏好
            .connectTimeout(Duration.ofSeconds(3))   // 建连
            .readTimeout(Duration.ofSeconds(10))     // 读 body：Go 没有对应项，靠 ctx
            .writeTimeout(Duration.ofSeconds(5))
            .callTimeout(Duration.ofSeconds(15))     // ≈ Go 的 Client.Timeout（整段）
            .retryOnConnectionFailure(true)
            .addInterceptor(traceInterceptor())
            .build();

    public static OkHttpClient client() {
        return CLIENT; // 只暴露这一个实例；newBuilder() 会**共享**同一个池，可以安全派生
    }

    // 对应 Go 的 httptrace：OkHttp 的 EventListener 才是"逐阶段耗时"，
    // Interceptor 只能看到"请求/响应"层面
    private static Interceptor traceInterceptor() {
        return chain -> {
            long t0 = System.nanoTime();
            var resp = chain.proceed(chain.request());
            // 生产上这里要打进 metrics：耗时、是否复用（resp.cacheResponse/protocol）
            System.out.printf("%s %s in %dms, protocol=%s%n",
                    chain.request().method(), chain.request().url(),
                    (System.nanoTime() - t0) / 1_000_000, resp.protocol());
            return resp;
        };
    }
}
```

⚠️ 一个容易误用的地方：`Interceptor` 与 `EventListener` **不是一回事**。`Interceptor` 只能包在「请求发出 → 响应拿到」这一层，**看不到 DNS 解析、TCP 建连、TLS 握手各自的耗时**；要拿到阶段分解必须挂 `EventListener`（对应 Go 的 `httptrace`），这也是上面对照表里「阶段观测」一行 OkHttp 打 ✅、JDK 打 ❌ 的原因。

> `newBuilder()` 派生出的新 client **共享同一个 `ConnectionPool` 与 `Dispatcher`**，所以「给某个调用单独改超时」是安全的；但反过来，想彻底换一套池只能显式传一个新的 `ConnectionPool`。

## 四、Go / Java 三套客户端的逐项对照

![三套 HTTP 客户端的归属与参数对照：业务调用只碰 Client 这个壳，池在 Transport / ConnectionPool 里；单例、空闲连接数、空闲存活、整体超时、只等响应头、阶段观测、强制退回 1.1 七件事逐项对齐，底部给出每次新建 Transport 的三个代价与超时分档](images/Go与Java三套HTTP客户端的连接池对照.svg)

图注：三套 HTTP 客户端的归属与参数对照：业务调用只碰 Client 这个壳，池在 Transport / ConnectionPool 里；单例、空闲连接数、空闲存活、整体超时、只等响应头、阶段观测、强制退回 1.1 七件事逐项对齐。

| 目标 | Go | OkHttp | JDK HttpClient |
|---|---|---|---|
| 单例 | `sync.OnceValue` | `static final` | `static final` |
| 空闲连接数 | `MaxIdleConnsPerHost` | `ConnectionPool(maxIdle..)` | ❌ 只能系统属性 |
| 空闲存活时间 | `IdleConnTimeout` | `keepAliveDuration` | `jdk.httpclient.keepalive.timeout` |
| 整体超时 | `Client.Timeout` | `callTimeout` | `HttpRequest.timeout` |
| 只等响应头 | `ResponseHeaderTimeout` | `readTimeout` | ❌ |
| 阶段观测 | `httptrace` | `EventListener` | ❌ |
| 强制 h1 | ALPN `NextProtos` | `protocols(List.of(HTTP_1_1))` | `.version(HTTP_1_1)` |

表里只写了参数名，具体取值以上面两段代码为准：

- **Go**：`MaxIdleConns=200`（全局空闲上限）、`MaxIdleConnsPerHost=64`（⚠️ 标准库默认只有 2）、`IdleConnTimeout=90 * time.Second`；`DialContext` 里另配 `Timeout=3 * time.Second` 与 `KeepAlive=30 * time.Second`；超时三档 `TLSHandshakeTimeout=5s` / `ResponseHeaderTimeout=10s` / `ExpectContinueTimeout=1s`。
- **OkHttp**：`ConnectionPool(64, 5, TimeUnit.MINUTES)` ＝ 空闲上限 64、空闲存活 **5 分钟**；超时四档 `connectTimeout=3s` / `readTimeout=10s` / `writeTimeout=5s` / `callTimeout=15s`。
- **JDK HttpClient**：空闲存活只能靠系统属性 `jdk.httpclient.keepalive.timeout`（构造器里没有这一档），整体超时走 `HttpRequest.timeout`。

## 五、Java 侧最容易踩的四个坑

**本节要点**：四个坑里三个是「以为是 SDK 帮你做了，其实没有」。

| 坑 | 表现 | 处理 |
|---|---|---|
| **每请求 new 一个 client** | 重新握手 + JDK 的 `executor` 泄漏（线程数一路涨） | `static final` 单例；需改选项用 `newBuilder()` |
| **以为 `version(HTTP_2)` 就一定是 h2** | 对端不支持时**静默**退回 1.1 | 读 `resp.version()` 或看 OkHttp 的 `resp.protocol()` |
| **用 `Interceptor` 当阶段计时** | 只拿到总耗时，DNS / 建连 / TLS 全糊在一起 | 挂 `EventListener`（Go 对应 `httptrace`） |
| **`retryOnConnectionFailure(true)` 当幂等安全网** | 非幂等接口（扣款、下单）可能被重放 | 该开关只管**连接层**失败重试；业务幂等仍需自己的幂等键 |

⚠️ 这四条里只有第一条有 Go 侧的等价物且能被同一把量具量出来（见 [HTTP客户端与连接池.md](../../go/网络编程/HTTP客户端与连接池.md) 第三节的实测）；其余三条属 SDK 行为陈述，**本篇未做实测**。

## 延伸追问

- **OkHttp 的 `readTimeout` 为什么不等于 Go 的 `ResponseHeaderTimeout`？** → `readTimeout` 覆盖的是「两次读之间的最大间隔」，读 body 时同样生效；Go 的 `ResponseHeaderTimeout` 只等到响应头为止，body 归 `ctx` 管。对慢响应的流式接口，两者的截断点完全不同。
- **JDK 为什么不暴露连接池参数？** → 设计上把它当"不可调的基础设施"，只留系统属性作为逃生门；这也意味着**需要精细控池的项目基本不会选它**。
- **`callTimeout` 与 `readTimeout` 同时设，哪个先生效？** → 谁先到算谁：`callTimeout` 管整通调用（含重定向与重试），`readTimeout` 只管单次读；两者不是"取小"关系，而是各自独立的计时器。
- **Java 侧怎么确认「连接到底复用了没」？** → OkHttp 看 `EventListener.connectionAcquired` 是否带 `Connection` 且 `protocol` 未变；JDK 没有钩子，只能从服务端侧的连接数反推。

## 关联

- **上游/总纲**：[HTTP与gRPC.md](../../../02-计算机基础/网络/应用层/HTTP与gRPC.md) — HTTP 与 gRPC 的分工，以及抓包与命令行的观测路径
- **对照/互补**：[HTTP客户端与连接池.md](../../go/网络编程/HTTP客户端与连接池.md) — Go 侧同题：三层结构、池参数默认值、超时分档与容器实测读数
- **对照/互补**：[HTTP1.1与HTTP2的区别.md](../../../02-计算机基础/网络/应用层/HTTP1.1与HTTP2的区别.md) — 本篇调参所依赖的机制：多路复用、消息定界、两级流控
- **对照/互补**：[接入gRPC.md](接入gRPC.md) — 同一语言栈的另一条客户端路径（`grpc-java` 与 HTTP/2 的关系）
- **下游机制**：[HTTP2机制.md](../../../03-数据与中间件/中间件/RPC框架/gRPC/HTTP2机制.md) — 帧头、HPACK、流状态机与两层限额
