# Goroutine

> goroutine 与线程的成本对照、初始栈与增长、创建/复用、goroutine id、循环变量语义、泄漏排查
>
> 内容整理自大厂 Go 后端面试真题，参考资料与原始素材见 [素材清单](../../素材清单.md)。
>
> 文中数字均为本机 **Go 1.26.5** 实测（`GOMAXPROCS=8`）；goroutine 怎么被调度见 [../运行时/GMP调度.md](../运行时/GMP调度.md)，泄漏的完整排查见 [协程泄漏与死锁.md](协程泄漏与死锁.md)。

---

## 一、goroutine 和线程到底差在哪？

**考察意图**：这题问的是"**为什么 Go 能开几十万个协程**"。答"更轻量"不给分，要能说出**轻在哪、量级差多少**。

### 1.1 五个维度的差别

| 维度 | 操作系统线程 | goroutine |
|---|---|---|
| **调度方** | 操作系统内核（抢占式） | **Go 运行时**（用户态调度器，GMP） |
| **栈** | 固定（默认 1~8 MB），创建时就要预留 | **初始 2 KB**（Linux），**按需增长**，上限 1 GB |
| **切换代价** | 陷入内核、保存完整寄存器上下文、可能换页表 | **纯用户态切换**，只保存少量寄存器 |
| **数量级** | 几千个就到瓶颈 | **几十万到百万级** |
| **创建代价** | 数十微秒（还要映射栈） | **亚微秒**（实测 364~678 ns，见第三节） |

### 1.2 一句话总结

> **线程的栈是"先付钱后使用"，goroutine 的栈是"用多少付多少"。**
>
> 一个 8 MB 栈的线程，哪怕只跑一个函数也得占着 8 MB 虚拟内存；goroutine 起步 2 KB，
> 递归深了才一块块往上加 —— 这才是"几十万个并发"在内存上可行的原因。

⚠️ **但"轻"不等于"不要钱"**：每个活着的 goroutine 至少要占**一个栈 span + 一个 `g` 结构体**，
实测每个阻塞中的 goroutine 均摊 **8139 B**（见第二节）——10 万个就是 **≈ 800 MB**。

---

## 二、goroutine 的栈初始到底多大？怎么长？

**考察意图**：能说出"2 KB"只是背书；说出"**Windows 上其实是 8 KB**"、以及"**扩容是拷贝、缩容在 GC 时做**"，才是看过源码。

### 2.1 实测：每个 goroutine 的栈开销

```go
gate := make(chan struct{})
var wg sync.WaitGroup
runtime.GC()
var b, a runtime.MemStats
runtime.ReadMemStats(&b)
wg.Add(n)
for i := 0; i < n; i++ {
    go func() { wg.Done(); <-gate }() // 全部阻塞住，保证同时存活
}
wg.Wait()
runtime.GC()
runtime.ReadMemStats(&a)
fmt.Printf("栈增量 %d KB → %.0f B/个\n", (a.StackInuse-b.StackInuse)/1024,
    float64(a.StackInuse-b.StackInuse)/float64(n))
```

```text
version=go1.26.5
n=  1000  栈增量    8000 KB →   8192 B/个 ｜ Sys 增量   13632 KB →  13959 B/个
n= 10000  栈增量   74368 KB →   7615 B/个 ｜ Sys 增量   77888 KB →   7976 B/个
n= 50000  栈增量  395648 KB →   8103 B/个 ｜ Sys 增量  346960 KB →   7106 B/个
n=100000  栈增量  797216 KB →   8163 B/个 ｜ Sys 增量  437636 KB →   4481 B/个
```

`n=1000` 时正好 **8192 B/个**（8 KB 整），规模放大到 10 万也稳定在 8.1 KB 左右 ——
所以**每个阻塞中的 goroutine 按 8 KB 计**，10 万个 ≈ **800 MB**。

### 2.2 为什么是 8 KB 而不是 2 KB？—— 平台常量算出来的

运行时源码里的算法（`runtime/stack.go`）：

```text
stackMin    = 2048                                  // 最小栈 2 KB
stackSystem = goos.IsWindows*4096 + ...             // Windows 额外 4096 字节
fixedStack0 = stackMin + stackSystem                // Windows: 2048 + 4096 = 6144
fixedStack  = 向上取整到 2 的幂                       // → 8192
```

| 平台 | `stackSystem` | 初始栈（`fixedStack`） |
|---|---|---|
| **Linux** | 0 | **2 KB** |
| **Windows** | 4096（留给栈保护页 / 系统用） | **8 KB**（6144 向上取整） |
| Plan 9 | 512 | 4 KB |

⭐ 这正好解释了上面的实测：**本机是 Windows，所以每个 goroutine 是 8 KB，而不是常说的 2 KB**。
面试里如果答"2 KB"，可以补一句"那是 Linux 的数字，Windows 因为要给系统留 4 KB，实际是 8 KB"——
这一句就足以区分"背过"和"看过"。

### 2.3 栈怎么长、怎么缩

| 动作 | 机制 |
|---|---|
| **增长** | 函数入口有**栈边界检查**（morestack），发现不够就在**堆上分配一块两倍的栈**，把旧栈内容**整体拷贝**过去，再改掉所有指向旧栈的指针（用栈帧里的指针信息修正），最后释放旧栈 |
| **增长幅度** | **翻倍**（不是按需 +N），所以一次深递归会让栈连续翻几倍 |
| **上限** | 64 位平台 `MaxStack = 1 GB`，超了直接 `fatal error: stack overflow`（**不能 recover**） |
| **收缩** | **只在 GC 时做**：扫描时发现栈只用了不到 1/4，就把栈减半（`shrinkstack`） |
| **小栈优化** | 栈 ≤ 2KB 时用**每个 P 的本地缓存**分配，不进全局锁；大栈才走 mheap |

⚠️ **两个常被追问的点**：
1. **栈扩容 = 拷贝**，所以"深递归 + 大量指针"很贵，这也是 Go 不建议深递归的原因；
2. **栈不会自己缩回去**，要等 GC —— 一个曾经很深、之后不再深的 goroutine，会一直占着大栈直到下次 GC。

---

## 三、创建一个 goroutine 有多便宜？

**考察意图**：这题考的是**量化能力**——能说出「亚微秒」、以及「**执行完的 G 和栈都会被复用**」，比说「很轻量」强得多。

### 3.1 实测

```go
const n = 100_000
start := time.Now()
var wg sync.WaitGroup
wg.Add(n)
for i := 0; i < n; i++ {
    go func() { wg.Done() }()
}
wg.Wait()
fmt.Printf("创建并跑完 %d 个 goroutine：耗时 %v，平均 %v/个\n", n, dur, dur/n)
```

```text
GOMAXPROCS=8 version=go1.26.5
创建并跑完 100000 个 goroutine：耗时 68ms，平均 678ns/个
Sys 增量 8704 KB / Heap 增量 1891 KB / 栈增量 5184 KB → 均摊 ≈ 53 B/个（goroutine 会被复用，跑完即回落）
```

同一个程序重复运行，单次值在 **364 ns ~ 678 ns** 之间波动（受机器负载影响）——
量级是**亚微秒**，比线程创建（数十微秒）低两个数量级。

### 3.2 ⭐ 注意最后一行：跑完的 goroutine 几乎不占内存

`Heap 增量`只有 1.8 MB、栈增量 5.1 MB（10 万个）—— 平均下来每个不到 60 B。原因：

- **G 会被复用**：执行完的 goroutine 变成 `Gdead`，被放进 **P 的 `gfree` 链表**或**全局 `sched.gFree`**，
  下次 `go f()` 直接取一个现成的 G 用，不重新分配；
- **栈也会被缓存**：释放的栈进 **`stackpool`**（按大小分级）或 P 的 `stackcache`，下次同样大小直接用。

> ⭐ 所以"Go 能扛住每秒几十万个 goroutine 创建"靠的不只是创建快，还有**创建/销毁几乎不产生内存分配**——
> 对比一下：每次 `new Thread` 都要向 OS 申请栈。

### 3.3 那 `go` 关键字到底做了什么

编译成对 `runtime.newproc` 的调用，大致三步：

1. 算出函数参数大小，**从当前 P 取一个空闲 G**（`gfree` 链表），没有就新建；
2. 把 G 挂到**当前 P 的本地队列**（队列满 256 → 溢出到全局队列）；
3. 唤醒一个 M 来跑（没有空闲 M 就新建一个，见 [../运行时/GMP调度.md](../运行时/GMP调度.md)）。

---

## 四、goroutine id 能拿到吗？为什么官方不给？

**考察意图**：光会解析 `runtime.Stack` 只是及格；说出**官方为什么刻意不给**（G 会被复用、可被调度迁移）才算过关。

### 4.1 实测：只能靠解析 `runtime.Stack` 的文本

```go
func goroutineID() int64 {
    var buf [64]byte
    n := runtime.Stack(buf[:], false)
    s := buf[:n] // "goroutine 18 [running]:\n"
    s = bytes.TrimPrefix(s, []byte("goroutine "))
    i := bytes.IndexByte(s, ' ')
    id, _ := strconv.ParseInt(string(s[:i]), 10, 64)
    return id
}
```

```text
main goroutine id = 1
新起 goroutine id  = 20
```

（新起的 id 每次运行都不同——它是运行时自增的，主协程永远是 1。）

### 4.2 官方为什么故意不提供

`runtime` 包里没有 `GoroutineID()`，官方的理由是 **goroutine 不应该有身份**：

- **有 id 就会有人依赖它**：写 `map[goroutineID]T` 做"协程本地存储"，而 goroutine 是**会被复用**的
  （见 3.2），这会直接导致串数据；
- **破坏"并发不该依赖执行实体"的抽象**：Go 的设计取向是"goroutine 无状态、可任意调度/迁移"，
  一旦有了身份，调度器就不能随意复用与迁移了；
- **上游库一旦依赖这个 hack，`runtime.Stack` 的输出格式变化就会炸**（解析文本本身就不可靠）。

⚠️ **实践结论**：需要"协程本地"语义时，正确做法是**显式传参**（`context.Context` 是官方推荐的载体），
而不是取 goroutine id。

---

## 五、循环变量捕获：Go 1.22 前后为什么不一样？

**考察意图**：这是"你最熟悉的语言细节"类问题——看起来是陷阱题，实际是**版本语义变更**题。

### 5.1 实测

```go
var fns []func()
for i := 0; i < 3; i++ {
    fns = append(fns, func() { fmt.Print(i, " ") })
}
for _, f := range fns {
    f()
}
```

```text
闭包捕获循环变量：0 1 2 
```

### 5.2 语义变更

| 版本 | 输出 | 原因 |
|---|---|---|
| **Go 1.21 及以前** | `3 3 3` | `for` 的循环变量**整个循环只有一份**，闭包捕获的是同一个变量；循环结束时它=3 |
| **Go 1.22 起** | `0 1 2` | **每一轮迭代都是新的变量**（编译器在每轮开头插入隐式的 `i := i`） |

> 修法（1.21 及以前）：循环体里写 `i := i` 或把 `i` 当参数传进闭包。
> 现在两种写法结果一样，但**老代码里的 `i := i` 不再是必需的，删掉也不会错**。

⚠️ **同一个变更也影响 `for range` 的 `value` 变量**：1.22 起 `range` 每轮的 key/value 都是新变量，
所以 `for _, v := range xs { go func(){ use(v) }() }` 不再有"所有协程都用最后一个 v"的 bug。

⚠️ 但**语言版本由 `go.mod` 的 `go` 指令决定**（不是编译器版本！）：
`go.mod` 写 `go 1.21` 时，即使你用 1.26 编译，语义仍然是旧的。
`go run main.go` 这种**单文件模式**不读 `go.mod`，永远用最新语义 —— 这也是"本地跑对了、项目里不对"的经典原因。

---

## 六、goroutine 泄漏怎么发现？

**考察意图**：不考 API，考你**有没有线上排查的经验**。完整机制与十个现场见专篇，这里只留判据。

### 6.1 三个最快的判据

| 手段 | 怎么用 | 看到什么说明泄漏 |
|---|---|---|
| `runtime.NumGoroutine()` | 在健康检查/定时打点里输出 | 数值随请求量**单调上涨**、不回落 |
| pprof | `http://host/debug/pprof/goroutine?debug=2` | 同一段**调用栈**的 goroutine 数量持续增长 |
| `runtime.Stack(buf, true)` | 直接 dump 全部栈 | 大量 goroutine 卡在同一行（同一个 channel 收发/同一把锁） |

### 6.2 三类根因（详版见 [协程泄漏与死锁.md](协程泄漏与死锁.md)）

1. **发送方永远等不到接收方**：无缓冲 channel 写了一个"没人读"的值 → 该 goroutine 永久 `chan send`；
2. **接收方永远等不到发送方**：`for range ch` 但没人 `close` → 永久 `chan receive`；
3. **忘了退出信号**：`select` 里没有 `ctx.Done()`/`done` 分支 → 循环永远转。

```text
启动时 NumGoroutine = 1
每个请求起 3 个 goroutine，但有一个忘了退出
→ 100 个请求后 NumGoroutine ≈ 1 + 100，且持续上涨不回落 = 泄漏
```

---

## 面试官会追问什么

- **goroutine 和线程的区别？** → 五个维度：调度方（用户态 vs 内核）、栈（按需增长 vs 固定）、切换代价、数量级、创建代价。一句总结：**栈是"先付钱后使用"还是"用多少付多少"**。
- **goroutine 初始栈多大？** → Linux **2 KB**；⚠️ **Windows 是 8 KB**（`stackSystem` 加 4096 后向上取整到 2 的幂），本机实测 8192 B/个。
- **栈增长与收缩的机制？** → 增长：栈边界检查 → 分配**两倍**大小的新栈 → **拷贝**旧栈并修正指针 → 释放旧栈；收缩：**只在 GC 时**做，用量低于 1/4 就减半。
- **栈溢出能 recover 吗？** → 不能，是 `fatal error: stack overflow`，直接终止进程（`recover` 只能拦 panic）。
- **一个 goroutine 最少占多少内存？** → 一个栈 span + `g` 结构体；本机实测**每个阻塞中的 goroutine 均摊 ≈ 8 KB**（Windows），10 万个 ≈ 800 MB。
- **创建 goroutine 的代价？** → 亚微秒级（本机 364~678 ns/个）；且**执行完的 G 与栈都会被缓存复用**，所以创建/销毁几乎不产生新分配。
- **为什么 goroutine id 拿不到？** → 官方刻意不提供：**有身份就会被依赖**，而 G 会被复用、可被任意调度迁移，依赖身份会串数据；需要"协程本地"就用 `context` 显式传参。
- **for 循环里起 goroutine 打印 i，结果是？** → **Go 1.22 起是 0 1 2**（每轮新变量）；1.21 及以前是 3 3 3。⚠️ 语义由 `go.mod` 的 `go` 指令决定，`go run 单文件` 不读 go.mod、永远用最新语义。
- **怎么排查 goroutine 泄漏？** → `NumGoroutine()` 看趋势、pprof goroutine 看**调用栈聚类**、`runtime.Stack(buf, true)` 全量 dump；三类根因：发送方没人接、接收方没人发也没 close、没有退出信号。
- **`GOMAXPROCS=1` 还能起 10 万个 goroutine 吗？** → 能。创建不受限制，限制的只是**同时真并行的个数**（= P 的数量），见 [../运行时/GMP调度.md](../运行时/GMP调度.md)。

---

## 关联

- [../运行时/GMP调度.md](../运行时/GMP调度.md) — goroutine 被谁调度、本地队列与全局队列、抢占式调度
- [协程泄漏与死锁.md](协程泄漏与死锁.md) — 泄漏的十个现场与排查套路（本篇第六节只留判据）
- [共享内存与CSP.md](共享内存与CSP.md) — 协程之间怎么通信，两种路线的失败模式
- [并发同步原语.md](并发同步原语.md) — 加锁、原子操作与 WaitGroup
- [channel原理与底层实现.md](channel原理与底层实现.md) — goroutine 阻塞时挂到哪个队列上
- [../运行时/内存分配器.md](../运行时/内存分配器.md) — 栈和 `g` 结构体最终落到哪一级分配器
- [../../linux/进程与线程.md](../../linux/进程与线程.md) — 线程侧的创建成本与内核结构
