# Java 锁与 AQS

> `synchronized` 的锁升级路径与 monitor 语义、`ReentrantLock` 与它的取舍、AQS「一个 `state` 撑起所有同步器」的设计，
> 以及锁的五类行为陷阱（可重入计数、`tryLock` 超时、读写锁升降级、`Condition` 定向唤醒、异常释放）。
>
> 内容整理自个人学习笔记。**全部结论配套本机 JDK 1.8.0_321 实测**（Windows / 8 核）；
> 读写锁的位布局另有 `D:/java/jdk1.8/src.zip` 源码断言。
> 线程池见 [线程池.md](线程池.md)；并发容器见 [并发容器.md](并发容器.md)；
> 内存模型与 `happens-before` 见 [JMM与内存屏障.md](JMM与内存屏障.md)。

---

## 一、`synchronized` 是怎么实现的？

**本节要点**：它是**语言级的**同步（由 JVM 保证释放），实现落在对象头的 Mark Word 与 `ObjectMonitor` 上。关键要能答出「锁存在哪儿」和「为什么无竞争时它几乎不花钱」。

### 1.1 锁存在哪儿：对象头 + Monitor

`synchronized` 加锁的对象有两个可能：

| 写法 | 锁的是什么 |
|---|---|
| `synchronized void m()`（实例方法） | 当前实例 `this` |
| `static synchronized void m()` | 该类的 `Class` 对象 |
| `synchronized (obj) { }` | 显式指定的 `obj` |

⚠️ 前两种最容易被写错：**静态方法锁的是 `Class`，不是实例** —— 同一个类的两个实例互相不阻塞，但它们的静态同步方法会互相阻塞。

锁信息编码在**对象头**里。64 位 JVM 下对象头为 12 字节（Mark Word 8 字节 + Klass Pointer 4 字节，未开指针压缩时各 8 字节），Mark Word 按 **最后两位** 区分状态：

| 状态 | Mark Word 末位 | 存的是什么 |
|---|---|---|
| 无锁 | `01` | hashcode、分代年龄 |
| 偏向锁 | `01`（后三位 `101`） | **偏向的线程 ID** |
| 轻量级锁 | `00` | 指向**栈上锁记录**的指针 |
| 重量级锁 | `10` | 指向 `ObjectMonitor` 的指针 |
| GC 标记 | `11` | 空 |

重量级锁对应的 `ObjectMonitor` 有三个关键队列：

```text
ObjectMonitor
├── _owner       当前持有锁的线程（谁拿到就写谁）
├── _EntryList   等待竞争锁的线程（阻塞在锁上）
└── _WaitSet     调用过 wait() 的线程（等着被 notify）
```

⭐ 这解释了 `wait/notify` 与 `synchronized` 的关系：**`wait()` 把线程从 `_EntryList` 挪到 `_WaitSet` 并释放锁；`notify()` 把它挪回 `_EntryList` 重新竞争**。所以 `wait/notify` 必须在 `synchronized` 块里调用（否则抛 `IllegalMonitorStateException`）——它们操作的是同一个 monitor。

### 1.2 锁升级：从偏向到重量级

无竞争时用一个 CAS 就能解决，没必要一上来就做内核态阻塞。JDK 6 之后的路径是：

```text
无锁 ──首次加锁──▶ 偏向锁 ──另一线程竞争──▶ 轻量级锁 ──自旋失败/竞争加剧──▶ 重量级锁
       (CAS 记线程ID)        (CAS 抢栈上锁记录)         (park 阻塞，进 _EntryList)
```

| 阶段 | 适用 | 代价 | 失效条件 |
|---|---|---|---|
| **偏向锁** | 只有**同一个线程**反复加锁 | 一次 CAS（记下线程 ID），之后进出同步块**零 CAS** | 出现第二个线程 |
| **轻量级锁** | 竞争轻微、临界区极短 | 每次加解锁各一次 CAS + **自旋** | 自旋超过阈值或竞争加剧 |
| **重量级锁** | 竞争激烈 | 用户态→内核态切换，进 `_EntryList` 阻塞 | — |

⚠️ **锁升级不可逆**（JDK 8 的实现）：一旦升到重量级就不会降回去。所以「偶发一次高竞争」会让这个对象之后一直付重量级的代价。

### 1.3 偏向锁的默认参数（实测）

```bash
$ D:/java/jdk1.8/bin/java.exe -XX:+PrintFlagsFinal -version 2>&1 | grep -iE "BiasedLocking|UseSpinning"
```

```text
     intx BiasedLockingBulkRebiasThreshold          = 20                                  {product}
     intx BiasedLockingBulkRevokeThreshold          = 40                                  {product}
     intx BiasedLockingDecayTime                    = 25000                               {product}
     intx BiasedLockingStartupDelay                 = 4000                                {product}
     bool TraceBiasedLocking                        = false                               {product}
     bool UseBiasedLocking                          = true                                {product}
```

三个值得记住的点：

1. **`UseBiasedLocking = true` 是默认值** —— JDK 8 默认开着偏向锁；
2. **`BiasedLockingStartupDelay = 4000`** —— 启动后**延迟 4 秒**才启用。原因：JVM 启动期本身有大量并发（类加载、编译），此时偏向锁只带来撤销开销。这也意味着**「进程刚起来那几秒的同步块走的是非偏向路径」**；
3. **`BulkRebiasThreshold = 20` / `BulkRevokeThreshold = 40`** —— 同一个类的对象被撤销偏向超过 20 次就**批量重偏向**（改指向新线程），超过 40 次就**批量撤销**（这个类的对象直接禁用偏向）。这是防止「一类对象反复被两个线程争抢」时撤销开销爆炸。

> ⚠️ **本机没测出偏向锁的性能收益**。我写了一个单线程反复进出同一同步块的对照（`BiasedLockLab.java`，2 亿次迭代），想在「启动后立即跑」与「睡 5 秒后再跑」之间看出差异，结果两组是 5829 ms vs 1343 ms —— 但这个差距**来自分层编译（C2 在后期才编译到最优）**，而不是偏向锁：加 `-XX:-UseBiasedLocking` 关闭偏向锁后，同样的两组是 5635 ms vs 1207 ms，**没有变慢**。所以上面的参数值是确定的，但「偏向锁值多少」这个微基准我测不准，不做断言。

> ⚠️ **版本演进**：JDK 15 起 `UseBiasedLocking` 默认改为 `false`（JEP 374 标记废弃），JDK 18 正式移除。**JDK 8 用户才需要考虑它**，新版本上这一节只需要知道「曾经有过这个优化、后来被移除了」。

---

## 二、`ReentrantLock` 与 `synchronized` 该怎么选？

**本节要点**：`synchronized` 是默认答案，只在需要**它给不了的四件事**时才换 `Lock`。先看能力差异，再看代价。

### 2.1 能力差异只有四条

| 能力 | `synchronized` | `ReentrantLock` |
|---|---|---|
| 自动释放（异常也释放） | ✅ JVM 保证 | ❌ **必须自己 `finally`** |
| 可中断的等待 | ❌ | ✅ `lockInterruptibly()` |
| 限时尝试 | ❌ | ✅ `tryLock(timeout)` |
| 公平锁 | ❌ 只有非公平 | ✅ `new ReentrantLock(true)` |
| 多个等待条件 | ❌ 只有一个 wait set | ✅ 多个 `Condition` |
| 读写分离 | ❌ | ✅ `ReentrantReadWriteLock` |

反过来说：**这四条都用不上时，`synchronized` 是更好的选择** —— 代码更短、不会忘记释放、JIT 还能做锁消除与锁粗化。

### 2.2 实测：四种计数方式的真实开销

4 线程 × 50 万次自增、抢同一把锁，跑 3 次取范围（`LockBench.java`）：

```text
JDK 1.8.0_321 | CPU 核数 = 8
线程数 = 4，每线程迭代 = 500000，期望计数 = 2000000

裸 int++（JIT 可折叠）    0.7 ms   计数=1848592  ❌ 丢了 151408
volatile int++           49.0 ms   计数=655441  ❌ 丢了 1344559
synchronized 块          87.5 ms   计数=2000000  正确
ReentrantLock            60.5 ms   计数=2000000  正确
AtomicInteger            52.4 ms   计数=2000000  正确

单线程纯循环（对照）      6.2 ms   计数=2000000（同一总次数，无任何同步）
```

三次运行的范围：

| 方案 | 耗时范围 | 计数 | 结论 |
|---|---|---|---|
| 裸 `int++` | 0.7 ~ 1.0 ms | 52.7 万 ~ 185 万 | ⚠️ **数据不可信**，见下方陷阱 |
| `volatile int++` | 49.0 ~ 55.7 ms | 64.7 万 ~ 72.4 万 | ❌ **不原子，丢掉约 2/3 更新** |
| `synchronized` | 83.9 ~ 87.5 ms | 200 万 ✓ | 正确 |
| `ReentrantLock` | 60.2 ~ 60.5 ms | 200 万 ✓ | 正确 |
| `AtomicInteger` | 48.6 ~ 53.2 ms | 200 万 ✓ | 正确，最快 |

⭐ 两条可以直接下结论的：

1. **`volatile` 不保证原子性** —— 4 个线程各加 50 万次，最终只数到 65 万左右（丢 2/3）。`volatile++` 是「读-改-写」三步，`volatile` 只保证**可见性与有序性**，不保证这三步不被交错。**要原子就用 `AtomicInteger`，不要用 `volatile` 计数。**
2. **竞争激烈时 `AtomicInteger` < `ReentrantLock` < `synchronized`** —— 本机约 52 ms / 60 ms / 86 ms。⚠️ 这个顺序**只在这个场景成立**：4 线程抢同一把锁、临界区里只有一句自增。`synchronized` 慢是因为它把线程送进 `_EntryList` 阻塞（涉及系统调用），而 `AtomicInteger` 是在用户态 CAS 自旋。**临界区里有真实工作时，这个差距会被淹没，别拿它当选型依据。**

⚠️ **微基准陷阱（本次实测亲自踩到）**：「裸 `int++`」那组只花 **0.7 ms**，比单线程纯循环的 6.2 ms 还快 8 倍 —— 这在物理上不可能。原因是 **JIT 检测到这个循环里没有任何同步点或对外可见的副作用，直接把 50 万次自增折叠成了一次加法**。所以「不加锁最快」这个结论在这个表里**完全无效**。修法是让循环体产生 JIT 无法消除的副作用（本例用 `volatile` 字段就消除了折叠，代价是那组变成了 49 ms）。**看到「快得反常」的基准数据，先怀疑它被优化掉了。**

### 2.3 公平锁的代价：约 300 倍

`ReentrantLock` 的公平模式让线程严格按入队顺序获取锁。代价在竞争激烈时非常可观（`AqsLab.java` 实验 2，4 线程抢同一把锁）：

```text
== 实验 2：公平锁 vs 非公平锁（4 线程抢同一把锁）==
  非公平   （fair=false）     24.1 ms   计数=800000
  公平    （fair=true）   7369.3 ms   计数=800000
```

三次运行：非公平 **23.9 ~ 24.3 ms**，公平 **7369 ~ 8926 ms** —— 差距约 **300 ~ 370 倍**。

原因：公平锁在 `tryAcquire` 里多一次 `hasQueuedPredecessors()` 检查（发现有人排队就必须让路），拿到锁后每次都会走到 park/unpark 的**系统调用**；非公平锁允许刚到达的线程直接 CAS 抢一次（**barging，插队**），抢到就不用进内核。

⭐ **所以「要不要公平」的判据是**：公平锁换来的是「不会饿死」，付出的是吞吐量断崖式下降。**绝大多数业务用非公平**（`synchronized` 本身就是非公平）；只有「任务必须按提交顺序执行、且量不大」时才开公平。

⚠️ 这个实验还有个副作用值得记：**公平锁下也要小心「顺序 ≠ 启动顺序」**。第一次写这个实验时，我让 10 个线程同时 `start()` 后一起抢锁，公平锁给出的顺序是 `[0,1,2,3,4,9,6,7,8,5]` —— 因为**入队顺序本身**就不是 0~9（线程启动有快慢）。改成「逐个启动、每个间隔 30 ms 确保已入队」之后，公平锁稳定给出 `[0..9]`，非公平锁在这个场景下也是 `[0..9]`（它的不公平体现在**新到达者插队**，而这里所有线程都已入队，复现不出来）。

---

## 三、AQS：一个 `state` 撑起所有同步器

**本节要点**：`AbstractQueuedSynchronizer` 是整个 `java.util.concurrent` 的底座。**一个 `int` 状态 + 一条 FIFO 队列 + 四个模板方法**，就同时实现了锁、信号量、倒计时门闩、读写锁。理解 AQS 的关键是接受「同一个 `state` 字段在不同子类里有完全不同的语义」。

### 3.1 骨架：一个 state + 一条队列

```text
AbstractQueuedSynchronizer
├── volatile int state            ← 子类各自赋予语义（就是「资源」）
└── Node head / tail              ← CLH 变体的双向队列，存等不到资源的线程
        Node { waitStatus, prev, next, thread }
```

线程获取不到资源时**不会盲目重试**，而是被包成 `Node` 挂到队尾，然后在 `acquireQueued` 里「先自旋试几次，不行就 `LockSupport.park()`」——这就是 2.2 里说的「AQS 的获取是两段式」。

### 3.2 一个字段、四种语义（反射实证）

`state` 是 `private`，但 JDK 8 没有模块系统，反射可以直接读。`StateLab.java` 把它们全打出来了：

```text
== CountDownLatch(3)：state 就是「还剩几次 countDown」==
  初始            state = 3
  countDown() 后  state = 2
  再两次后        state = 0  → await() 立即返回
  ⚠️ 单向计数：到 0 就不能再复位，复用一个 latch 只能用一次

== Semaphore(5)：state 就是「还剩几个许可」==
  初始            state = 5（availablePermits=5）
  acquire(2) 后   state = 3（availablePermits=3）
  release(1) 后   state = 4  → 与 CountDownLatch 相反：可增可减

== ReentrantLock：state 就是「同一个线程重入了几次」==
  未加锁          state = 0
  lock() 1 次     state = 1（holdCount=1）
  lock() 2 次     state = 2（holdCount=2）
  unlock 完        state = 0  → 0 才真正释放，这就是「可重入」的实现

== ReentrantReadWriteLock：一个 int 拆两半 —— 高 16 位 = 读锁数，低 16 位 = 写重入数 ==
   （依据源码：sharedCount(c) = c >>> 16；exclusiveCount(c) = c & 0xFFFF）
  1 个读锁        state = 65536  (0x10000)  高16位(读)=1 低16位(写)=0
  2 个读锁（可重复拿）state = 131072  高16位(读)=2 低16位(写)=0
  写锁重入 2 次    state = 2  (0x2)
  → 高 16 位(读) = 0，低 16 位(写) = 2
```

这张表把 AQS 的抽象力说透了 —— **同一个 `state` 字段**：

| 同步器 | `state` 的语义 | 方向 |
|---|---|---|
| `CountDownLatch` | 还剩几次 `countDown` | 只减不增，到 0 一次性放行**所有**等待者 |
| `Semaphore` | 还剩几个许可 | 可减可增 |
| `ReentrantLock` | 当前线程重入了几次 | 0 = 未持有 |
| `ReentrantReadWriteLock` | 高 16 位读计数 + 低 16 位写计数 | 一个字段编码两件事 |

读写锁的位布局有源码断言（`D:/java/jdk1.8/src.zip` 里 `ReentrantReadWriteLock.java` 原样摘出）：

```java
static final int SHARED_SHIFT   = 16;
static final int SHARED_UNIT    = (1 << SHARED_SHIFT);
static final int MAX_COUNT      = (1 << SHARED_SHIFT) - 1;
static final int EXCLUSIVE_MASK = (1 << SHARED_SHIFT) - 1;
static int sharedCount(int c)    { return c >>> SHARED_SHIFT; }
static int exclusiveCount(int c) { return c & EXCLUSIVE_MASK; }
```

⭐ 由此得出「读共享、写独占」的实现判据：**拿读锁要求低 16 位（写计数）为 0；拿写锁要求整个 `state` 为 0。** `MAX_COUNT = 65535` 也解释了为什么「读锁最多 65535 个、写锁最多重入 65535 次」——这不是魔法数字，是 16 位字段的上限。

### 3.3 手写一个 AQS 子类

AQS 用的是**模板方法模式**：父类管排队、阻塞、唤醒，子类只回答「现在能不能拿到 / 怎么释放」。只实现两个方法就是一个可用的互斥锁：

```java
static class Mutex extends AbstractQueuedSynchronizer {
    @Override
    protected boolean tryAcquire(int arg) {
        if (compareAndSetState(0, 1)) {          // 0→1 抢到
            setExclusiveOwnerThread(Thread.currentThread());
            return true;
        }
        return false;                            // 抢不到 → 交给 AQS 排队并 park
    }

    @Override
    protected boolean tryRelease(int arg) {
        if (getState() == 0) throw new IllegalMonitorStateException();
        setExclusiveOwnerThread(null);
        setState(0);                             // ⚠️ 先改 owner 再放 state，避免窗口期
        return true;
    }

    @Override
    protected boolean isHeldExclusively() { return getState() == 1; }

    void lock()   { acquire(1); }
    void unlock() { release(1); }
}
```

4 线程各 20 万次自增，结果 `期望 = 800000，实际 = 800000 → 正确`，且释放后 `等待队列长度 = 0`（`AqsLab.java` 实验 1）。

**注意写 `tryAcquire` 时只用了 `compareAndSetState`，不用 `synchronized`** —— 因为 `state` 是 `volatile`、CAS 是原子的，而「谁持有」这个信息存在 `exclusiveOwnerThread` 里，只在 `state` 成功从 0 变 1 的那个线程手里写。这就是 AQS 能不加锁实现锁的原因。

子类要实现的四个模板方法：

| 方法 | 用在哪 | 语义 |
|---|---|---|
| `tryAcquire` / `tryRelease` | **独占**模式 | 返回「这次尝试成功了吗」 |
| `tryAcquireShared` / `tryReleaseShared` | **共享**模式 | 返回值 ≥0 表示成功（还能再给几个） |
| `isHeldExclusively` | 仅 `Condition` 需要 | 当前线程是否独占 |
| `newCondition` | 需要 `await/signal` 时 | 交给子类提供 |

### 3.4 独占与共享：为什么 `CountDownLatch` 能一次放行所有线程

- **独占**：`acquire → tryAcquire → 失败则入队`，同一时刻只有一个线程拿到。`ReentrantLock` 属于这类。
- **共享**：`acquireShared` 返回值 ≥ 0 时，除了自己继续跑，还会**唤醒后继节点**（`setHeadAndPropagate`）。所以 `CountDownLatch` 到 0 的那一刻，队列上所有等待者会**接连醒来**（一个传一个，不需要 `notifyAll`）。`Semaphore`、`CountDownLatch`、`ReadLock` 都是共享模式。

---

## 四、CAS 与原子类

**本节要点**：CAS 是 AQS 与所有原子类的基石。要能说清它的三条组成、它的两个经典缺陷，以及它与锁的分工。

### 4.1 CAS 做了什么

`AtomicInteger.incrementAndGet()` 的循环本质上就是：

```java
do {
    int old = get();                       // ① volatile 读，拿到最新值
} while (!compareAndSet(old, old + 1));     // ② 比较 + ③ 写入：一个原子指令
```

三步合成一条 CPU 指令（x86 上是 `lock cmpxchg`），由 `Unsafe` 暴露给 Java。**没有 CAS，就得用锁；有了 CAS，无竞争时完全在用户态完成。**

⭐ 与 2.2 的实测呼应：`AtomicInteger`（52 ms）明显快于 `synchronized`（86 ms），**因为它从不进内核**。但代价是竞争激烈时大量线程在同一变量上自旋空转 —— 所以 JDK 8 加了 `LongAdder`：把热点变量拆成多个 `Cell`，各线程更新各自的、求和时才汇总，**用空间换掉了自旋冲突**。计数场景（`LongAdder` 能接受短暂不一致）优先用它，需要精确瞬时值才用 `AtomicLong`。

### 4.2 两个经典缺陷

**① ABA 问题**。CAS 只比较「值是否还是 old」，不关心「中间有没有被改回来」。场景：线程 A 读到 `100`，线程 B 把它改成 `200` 又改回 `100`，A 的 CAS 成功 —— 但状态其实已经变了。对**值类型计数器**通常无害，对**链表/栈的节点引用**则是致命的（节点已被弹出又复用，指针看似没变）。解法是 `AtomicStampedReference`（每改一次版本号 +1，比较「值 + 版本」）或 `AtomicMarkableReference`。

**② 自旋的代价**。CAS 失败会重试，高竞争下大量线程反复重试会**空耗 CPU**。这正是 AQS 采用「两段式」的原因：**先自旋试几次，不行就 park 让出 CPU**，而不是无限自旋。

### 4.3 与锁的分工

| 场景 | 选择 |
|---|---|
| 单个变量的读-改-写（计数、状态位、指针替换） | **CAS / 原子类** |
| 不可变对象的整体替换（COW 快照） | **`AtomicReference`** |
| 多个变量要一起改（复合操作） | **锁**（CAS 保不住跨变量的一致性） |
| 临界区里有 I/O 或长计算 | **锁**（自旋纯属浪费） |

---

## 五、锁的五类行为陷阱（实测）

**本节要点**：这些是**确定性的行为**（不依赖 JIT，可精确断言），比微基准更值得背下来。全部来自 `BehaviorLab.java`。

### 5.1 异常释放：`synchronized` 自动、`Lock` 必须自己写

```text
A synchronized 异常后锁是否释放：已释放（monitor 由 JVM 保证退出）
B ReentrantLock 未在 finally 释放 → lock() 后 isLocked=true holdCount=1，当前线程 tryLock=true（同一线程可重入拿到）
  其它线程会永久阻塞 —— 这是「Lock 必须写 finally」的原因
```

`synchronized` 块抛异常时，`monitorenter` 有对应的字节码级异常表保证 `monitorexit`，**锁一定释放**。`ReentrantLock` 没有这层保证：`lock()` 后没走到 `unlock()`，锁就被永久占用，**别的线程会一直阻塞**（注意 `isLocked=true`、`holdCount=1`，但**当前线程自己** `tryLock()` 还能成功 —— 因为可重入，这也意味着**用 `tryLock` 自测发现不了这个 bug**）。

### 5.2 可重入计数与 `unlock` 次数

```text
C 可重入：
  第 1 次 lock  holdCount=1
  第 2 次 lock  holdCount=2
  第 1 次 unlock holdCount=1（还在持锁）
  第 2 次 unlock holdCount=0 isLocked=false
  实际：抛了 IllegalMonitorStateException ✓
```

`unlock()` 次数多于 `lock()` 抛 `IllegalMonitorStateException`（**不是** `IllegalStateException`）。这也是为什么加解锁必须**成对且在同一层次**——一旦某个分支提前 `return` 漏掉 `unlock`，下一个 `unlock` 就会炸在这里。

### 5.3 `tryLock(timeout)` 是真的会放弃

```text
D 锁被别人持有时 tryLock(300ms)：实际等待 315 ms，返回 false（≈300ms 后放弃；对照同一线程自己持锁时会因可重入立刻返回 true）
```

实测 310 ~ 316 ms，与设定的 300 ms 吻合（多出的十几毫秒是调度与唤醒开销）。⭐ **写这个实验时踩了一个坑**：第一版让**主线程自己**先 `lock()` 再 `tryLock(300ms)`，结果是 `等待 5 ms，返回 true` —— 因为**同一线程可重入**，`tryLock` 直接成功，根本测不出超时。要测超时必须让**另一个线程**持锁。

### 5.4 读写锁：读不能升写，写能降读

```text
F 读写锁的升级/降级：
  持有读锁时 tryLock 写锁 = false  → 读锁不能升级为写锁（tryLock 直接失败，用 lock() 会死锁）
  持写锁时再拿读锁 = 可以（这就是锁降级）；当前 writeHoldCount=1 readHoldCount=1
```

- **读锁 → 写锁：不允许。** 若两个线程都持有读锁、都去申请写锁，会互相等对方释放读锁 → **死锁**。所以 `tryLock` 直接返回 `false`，而用 `lock()` 会挂住。
- **写锁 → 读锁：允许（锁降级）。** 持有写锁时再拿读锁一定成功（写锁是独占的，没人能持有读锁）。典型用法是「改完数据后在持锁状态下读一遍」，读的时候别的线程仍不能写。

### 5.5 `Condition` 的定向唤醒

```text
G Condition 的精确唤醒（两个 Condition 分开唤醒生产者/消费者）：
  入队 1 个后 signalAll(notEmpty)，队列 = [A]
  等 notFull 的线程数 = 0（各不相同 → 定向唤醒）
```

`synchronized` 只有**一个** wait set，`notifyAll` 会把生产者与消费者**一起唤醒**再重新竞争（大量无效唤醒）。`ReentrantLock` 可以 `newCondition()` 出多个队列：生产者在 `notFull` 上等、消费者在 `notEmpty` 上等，`signal` 只叫醒该醒的那批。上面实测里「等 `notFull` 的线程数 = 0」就是这个机制的直接体现。

> 这也是「生产者-消费者为什么优先用 `BlockingQueue` 而不是自己 `wait/notify`」的答案 —— `ArrayBlockingQueue` 内部就是 `ReentrantLock` + 两个 `Condition` 把这件事做完了。

---

## 使用：弹幕系统的连接表，为什么读侧一把锁都不用

本项目里**真实存在**的 Java 并发代码在 [弹幕系统的Java实现.md](../../分布式/系统设计/弹幕系统的Java实现.md)（同一套四层的 Java 版）。拿其中「房间 → 订阅者列表」的维护逐环节拆：

**第一步：确定读写的比例。** 弹幕是典型的**读极多、写极少** —— 每条弹幕推送都要遍历一次订阅者列表（读），而 `join` / `leave` 只在用户上下线时发生（写）。这个比例直接决定了「不能让读侧也加锁」。

**第二步：把「多个字段」压成「一个不可变对象」。** 如果写成 `List<Sub> subs` 然后读侧加锁遍历，锁就被读流量独占。真实写法是把它变成一个**不可变快照**：

```java
private final AtomicReference<List<Sub>> snap =
        new AtomicReference<>(Collections.emptyList());
```

读侧 `snap.get()` 一次原子读拿到列表引用，**后面遍历全程无锁** —— 因为列表内容不会被原地修改，读到的永远是一个自洽的版本。

**第三步：写侧老老实实加锁。** 写是「读-改-写」（取出旧列表 → 造新列表 → 换回去），跨了两步，**CAS 单独保不住**（这正是 4.3 表里「多个变量要一起改 → 用锁」的情形）。所以真实代码里 `join` / `leave` 是 `synchronized` 方法：

```java
synchronized void join(Sub s) { /* 造新列表 → snap.set(...) */ }
```

**第四步：明确不做什么。** ⚠️ 最容易犯的错是「用 `Collections.synchronizedList` 然后在原地 `add/remove`」—— 那样**快照语义就完全丢了**：读侧拿到的引用不变、但内容在被并发修改，遍历时可能看到写者的中间状态甚至抛 `ConcurrentModificationException`。**加锁保护的是「写」，不是「读」；读侧安全的前提是「内容不可变」，不是「列表线程安全」。**

**第五步：队列那一侧用同一套判据。** 投递路径用的是**有界 `ArrayBlockingQueue` + `offer`**（非阻塞，满了立刻返回 `false`）而不是 `put`（满了阻塞投递线程）。这与「读侧不能等」是同一条原则的两面：**推送路径上任何可能阻塞的操作，都会把慢客户端的问题传染给整个房间**。

⭐ 归纳成一句可复用的话：**热点路径上只用「原子读 + 不可变值」；跨步骤的复合写才动用锁；而锁的保护范围要精确到「写」，别顺手把读也圈进去。**

---

## 延伸追问

- **`synchronized` 和 `ReentrantLock` 哪个快？** → 要看竞争程度。本机 4 线程抢同一把锁、临界区只有一句自增时是 `ReentrantLock` 快约 40%（60 ms vs 86 ms）；但**无竞争时 `synchronized` 更快**（偏向锁让它近乎零成本），且临界区有真实工作后差距被淹没。**别拿微基准当选型依据，按 2.1 的四条能力差异来选。**
- **为什么 `wait/notify` 必须在 `synchronized` 块里？** → 它们操作的是对象的 `ObjectMonitor`（`_EntryList` ↔ `_WaitSet`），不在同步块里就没有 monitor 可操作（抛 `IllegalMonitorStateException`）。更深一层：`wait` 要「释放锁 + 挂起」原子完成，否则会丢通知。
- **`state` 为什么用 `int` 而不是 `long`？** → 64 位 CAS 在 32 位平台上无法保证原子（且需要内存对齐）。`int` 是跨平台都能原子 CAS 的宽度；读写锁把两个计数塞进 16 + 16 位，也是在这个约束下做的设计。需要 `long` 状态的场景（如 `StampedLock`）会另想办法。
- **AQS 的队列为什么叫「CLH 变体」？** → 原始 CLH 锁是「自旋在前驱节点上」的单向链表；AQS 改成了**双向链表**（释放时要唤醒后继，必须能向后找），并且把「自旋」换成「自旋几次后 park」，以适应「临界区可能很长」的通用场景。
- **公平锁一定不会饿死吗？** → 是的（这就是它存在的唯一理由），代价是吞吐量下降约 300 倍（本机实测）。反过来**非公平锁理论上能饿死某个线程**，但实践中概率极低，所以默认都用非公平。
- **`synchronized` 加在静态方法上和普通方法上有什么区别？** → 静态方法锁的是 `Class` 对象，所有实例共用同一把锁；普通方法锁的是 `this`，不同实例互不影响。**混用这两种的类很容易出现「以为加了锁却还能并发」的 bug。**
- **为什么 `unlock()` 推荐写在 `finally` 的第一行而不是最后一行？** → `finally` 里如果 `unlock` 之前还有别的语句且它抛异常，锁就泄漏了。放在第一行能保证「无论 try 块怎么退出，锁都会被放掉」。
- **`LongAdder` 为什么比 `AtomicLong` 快，代价是什么？** → 它把热点拆成多个 `Cell` 分散自旋冲突（空间换竞争），代价是**求和不是瞬时精确值**（累加过程中读到的可能偏小）。计数类指标用 `LongAdder`，需要精确值的序列号生成用 `AtomicLong`。

## 关联

- [线程池.md](线程池.md) — 同一目录：`ThreadPoolExecutor` 内部用的也是 `ReentrantLock` + `Condition`，本篇的锁语义是读懂它的前置
- [并发容器.md](并发容器.md) — `ConcurrentHashMap` 的桶级 `synchronized`、`BlockingQueue` 的双 `Condition`，都用的是本篇这套机制
- [JMM与内存屏障.md](JMM与内存屏障.md) — `volatile` 的可见性与有序性为什么保不住原子性（本篇 2.2 的实测正是它的反面证据）
- [JVM与垃圾回收.md](../运行时/JVM与垃圾回收.md) — 锁与 GC 共享同一套「对象头」结构：偏向锁占 Mark Word，GC 分代年龄也在里面
- [运行时数据区与栈帧.md](../运行时/运行时数据区与栈帧.md) — 轻量级锁的「锁记录」就分配在线程栈上
- [并发同步原语.md](../../go/并发/并发同步原语.md) — 同主题的 Go 侧：`Mutex` 的饥饿模式与 `sync.Map`，与本文的公平锁 / `ConcurrentHashMap` 正好对照
- [../../分布式/系统设计/弹幕系统的Java实现.md](../../分布式/系统设计/弹幕系统的Java实现.md) — 「使用」一节的代码出处：`AtomicReference<List<Sub>>` 快照 + `synchronized` 写侧
- [../../分布式/服务发现的Java实现.md](../../分布式/服务发现的Java实现.md) — 同一手法的另一处落地：不可变快照 + `ConcurrentHashMap` + `AtomicInteger` 轮询
> 反向引用（本篇被下列文档引到）：[Spring-boot核心.md](../Spring-boot核心.md)
