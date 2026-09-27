# Goroutine

> goroutine 与线程的成本对照、`g` 结构体与状态机、阻塞与唤醒（gopark / goready）、初始栈与增长、
> 创建/复用、goroutine id、循环变量语义、泄漏排查，以及**五种使用形态、四条必须遵守的规矩**
> 与**真实业务场景的判据**（以导入导出为例：哪些环节能并发、哪些绝对不能）
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

## 四、`g` 结构体里有什么？状态机长什么样？

**考察意图**：说"G 就是协程"只是名词；能说出 `g` 里存了哪几类东西、有哪些状态、状态怎么迁移，才算看过 runtime。

### 4.1 `g` 里存的四类东西

从 `runtime/runtime2.go` 摘关键字段（Go 1.26.5，省略注释）：

```go
type g struct {
	stack       stack   // 栈区间 [lo, hi)：当前栈在哪、多大
	stackguard0 uintptr // 栈增长的水位线；被抢占时会设成 StackPreempt
	stackguard1 uintptr // g0 / gsignal 用（普通 G 上是 ~0，误用即崩）

	_panic *_panic // 最内层的 panic（recover 靠它）
	_defer *_defer // 最内层的 defer

	m     *m    // 当前绑定的 M
	sched gobuf // ⭐ 切换时保存的执行现场

	atomicstatus atomic.Uint32 // ⭐ 状态机的状态
	goid         uint64        // 运行时内部编号
	schedlink    guintptr      // 用来在队列里串成链表
	waitsince    int64         // 什么时候开始阻塞
	waitreason   waitReason    // ⭐ 为什么阻塞

	preempt       bool // 被抢占的信号
	preemptStop   bool
	preemptShrink bool // 在同步安全点上收缩栈
}
```

四类：**① 栈**（在哪、多大、水位线）、**② 执行现场**（`sched`）、**③ 状态与原因**（`atomicstatus` / `waitreason`）、**④ 控制位**（抢占与栈操作）。

⭐ `sched gobuf` 就是"切换时要保存什么"的答案：

```go
type gobuf struct {
	sp   uintptr        // 栈指针
	pc   uintptr        // 下一条要执行的指令
	g    guintptr       // 这个现场属于哪个 g
	ctxt unsafe.Pointer // 闭包上下文（funcval）
	lr   uintptr        // 返回地址（有 link register 的架构）
	bp   uintptr        // 栈基址（帧指针）
}
```

**和线程切换比一比**：OS 切线程要保存整套寄存器（含浮点 / SIMD）、还可能换页表；
goroutine 之间切换只换 `sp / pc / bp / lr / ctxt` 这几个字 —— 因为编译器已经保证"**函数调用的边界上，
其他寄存器要么是死的、要么已经落在栈里**"。这正是 1.1 里"切换代价"那一行差两个数量级的来源。

### 4.2 状态机

源码里的常量（`runtime/runtime2.go`）：

```text
_Gidle(0) ──分配好──→ _Grunnable(1) ──被 M 取走──→ _Grunning(2)
                         ▲                            │
                         │                            ├─ 阻塞（chan / mutex / sleep / IO）→ _Gwaiting(4)
                         │                            ├─ 进系统调用 ──────────────────────→ _Gsyscall(3)
                         └──── 被唤醒（goready）──────┘
                                                      │
        _Gdead(6) ←── 执行结束 ────────────────────────┘
        另有：_Gcopystack(8) 栈正在被复制、_Gpreempted(9) 被异步抢占后停住
```

- 只有 `_Grunnable` / `_Grunning` / `_Gsyscall` 的 G 算"活着要干活"；`_Gwaiting` 是完全被动的一方；
- ⭐ **`_Gscan` 是位不是状态**：GC 扫某个 G 的栈时给它加上 `0x1000`（于是出现 `_Gscanwaiting` 这类组合），
  所以运行时里真正的读法是 `readgstatus(gp) &^ _Gscan`。

### 4.3 实测：状态与「为什么阻塞」在栈 dump 里都看得到

```go
buf := make([]byte, 1<<20)
n := runtime.Stack(buf, true) // ⚠️ 返回的是写入的**字节数**，不是协程数
// 每个协程的第一行长这样：goroutine 21 [chan receive]:
```

造出各种阻塞形态后 dump（本机 Go 1.26.5，21 个协程 → 栈 dump 10209 字节）：

```text
[chan receive          ] 5 个
[IO wait               ] 3 个
[sync.Mutex.Lock       ] 2 个
[sync.WaitGroup.Wait   ] 2 个
[select                ] 2 个
[chan send             ] 2 个
[select (no cases)     ] 2 个
[sleep                 ] 2 个
[running               ] 1 个
```

⭐ **一个意外发现**：我起了 3 个"直接 `<-ch`"和 2 个"`select { case <-ch: }`"，
结果 `[chan receive]` 是 **5** 个、`[select]` 一个都没有 ——
**单 case 的 `select` 被编译器退化成了直接收发**，只有**多路** `select`（两个以上 case）才会真的进入 `[select]` 状态（表中那 2 个）。

另外注意 `sync.Mutex.Lock` 与 `sync.WaitGroup.Wait` 是**分开的 `waitreason`**（不是笼统的 `semacquire`）——
排查时"卡在哪一类等待上"一眼就能看出来。

---

## 五、一个 goroutine 被阻塞后去哪了？

**考察意图**：上一节说明了"有 `_Gwaiting` 这个状态"，这一节要能说清**它怎么进去、又怎么出来**，
以及"**为什么阻塞一万个协程不等于阻塞一万条线程**"。

### 5.1 `gopark`：让出，而不是占住

```go
func gopark(unlockf func(*g, unsafe.Pointer) bool, lock unsafe.Pointer,
	reason waitReason, traceReason traceBlockReason, traceskip int) {
	// …
	gp.waitreason = reason // ① 记下"为什么阻塞" → 就是栈 dump 里的 [chan receive]
	// …
	mcall(park_m) // ② 切到 g0 上执行 park_m
}
```

三步走：

1. 记 `waitreason`（**可观测性的来源**）；
2. `mcall(park_m)` —— 切到当前 M 的 **g0（系统栈）** 上执行；
3. `park_m` 把 G 置为 `_Gwaiting`、**解绑 M**，然后调 `schedule()` 挑下一个 G 来跑。

⭐ 关键在第三步：**M 完全没有被拖住**。G 只是"从运行队列里消失"，M 立刻去跑别的 G ——
所以"阻塞一个 goroutine"的成本 ≈ 一个 `g` 结构体 + 它的栈，**跟线程数无关**。

谁在调它？**几乎所有的"等"**：channel 收发、mutex、`time.Sleep`、`WaitGroup.Wait`、`select`、网络/文件 IO（netpoller）。

### 5.2 `goready`：被唤醒时发生什么

```go
func goready(gp *g, traceskip int) {
	systemstack(func() {
		ready(gp, traceskip, true)
	})
}

func ready(gp *g, traceskip int, next bool) {
	// …
	casgstatus(gp, _Gwaiting, _Grunnable) // ① 状态改回可运行
	runqput(mp.p.ptr(), gp, next)         // ② 排队（next=true → 进 runnext，优先跑）
	wakep()                               // ③ 没有空闲 M 在跑就唤醒/新建一条
	// …
}
```

三个动作对应三个问题：**状态怎么改回来 → 排到哪 → 谁来跑**。
`next=true` 意味着被唤醒的 G 会进 **`runnext`**（见 [../运行时/GMP调度.md](../运行时/GMP调度.md)），
**比本地队列里排队的老 G 更优先** —— 这就是"刚被唤醒的 G 往往马上就能跑"的原因。

### 5.3 实测：1 万个阻塞协程，线程只有 10 条

```bash
go build -o golab.exe . # ⚠️ 先 build：GODEBUG 会同时作用在 go run 的工具进程上
GODEBUG=schedtrace=400 ./golab.exe threads
```

```text
NumGoroutine=10001（全部阻塞在同一个 channel 上）
SCHED 405ms: gomaxprocs=8 idleprocs=8 threads=10 spinningthreads=0 needspinning=0 idlethreads=7 runqueue=0 [ 0 0 0 0 0 0 0 0 ]
SCHED 806ms: gomaxprocs=8 idleprocs=8 threads=10 … runqueue=0 [ 0 0 0 0 0 0 0 0 ]
```

⭐ 三个数一起读：**协程 10001 个、线程只有 10 条、8 个 P 全在空闲（`idleprocs=8`）、运行队列全 0**。
这就是"协程便宜"的完整证据链：**被 park 的 G 既不占 P 也不占 M，只是躺在内存里等信号**。

### 5.4 网络 IO 为什么也不占线程

Go 把 socket 设成非阻塞并交给 **`netpoller`**（Linux epoll / Windows IOCP）：
读不 ready 时 `gopark` 自己，数据到了由 poller 触发 `goready` —— 所以"**几条线程服务上万连接**"才成立。

⚠️ **三个例外，别当成"起协程就能无限并发"**：

| 会真的占住 M | 说明 |
|---|---|
| **CGO 调用** | 进入 C 代码期间 M 被占住（运行时会 handoff 一条新 M 顶上，所以**线程数会涨**） |
| **某些文件系统调用** | 不是所有平台/文件都能被 poller 接管，可能落进阻塞式 syscall |
| **没有函数调用的死循环** | 1.14 起有异步抢占兜底，但抢占有延迟（实测见 [../运行时/GMP调度.md](../运行时/GMP调度.md)） |

---

## 六、goroutine id 能拿到吗？为什么官方不给？

**考察意图**：光会解析 `runtime.Stack` 只是及格；说出**官方为什么刻意不给**（G 会被复用、可被调度迁移）才算过关。

### 6.1 实测：只能靠解析 `runtime.Stack` 的文本

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

### 6.2 官方为什么故意不提供

`runtime` 包里没有 `GoroutineID()`，官方的理由是 **goroutine 不应该有身份**：

- **有 id 就会有人依赖它**：写 `map[goroutineID]T` 做"协程本地存储"，而 goroutine 是**会被复用**的
  （见 3.2），这会直接导致串数据；
- **破坏"并发不该依赖执行实体"的抽象**：Go 的设计取向是"goroutine 无状态、可任意调度/迁移"，
  一旦有了身份，调度器就不能随意复用与迁移了；
- **上游库一旦依赖这个 hack，`runtime.Stack` 的输出格式变化就会炸**（解析文本本身就不可靠）。

⚠️ **实践结论**：需要"协程本地"语义时，正确做法是**显式传参**（`context.Context` 是官方推荐的载体），
而不是取 goroutine id。

---

## 七、循环变量捕获：Go 1.22 前后为什么不一样？

**考察意图**：这是"你最熟悉的语言细节"类问题——看起来是陷阱题，实际是**版本语义变更**题。

### 7.1 实测

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

### 7.2 语义变更

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

## 八、goroutine 泄漏怎么发现？

**考察意图**：不考 API，考你**有没有线上排查的经验**。完整机制与十个现场见专篇，这里只留判据。

### 8.1 三个最快的判据

| 手段 | 怎么用 | 看到什么说明泄漏 |
|---|---|---|
| `runtime.NumGoroutine()` | 在健康检查/定时打点里输出 | 数值随请求量**单调上涨**、不回落 |
| pprof | `http://host/debug/pprof/goroutine?debug=2` | 同一段**调用栈**的 goroutine 数量持续增长 |
| `runtime.Stack(buf, true)` | 直接 dump 全部栈 | 大量 goroutine 卡在同一行（同一个 channel 收发/同一把锁） |

### 8.2 三类根因（详版见 [协程泄漏与死锁.md](协程泄漏与死锁.md)）

1. **发送方永远等不到接收方**：无缓冲 channel 写了一个"没人读"的值 → 该 goroutine 永久 `chan send`；
2. **接收方永远等不到发送方**：`for range ch` 但没人 `close` → 永久 `chan receive`；
3. **忘了退出信号**：`select` 里没有 `ctx.Done()`/`done` 分支 → 循环永远转。

```text
启动时 NumGoroutine = 1
每个请求起 3 个 goroutine，但有一个忘了退出
→ 100 个请求后 NumGoroutine ≈ 1 + 100，且持续上涨不回落 = 泄漏
```

---

## 使用：goroutine 该在什么时候起、怎么起

### 1. 写 `go` 之前先回答三个问题

| 问题 | 不合格的答案 | 合格的答案 |
|---|---|---|
| **它什么时候结束？** | "跑完就结束了" | "ctx 取消 / channel 关闭 / 超时到点" |
| **结束时谁负责收尾？** | 没想过 | `defer` 里 close / Unlock / 放回池 |
| **它崩了会怎样？** | 不知道 | 自己 recover 兜底（否则**整个进程退出**，见规矩 1） |

> 一句话判据：**能回答"它靠什么停下来"，才允许写这个 `go`**。答不出来，就是一次潜在的泄漏。

### 2. 五种形态：什么时候用哪一个

**形态一：并发加速、有明确收工点** → 用 `errgroup`（任一失败就收工）

```go
g, ctx := errgroup.WithContext(ctx)
for _, id := range ids {
	id := id
	g.Go(func() error { return fetch(ctx, id) }) // 任一失败 → ctx 取消，其余任务自动收工
}
err := g.Wait() // 返回第一个非 nil 的错误
```

**形态二：fan-out 收集结果** → `WaitGroup` + 按下标写切片（各写各的下标，不需要加锁）

```go
results := make([]Result, len(ids))
var wg sync.WaitGroup
for i, id := range ids {
	i, id := i, id
	wg.Add(1)
	go func() { defer wg.Done(); results[i] = fetchOne(ctx, id) }()
}
wg.Wait()
```

**形态三：限并发（背压）** → 带缓冲 channel 当信号量

```go
sem := make(chan struct{}, 50) // 最多 50 个在飞
var wg sync.WaitGroup
for _, id := range ids {
	sem <- struct{}{} // 拿不到令牌就在这里等 —— 背压就发生在这一行
	wg.Add(1)
	go func() { defer wg.Done(); defer func() { <-sem }(); fetchOne(ctx, id) }()
}
wg.Wait()
```

**形态四：常驻 worker** → `ctx` + `for select`（退出路径写在循环里，最不容易漏）

```go
for {
	select {
	case <-ctx.Done(): // 退出路径必须有
		return
	case job := <-jobs:
		handle(job)
	}
}
```

**形态五：一次性后台任务** → 必须自己兜 panic

```go
go func() {
	defer func() { // ⚠️ 不写这段，一个 panic 会带走整个进程
		if r := recover(); r != nil {
			log.Printf("后台任务 panic: %v\n%s", r, debug.Stack())
		}
	}()
	doWork()
}()
```

### 3. 四条规矩（前两条配本机实测）

**规矩 1：`go` 里 panic 且没 recover，会终止整个进程 —— main 的 `recover` 拦不住**

```go
func main() {
	defer func() {
		if r := recover(); r != nil {
			fmt.Println("main 抓到了", r)
		}
	}()
	go func() { panic("协程里的 panic") }() // 另一个栈，main 的 defer 够不着
	time.Sleep(2 * time.Second)
}
```

```text
退出码 = 2
输出：panic: 协程里的 panic（随后是完整栈）
（没有出现 "main 抓到了"，也没有走完 main）
```

⭐ 原因：`recover` 只对**同一个 goroutine 的 defer 链**有效。
所以**每个"可能 panic 的后台协程"都要自带 recover**；反过来，Web 框架能给每个请求兜 panic，
正是因为**每个请求都在自己的 goroutine 里**。

**规矩 2：起之前先想好背压 —— 别把下游当无限容量**

5000 个任务、每个 20ms，只改"有没有限并发"（其余一字不动）：

```text
① 无限制（来一个起一个）      峰值协程 3702 ｜ 总耗时     46ms ｜ 结束后协程 1
② 信号量限 50 并发           峰值协程   52 ｜ 总耗时   2.034s ｜ 结束后协程 1
```

⭐ 两点结论：

- **峰值差约 70 倍**：无限并发把 5000 个请求**同时**压给下游。实测这里下游只是 `sleep`，所以没出事；
  换成 DB / 三方 API / 连接池，就是排队、超时、雪崩（见 [../../分布式/限流降级熔断.md](../../分布式/限流降级熔断.md)）；
- **限并发是"用吞吐换可控性"**：② 的总耗时是 ① 的 44 倍 —— 它不快，但**内存峰值与下游压力都可控**。

> 顺带一个反直觉点：① 的峰值是 3702 而不是 5000，因为**峰值 ≈ 创建速率 × 任务时长** ——
> 创建速度跟不上、早起的任务已经跑完。所以"来一个起一个"的实际峰值取决于**上游产生请求的速度**，
> 不是任务总数；上游一突发才会真正打满。

**规矩 3：传参，别依赖闭包捕获循环变量** —— Go 1.22 起语义已修，但显式传参更清楚，
也让代码在 `go.mod` 声明旧版本时同样正确（见第七节）。

**规矩 4：每个 `go` 都要有退出路径** —— 三类根因与排查手段见第八节与 [协程泄漏与死锁.md](协程泄漏与死锁.md)。

### 4. 选型速查

| 需求 | 用什么 | 为什么 |
|---|---|---|
| 并发做完一批事，任一失败就整体放弃 | `errgroup.WithContext` | 自动 cancel + 收敛错误，不用手写 `WaitGroup` + `cancel` + 错误聚合 |
| 并发做完一批事，要拿全部结果 | `WaitGroup` + 按下标写切片（或 channel 汇总） | 不需要"失败即取消"语义，也就不用引入 ctx |
| 保护下游 / 控住内存峰值 | 带缓冲 channel 当信号量（或 `golang.org/x/sync/semaphore`） | 令牌数就是"在飞数量"的硬上限 |
| 常驻循环消费 | `for { select { case <-ctx.Done(): … } }` | 退出路径写在循环里，最不容易漏 |
| 纯粹的"发出去就不管了" | ⚠️ 尽量避免；真要做就自带 recover + ctx 超时 | 无人接管的任务是泄漏与崩溃的高发区 |

### 5. 真实业务场景：导入导出能不能用 goroutine？

**不能整体回答"能"或"不能"，要逐环节看。** 拿一个真实接口拆开——
`photography-server` 里的 `FinanceExport`（导出对账 CSV，权限点独立于"查看"：
可见不等于可带走），它由三个环节组成，每一环的答案都不一样：

```go
// internal/service/finance.go（现状：三段查询串行 + 内存拼 CSV + 返回 []byte）
sum, err := s.FinanceRepo.GetSummary(ctx, op.CompanyID, start, end)   // 四口径汇总
pays, err := s.FinanceRepo.ExportPayments(ctx, op.CompanyID, start, end) // 收款流水（不分页）
refunds, err := s.FinanceRepo.ExportRefunds(ctx, op.CompanyID, start, end) // 退款单据
var buf bytes.Buffer                     // ⚠️ 整份文件在内存里拼
w := csv.NewWriter(&buf)
// … w.Write(每一行) …
w.Flush()
return "财务对账-" + month + ".csv", buf.Bytes(), nil // ⚠️ 全部生成完才返回
```

| 环节 | 能不能并发 | 为什么 |
|---|---|---|
| **多个独立查询** | ✅ 能 | 三段互不依赖，`errgroup` 一把收 |
| **单个大查询** | ⚠️ 有条件 | 只能按区间/分页切片并发，且**必须保序**（否则导出文件行序错乱） |
| **写 CSV / 写响应** | ❌ 不能 | `csv.Writer` 与 HTTP 响应都是**单一有序流** → 只能**并发取数、串行写出** |
| **一次 `Find(&list)` 全量加载** | ❌ 不要 | 内存 O(行数)，且首字节要等全部查完 |

#### 5.1 三段独立查询：能并发，但收益有限（实测 1.4~1.6x）

```go
var (
	sum     *Summary
	pays    []OrderPayment
	refunds []OrderRefund
)
g, gctx := errgroup.WithContext(ctx)
g.Go(func() error {
	var err error
	sum, err = s.FinanceRepo.GetSummary(gctx, op.CompanyID, start, end)
	return err
})
g.Go(func() error {
	var err error
	pays, err = s.FinanceRepo.ExportPayments(gctx, op.CompanyID, start, end)
	return err
})
g.Go(func() error {
	var err error
	refunds, err = s.FinanceRepo.ExportRefunds(gctx, op.CompanyID, start, end)
	return err
})
if err := g.Wait(); err != nil {
	return err // 任一段失败，其余请求随 gctx 一起被取消
}
```

实测（本地 SQLite 三表 5 万 / 3 万行，各跑 5 次）：

```text
① 串行三段查询    ~305ms
② 三段并发        ~205ms     加速比 1.41~1.56x
```

⭐ **收益为什么只有 1.5 倍**：三段查询在本地争同一块磁盘与 CPU，没有网络往返可重叠。
真实 MySQL 上每段各自有网络往返与执行时间，收益更接近"**最慢的那一段**"——但如果三段都很快
（比如都 < 50 ms），并发反而因为多占连接而不划算。

> 判据：**只有当「各段耗时之和」明显大于「最慢那一段」时，并发才有意义。**
> 三段各 10ms 的查询并发起来只会更慢（多三次调度 + 多占两个连接）。

#### 5.2 真正的大头：「内存拼接」换成「流式写出」（实测 40 MB → 0 MB）

20 万行 CSV（18.5 MB），只改写出方式：

```text
① 内存拼接后一次性返回   耗时 ~110ms ｜ 内容 19419963 字节（18.5 MB）｜ 堆峰值增量 40.0 MB
② 流式写出（io.Writer）  耗时  ~80ms ｜ 写出 19419963 字节（18.5 MB）｜ 堆峰值增量  0.0 MB
```

（耗时是单次读数、重复跑在 ±40% 内波动；**两个堆峰值是稳定值** —— 40.0 MB 与 0.0 MB 每次都能复现。）

⭐ **内存差 40 MB，耗时还略快**（省掉了 `bytes.Buffer` 反复扩容搬数据）。

问题在**函数签名**：`FinanceExport(...) (string, []byte, error)` 这个 `[]byte` 决定了**没法流式** ——
必须整份生成完才能返回。要流式就得改成：

```go
// 把写入目标变成参数：生产环境传 http.ResponseWriter，测试传 bytes.Buffer
func (s *Service) FinanceExport(ctx context.Context, op Operator, month string, w io.Writer) (string, error)
```

代价是要重新安排"出错怎么办"：**响应头一旦发出就不能再改状态码**，所以要么先做一段能失败的准备
（查汇总/流水），要么约定"中途失败就把错误写进 CSV 的最后一行"（对账文件里这是可接受的）。

> ⭐ **导出场景的优化优先级**：① 流式写出 > ② 单查询拆并行 / 加索引 > ③ 三段小查询并发。
> 顺序搞反了会在小收益上花大力气。

#### 5.3 导入：并发写不会更快，"批量 + 事务"才是关键

| 写法 | 20 万行耗时 | 说明 |
|---|---|---|
| 逐行插入（每行一次提交） | **13~14.3s** | 提交代价 × 行数 |
| 每 1000 行一个事务（单协程） | **1.8~1.9s** | 提交次数降到 1/1000 → **7~8 倍** |
| 每 1000 行一个事务 + 8 并发 | **2.9~3.0s** | ⚠️ **比单协程更慢**：写本身就是串行的，并发只是多花在抢锁上 |

（绝对耗时两次实测在 13.0~14.3s 之间波动；**"批量 ≫ 逐行、并发 < 单协程"这两条关系稳定**。）

同样 **2 万行**、改成"每次提交都真的 fsync"（贴近 MySQL 的 autocommit 语义）：

```text
④ 逐行插入（每次 fsync）  11.4~12.9s
⑤ 每 1000 行一个事务       0.19~0.30s     → 50~60 倍
```

⭐ 结论：**导入的银弹是「批量 + 事务」，不是 goroutine。**
写密集场景里，先问"一次事务能装多少行"，再谈并发——**并发度对写几乎无用**。

#### 5.4 导入：无脑起协程的后果 —— 不会打挂 DB，但会堆在连接池上

"一行一个 goroutine、各自提交"（2 万行）：

```text
⑥ 一行一个 goroutine      耗时 2.531s ｜ 峰值协程 19098
连接池：MaxOpen=16 ｜ WaitCount=19984 ｜ WaitDuration=7h1m12s
```

⭐ **2 万个请求累计在"等连接"上花了 7 小时**。连接池（`MaxOpenConns=16`）挡住了对 DB 的直接冲击，
所以**数据库没事**；但**应用侧堆了 1.9 万个协程**，谁先拿到连接是随机的，延迟完全不可控。

> ⚠️ `WaitDuration` 是**累计值、随行数与等待分布波动**（两次实测 7h1m 与 5h22m），
> 要记的是它的**量级**：2 万个请求为了抢 16 个连接，累计等了好几个小时。
>
> 这是"**连接池是天然闸门，但不能替代应用层限并发**"的量化证据：
> 池只保证不打死 DB，不保证你的服务还健康。要限并发请用信号量（见第 2 节形态三）。

#### 5.5 判据表：哪些环节该上 goroutine

| 场景 | 结论 | 做法 |
|---|---|---|
| 导出：多个独立查询 | ✅ 用 | `errgroup`；收益 ≈ 最慢那一段的耗时 |
| 导出：单个大查询 | ⚠️ 谨慎 | 按区间切片 + **保序**输出；通常不如先把索引/分页做好 |
| 导出：写 CSV / 写响应 | ❌ 不用 | **并发取数、串行写出**，并改成流式（`io.Writer`） |
| 导出：一次 `Find(&list)` 全量加载 | ❌ 不要 | 游标/分页 + 边查边写，内存与 TTLB 都受益 |
| 导入：解析与校验（纯 CPU） | ✅ 用 | fan-out 到 `GOMAXPROCS`；注意带行号以保持错误定位 |
| 导入：写库 | ❌ 不用 | **批量 + 事务**（1000 行/批），单协程足够 |
| 导入：调外部依赖（下载图片、传 OSS） | ✅ 用，必须限并发 | 信号量限 8~16，别把三方打挂 |
| 导入：进度反馈 | ⚠️ 别并发写 | 原子计数 + 定时上报，不要每个协程各写一次进度 |
| 导出/导入：超大文件 | ❌ 别放在 HTTP 请求里 | 落成异步任务（`xxl-job` / MQ）+ 轮询进度，见 [../../分布式/系统设计/文件存储与上传架构.md](../../分布式/系统设计/文件存储与上传架构.md) |

> ⭐ 一句话：**并发点要选在「纯计算」和「独立 I/O」上，绝不要选在「写库」和「写响应」上。**

### 6. 实测汇总（本机 Go 1.26.5 / Windows / 8 核）

| 实验 | 读数 | 结论 |
|---|---|---|
| 状态分布 | 21 个协程 → 9 种状态 | 阻塞原因是**一等公民**，栈头直接可读 |
| 单 case `select` | `[chan receive]` 计 5（含 2 个单 case select）、`[select]` 仅 2 | **单 case select 被退化成直接收发** |
| 1 万个阻塞协程 | `threads=10`、`idleprocs=8`、`runqueue=0` | 阻塞的 G **不占 M 也不占 P** |
| 协程内 panic | 两个场景**退出码都是 2**，main 的 recover 无效 | 后台协程必须自带 recover |
| 无背压 vs 限 50 | 峰值 **3702 → 52**；耗时 46ms → 2.034s | 限并发是**用吞吐换可控性** |
| `errgroup` | 任务 2 在 200ms 失败 → 任务 3/4/5 同时被取消，总耗时 200ms | 并发组自带"一错全停" |
| **导出的三段独立查询** | 串行 ~305ms → 并发 ~205ms（**1.4~1.6x**） | 本地无网络往返，收益有限；真实库上≈最慢那一段 |
| **导出：内存拼接 vs 流式** | 20 万行 18.5MB：堆峰值 **40.0 MB → 0.0 MB**，耗时还略快 | **流式 > 并发**：签名里的 `[]byte` 才是根因 |
| **导入：逐行 vs 批量事务** | 20 万行 **14.3s → 1.77s**（8.1x）；2 万行 fsync 场景 **11.4s → 0.19s**（60x） | 导入的银弹是**批量 + 事务** |
| **导入：批量 + 8 并发** | 20 万行 **2.96s**，比单协程的 1.77s **更慢** | 写是串行的，并发只多花在抢锁上 |
| **导入：一行一个协程** | 2 万行：峰值协程 **19098**、`WaitCount=19984`、`WaitDuration=7h1m12s` | 连接池挡住 DB，但应用侧堆协程 |

`errgroup` 的原始输出（5 个任务，第 2 个在 200ms 失败）：

```text
  任务 1 完成（100ms）
  任务 4 被取消（context canceled，200ms）
  任务 5 被取消（context canceled，200ms）
  任务 3 被取消（context canceled，200ms）
g.Wait() 返回：任务 2 失败（总耗时 200ms）
```

> 实验台：goroutine 本身在 `.workbuddy/tmp/golab/`（`golab <states|threads|panic1|panic2|backpressure|errgroup>`）；
> 第 5 节的导入导出实测在 `.workbuddy/tmp/iolab/`（`iolab <exp-a|exp-b|exp-c>`，真实 `database/sql` + SQLite，
> 脚本会现场建表灌数据，跑完即可复现上表）。

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
- **`g` 结构体里存了什么？** → 四类：**栈**（`stack` / `stackguard0` 水位线）、**执行现场**（`sched gobuf`：`sp/pc/g/ctxt/lr/bp`）、**状态与原因**（`atomicstatus` / `waitreason`）、**控制位**（`preempt` 等）。切换之所以便宜，是因为只需换 `gobuf` 里这几个字，而不是整套寄存器。
- **goroutine 有哪些状态？** → `_Gidle(0)` / `_Grunnable(1)` / `_Grunning(2)` / `_Gsyscall(3)` / `_Gwaiting(4)` / `_Gdead(6)`，另有 `_Gcopystack(8)`（栈正在被复制）与 `_Gpreempted(9)`（被异步抢占后停住）。⚠️ **`_Gscan` 是位（`0x1000`）不是状态**，所以真正的读法是 `readgstatus(gp) &^ _Gscan`。
- **goroutine 阻塞时到底发生了什么？** → `gopark`：① 记 `waitreason`；② `mcall(park_m)` 切到该 M 的 g0 系统栈；③ `park_m` 把 G 置为 `_Gwaiting`、**解绑 M**，再 `schedule()` 挑下一个 G —— **M 完全没被拖住**。
- **被唤醒时呢？** → `goready` → `ready()` 三件事：`casgstatus(_Gwaiting → _Grunnable)`、`runqput(p, gp, next=true)`（进 `runnext`，优先跑）、`wakep()`（没空闲 M 就唤醒或新建）。
- **为什么阻塞一万个协程不占一万条线程？** → 实测 10001 个全阻塞在 channel 上的协程：`threads=10`、`idleprocs=8`、`runqueue=0` —— 被 park 的 G 不占 P 也不占 M。
- **单 case 的 `select` 是什么状态？** → **不是 `[select]`**。实测 `select { case <-ch: }` 被编译器退化成直接收发，状态是 `[chan receive]`；只有多路 select 才进 `[select]`。
- **goroutine 里 panic 会怎样？** → **整个进程终止**（实测两个场景退出码都是 2），且 **main 的 `defer recover()` 拦不住** —— recover 只对同一个 goroutine 的 defer 链有效。所以每个"可能 panic 的后台协程"都要自带 recover。
- **什么时候必须限并发？** → 只要调用了**容量有限的外部资源**（DB、三方 API、连接池）就必须限。实测：5000 个任务，无限制峰值协程 3702、耗时 46ms；限 50 后峰值 52、耗时 2.034s —— 限并发是**用吞吐换可控性**，不是"更快"。
- **导入导出能用 goroutine 吗？** → **要逐环节看**：多段**独立查询**能并发（实测 1.4~1.6x）；**写 CSV / 写响应**绝不能并发（`csv.Writer` 与响应都是单一有序流）→ 只能"并发取数、串行写出"；**写库**不要并发（实测"每 1000 行一个事务 + 8 并发"比单协程**更慢**）。并发点要选在**纯计算**和**独立 I/O**上。
- **导入 20 万行，怎么最快？** → 不是加协程，而是**批量 + 事务**：实测逐行插入 14.3s → 每 1000 行一个事务 1.77s（8.1 倍）；在"每次提交都 fsync"（贴近 MySQL 的 autocommit）下 2 万行是 11.4s → 0.19s（**60 倍**）。先问"一次事务能装多少行"，再谈并发。
- **导出为什么要流式？** → 项目现状是 `bytes.Buffer` + 最后 `buf.Bytes()`，整份文件在内存里拼完才返回：实测 20 万行 18.5MB 的堆峰值增量 **40MB**，而流式写 `io.Writer` 是 **0MB**、耗时还略快。根因在函数签名 `(string, []byte, error)`——要流式就得改成 `w io.Writer`。
- **无脑"一行一个 goroutine"写库会怎样？** → **不会打挂 DB，但会堆在连接池上**：实测 2 万行起 2 万个协程，峰值协程 19098、`WaitCount=19984`、`WaitDuration=7h1m12s`（累计等连接 7 小时）。连接池（`MaxOpenConns=16`）是天然闸门，但它只保证不打死 DB，不保证你的服务还健康 —— 限并发还得靠自己。
- **`errgroup` 和 `WaitGroup` 怎么选？** → 需要"**一错全停**"用 `errgroup.WithContext`（实测任务 2 在 200ms 失败后，其余任务立刻被 ctx 取消，总耗时 200ms）；只要结果、不要取消语义就用 `WaitGroup` + 按下标写切片。
- **`GOMAXPROCS=1` 还能起 10 万个 goroutine 吗？** → 能。创建不受限制，限制的只是**同时真并行的个数**（= P 的数量），见 [../运行时/GMP调度.md](../运行时/GMP调度.md)。

---

## 关联

- [../运行时/GMP调度.md](../运行时/GMP调度.md) — goroutine 被谁调度、本地队列与全局队列、抢占式调度
- [协程泄漏与死锁.md](协程泄漏与死锁.md) — 泄漏的十个现场与排查套路（本篇第八节只留判据）
- [共享内存与CSP.md](共享内存与CSP.md) — 协程之间怎么通信，两种路线的失败模式
- [并发同步原语.md](并发同步原语.md) — 加锁、原子操作与 WaitGroup
- [channel原理与底层实现.md](channel原理与底层实现.md) — goroutine 阻塞时挂到哪个队列上
- [../运行时/内存分配器.md](../运行时/内存分配器.md) — 栈和 `g` 结构体最终落到哪一级分配器
- [../运行时/函数调用与栈.md](../运行时/函数调用与栈.md) — `gobuf` 里的 sp/pc/bp 对应栈帧的哪一部分
- [../../linux/进程与线程.md](../../linux/进程与线程.md) — 线程侧的创建成本与内核结构
- [数据导入导出设计.md](../../分布式/系统设计/数据导入导出设计.md) — 导入导出的完整设计（形态选择、任务表、进度回报、幂等），本篇第五节只讲并发判据
