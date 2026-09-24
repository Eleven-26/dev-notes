# HTTP 与 gRPC

> HTTP/2 相对 HTTP/1.1 的五点改进，以及 gRPC 与 HTTP 的本质差别；含连接池、`httptrace` 复用观测与多路复用实测。
>
> 内容整理自大厂 Go 后端面试真题视频，参考资料与原始素材见 [素材清单](../面试/素材清单.md)。

---

## Q1. HTTP 与 gRPC 有什么区别？

**来源**：`p=47` 小鹏 AI Infra 后台开发日常实习面试 · 时长 13分09秒
**考察意图**：**关键思路转换——这个问题等价于「HTTP/1.1 vs HTTP/2」**。
答不出这个转换，就会在"HTTP 和 gRPC 传的东西不一样"上绕圈。

### 一、先做问题转换（一句话点透）

> **gRPC 的底层就是 HTTP/2**。
> 所以"HTTP 和 gRPC 的区别"→ 转成 **"HTTP/2 和 HTTP/1.1 的区别"**
> （我们现在常用的 HTTP 是 1.1）。
>
> 而 **HTTP/1.1 和 HTTP/2 底层都是 TCP**，**底层是一样的**，
> 区别全在**应用层**。

### 二、HTTP/2 相对 HTTP/1.1 的五点改进

#### ① 多路复用 —— 解决「队头阻塞」（最重要）

**HTTP/1.1 的问题**：

- 连接是**持久**的（一个连接可发多个请求），听起来不错；
- 但**只能发顺序请求**——客户端发了请求**必须等到响应回来**，才能发下一个。
- → 一旦第一个请求阻塞（服务端很久不响应），**后续请求全都被卡住**，这就是**队头阻塞**。

原视频的资源加载图很直观：
```text
请求 index.html → 等响应 → 请求样式文件 → 等响应 → 请求脚本 → 等响应
       ↑ 任何一步阻塞，后面全部等待
```

**HTTP/2 的做法 —— 多路复用**：

- 数据被拆成一个个**数据帧**，**不要求同一个请求的数据连续**；
- 收发双方拿到数据后，**根据帧里的「流 ID」重排**：
  把属于同一个流的数据归到一起，并按编号顺序排列；
- 于是**多个流的数据可以交错传输**——
  传的可能是请求一的一部分、请求二的一部分、请求三的响应……
- 因为客户端和服务端都考虑了**并发并行处理**，**不再需要顺序等待，队头阻塞被解决**。

#### ② 数据传输格式：文本 vs 二进制

- HTTP/1.1 用**文本格式**——TCP 层收到数据后要**转成文本再理解**，
  所以它解析的是**字符**；
- HTTP/2 把数据分成**二进制帧**——
  **计算机对二进制的解析有天然优势**，因此**解析效率更高**。
- → 这也是 **gRPC 在数据解析上比 HTTP 更有优势**的原因。

#### ③ 头部压缩（HPACK）

**问题**：每次请求都要带 Header（含 Cookie 等描述性信息），
但**实际可能只用得上一两个字段**，这些描述信息却**不断重复传输，大量浪费带宽**。

**HTTP/2 的做法**：**并不是"不传头部了"**，而是：

> **客户端和服务端各自维护一张索引表**。
> 发送时如果发现这个头部**之前已经发送过**（索引表里已有），
> **就不再重复发送完整内容，只发送索引/新增的这条数据**。
>
> 所以**第一次请求携带的描述信息会多一些，后续请求只需要携带增量信息**。

**效果**：大幅减少网络传输的字节数。

#### ④ 服务端推送

HTTP/2 **支持服务端主动向客户端推送消息**，不再是纯粹的"请求—响应"模式。

#### ⑤ 流的优先级

允许对**每个流/每个请求设置优先级**，保证**重要资源被优先处理**。

#### 附带：TLS

HTTP/1.1 需要**额外配置 SSL/TLS**；HTTP/2 **天然支持 TLS**。

### 三、⚠️ 一个重要澄清（避免答错）

> **HTTP 和 gRPC 的区别，不是"传输内容的格式不同"。**
>
> - gRPC **也可以**用 JSON 传输，**也可以**用 Protobuf 传输；
> - HTTP **同样可以**传 JSON，**也可以**传 Protobuf。
>
> **真正的区别是「机制」**——
> 比如**怎么解决队头阻塞**（多路复用）、**怎么压缩头部**（HPACK）、**能不能服务端推送**。

### 四、面试策略（原视频给的忠告）

> **能讲清一到两个点就能通过。**
> 比较稳妥的选择是：**把"多路复用"讲明白**（含队头阻塞的成因），
> 或者**把"头部压缩"讲清楚**。

---

## 使用一：Go（HTTP 客户端与「租约」这两件事）⭐

> 校验说明：本节 Go 代码只用标准库，`go build` + `go vet` + `gofmt` 通过；第 3 节的实验**在本机真实跑过**（数据见文中表）。
> 前三个块属包 `httpdemo`，第四个块属包 `leasedemo`。

### 1. `http.Client`：池在 `Transport` 里，不在 `Client` 里

正文说 HTTP/1.1 的连接是"持久连接"——那么在 Go 里**谁在维护这些持久连接**？答案是 `http.Transport`。
于是本仓库那条「中间件实例必须单例」在这里的具体形态是：

```go
package httpdemo

import (
	"crypto/tls"
	"net"
	"net/http"
	"sync"
	"time"
)

// Transport 才是连接池本体：它缓存 keep-alive 空闲连接，也是 HTTP/2 复用流的载体。
//
// ⚠️ 每次请求都 new 一个 Transport 的代价（这是 Go 侧最常见的性能事故之一）：
//
//	① 每个请求都要重新 TCP + TLS 握手，正文说的"持久连接"收益全部丢掉；
//	② 旧 Transport 缓存的空闲连接**不会自动关闭**，只会被 GC 掉 → 连接数与 TIME_WAIT 一路涨；
//	③ 想复用 HTTP/2 的单连接多流？每份 Transport 各自一条连接，等于回到 HTTP/1.1。
var Transport = sync.OnceValue(func() *http.Transport {
	return &http.Transport{
		// 一旦自定义 DialContext，net.Dialer 的默认值就不再自动生效，
		// 必须**显式**把 KeepAlive 写回来（否则丢掉默认 15s 的 SO_KEEPALIVE）
		DialContext: (&net.Dialer{
			Timeout:   3 * time.Second, // 只管建连
			KeepAlive: 30 * time.Second,
		}).DialContext,

		ForceAttemptHTTP2: true, // TLS 下靠 ALPN 协商；明文 h2c 标准库不支持

		// ↓ 三个池参数，默认值在生产上几乎都不对
		MaxIdleConns:        200, // 全局空闲连接上限（默认不限）
		MaxIdleConnsPerHost: 64,  // ⚠️ 默认只有 2！超过 2 的空闲连接会被直接关闭 → QPS 一高就疯狂重建连接
		IdleConnTimeout:     90 * time.Second,
		// 想"一条连接扛全部并发"（真·多路复用）就把 MaxConnsPerHost 设成 1，见第 3 节实验

		// 超时要分档：Client.Timeout 覆盖"拨号→读完响应体"整个过程，
		// 只设它会让"下游慢响应"整段吃掉预算，也无法区分"连不上"和"响应慢"
		TLSHandshakeTimeout:   5 * time.Second,
		ResponseHeaderTimeout: 10 * time.Second, // 只等响应头，最常用的一档
		ExpectContinueTimeout: 1 * time.Second,  // 100-continue

		TLSClientConfig: &tls.Config{MinVersion: tls.VersionTLS12},
	}
})

var Client = sync.OnceValue(func() *http.Client {
	return &http.Client{
		Transport: Transport(), // ← 复用；需要临时改选项时用 Transport().Clone()，Clone 仍共享同一个连接池
		Timeout:   15 * time.Second,
		// 不设 CheckRedirect = 默认最多 10 跳；跨域跳转会带上前一个站的 Header，
		// 需要严格隔离时用自定义 CheckRedirect 把敏感头剥掉
	}
})
```

**三个必须记住的对照**：

| 想要 | 做法 | 不要做 |
|---|---|---|
| 单个请求更短的超时 | `ctx, cancel := context.WithTimeout(...)` 传进 `NewRequestWithContext` | 为每个超时 new 一个 Client |
| 只等响应头 3s | `Transport.ResponseHeaderTimeout` 或 Clone 后改 | 只设 `Client.Timeout`（它含读 body，流式响应会被误杀） |
| 用完释放空闲连接 | 进程退出前 `Transport().CloseIdleConnections()` | 以为 `Client` 有 `Close` |

> ⚠️ `Client.Timeout` 包含**读取响应体的时间**：正文「服务端推送」「流式响应」这类长连接场景，
> 用 `Timeout` 会必然超时，只能改用 `ResponseHeaderTimeout` + `ctx` 取消。

### 2. `httptrace`：把「连接是否复用」变成可观测数据

正文「多路复用」和「队头阻塞」是机制层面的说法，落到线上你要能回答：
**这次请求到底有没有新建连接？握手花了多久？首字节多久回来？**

```go
package httpdemo

import (
	"context"
	"net/http"
	"net/http/httptrace"
	"time"
)

// Timing 是一次请求的时间分解。HTTP/2 正常工作时应该看到：
// Dial≈0、Reused=true、FirstByte 接近下游真实耗时。
type Timing struct {
	Dial      time.Duration // 仅 Reused=false 时有效
	FirstByte time.Duration // 从发起请求算起，等价于"服务端处理 + 网络往返"
	Total     time.Duration // 到函数返回（未读完 body）
	Reused    bool
	Remote    string // 服务端地址：多路复用时**所有请求的 Remote 都一样**
}

func GetWithTrace(ctx context.Context, url string) (*http.Response, Timing, error) {
	var (
		t         Timing
		start     = time.Now()
		connStart time.Time
	)

	trace := &httptrace.ClientTrace{
		ConnectStart: func(_, _ string) { connStart = time.Now() },
		ConnectDone: func(_, _ string, err error) {
			if err == nil {
				t.Dial = time.Since(connStart)
			}
		},
		GotConn: func(i httptrace.GotConnInfo) {
			t.Reused = i.Reused
			if i.Conn != nil {
				t.Remote = i.Conn.RemoteAddr().String()
			}
		},
		GotFirstResponseByte: func() { t.FirstByte = time.Since(start) },
	}

	req, err := http.NewRequestWithContext(httptrace.WithClientTrace(ctx, trace), http.MethodGet, url, nil)
	if err != nil {
		return nil, t, err
	}
	resp, err := Client().Do(req)
	if err != nil {
		return nil, t, err
	}
	t.Total = time.Since(start)
	return resp, t, nil
}
```

> `Reused=false` 不一定是坏事（连接池刚建立时本来就要建），
> 但**稳态下仍然持续 `Reused=false`** 就说明池被参数卡住了——第一个要查的就是 `MaxIdleConnsPerHost`。

### 3. 一条连接 vs 多条连接：正文「多路复用」的实测

这段是**在本机（Windows + Go，`httptest` 起 TLS 服务，每个请求固定 sleep 100ms，共 60 个请求）真实跑出来的**：

| 协议 | 最大并发 | `MaxConnsPerHost` | 总耗时 | TCP 拨号次数 |
|---|---|---|---|---|
| HTTP/1.1 | 1 | 1 | 6.05s | 1 |
| HTTP/1.1 | 6 | 1 | **6.04s** | 1 |
| HTTP/1.1 | 60 | 1 | **6.05s** | 1 |
| HTTP/2 | 1 | 1 | 6.05s | 1 |
| HTTP/2 | 6 | 1 | **1.01s** | 1 |
| HTTP/2 | 12 | 1 | **510ms** | 1 |
| HTTP/2 | 30 | 1 | 210ms | 1 |
| HTTP/2 | 60 | 1 | **110ms** | 1 |
| HTTP/1.1 | 60 | 不限（默认） | 170ms | **60** |
| HTTP/2 | 60 | 不限（默认） | 140ms | 60 |

**四行结论**（正好对应正文的四个论断）：

1. **同一条连接上，HTTP/1.1 加并发毫无用处**：并发从 1 提到 60，总耗时死死钉在 6.05s = 60×100ms——
   这就是**队头阻塞**：请求必须等前一个响应回来。
2. **HTTP/2 同一条连接上并发直接生效**：6 并发 → 1.01s，60 并发 → 110ms，接近"并发数"倍的加速，
   这就是**多路复用**（帧交错 + 流 ID 重排）。
3. **`conc=1` 时两者都是 6.05s**：多路复用不是"服务端变快"，**没有并发就没有可复用的流**——
   别把 HTTP/2 当成免费的性能提升。
4. **最后一行才是现实**：不限连接数时，HTTP/1.1 靠**开 60 条 TCP 连接**也能压到 170ms。
   所以 HTTP/2 省的不是"能不能并发"，而是**连接数**：
   60 次 TCP+TLS 握手 vs 1 次。**这就是移动端/弱网/大量小接口场景下 HTTP/2 的真实收益，也是 HPACK 能生效的前提**
   （索引表是按连接维护的，正文第 ③ 点）。

复现程序（单文件，`go mod init x && go run main.go`）：

```go
package main

import (
	"context"
	"crypto/tls"
	"fmt"
	"io"
	"net"
	"net/http"
	"net/http/httptest"
	"sync"
	"sync/atomic"
	"time"
)

// countingDialer 让我们能数出"真的建了几条 TCP 连接"。
type countingDialer struct {
	base  net.Dialer
	dials *int64
}

func (d *countingDialer) DialContext(ctx context.Context, network, addr string) (net.Conn, error) {
	atomic.AddInt64(d.dials, 1)
	return d.base.DialContext(ctx, network, addr)
}

// run 起一个"每个请求固定慢 100ms"的 TLS 服务，
// 然后用 n 个请求 / 指定并发 / 指定协议打它，返回总耗时与拨号次数。
func run(proto string, maxConns, concurrency, n int) (time.Duration, int64) {
	var dials int64

	srv := httptest.NewUnstartedServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		time.Sleep(100 * time.Millisecond) // 模拟下游耗时，让队头阻塞可见
		fmt.Fprint(w, "ok")
	}))
	srv.EnableHTTP2 = true // 打开服务端 h2；客户端用 ALPN 协商
	srv.StartTLS()
	defer srv.Close()

	d := &countingDialer{base: net.Dialer{KeepAlive: 15 * time.Second}, dials: &dials}
	nextProtos := []string{"h2", "http/1.1"}
	if proto == "h1" {
		nextProtos = []string{"http/1.1"} // 通过 ALPN 把协商结果强制退回 1.1
	}

	tr := &http.Transport{
		DialContext:         d.DialContext,
		ForceAttemptHTTP2:   proto == "h2",
		MaxConnsPerHost:     maxConns, // ← 关键变量：1 = 逼它只用一条连接
		MaxIdleConns:        100,
		MaxIdleConnsPerHost: 100,
		IdleConnTimeout:     90 * time.Second,
		TLSClientConfig: &tls.Config{
			InsecureSkipVerify: true, // 只因为 httptest 的证书是自签的
			NextProtos:         nextProtos,
		},
	}
	c := &http.Client{Transport: tr}

	var wg sync.WaitGroup
	sem := make(chan struct{}, concurrency)
	start := time.Now()
	for range n {
		wg.Add(1)
		go func() {
			defer wg.Done()
			sem <- struct{}{}
			defer func() { <-sem }()

			// 每个请求都要把 body 读干并 Close，否则连接不归还池 —— 这本身就是一个"队头阻塞"来源
			resp, err := c.Get(srv.URL)
			if err != nil {
				fmt.Println("ERR", proto, err)
				return
			}
			_, _ = io.Copy(io.Discard, resp.Body)
			_ = resp.Body.Close()
		}()
	}
	wg.Wait()
	return time.Since(start), dials
}

func main() {
	const n = 60
	for _, proto := range []string{"h1", "h2"} {
		// 单连接：把协议差异完全暴露出来
		for _, conc := range []int{1, 6, 12, 30, 60} {
			d, dial := run(proto, 1, conc, n)
			fmt.Printf("%-3s maxConns=1   conc=%-3d total=%-12v dials=%d\n", proto, conc, d.Round(10*time.Millisecond), dial)
		}
		// 不限连接数：这才是 Go 程序的默认现实
		d, dial := run(proto, 0, n, n)
		fmt.Printf("%-3s maxConns=0   conc=%-3d total=%-12v dials=%d\n", proto, n, d.Round(10*time.Millisecond), dial)
	}
}
```

> ⚠️ 这个实验量的是**应用层并发**，没有真网络，所以绝对值不代表线上。
> 想加上网络代价：`srv.Close()` 换成真实远端服务，或在 Linux 上用 `tc netem` 加延迟与丢包
> （见 [TCP滑动窗口.md](tcp/TCP滑动窗口.md) 的「使用三」）——**HTTP/2 在丢包链路上的优势会缩水**，
> 因为它的队头阻塞从"应用层"下移到了"TCP 层"，这正是 HTTP/3/QUIC 要解决的问题。

### 4. gRPC：机制与 HTTP/2 完全一致，代码只多一层生成

正文的澄清是「区别在机制，不在传什么」。所以工程上接入 gRPC 只有两件事：
**① `.proto` 与服务生成（见 [数据序列化.md](数据序列化.md)），② 客户端保持**长连接**（因为它是一条 HTTP/2 连接上跑多流）。

```go
package httpdemo

// 说明性片段：真实的 gRPC 拦截器要 `google.golang.org/grpc` 与生成的 stub，
// 这里只给出**必须存在**的三件事——超时、重试预算、单例连接。
//
//  1. 连接是单例：grpc.ClientConn 内部自带负载均衡与重连，
//     一次调用一个 grpc.Dial 等于每次重新握手，而且会泄漏连接（必须 Close）。
//     conn := grpc.NewClient(target, grpc.WithDefaultServiceConfig(...))  // 全进程共用
//
//  2. 超时由调用方给：ctx, cancel := context.WithTimeout(ctx, 200*time.Millisecond)
//     正文 Q1 说 gRPC 是"二进制帧 + 多流"，但**慢流会占住那条连接的一个流配额**，
//     所以服务端要设 MaxConcurrentStreams，客户端要设每调用的 deadline。
//
//  3. 重试要幂等：grpc 的 retry policy 会在 TRANSIENT_FAILURE 上重放，
//     非幂等接口（扣款）必须关掉自动重试，改由业务侧带幂等键。
```

## 使用二：Java（HttpClient / OkHttp 的连接池）

> ⚠️ 本节 Java 代码按 JDK 17 + OkHttp 4.12 书写，**未编译校验**。三条结论与 Go 侧一一对应。

### 1. JDK `HttpClient`：单例 + HTTP/2 优先

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

> ⚠️ JDK HttpClient 的坑：**没有暴露连接池参数**（`maxIdleConnections` / keep-alive 时长只能靠
> 系统属性 `jdk.httpclient.connectionPoolSize`、`jdk.httpclient.keepalive.timeout`）。
> 需要精确控制池时，工程上通常换 OkHttp 或直接上 gRPC/Netty。

### 2. OkHttp：池参数与 Go 侧的对照表

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

**Go / Java 侧对照**（这一张表就是本节全部内容）：

| 目标 | Go | OkHttp | JDK HttpClient |
|---|---|---|---|
| 单例 | `sync.OnceValue` | `static final` | `static final` |
| 空闲连接数 | `MaxIdleConnsPerHost` | `ConnectionPool(maxIdle..)` | ❌ 只能系统属性 |
| 空闲存活时间 | `IdleConnTimeout` | `keepAliveDuration` | `jdk.httpclient.keepalive.timeout` |
| 整体超时 | `Client.Timeout` | `callTimeout` | `HttpRequest.timeout` |
| 只等响应头 | `ResponseHeaderTimeout` | `readTimeout` | ❌ |
| 阶段观测 | `httptrace` | `EventListener` | ❌ |
| 强制 h1 | ALPN `NextProtos` | `protocols(List.of(HTTP_1_1))` | `.version(HTTP_1_1)` |

## 使用三：抓包与命令实操

### 1. 一眼看清协议版本、ALPN 与帧

```bash
# 关键看三行：Connected to / ALPN 协商结果 / 是否 "Using HTTP2, server supports multiplexing"
curl -v --http2 https://example.com/ -o /dev/null 2>&1 | egrep 'ALPN|SSL connection|HTTP/|< |expire'

# 强制退回 1.1，对比同一个接口的连接数（正文"多路复用省的是连接数"）
curl -v --http1.1 https://example.com/ -o /dev/null

# 跳过 TLS 直连 h2c（需要先验 prior knowledge）
curl -v --http2-prior-knowledge http://127.0.0.1:8080/

# 证书链与到期：正文「头部压缩/多路复用之外，TLS 是 h2 的事实前提」
echo | openssl s_client -connect example.com:443 -alpn h2 2>/dev/null | openssl x509 -noout -subject -issuer -dates -ext subjectAltName
```

### 2. 直接看 HTTP/2 的帧与 HPACK

```bash
# tshark：解开 TLS 才能看帧（用 SSLKEYLOGFILE，方法见 HTTPS与TLS.md）
TSHARK_DEBUGS="Wireshark:DecryptionKeys:/tmp/sslkey.log:TLS" \
  tshark -i lo -f 'tcp port 8443' -Y http2 \
  -T fields -e frame.number -e http2.type -e http2.streamid -e http2.header.name -e http2.header.value

# 只看 HEADERS 帧：能亲眼看到"第二次请求不再重复发 user-agent"（HPACK 增量索引）
tshark -r h2.pcapng -Y 'http2.frame.type==1' -T fields -e http2.streamid -e http2.headers
```

| 想验证正文哪句 | 看什么 |
|---|---|
| 「帧 + 流 ID 重排」 | `http2.streamid` 在同一个 TCP 连接上**交错出现** |
| 「同一请求的数据不要求连续」 | 同一 stream 有多个 `DATA` 帧，中间夹着别的 stream |
| 「各自维护一张索引表」 | 第二个请求的 `HEADERS` 帧长度显著变小 |
| 「服务端推送」 | 出现 `PUSH_PROMISE` 帧（Go 标准库**不支持**推送，要用 h2_bundle 或换语言） |
| 「流的优先级」 | `HEADERS` 帧里的 `PRIORITY` 段；h2 `dependency`/`weight` |

---

---

## 面试官会追问什么

- **HTTP/2 解决了队头阻塞吗？** → 只解决了**应用层**的；TCP 层仍是字节流，一个包丢了整条连接都等——这正是 HTTP/3 上 QUIC 的原因。
- **gRPC 一定比 HTTP+JSON 快吗？** → 主要快在**序列化体积**与多路复用，不是协议本身；载荷小、调用少的场景差异很小，还要付出可读性与网关兼容性的代价。
- **连接池该配多大？** → 看「并发请求数 ÷ 单连接可承载并发」。HTTP/2 一条连接能跑多流，池子该**小**；HTTP/1.1 才需要按并发数放大（见本文「使用一」的实测）。
- **长连接有哪些坑？** → 空闲连接被中间设备静默断开（要 keepalive + 重试）、`MaxConnsPerHost` 配太小导致排队、以及服务端 LB 下连接分布不均。
- **怎么确认连接到底复用了没？** → 用 `httptrace` 的 `GotConn` 看 `Reused` 字段，比在代码里打日志可靠；⚠️ `Transport` 才是池的主人，`Client` 只是壳。
- **代理会破坏 HTTP/2 吗？** → 会：非 h2-aware 的代理要么把连接降级成 1.1、要么逐条转发丢掉多路复用；上线前用 ALPN 协商结果确认。

## 关联

- [DHCP.md](DHCP.md) — T1/T2 的租约形状与续租流程
- [HTTPS与TLS.md](HTTPS与TLS.md) — h2 的事实前提：TLS 与 ALPN
- [数据序列化.md](数据序列化.md) — gRPC 默认的 Protobuf 载荷
- [context.md](../go/工程实践/context.md) — 请求取消与超时在客户端侧的落点
