# 不使用 protoc 的 gRPC

> 内容整理自个人学习笔记，实测基于自研网关项目 [gateway](https://github.com/Eleven-26/gateway)（module `gwlab`，Go 1.26.5）的 `internal/transcode/`（本篇 `text` 块都是本机真实运行结果）。

---

## 一、为什么没有 protoc 也能调 gRPC？

**本节要点**：gRPC 的「方法名」只是一个字符串 `"/包.服务/方法"`；生成的 stub 只是语法糖，本体是 `ClientConn.Invoke`；请求与响应可以是**任意类型**，只要 codec 能编解码。

### 1.1 方法名只是一个字符串

gRPC 跑在 HTTP/2 上，一次调用在协议层就是「带 `:path` 的请求」：服务端按 `:path` 查注册表，命中哪个 `MethodDesc` 就执行哪个 Handler。所以**客户端拼出 `"/order.OrderService/CreateOrder"` 就能调、服务端把这个字符串注册进去就能被调**。

`.proto` 与 `protoc` 在这两个位置**都没有出现**——它们只是帮人**自动生成**这段拼接与解析代码。

### 1.2 stub 是语法糖，`Invoke` 才是本体

生成代码里客户端的每个方法，展开后就是一行 `Invoke`。下面 ① 是生成代码的示意展开，② 是**没有生成代码时的手写等价物**：

```go
// ① 生成代码的等价展开（示意）：方法体剥掉拦截器选项，核心就一行 Invoke
reply := new(CreateOrderReply)
err := c.cc.Invoke(ctx, "/order.OrderService/CreateOrder", in, reply, opts...)

// ② 没有 stub：把方法名字符串和两个任意类型的指针直接交给 Invoke
req := map[string]any{"sku": "A-1", "qty": 2}
out := map[string]any{}
if err := conn.Invoke(ctx, "/order.OrderService/CreateOrder", &req, &out); err != nil {
	return err
}
```

`Invoke` 的签名是 `Invoke(ctx, method string, args, reply any, opts ...CallOption) error`。`args` / `reply` 之所以是 `any`，是因为**序列化这一步被交给了 codec**，`Invoke` 本身不关心它们具体是什么类型。

### 1.3 `req` / `resp` 可以是任意类型

| 路线 | 消息体类型要求 | 谁在约束 |
|---|---|---|
| 默认 protobuf codec | 必须实现 `proto.Message`（生成的结构体） | protobuf codec 内部类型断言 |
| 自定义 JSON codec | 任意类型：`map[string]any`、普通 struct 都行 | `encoding/json` 的能力边界 |
| 自定义其它 codec | codec 的 `Marshal` / `Unmarshal` 说了算 | 你自己 |

这就是网关能在**同一份代码**里同时支持 protobuf 与 JSON 的底层原因：变的只有 codec，`Invoke` 那一行不动。

---

## 二、codec 怎么换？JSON 从哪一步插进去？

**本节要点**：客户端两步——`encoding.RegisterCodec(jsonCodec{})` 注册一个名叫 `json` 的 codec，再用 `grpc.WithDefaultCallOptions(grpc.CallContentSubtype("json"))` 声明本次调用用它；服务端一步——`grpc.ForceServerCodec(...)`。

### 2.1 实现并注册一个 codec

codec 只有两个方法加一个名字，比想象中简单得多：

```go
type jsonCodec struct{}

func (jsonCodec) Marshal(v any) ([]byte, error)      { return json.Marshal(v) }
func (jsonCodec) Unmarshal(data []byte, v any) error { return json.Unmarshal(data, v) }
func (jsonCodec) Name() string                       { return "json" }

func init() { encoding.RegisterCodec(jsonCodec{}) } // 进全局 codec 表，key 就是 "json"
```

### 2.2 客户端：注册之后还要「声明用哪个」

只注册不声明没用：默认仍是 `proto`。`CallContentSubtype("json")` 把本次 RPC 的 `content-type` 拼成 `application/grpc+json`，对端据此去自己的 codec 表里查 `json`。连接期一次性设好即可：

```go
opts := []grpc.DialOption{
	grpc.WithDefaultCallOptions(grpc.CallContentSubtype("json")), // 每次调用都用 json codec
	grpc.WithTransportCredentials(insecure.NewCredentials()),
}
conn, err := grpc.NewClient(addr, opts...)
```

### 2.3 服务端：`ForceServerCodec` 用来兜住「对端声明了本机没有的子类型」

默认行为是**看对端声明的子类型去查表**；对端要是声明了一个本机没注册的名字，这一路 RPC 就会失败。`grpc.ForceServerCodec(jsonCodec{})` 直接覆盖这个选择——不管对端声明什么，服务端一律用指定的 codec 解码，等于把「用哪种编解码」的决定权收回服务端。

### 2.4 实测：JSON codec 端到端

独立实验台（手写 `ServiceDesc` 的服务端 + `conn.Invoke` 的客户端，输出逐行对齐；下面只是其中一次运行的读数，本机复跑只有端口号在变）：

```text
服务端监听 127.0.0.1:49816，ServiceDesc.ServiceName="order.OrderService", MethodName="CreateOrder"
客户端调用的方法名字符串 = "/order.OrderService/CreateOrder"
客户端发出的请求体 = {"qty":2,"sku":"A-1"}
客户端收到的回包   = {"echo":{"qty":2,"sku":"A-1"},"has_lower_user":["u-1001"],"server_md_keys":[":authority=[127.0.0.1:49816]","content-type=[application/grpc+json]","user-agent=[grpc-go/1.84.0]","x-real-ip=[9.9.9.9]","x-trace-id=[trace-abc]","x-user-id=[u-1001]"]}
```

回包里的 `content-type=[application/grpc+json]` 是**服务端自己看到的 HTTP/2 头**——它就是下一节要讲的那件事的直接证据。

---

## 三、换掉 codec 为什么不等于换掉协议？⭐

**本节要点**：换掉的只有「消息体怎么编解码」这一层。HTTP/2 帧、多路复用、stream、超时/取消传播、status code、metadata **一个都没换**。把这件事讲清楚，才不会让人误以为「这是自己拼的 HTTP」。

### 3.1 证据一：`content-type` 仍是 `application/grpc+json`

`application/grpc` 是 gRPC 的媒体类型，`+json` 只是它的**子类型**（换成 protobuf 时是 `application/grpc+proto`）。头还在、帧格式还在，只是帧里的字节从 protobuf 换成了 JSON 文本。

> 帧格式（1 字节压缩标志 + 4 字节大端长度 + 消息体，即 gRPC 规范里的 Length-Prefixed-Message）来自协议规范；⚠️ 本机没有 `tcpdump` / `tshark`，**这一条未用抓包复核**，本篇只以头与行为为证据。

### 3.2 证据二、三：status code 与超时都能穿透

同样是上面那个实验台，另外两条断言（真实输出）：

```text
B 状态码传播: err=rpc error: code = NotFound desc = 手写方法也能返回 gRPC 状态码  code=NotFound  message="手写方法也能返回 gRPC 状态码"
C 超时传播: 客户端 50ms 超时 / 服务端睡 300ms → code=DeadlineExceeded
```

手写的 Handler 里 `return nil, status.Error(codes.NotFound, ...)`，客户端 `status.Convert(err)` 就还原出 `NotFound`；客户端给 50ms 的 ctx、服务端睡 300ms，客户端拿到的是 `DeadlineExceeded`——**deadline 与取消是协议层在传**，不是自己约定的。

### 3.3 证据四：多路复用仍然成立

HTTP/2 的多路复用指「一条 TCP 连接上并发跑多个流」。实验台用**同一个 `conn`** 并发发 8 条请求，再由服务端统计「调用次数 vs 不同客户端地址数」：

```text
D 并发前连接状态: READY
D 并发发 8 条请求，全部成功 = true
D 服务端共收到 11 次调用，来自 1 个不同客户端地址 → 复用同一条 TCP/HTTP2 连接
D 并发后连接状态: READY
```

11 次调用（首次 + 状态码 + 超时 + 8 并发）**全部来自 1 个客户端地址**，说明它们复用同一条 TCP 连接；8 条请求同时在途而没有互相等待，就是多路复用。

### 3.4 「自己拼 HTTP」会缺什么

| 能力 | gRPC（含换 codec 后） | 自己拼 HTTP/JSON |
|---|---|---|
| 一条连接并发多请求 | 有（HTTP/2 流） | 要自己实现或退回多连接 |
| 超时 / 取消传播 | `ctx` → 协议，自动 | 自己约定并解析 |
| 结构化错误码 | `status` + 13 个规范 code | 自己定错误码规定 |
| 跨进程元数据 | `metadata` 统一通道 | 只能塞 header，无规范 |

结论：**换 codec 是换「内容怎么装」，不是换「怎么运」**。

---

## 四、不用 protoc 怎么写服务端？

**本节要点**：手写一个 `grpc.ServiceDesc` 就够——三个字段各有分工：`ServiceName` 决定方法名前缀、`HandlerType` 做实现校验、`Methods[].Handler` 是真正的执行体；`HandlerType` **必须是指向接口类型的指针**。

### 4.1 `ServiceDesc` 的三个字段

```go
// HandlerType 必须是「指向接口类型的指针」：( *orderService)(nil)
type orderService interface {
	CreateOrder(context.Context, *map[string]any) (*map[string]any, error)
}

desc := grpc.ServiceDesc{
	ServiceName: "order.OrderService",   // 拼 :path 的前半段
	HandlerType: (*orderService)(nil),   // 只用于校验 impl 有没有实现该接口
	Methods: []grpc.MethodDesc{{
		MethodName: "CreateOrder",       // 拼 :path 的后半段
		Handler:    handler,             // 真正的执行体，签名固定为 4 个参数
	}},
}
srv := grpc.NewServer(grpc.ForceServerCodec(jsonCodec{}))
srv.RegisterService(&desc, impl)         // impl 要被 HandlerType 那个接口接住
```

- `ServiceName` 与 `MethodName` 一拼，就是客户端要用的 `"/order.OrderService/CreateOrder"`；
- `HandlerType` 用反射检查 `impl` 是否满足接口；⚠️ 写成 `orderService(nil)`（不是指针）会 panic，必须是指针；
- `RegisterService` 的第二个参数是任意 `any`，只要能被 `HandlerType` 断言成对应实现即可。

### 4.2 `dec(any)` 怎么用

Handler 的签名固定，第二个参数 `dec` 是「把消息体解到你给的容器里」的函数：

```go
func handler(srv any, ctx context.Context, dec func(any) error,
	_ grpc.UnaryServerInterceptor) (any, error) {

	in := map[string]any{}
	if err := dec(&in); err != nil { // dec 内部就是 codec.Unmarshal(帧字节, &in)
		return nil, err
	}
	// srv 是 RegisterService 传进来的实现；要调方法就 srv.(orderService).CreateOrder(ctx, &in)
	md, _ := metadata.FromIncomingContext(ctx) // 顺带取 metadata
	return map[string]any{"echo": in, "md": md.Get("x-trace-id")}, nil
}
```

两个容易踩的点：`dec` 读的是**同一个消息帧**，调一次就好，调第二次没有更多数据可解；返回的 `any` 会被 codec `Marshal` 后回包，所以返回 `map[string]any` 与返回生成结构体在协议层没有区别。

---

## 五、连接为什么要复用？建连为什么不能占请求路径？⭐

**本节要点**：gRPC 建连要做 TCP 握手 + HTTP/2 SETTINGS，每请求新建会把收益全部吃回去；更要命的是**建连不能占请求路径、也不能锁全局**——否则一个黑洞上游会把所有地址的转码请求一起卡住（头阻塞）。

### 5.1 非阻塞建连 + 按地址分锁

`Pool.Conn` 的两个设计（`internal/transcode/transcode.go`）：

```go
func (p *Pool) Conn(up *config.Upstream) (*grpc.ClientConn, error) {
	addr := up.Addr
	if c := p.lookup(addr); c != nil { // 快路径：已建好就直接给
		return c, nil
	}
	dl := p.dialLock(addr) // ⭐ 地址专属锁，在 p.mu 之外获取
	dl.Lock()
	defer dl.Unlock()
	if c := p.lookup(addr); c != nil { // ⭐ 双重检查：等锁期间别人可能已建好
		return c, nil
	}
	// ⚠️ 千万不要加 grpc.WithBlock()：一旦阻塞等握手，本函数又变成长达数秒的调用
	c, err := grpc.NewClient(addr, // 非阻塞：只解析 target、拉起后台连接 goroutine
		grpc.WithDefaultCallOptions(grpc.CallContentSubtype("json")),
		grpc.WithTransportCredentials(insecure.NewCredentials()),
	)
	if err != nil {
		return nil, err
	}
	p.mu.Lock()
	p.conns[addr] = c
	p.mu.Unlock()
	return c, nil
}
```

三条判据：

1. **建连不进请求路径**：`grpc.NewClient` 不等握手完成，真正的建连由后续 RPC 放进**该请求自己的 ctx 超时**里；
2. **不锁全局**：per-addr 锁必须在全局锁**之外**获取，否则按地址分锁等于没做；
3. **双重检查不能省**：拿到 per-addr 锁后要**再查一次** `p.conns`，否则并发首访同一地址会建出多条连接，多出来的既无人用也无人关（连接泄漏）。

### 5.2 实测：`go test -v` 全量

```text
=== RUN   TestConnNonBlocking
    transcode_test.go:129: 黑洞=127.0.0.1:64641 正常=127.0.0.1:64642
    transcode_test.go:130: Conn(黑洞)=516.8µs (516800ns)，Conn(正常)=0s (0ns)，均 < 1s（旧实现黑洞地址要等满 3s）
--- PASS: TestConnNonBlocking (0.00s)
=== RUN   TestBlackholeFixtureActuallyStalls
    transcode_test.go:155: 旧行为复现成功：WithBlock 撞上黑洞耗时 300.6801ms 后失败（context deadline exceeded）
--- PASS: TestBlackholeFixtureActuallyStalls (0.30s)
=== RUN   TestConnReuse
--- PASS: TestConnReuse (0.00s)
=== RUN   TestConnConcurrentSameAddr
--- PASS: TestConnConcurrentSameAddr (0.00s)
=== RUN   TestTranscodeEndToEnd
--- PASS: TestTranscodeEndToEnd (0.00s)
=== RUN   TestTranscodeCarriesClientIP
=== RUN   TestTranscodeCarriesClientIP/ctx_里有解析结果_→_带给上游
=== RUN   TestTranscodeCarriesClientIP/ctx_里没有_→_不塞这个键（不能瞎猜一个地址）
--- PASS: TestTranscodeCarriesClientIP (0.01s)
    --- PASS: TestTranscodeCarriesClientIP/ctx_里有解析结果_→_带给上游 (0.00s)
    --- PASS: TestTranscodeCarriesClientIP/ctx_里没有_→_不塞这个键（不能瞎猜一个地址） (0.00s)
PASS
ok  	gwlab/internal/transcode	0.718s
```

读数口径：

- **时序类**（`516.8µs`、`300.6801ms`）是单次读数；本机复跑一次得到 `537.4µs` 与 `300.4248ms`，量级稳定。只断言趋势——`Conn(黑洞)` 在亚毫秒级（远小于 1s），`WithBlock` 撞黑洞必然在 300ms 预算耗尽时失败；
- `TestBlackholeFixtureActuallyStalls` 是「测测试本身」：它用废弃的 `WithBlock` 复现**旧行为**，证明黑洞 fixtures 真的会卡住，上面那条「秒回」才不是空断言；
- `TestTranscodeEndToEnd` 就是**不用 protoc 的服务端 + 完整转码链路**（json codec、metadata 透传、`paramN` 补参、`Invoke`）的端到端回归。

---

## 六、什么时候该上真 protobuf？

**本节要点**：手写不是「反模式」，但要清楚它的能力边界——**性能、跨语言契约、字段演进**这三件事手写替代不了。

| 维度 | 手写（JSON codec + `map`）够用 | 应该上真 protobuf |
|---|---|---|
| 性能 | 内部演示、低流量控制面 | 高吞吐 / 大消息：编解码更快、体积更小 |
| 跨语言契约 | 上游不可控、只会说 JSON | 多语言共用一份 `.proto`，契约唯一 |
| 字段演进 | 字段随便加，够用 | 需要 `reserved` 防字段号复用、保证向后兼容 |

三个手写**合理**的场景：① 内部演示 / 实验；② 协议转换层（网关对外 REST、对内 gRPC，转完即弃）；③ 上游跨语言且不可控，只有 JSON 契约。

⚠️ 性能数字不在本篇给：`internal/transcode/` 包里**没有 `Benchmark` 函数**，本机跑 `-bench .` 只输出 `PASS`、无基准读数（不编）。protobuf 与 JSON 的体积 / 耗时对比见 [数据序列化.md](../../../02-计算机基础/网络/数据序列化.md) 第一节的实测表。

---

## 七、metadata 为什么是跨进程的「唯一通道」？

**本节要点**：`metadata` 是随请求发给对端的键值集合，也是**唯一**能跨进程带着业务信息走的通道——链路的 `traceid`、用户身份、客户端 IP 在本项目里都从这里过；key 还会被统一规范成**小写**。

### 7.1 与 `context.WithValue` 的分水岭

`context.WithValue` 只在**单进程内**可见，出了进程就没了；`metadata` 是随这次 RPC 发给对端的键值集合，对端用 `FromIncomingContext` 取到——这就是两者唯一的分水岭。

### 7.2 key 会被规范成小写

客户端可以大写声明，服务端**必须按小写查**（否则取不到）。第二节那次运行的实测里，客户端写的是 `metadata.Pairs("X-Trace-Id", ..., "X-User-Id", ...)`，而服务端看到的键是：

```text
    x-real-ip=[9.9.9.9]
    x-trace-id=[trace-abc]
    x-user-id=[u-1001]
服务端按小写 key 取到大写声明的那条: x-user-id=[u-1001]
```

服务端同时还会看到几个 gRPC **自带**的键：`:authority`（目标地址）、`content-type`（`application/grpc+json`）、`user-agent`（`grpc-go/1.84.0`）——它们同样全是小写。

### 7.3 本项目走了哪些键

| key | 含义 | 来源 |
|---|---|---|
| `x-trace-id` | 链路 id | 入口链路中间件 |
| `x-user-id` | 用户身份 | 认证鉴权结果 |
| `x-real-ip` | 真实客户端 IP | 入口 `clientip.Resolve` 的解析结果 |

⚠️ 只透传**白名单**：网关把 `Cookie` / `User-Agent` 之类塞进 metadata 没有意义，还会把请求头污染进协议头；`x-real-ip` 也必须与 HTTP 出口取**同一个值**（见 [客户端真实IP与可信代理.md](../../../02-计算机基础/网络/客户端真实IP与可信代理.md)），否则同一请求经两条出口会给上游两个口径。

---

## 使用：从零写一个能被调通的手写 gRPC 客户端 + 服务端

下面这份是上面实验台的**最小可运行版**（本机实测通过）：服务端手写 `ServiceDesc`，客户端用 `conn.Invoke` 调它。

```go
package main

import (
	"context"
	"encoding/json"
	"fmt"
	"net"

	"google.golang.org/grpc"
	"google.golang.org/grpc/credentials/insecure"
	"google.golang.org/grpc/encoding"
	"google.golang.org/grpc/metadata"
)

type jsonCodec struct{}

func (jsonCodec) Marshal(v any) ([]byte, error)   { return json.Marshal(v) }
func (jsonCodec) Unmarshal(d []byte, v any) error { return json.Unmarshal(d, v) }
func (jsonCodec) Name() string                    { return "json" }

func init() { encoding.RegisterCodec(jsonCodec{}) }

type orderService interface{} // HandlerType 需要一个接口类型

func handler(srv any, ctx context.Context, dec func(any) error, _ grpc.UnaryServerInterceptor) (any, error) {
	in := map[string]any{}
	if err := dec(&in); err != nil { // 把请求帧解到 map
		return nil, err
	}
	md, _ := metadata.FromIncomingContext(ctx)
	return map[string]any{"echo": in, "trace": md.Get("x-trace-id")}, nil
}

func main() {
	// ---- 服务端：手写 ServiceDesc，不用 protoc ----
	ln, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		panic(err)
	}
	desc := grpc.ServiceDesc{
		ServiceName: "order.OrderService",
		HandlerType: (*orderService)(nil),
		Methods:     []grpc.MethodDesc{{MethodName: "CreateOrder", Handler: handler}},
	}
	srv := grpc.NewServer(grpc.ForceServerCodec(jsonCodec{}))
	srv.RegisterService(&desc, struct{}{})
	go func() { _ = srv.Serve(ln) }()
	defer srv.Stop()

	// ---- 客户端：注册 json codec，用方法名字符串 Invoke ----
	conn, err := grpc.NewClient(ln.Addr().String(),
		grpc.WithTransportCredentials(insecure.NewCredentials()),
		grpc.WithDefaultCallOptions(grpc.CallContentSubtype("json")),
	)
	if err != nil {
		panic(err)
	}
	defer conn.Close()

	ctx := metadata.NewOutgoingContext(context.Background(),
		metadata.Pairs("X-Trace-Id", "trace-abc")) // 大写声明
	req := map[string]any{"sku": "A-1", "qty": 2}
	out := map[string]any{}
	if err := conn.Invoke(ctx, "/order.OrderService/CreateOrder", &req, &out); err != nil {
		panic(err)
	}
	raw, _ := json.Marshal(out)
	fmt.Printf("收到的回包 = %s\n", raw)
}
```

跑起来（⚠️ 沙箱按「可执行文件所在路径」限制监听，所以构建产物要落到 `%TEMP%` 下再执行）：

```bash
go mod init grpclab
go get google.golang.org/grpc@v1.84.0
go build -o "$TEMP/grpclab.exe" . && cd "$TEMP" && ./grpclab.exe   # 代码存成 main.go
```

预期输出（回包字段由 `encoding/json` 按键排序，逐行对齐）：

```text
收到的回包 = {"echo":{"qty":2,"sku":"A-1"},"trace":["trace-abc"]}
```

---

## 延伸追问

- **没有 stub 我怎么知道方法名？** → 方法名 = `"/包.服务/方法"`；包名与 service 名来自 `.proto`。手上连 `.proto` 都没有时，可以对端开放 **gRPC reflection** 列出服务与方法（本篇未实测 reflection）。
- **`Invoke` 的 `args` / `reply` 有什么类型要求？** → 没有硬性要求，唯一约束是 codec 认不认：protobuf codec 要求 `proto.Message`，JSON codec 任意类型都能收。
- **`ForceServerCodec` 和 `WithDefaultCallOptions` 是不是必须的？** → 客户端不声明子类型就仍走 protobuf；服务端不 `Force` 就按对端声明的子类型查表，对端声明了本机没注册的名字这一路就失败。两端配套才稳。
- **换成 JSON codec 会不会丢 gRPC 的能力？** → 不会。超时 / 取消、`status` code、metadata、stream、多路复用全在；丢的是 protobuf 的**体积小**与**强类型**。
- **为什么 metadata 的 key 必须小写查？** → HTTP/2 头名规范要求小写，grpc-go 统一 lower-case 后再交给服务端；服务端用大写查会取到空。
- **手写 `ServiceDesc` 支持流式吗？** → `ServiceDesc` 还有 `Streams []StreamDesc` 字段，机制相同；⚠️ 本篇只实测了一元调用，**streaming 未实测**。
- **生产什么时候必须上 protobuf？** → 跨语言契约、字段演进要 `reserved` 保护、或对编解码开销敏感时（见第六节）。

---

## 关联

- [从零实现网关.md](从零实现网关.md) — 第七节「协议转换：HTTP 请求怎么变成 gRPC 调用」是同一命题的**简版**，本篇把 codec、`ServiceDesc`、连接池讲透
- [Kratos框架.md](../工程实践/框架/微服务/Kratos框架.md) — 有 `protoc` 时的常规路线：生成 stub、metadata 前缀规范、proto 里的校验规则
- [context.md](../工程实践/context.md) — `ctx` 超时预算与级联取消，正是 RPC 超时能穿透的基础
- [HTTP与gRPC.md](../../../02-计算机基础/网络/HTTP与gRPC.md) — HTTP/1.1 vs HTTP/2 vs gRPC 的**协议语义对照**（本篇只讲不用代码生成怎么调 / 怎么写，两者分工互补）
- [数据序列化.md](../../../02-计算机基础/网络/数据序列化.md) — protobuf 与 JSON 的**格式选型**与体积 / 性能实测
- [客户端真实IP与可信代理.md](../../../02-计算机基础/网络/客户端真实IP与可信代理.md) — `x-real-ip` 在 HTTP 出口与 gRPC metadata 两条路上的同源问题
- [net包与TCP-UDP编程.md](net包与TCP-UDP编程.md) — gRPC 走 HTTP/2；TCP framing 与它怎么选、UDS 快多少在彼第十节
- [HTTP客户端与连接池.md](HTTP客户端与连接池.md) — gRPC 复用连接的对照：HTTP 客户端的连接池与它各自的复用边界在彼
- [基础概念.md](../../../03-数据与中间件/中间件/RPC框架/gRPC/基础概念.md) — 本篇主题的**本体篇**：第六节正面回答「不用 protoc 也能调，那 protoc 到底省了什么」，含默认限额与 wire 层实测
- [Protobuf.md](../../../03-数据与中间件/中间件/RPC框架/gRPC/Protobuf.md) — 换掉 codec 之后仍需的那份契约：Protobuf 的编码、`reserved` 纪律与 schema 演进
> 反向引用（本篇被下列文档引到）：[HTTP服务端与优雅关停.md](HTTP服务端与优雅关停.md)、[接入gRPC.md](../../java/接入gRPC.md)、[RPC框架选型对比.md](../../../03-数据与中间件/中间件/RPC框架/RPC框架选型对比.md)、[四种通信模式.md](../../../03-数据与中间件/中间件/RPC框架/gRPC/四种通信模式.md)、[生态与实战.md](../../../03-数据与中间件/中间件/RPC框架/gRPC/生态与实战.md)
