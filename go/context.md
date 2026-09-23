# Go context 使用场景

> context 是什么、四个派生函数怎么选、链路超时与级联取消怎么写，以及落地时最容易踩的四个反模式
>
> 内容整理自大厂 Go 后端面试真题视频，参考资料与原始素材见 [素材清单](../interview/素材清单.md)。

---

## Q1. context 是什么？它要解决什么问题？

**来源**：`BV1LfB9Y2EUD p=1` 京东云 Go 后端一面 · 时长 15分01秒
**考察意图**：先考"它为什么存在"。答不出问题域，后面所有 API 都只是背诵。

### 一句话定义

`context` 是 Go 标准库提供的一种机制，用来在**同一条调用链路衍生的多个 goroutine 之间**统一传递三样东西：

| 传递内容 | 对应 API |
|---|---|
| **取消信号** | `ctx.Done()` 返回的 channel 被 `close` |
| **截止时间** | `WithTimeout` / `WithDeadline` 写入的 deadline |
| **请求域元数据** | `WithValue` 挂载的 key-value |

### 它真正解决的两个问题

**① 任务的主动终止能力。**
一个请求进来，往往会 fork 出多个 goroutine（查缓存、查 DB、调下游 RPC）。
如果没有统一机制，当上游断开连接或超时后，**这些 goroutine 无法被通知"别干了"**，
只能各自跑完，白烧 CPU 和下游资源。context 用一个"广播式"的信号解决了它。

**② 链路级的信息与时间预算传递。**
超时预算要在**跨进程、跨层级**的调用中传递：网关给 3s，服务 A 扣掉自身开销后
必须把**剩余的时间预算**传给服务 B，否则每一层都按自己的 3s 算，总耗时会被放大成倍数。
（gRPC 的 deadline 传播就是干这件事的。）

### 为什么它长得像"树"

`context.Background()` / `context.TODO()` 是根，每次 `WithXxx` 都**派生出一个子节点并持有父节点**。
取消一个节点 = **取消它整棵子树**；反之，子节点取消**不影响父节点**。
这就是"级联取消"的实现基础。

```go
// 根节点：main / init / 测试函数里用 Background
// 占位节点：暂时不确定用什么、先占位用 TODO
ctx := context.Background()
```

> **`Background` 与 `TODO` 的区别**：语义上一个是"确定的根"，一个是"还没想好"；
> 实现上两者都是空的 `emptyCtx`，行为完全一致。面试问到就答"**约定不同，能力相同**"。

### 面试官会追问什么

- **context 是并发安全的吗？** → 是。可以在多个 goroutine 中同时读取、同时派生，内部用锁 + 原子变量保证。
- **不传 context 的代码有什么问题？** → 调用方失去了取消和超时能力，长链路里的 goroutine 只能等自己跑完，
  是**协程泄漏**最常见的源头之一。
- **context 能取消一个"计算密集型"的纯 CPU 循环吗？** → 不能自动取消。
  循环体必须**自己检查 `ctx.Done()` 或 `ctx.Err()`**，context 只负责"通知"，不负责"强杀"。

---

## Q2. 四个派生函数分别是什么？什么场景用哪个？

**来源**：`BV1LfB9Y2EUD p=1` 京东云 Go 后端一面 · 时长 15分01秒
**考察意图**：考 API 的**差异化理解**——尤其是 `WithTimeout` 与 `WithDeadline`、`WithValue` 的定位。

### 四件套速查

| 函数 | 返回 | 触发取消的时机 | 典型场景 |
|---|---|---|---|
| `WithCancel(parent)` | `(ctx, cancel)` | **手动调用 `cancel()`** | 主动结束一批任务、首个结果返回就收工 |
| `WithTimeout(parent, d)` | `(ctx, cancel)` | `d` 时长后自动触发 | 给下游设置**相对时长**的超时 |
| `WithDeadline(parent, t)` | `(ctx, cancel)` | 到达绝对时间点 `t` | 上游传下来**绝对截止时间**时透传 |
| `WithValue(parent, k, v)` | `ctx`（无 cancel） | 不会被取消 | 传 trace id、用户身份等请求域元数据 |

> `WithTimeout(parent, d)` 在实现上就是 `WithDeadline(parent, time.Now().Add(d))`。
> 所以真正理解一个，另一个自然就懂。

### 取值与取错

```go
ctx, cancel := context.WithTimeout(parent, 3*time.Second)
defer cancel()                 // 见 Q4：必须调用

<-ctx.Done()                   // 被取消 / 超时后关闭
err := ctx.Err()               // context.Canceled 或 context.DeadlineExceeded
```

### Go 1.20+ 的"取消原因"

新版标准库补上了"为什么取消"这一块拼图：

```go
ctx, cancel := context.WithCancelCause(parent)
cancel(errors.New("下游返回 5xx，提前收工"))

err := context.Cause(ctx)      // 拿到自定义原因；无原因时退化为 ctx.Err()
```

对应还有 `WithTimeoutCause` / `WithDeadlineCause`。
**注意**：`cancel()` 本身永不返回错误（返回类型是 `CancelFunc` 而非 `func() error`），
所以"取消原因"必须靠这套 Cause 系列 API 传递，不能靠返回值。

### 三行代码讲清 `WithValue`

```go
type ctxKeyTraceID struct{}                    // 自定义 key 类型，见 Q4

ctx = context.WithValue(ctx, ctxKeyTraceID{}, "trace-123")
v, ok := ctx.Value(ctxKeyTraceID{}).(string)   // 取值必须类型断言
```

要点：

- `WithValue` **不产生取消能力**，返回的 ctx 没有 cancel，因为它压根不参与生命周期管理；
- 要挂多个键值，就**一层层往下套**（每次调用返回新 ctx，父 ctx 不变）；
- 遍历是**沿父链逐级向上查找**的链表式查找，所以**别挂太多层**，更别把 context 当万能传参袋子。

### 面试官会追问什么

- **`WithTimeout` 和 `WithDeadline` 选哪个？** → 自己决定超时用 `WithTimeout`；
  上游已经把**绝对时间**传下来（例如 HTTP 的 `X-Request-Deadline`、gRPC deadline）用 `WithDeadline`，
  这样能自动吃掉上游已经消耗掉的时间，不会层层叠加。
- **父 ctx 已经超时，子 ctx 还会生效吗？** → 会，**父先取消则子立即取消**；
  子设置的更短超时也会先于父触发。取两者中更早的那个。
- **`WithValue` 的 value 可以放指针吗？** → 可以，但并发修改指针指向的数据就是数据竞争，
  context 只保证"取出的值不被替换"，不保证"值本身线程安全"。

---

## Q3. context 在实际项目里怎么用？（三个典型 case）

**来源**：`BV1LfB9Y2EUD p=1` 京东云 Go 后端一面 · 时长 15分01秒
**考察意图**：视频用三段可运行代码演示，**面试里"能不能写出可运行的骨架"比背定义更有说服力**。

### case 1：跨层传递请求域数据（WithValue）

```go
func main() {
    ctx := context.WithValue(context.Background(), ctxKeyTraceID{}, "trace-123")
    handle(ctx)      // 主协程直接调用
    go handle(ctx)   // 新起的协程持有同一份上下文，一样能取到值
}

func handle(ctx context.Context) {
    if v, ok := ctx.Value(ctxKeyTraceID{}).(string); ok {
        log.Printf("trace_id=%s", v)
    }
}
```

**关键点**：数据"穿过"中间层而不需要每个函数都显式加参数。
代价是**链路变得隐式**，所以只能放元数据，不能放业务参数（见 Q4）。

### case 2：给单个请求设置超时（WithTimeout + 一次非阻塞检查 + 一次阻塞 select）

```go
func handler(w http.ResponseWriter, r *http.Request) {
    ctx, cancel := context.WithTimeout(r.Context(), 3*time.Second)
    defer cancel()

    // ① 先做一次非阻塞探测：上游已经取消/超时，就没必要再干活
    //    （select 带 default，永远不会阻塞）
    select {
    case <-ctx.Done():
        http.Error(w, "request already canceled", http.StatusGatewayTimeout)
        return
    default:
        // 正常开始干活
    }

    resultCh := make(chan string, 1)
    go func() { resultCh <- longTask(ctx) }()   // 结果只能通过 channel 回传，不能 return

    // ② 阻塞式 select：两个出口，谁先到走谁
    select {
    case <-ctx.Done():                          // 超时 / 上游取消
        http.Error(w, "request timeout", http.StatusGatewayTimeout)
    case res := <-resultCh:                     // 正常拿到结果
        fmt.Fprintln(w, res)
    }
}
```

几个容易漏的细节：

- `select` **只会命中一次**就往下走，不会"循环等待"——要持续等待必须外面套 `for`；
- 后台协程是**跑完才写 channel**的，`ctx` 超时不会强杀它，只是**主流程不再等它**；
- `resultCh` 必须带缓冲（或确保接收方一定读），否则超时路径下这个 goroutine 会永久阻塞在发送上；
- 只要 `ctx.Done()` 能作为 `select` 的一个 case，**就不要用 `time.After` 单独做超时**，
  否则每次循环都会新建一个 `Timer`，在等它触发前无法被 GC 回收。

### case 3：一批任务一起收工（WithCancel + WaitGroup）

```go
func main() {
    ctx, cancel := context.WithCancel(context.Background())
    var wg sync.WaitGroup

    for i := 1; i <= 3; i++ {
        wg.Add(1)
        go func(id int) {
            defer wg.Done()
            work(ctx, id)
        }(i)
    }

    time.Sleep(5 * time.Second)
    cancel()     // 唯一的退出信号
    wg.Wait()    // 等所有 worker 真正退出
}

func work(ctx context.Context, id int) {
    ticker := time.NewTicker(time.Second)
    defer ticker.Stop()

    for {                                  // for-select 死循环，只有 ctx 能把它拉出来
        select {
        case <-ctx.Done():
            log.Printf("worker %d exit: %v", id, ctx.Err())
            return
        case <-ticker.C:
            log.Printf("worker %d working", id)
        }
    }
}
```

**这段代码是 Q3 的"标准答案模板"**，值得背下来：

1. 一个 `ctx` + 一个 `cancel`，多个 worker 共享；
2. worker 内部用 `for { select { case <-ctx.Done(): return ... } }` 形成可中断的循环；
3. **退出信号只有一个来源**——`cancel()`，没有它 worker 永远不会返回；
4. 主协程 `cancel()` 之后必须 `wg.Wait()`，否则主协程先退出，任务被"腰斩"。

### 面试官会追问什么

- **超时之后那个还在跑的 goroutine 会怎样？** → 不会被打断，它会继续执行到函数返回；
  如果它内部也在 `select` 检查 `ctx`，就能自己提前退出——**这就是为什么可中断的任务要把 ctx 一路传下去**。
- **为什么 `case 2` 里要 `defer cancel()` 而不是干脆不调？** → 不调会让父节点一直持有子节点引用，
  直到父节点自己被取消，属于**内存/goroutine 泄漏**（见 Q4）。
- **`cancel()` 可以被调用多次吗？** → 可以，幂等；重复调用只生效一次，不会 panic。
  所以 `defer cancel()` 和手动 `cancel()` 同时存在是安全的。

---

## Q4. 使用 context 有哪些必须遵守的规范与反模式？

**来源**：`BV1LfB9Y2EUD p=1` 京东云 Go 后端一面 · 时长 15分01秒
**考察意图**：这一题区分"会写"和"在生产里被坑过"——规范大多是被事故教育出来的。

### ① 不要用 context 传业务参数

`WithValue` 的定位是**请求域元数据**（trace id、租户 id、鉴权结果），
不是"免写函数签名的偷懒通道"。判断标准：

> 如果一个参数缺失会导致**函数逻辑跑错**，它就是业务参数，必须**显式入参**；
> 如果参数缺失只是**可观测性变差**（少了个 trace id），它才是 context 的菜。

### ② `WithValue` 的 key 必须是自定义类型

```go
type ctxKeyTraceID struct{}     // ✅ 未导出的空结构体：零内存占用、不会撞 key

const traceIDKey = "trace_id"   // ❌ 内置 string 作 key，两个包用同名 key 直接互相覆盖
```

- 用自定义类型可以**避免跨包冲突**：`A 包的 "user"` 和 `B 包的 "user"` 是两个不同的 key；
- 用**未导出的空结构体**类型 `struct{}` 作 key，零内存占用，且外部包无法构造同类型；
- **key 必须是可比较类型**，否则 `WithValue` 会直接 panic。

### ③ 派生出 ctx 就必须 `cancel()`

```go
ctx, cancel := context.WithTimeout(parent, 3*time.Second)
defer cancel()                  // 必须
```

即使超时是自己触发的、即使后面逻辑根本不读 ctx，也要调：

- 父节点内部用 map 保存子节点，**不取消就会一直持有引用**，直到父节点取消——长生命周期父节点（如常驻服务）下就是慢性泄漏；
- `cancel()` 还会**释放关联的定时器**，不调用就是白等一个 timer 到期。

> `go vet` 的 `lostcancel` 检查会直接报出漏调用的分支，CI 里应该开起来。

### ④ 不要把 context 存进结构体字段

```go
type Server struct {
    ctx context.Context   // ❌ 生命周期与结构体不匹配，超时/取消语义会被"固化"
}
```

正确做法是**作为第一个参数显式传递，命名 `ctx`**：

```go
func DoWork(ctx context.Context, req *Request) (*Response, error)   // ✅
```

原因：存入结构体的 ctx 会**脱离原始调用链的时间边界**——
一个结构体的存活时间通常远长于一次请求，取消信号就永远对不上号。
（唯一例外是标准库内部实现，比如 `http.Request` 自带的 `r.Context()`。）

### ⑤ 不要传 nil context

```go
fn(nil)                        // ❌
fn(context.TODO())             // ✅ 还没想好用什么
fn(context.Background())       // ✅ 顶层入口用这个
```

传 `nil` 会在下游调用 `ctx.Done()` / `ctx.Err()` 时 panic，
而且很多静态检查工具也要求 ctx 参数不可为 nil。

### 面试官会追问什么

- **`context.Background()` 和 `context.TODO()` 的实现有区别吗？** → 没有，都是 `emptyCtx`，纯语义区分。
- **`ctx.Value` 为什么建议少用？** → 取值要类型断言（断言失败返回 nil 而非报错），
  链路过深时排查困难，且 value 本身不参与编译期检查，重构风险高。
- **HTTP 请求里 `r.Context()` 什么时候被取消？** → 客户端断开连接、
  服务端处理超时（`http.Server.ReadTimeout` 等）或 handler 返回后由框架取消。
  所以**下游调用应该基于 `r.Context()` 派生**，客户端一断，整条链路自动收工。

---

## Q5. context 怎么和 select、errgroup 配合？

**来源**：`BV1LfB9Y2EUD p=1` 京东云 Go 后端一面 · 时长 15分01秒
**考察意图**：考组合能力。单点 API 会背的人多，能把"取消 + 并发聚合"搭成生产代码的人少。

### 与 `select` 配合：`ctx.Done()` 是"万能退出口"

```go
for {
    select {
    case <-ctx.Done():
        return ctx.Err()          // 唯一出口
    case job := <-jobCh:
        handle(job)
    case <-ticker.C:
        flush()
    }
}
```

三条纪律：

1. **`ctx.Done()` 必须是一个 case**，否则循环不可中断；
2. 多个 case 同时就绪时，`select` 是**伪随机**选一个 —— 所以不要依赖 case 的书写顺序；
3. **取消的优先级不是绝对的**：如果 `jobCh` 和 `ctx.Done()` 同时就绪，仍可能先取到 job。
   要"立即停"就得在业务分支开头**再检查一次** `ctx.Err()`。

> `ctx.Done()` 本质上是一个 channel，而取消的动作是 `close(channel)`。
> `close` 是**广播语义**（所有接收者同时被唤醒），这就是 context 能一次唤醒任意多个 goroutine 的原因。

### 与 `errgroup` 配合：并发聚合并共享取消

```go
import "golang.org/x/sync/errgroup"

func fetchAll(ctx context.Context, urls []string) error {
    g, ctx := errgroup.WithContext(ctx)   // 派生带取消的 ctx
    g.SetLimit(8)                         // 限制并发度，避免打爆下游

    for _, url := range urls {
        url := url
        g.Go(func() error {
            return fetch(ctx, url)        // 必须用 errgroup 给的 ctx
        })
    }
    return g.Wait()                       // 返回第一个非 nil error
}
```

行为要点：

- **`g.Go` 用的必须是 `errgroup` 返回的 ctx**，否则某个分支失败时其他分支收不到取消信号；
- **任一分支返回 error，派生 ctx 立即被取消**，剩下的分支只要检查 ctx 就能提前收工；
- `g.Wait()` **返回第一个非 nil 错误**（后续错误被丢弃）；
  `Wait` 返回时该 ctx 也一定被取消，所以**不需要额外 `defer cancel()`**；
- 与 `sync.WaitGroup` 的取舍：`WaitGroup` 只管"等齐"，**不收集错误、不传播取消**；
  `errgroup` 管"等齐 + 首个错误 + 级联取消"，缺点是丢了后续分支的具体错误。

### 常见组合模式：先到先用（First-Result Wins）

```go
g, ctx := errgroup.WithContext(ctx)
resultCh := make(chan Result, len(replicas))

for _, r := range replicas {
    g.Go(func() error {
        v, err := r.Query(ctx)
        if err != nil {
            return err
        }
        select {
        case resultCh <- v:      // 抢到就写
        default:
        }
        return nil
    })
}
```

只要有一个副本成功，业务侧就可以从 `resultCh` 取走结果；
配合 `ctx` 取消把"已经不需要的"剩余请求全部叫停——**超时压测下的尾延迟优化，靠的就是这套**。

### 面试官会追问什么

- **`errgroup` 的 ctx 和 `WaitGroup` 能混用吗？** → 能，但没必要，
  `errgroup` 已经把"等齐 + 取消"都包了；硬要混用只会让取消来源变多，排查困难。
- **并发限流该用 `SetLimit` 还是 `semaphore`？** → 任务是"固定一组、跑完就完"用 `SetLimit`（Go 1.20+）；
  任务持续不断产生、需要长期限流，用 `golang.org/x/sync/semaphore` 或 `channel` 令牌更合适。
- **context 取消后，下游已经发出的 RPC 会立刻中断吗？** → 取决于下游实现。
  基于 ctx 的客户端（`http.NewRequestWithContext`、gRPC 客户端）会在 ctx 取消时**主动中断连接**；
  自己写的裸 socket 调用则不会有任何反应。

---

## 附：一页速查

| 问题 | 结论 |
|---|---|
| ctx 放哪 | 函数的**第一个参数**，命名 `ctx` |
| 顶层用什么 | `context.Background()`；不确定时 `context.TODO()` |
| 一定要做 | 所有派生 ctx 都 `defer cancel()` |
| `WithValue` 放什么 | 请求域元数据；key 用**未导出空结构体类型** |
| `WithValue` 不放什么 | 业务参数、可变大对象 |
| 超时选哪个 | 自己定超时 → `WithTimeout`；透传上游绝对时间 → `WithDeadline` |
| 取消原因 | `WithXxxCause` + `context.Cause(ctx)`（Go 1.20+） |
| 错误判断 | `errors.Is(ctx.Err(), context.DeadlineExceeded)` |
| 并发聚合 | `errgroup.WithContext` + `SetLimit` |
| 最常踩的坑 | 漏调 `cancel()` 泄漏、key 用 string 撞车、把 ctx 存结构体、goroutine 里不传 ctx |
