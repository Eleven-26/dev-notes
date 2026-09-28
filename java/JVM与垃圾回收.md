# JVM 与垃圾回收

> 分代式 GC 的完整实现视角：堆怎么分代、一次 Minor / Major / Full GC 各做了什么、对象何时进老年代、
> 收集器谱系与版本演进、GC 日志怎么读、堆与代比例怎么调、诊断三件套的实操。
>
> 内容整理自个人学习笔记，**全部结论配套本机 JDK 1.8.0_321 实测**（Windows / server class / 8 核）。
>
> 本篇只讲 **JVM 自己的 GC 实现**；算法层的通用原理（判定垃圾的两条路线与根集合、标记-清除 / 复制 /
> 压缩 / 分代的代价、三色抽象与写屏障、安全点）见 [../垃圾回收/](../垃圾回收/)，类与运行时数据区见
> [类加载机制.md](类加载机制.md)、[运行时数据区与栈帧.md](运行时数据区与栈帧.md)。

---

## 一、JVM 的 GC 是什么类型？和 Go 有什么不一样？

**本节要点**：能一句话点出"**分代 + 复制/压缩 + 停顿型为主**"，并说明这套取舍换来了什么。

### 1.1 四个特征

| 特征 | 说明 |
|---|---|
| **分代** | 堆按对象存活期切成新生代 / 老年代，**各代用不同算法**：新生代复制、老年代标记-清除或标记-压缩 |
| **会压缩（会移动对象）** | 复制与标记-压缩都会改对象地址，所以**没有外部碎片**；代价是移动时必须在安全点上停住全场 |
| **以停顿型为主** | Minor GC 与 Full GC 都是 STW；CMS / G1 / ZGC 把**部分**工作搬到并发阶段，但仍有 STW 段 |
| **目标函数是吞吐与停顿的平衡** | 默认收集器（Parallel）明确偏向吞吐，可通过换收集器与参数把权重移向停顿 |

> 判定垃圾用**可达性分析**（不是引用计数），标记过程就是三色标记 —— 原理见
> [../垃圾回收/GC基础算法.md](../垃圾回收/GC基础算法.md) 第二节与
> [../垃圾回收/并发标记与写屏障.md](../垃圾回收/并发标记与写屏障.md)。

### 1.2 一次 Minor GC 的完整流程

新生代 = 1 个 Eden + 2 个 Survivor，同一时刻只有一个 Survivor 在用（记作 from，另一个是 to）：

```text
① 新对象在 Eden 分配（走 TLAB，见 2.5）
② Eden 满 → 触发 Minor GC（日志里的 Allocation Failure）→ 全场到达安全点（STW）
③ 从 GC Roots 出发标记存活对象（新生代 GC 的根还要加上「老年代指向新生代的引用」，靠卡表找）
④ 存活的复制到 to 空间，年龄 +1；达到阈值（或触发动态年龄判定）的直接晋升老年代
⑤ 清空 Eden 与 from，from / to 角色互换
⑥ 解除 STW，用户线程继续
```

⭐ 三个要点：① 是**复制**不是标记清除，所以存活对象少时极快；② 新生代的根**不只 GC Roots**，还要把"老年代 → 新生代"的引用当作根（卡表就是干这个的，原理见 [../垃圾回收/复制与分代回收.md](../垃圾回收/复制与分代回收.md)）；③ 复制前要先确认老年代装得下晋升对象，否则要走**空间分配担保**（见 2.6）。

### 1.3 一次 Full GC（Major GC）在做什么

- 收集**整堆**（新生代 + 老年代 + 元空间），老年代用标记-压缩，**通常会压缩** → 停顿最贵；
- 触发原因在日志里写得很清楚，实测见到过的几种：`Ergonomics`（自适应策略判定该收了）、分配失败、`Metadata GC Threshold`、显式 `System.gc()`、CMS 的并发失败退化；
- ⭐ **看 Full GC 值不值，只看一个数**：老年代回收前后的差值。实测 -Xmx48m 那次的日志——

```text
[Full GC (Ergonomics) [PSYoungGen: 1024K->0K(14336K)] [ParOldGen: 31468K->31460K(32768K)] 32492K->31460K(47104K), ..., 0.0043090 secs]
[Full GC (Ergonomics) [PSYoungGen: 11504K->0K(14336K)] [ParOldGen: 31460K->31350K(32768K)] 42965K->31350K(47104K), ..., 0.0107810 secs]
[Full GC (Ergonomics) [PSYoungGen: 11497K->0K(14336K)] [ParOldGen: 31350K->32374K(32768K)] 42847K->32374K(47104K), ..., 0.0030506 secs]
```

`ParOldGen: 31468K -> 31460K` —— **收了半天只回收 8 KB**；第三次甚至 `31350K -> 32374K` 反而涨了（新生代对象被晋升进来）。
这就是"**Full GC 频繁但收不动 = 存活集已经顶到堆上限**"的教科书信号：此时调收集器没用，**只能加堆或减对象**。

（复核实跑该场景时读到 `31296K->31272K`，差 24 KB —— 具体数字每轮不同，但"回收量接近 0"这个特征是稳定的。）

### 1.4 与 Go 的对照

| 维度 | JVM（JDK 8 默认 Parallel；G1/ZGC 同理） | Go |
|---|---|---|
| 判定垃圾 | 可达性分析 | 可达性分析 |
| 分代 | **有**（新生代 / 老年代） | **无**（一次全堆标记） |
| 压缩 / 移动 | 复制与标记-压缩，**会移动** | 标记-清除，**不移动** |
| 写屏障税 | 卡表 + SATB / 增量更新 | 混合写屏障 |
| 触发方式 | **按代容量**（Eden 满即 Minor GC；老年代水位触发并发周期） | 按**目标堆** `存活堆 × (1 + GOGC/100)` |
| 停顿 | Minor GC / Full GC 都是 STW，停顿与**存活集**成正比 | 只有两次极短的 STW，标记与清扫并发 |
| 调优主旋钮 | `-Xmx` / 新生代比例 / 收集器选择 / `MaxGCPauseMillis` | `GOGC` / `GOMEMLIMIT` |

> ⭐ 一句话对比：**JVM 用"分代 + 复制"减少工作量、用压缩换无碎片，接受较长的停顿；
> Go 放弃这两件武器，只保留"并发标记 + 并发清除"，把代价换算成内存水位与碎片。**
> Go 侧的完整实测见 [../go/运行时/垃圾回收机制.md](../go/运行时/垃圾回收机制.md)。

---

## 二、堆是怎么分代的？参数怎么算？

**本节要点**："8:1:1"人人会背，关键在能不能说清**它什么时候不成立**、以及**默认值从哪来**。

### 2.1 布局

```text
┌──────────────── 新生代（Young，默认占堆 1/3）────────────────┐ ┌──── 老年代（Old，2/3）────┐
│      Eden (8)      │ Survivor from (1) │ Survivor to (1)     │ │      标记-压缩 / 标记-清除    │
└────────────────────┴───────────────────┴─────────────────────┘ └───────────────────────────┘
     NewRatio = 2 → 新生代 : 老年代 = 1 : 2
     SurvivorRatio = 8 → Eden : 单块 Survivor = 8 : 1（于是 8:1:1）
```

### 2.2 实测：默认值是从哪来的

```bash
java -XX:+PrintFlagsFinal -version | grep -E "UseParallelGC|InitialHeapSize|MaxHeapSize|NewRatio|SurvivorRatio|MaxTenuringThreshold|UseAdaptiveSizePolicy"
```

```text
uintx InitialHeapSize          := 268435456          （256 MB ≈ 物理内存的 1/64）
uintx MaxHeapSize              := 4263510016         （≈ 4 GB = 物理内存的 1/4）
uintx NewRatio                  = 2
uintx SurvivorRatio             = 8
uintx TargetSurvivorRatio       = 50
uintx MaxTenuringThreshold      = 15
uintx PretenureSizeThreshold    = 0
uintx ParallelGCThreads         = 8
bool  UseAdaptiveSizePolicy     = true                （⚠️ 就是它，见 2.3）
bool  UseTLAB                   = true
bool  UseParallelGC            := true                （:= 表示由 ergonomics 自动选择，不是 flag 默认值）
bool  UseParallelOldGC         := true
```

两处要读懂的地方：

- **`:=` 与 `=` 的区别**：`PrintFlagsFinal` 输出里带 `:=` 的行是**被 ergonomics 自动设置**的（JVM 按机器 CPU / 内存自己选的），`=` 才是写在源码里的默认值。所以"JDK 8 默认用 Parallel"这句话严格说是"**server class 机器上 ergonomics 选了 Parallel**"；
- **堆的上下限来自物理内存**：初始堆取 1/64、最大堆取 1/4（实测本机 15.9 GB 内存 → 256 MB / 4 GB）。容器里部署必须显式 `-Xmx`，否则 JVM 看到的是宿主机内存（JDK 8u191+ 才有 `UseContainerSupport`）。

### 2.3 ⚠️ 实测：自适应策略会改掉你的 8:1:1

`UseAdaptiveSizePolicy=true` 时，JVM 会**动态调整新生代内部比例与晋升阈值**去凑停顿 / 吞吐目标。同一份压力（512 MB 分配、存活 32 MB）下，GC 日志结尾的堆快照：

```text
# 默认（UseAdaptiveSizePolicy=true）
 PSYoungGen      total 73216K, used 35502K
  eden space 59392K, 42% used        ┐
  from space 13824K, 74% used        ├─ 59392 : 13824 ≈ 4.3 : 1   ← 不是 8:1！
  to   space 13824K, 0% used         ┘
 ParOldGen       total 175104K, used 22236K

# 加上 -XX:-UseAdaptiveSizePolicy -XX:SurvivorRatio=8
 PSYoungGen      total 78336K, used 58266K
  eden space 69632K, 71% used        ┐
  from space 8704K, 99% used         ├─ 69632 : 8704 = 8.00 : 1   ← 正好 8:1
  to   space 8704K, 0% used          ┘
 ParOldGen       total 175104K, used 22784K
```

⭐ **结论：不关自适应，"8:1:1" 只是个名义值**。实测里 Eden 被压到 4 份左右、Survivor 被撑大 —— 因为幸存对象多，JVM 怕 Survivor 装不下而提前晋升。（这个比例每轮会变：另一次实测是 `57344K / 14848K ≈ 3.86`；而关掉自适应后的 8.00 是**精确可复现**的。）

同理，**晋升阈值也不是设的 15**。打开 `-XX:+PrintTenuringDistribution`：

```text
Desired survivor size 11010048 bytes, new threshold 7 (max 15)
...
Desired survivor size 16252928 bytes, new threshold 6 (max 15)
```

`max 15` 是你设的上限，`new threshold 7` 是**本次真正生效的阈值** —— 这就是**动态年龄判定**：若 survivor 中「年龄 ≤ N 的对象总和」超过 survivor 空间的一半（`TargetSurvivorRatio=50`），直接把阈值降到 N，不等 15 了。

### 2.4 对象什么时候进老年代

| 路径 | 条件 | 备注 |
|---|---|---|
| **年龄达标** | 每熬过一次 Minor GC 年龄 +1，达到 `MaxTenuringThreshold`（默认 15）后晋升 | 实测常被动态年龄判定提前到 6~7 |
| **动态年龄判定** | survivor 中「年龄 ≤ N 的对象总和」> survivor 空间的 50% | 见 2.3 实测，可随时改阈值 |
| **大对象直接进入** | 超过 `-XX:PretenureSizeThreshold` 的对象直接在老年代分配 | ⚠️ **对 Parallel Scavenge 无效**，见下 |
| **空间分配担保** | 晋升时老年代放不下 | 担保失败就走 Full GC |
| **TLAB 装不下的大数组** | 走「慢路径」直接向 Eden 申请 | 与上面一条不同，它仍在新生代 |

⚠️ **实测踩到的坑：`-XX:PretenureSizeThreshold` 只认可 Serial / ParNew 两个收集器**。同一段代码（分配 3 个 4 MB 大对象）用 `jstat -gc` 读老年代用量：

```text
# 默认 Parallel + -XX:PretenureSizeThreshold=2m
  EC 16384.0  EU 13938.1   OC 44032.0  OU      0.0     ← 12 MB 全在 Eden，阈值被忽略
# -XX:+UseSerialGC -XX:PretenureSizeThreshold=2m
  EC 17472.0  EU  1749.9   OC 43712.0  OU 12288.0     ← 12 MB 全在老年代，阈值生效
```

> 顺手确认：该参数的单位后缀 `2m` / `2M` / `2097152` 都会被正确解析成 `2097152` 字节。

### 2.5 TLAB：为什么小对象分配这么快

`UseTLAB=true`（默认）时，每个线程在 Eden 里划走一小块**私有**的分配缓冲，小对象分配只需"指针 + 大小"的本地运算，不需要任何同步。实测（`-XX:+PrintTLAB`）：

```text
TLAB: gc thread: [id: 17820] desired_size: 1310KB slow allocs: 63  refill waste: 22984B alloc: 1.00000 64800KB refills: 1 waste 34.4% gc: 462096B
TLAB totals: thrds: 1  refills: 1 max: 1 slow allocs: 63 max 63 waste: 34.4% gc: 462096B
```

- `desired_size: 1310KB` —— 本次 TLAB 约 1.3 MB（会随分配速率自适应）；
- `slow allocs: 63` —— 有 63 次**慢路径**分配：对象比 TLAB 剩余空间大（本实验每块都是 1 MB），只能直接找 Eden；
- `waste 34.4%` —— TLAB 尾部用不上的碎片，占总分配的三分之一；下一次采样已经降到 21.1%，说明 JVM 在按浪费率调整 TLAB 大小。

### 2.6 空间分配担保

Minor GC 前，JVM 要先确认"**最坏情况下**（新生代所有对象都晋升）老年代装得下"，装不下就不做 Minor GC 而直接做 Full GC。JDK 8 里 `HandlePromotionFailure` 这个开关**已经被移除**（`PrintFlagsFinal` 里查不到），担保逻辑改为按历次晋升的平均大小做更精确的预估。日志里的 `(to-space exhausted)`（G1）、`(promotion failed)` 就是担保出问题的现场。

---

## 三、可达性分析与 GC Roots

**本节要点**：原理（两条判定路线、根集合、三色标记）在 [../垃圾回收/](../垃圾回收/) 里；这一节只看**JVM 把根具体化成了什么**，以及两个高频陷阱。

### 3.1 哪些对象算 GC Roots

| 类别 | 说明 |
|---|---|
| **虚拟机栈（栈帧局部变量表）中引用的对象** | 每个线程各一份 —— 所以枚举根必须让所有线程停在安全点上 |
| **方法区中类静态属性引用的对象** | `static` 字段 |
| **方法区中常量引用的对象** | 字符串常量池、`final` 常量 |
| **本地方法栈中 JNI 引用的对象** | native 代码持有的引用 |
| **JVM 内部引用** | 基本类型的 `Class` 对象、常驻异常对象、系统类加载器、被同步锁（`synchronized`）持有的对象、活跃线程 |

① 是"每个线程一份"这件事，直接推出了 STW 的必要性：**根枚举的到齐时间由最慢的那个线程决定**（原理与安全点的展开见 [../垃圾回收/GC基础算法.md](../垃圾回收/GC基础算法.md) 的「安全点」一节）。

### 3.2 实测：循环引用照样被回收

用两个互相引用的对象 + 弱引用队列来验证（引用计数法在这里必然失败，因为两个计数都停在 1）：

```java
Node a = new Node("A");
Node b = new Node("B");
a.peer = b;
b.peer = a;                                        // 互相引用
WeakReference<Node> wa = new WeakReference<Node>(a, queue);
WeakReference<Node> wb = new WeakReference<Node>(b, queue);
a = null;
b = null;                                          // 只剩"环"里的互相引用
System.gc();
```

```text
置空前：wa.get()=CycleRef$Node@232204a1 ｜ wb.get()=CycleRef$Node@4aa298b7 ｜ A.peer==B 且 B.peer==A
   [引用队列] 收到通知：java.lang.ref.WeakReference@196406c7
   [引用队列] 收到通知：java.lang.ref.WeakReference@17a3eb9b
System.gc() 之后：wa 被清除=true ｜ wb 被清除=true
→ 循环引用照样被回收：JVM 不靠引用计数，靠「从 GC Roots 出发的可达性分析」
```

### 3.3 对象"死亡"要两次标记：`finalize()` 的实测

重写了 `finalize()` 的对象，第一次不可达时**不会被立刻回收**，而是进 `F-Queue` 等一次执行机会；若在 `finalize()` 里重新挂回强引用，就"复活"了：

```java
@Override
protected void finalize() throws Throwable {
    finalized++;
    if ("复活者".equals(name)) {
        saved = this;                 // 在 finalize 里重新建立强引用 → 复活
    }
    super.finalize();
}
```

```text
== 第一次 GC：对象已不可达，但重写了 finalize → 进 F-Queue 等一次执行机会 ==
   [F-Queue] 复活者.finalize() 被调用（累计第 1 次）
   finalize 累计 1 次 ｜ saved != null ? true → 对象被「复活」了
== 把复活后的引用也置空，再 GC 一次 ==
   finalize 累计 1 次（本次 +0）→ 第二次标记后直接回收，finalize 不会再跑
```

⭐ **`finalize()` 只跑一次**是这条规则最实用的推论。所以：

- 声明了 `finalize()` 的对象**至少多活一轮 GC**，且终结线程串行执行、时机不确定 → 大对象 + 终结器 = 内存水位与停顿的双重负担；
- 实战里要"对象释放时做清理"，用 **try-with-resources**（确定性）或 `java.lang.ref.Cleaner`（JDK 9+），**不要用 `finalize()`** —— Java 9 起它已被标记废弃。

---

## 四、收集器谱系与版本演进

**本节要点**：不能只记名字，还要知道"**各代收集器分别在优化什么**"以及"**哪些已经退场**"。

### 4.1 演进时间线

| 收集器 | 分代 | 算法 | 优化目标 | 状态 |
|---|---|---|---|---|
| **Serial / Serial Old** | 新生代 / 老年代 | 复制 / 标记-压缩 | 简单、内存少 | 仍在（Client 模式默认、小容器） |
| **ParNew** | 新生代 | 复制（多线程） | 缩短新生代暂停 | 仍在（CMS 的搭档） |
| **Parallel Scavenge / Parallel Old** | 新生代 / 老年代 | 复制 / 标记-压缩 | **吞吐量**（自适应调代大小） | **JDK 8 server class 的默认** |
| **CMS** | 老年代 | 标记-清除（不压缩） | **停顿优先**；并发标记用**增量更新** | ⚠️ JDK 9 废弃、**JDK 14 移除** |
| **G1** | 整堆 Region 化 | 局部复制 + 整体标记-压缩 | **可预测停顿**；标记用 **SATB** | JDK 9 起为默认 |
| **ZGC / Shenandoah** | 整堆 Region 化 | 着色指针 / 读屏障 + 并发整理 | 亚毫秒停顿 | JDK 15+ 生产可用；ZGC 自 JDK 21 支持分代 |

### 4.2 实测：同一负载换四种收集器

固定压力（512 MB 分配、存活 32 MB）、固定 `-Xmx256m`，只换收集器：

| 收集器 | 新生代收集器 | 老年代收集器 | GC 次数 | 总耗时 |
|---|---|---|---|---|
| Serial | `Copy` | `MarkSweepCompact` | 7 | 53 ms |
| Parallel | `PS Scavenge` | `PS MarkSweep` | 8 | 33 ms |
| CMS | `ParNew` | `ConcurrentMarkSweep` | 7 | 53 ms |
| G1 | `G1 Young Generation` | `G1 Old Generation` | 7 | 21 ms |

⚠️ **这张表不能当"G1 比 Parallel 快 1.5 倍"的结论**：① 负载太小（几百毫秒就跑完），停顿受 JIT 与启动阶段影响很大；② 本实验每块都是 1 MB 的 **humongous 对象**，恰好是 G1 最不擅长的形态（见 5.5）。**选收集器要看负载特征，不是看某个微基准**。

### 4.3 ⚠️ 实测：换收集器会偷偷改掉别的默认值

GC 日志第 3 行会打印**完整生效参数**，对比一下就很清楚：

```text
# -XX:+UseParallelGC
CommandLine flags: -XX:InitialHeapSize=266412288 -XX:MaxHeapSize=268435456 ... -XX:+UseParallelGC

# -XX:+UseConcMarkSweepGC
CommandLine flags: -XX:InitialHeapSize=266412288 -XX:MaxNewSize=89481216 -XX:MaxTenuringThreshold=6 ... -XX:+UseConcMarkSweepGC -XX:+UseParNewGC

# -XX:+UseG1GC
CommandLine flags: -XX:InitialHeapSize=266412288 -XX:MaxHeapSize=268435456 ... -XX:+UseG1GC
```

CMS 那一行多出来的 `-XX:MaxTenuringThreshold=6` **不是你设的** —— 是 ergonomics 为了让 Survivor 装得下而把默认 15 压到了 6。**换收集器前后一定要重新看一遍生效参数**，别拿旧的经验值套。

### 4.4 怎么选

| 场景 | 选择 | 理由 |
|---|---|---|
| 批处理、后台计算、吞吐优先 | **Parallel**（JDK 8 默认） | 吞吐最高，停顿可容忍 |
| 单核小容器 / 内存极紧 | **Serial** | footprint 最小，无线程切换开销 |
| 交互服务、堆 4~16 GB、停顿目标 100~500 ms | **G1** | 停顿可预测，可给 `-XX:MaxGCPauseMillis` |
| 堆很大（几十 GB）、停顿要求 < 10 ms | **ZGC / Shenandoah**（JDK 15+） | 停顿与堆大小基本解耦 |
| 还在 JDK 8 且老年代有碎片问题 | 只能 `-XX:+UseCMSInitiatingOccupancyOnly` + 调阈值，或**升级** | CMS 已无出路（JDK 14 移除） |

---

## 五、GC 日志怎么读？

**本节要点**："线上 GC 有问题怎么查" —— 答不出日志字段，说明没真跑过。

打开方式（JDK 8）：

```bash
java -Xmx256m -XX:+PrintGCDetails -XX:+PrintGCDateStamps -Xloggc:gc.log -jar app.jar
```

### 5.1 Minor GC：Parallel 的日志

```text
2026-09-27T23:07:12.077+0800: 0.175: [GC (Allocation Failure) [PSYoungGen: 64799K->4920K(76288K)] 64799K->4928K(251392K), 0.0072831 secs] [Times: user=0.00 sys=0.00, real=0.01 secs]
```

| 字段 | 含义 |
|---|---|
| `2026-09-27T23:07:12.077+0800` | 墙钟时间（`PrintGCDateStamps` 打开后才有；排查时先对齐业务日志的时间轴） |
| `0.175` | JVM 启动后的秒数 |
| `GC (Allocation Failure)` | **触发原因**：Eden 分配失败（最常见的一种） |
| `[PSYoungGen: 64799K->4920K(76288K)]` | 新生代 回收前 → 回收后 (**总容量**) |
| `64799K->4928K(251392K)` | **整堆** 回收前 → 回收后 (总容量) |
| `0.0072831 secs` | 本次 GC 的**墙钟耗时**（这就是这次 STW 的长度） |
| `[Times: user/sys/real]` | CPU 时间三段 |

⭐ **两个数一眼定健康**：① `0.0072831 secs` 就是停顿，亚毫秒到几毫秒都正常；② 对比新生代与整堆的前后差值 —— 这次新生代回收了 59879 KB，整堆只回收 59871 KB，说明**这次几乎全是短命对象，老年代基本没动**。反过来，如果整堆回收量远大于新生代回收量，说明对象在被晋升。

### 5.2 一次 Full GC 的日志（见 1.3）

重点看 `[ParOldGen: 31468K->31460K(32768K)]` 这一段：**老年代回收前后的差值就是这次 Full GC 的产出**。差值接近 0 → 堆不够了。

### 5.3 一次完整的 CMS 并发周期

CMS 的日志是"讲故事"最完整的，逐步看（`-XX:+UseConcMarkSweepGC`）：

```text
3.501: [GC (CMS Initial Mark) [1 CMS-initial-mark: 225824K(349568K)] 244256K(506816K), 0.0008613 secs]
3.502: [CMS-concurrent-mark-start]
3.504: [CMS-concurrent-mark: 0.001/0.001 secs]
3.504: [CMS-concurrent-preclean-start]
3.504: [CMS-concurrent-preclean: 0.000/0.000 secs]
3.504: [CMS-concurrent-abortable-preclean-start]
3.907: [CMS-concurrent-abortable-preclean: 0.002/0.402 secs]
3.907: [GC (CMS Final Remark) [YG occupancy: 141021 K (157248 K)][Rescan (parallel) , 0.0007380 secs][weak refs processing, 0.0000125 secs][class unloading, 0.0001878 secs][scrub symbol table, 0.0003495 secs][scrub string table, 0.0001536 secs][1 CMS-remark: 225824K(349568K)] 366846K(506816K), 0.0015155 secs]
3.909: [CMS-concurrent-sweep-start]
3.909: [CMS-concurrent-sweep: 0.000/0.000 secs]
3.909: [CMS-concurrent-reset-start]
3.911: [CMS-concurrent-reset: 0.002/0.002 secs]
```

⭐ 逐行读出的三件事：

- **只有两行是 STW**：`CMS Initial Mark`（0.86 ms）与 `CMS Final Remark`（1.52 ms）；中间的 mark / preclean / sweep / reset 都是**并发**的（日志里没有 `[GC` 前缀）。本次周期墙钟跨度 **410 ms，其中停顿合计只有 2.38 ms** —— 这就是"停顿优先"的含义；
- **`Final Remark` 里在干什么**：`Rescan (parallel)` 就是**增量更新**要付的那笔账（以并发期间被改过的黑对象为根重扫），后面还有弱引用处理、类卸载、符号表/字符串表清理 —— 这是 CMS 最贵的 STW 段，也是它"停顿不像宣传那么低"的原因；
- **`abortable-preclean: 0.002/0.402 secs`** 这种 `CPU/墙钟` 双数格式在 CMS 日志里到处都是，别看成两个阶段。

### 5.4 G1 的日志（注意体量）

同一份负载，Parallel 打 8 行日志，G1 打了 **232 行** —— G1 会打印每个 GC 工作线程的分阶段耗时。压缩后的关键行：

```text
2026-09-27T23:07:13.039+0800: 0.240: [GC pause (G1 Humongous Allocation) (young) (initial-mark), 0.0018085 secs]
   [Parallel Time: 1.0 ms, GC Workers: 8]
      [Ext Root Scanning (ms): Min: 0.2, Avg: 0.3, Max: 0.3, Diff: 0.2, Sum: 2.3]
      [Object Copy (ms): Min: 0.3, Avg: 0.4, Max: 0.4, Diff: 0.1, Sum: 3.0]
   [Eden: 1024.0K(24.0M)->0.0B(19.0M) Survivors: 0.0B->1024.0K Heap: 59.0M(256.0M)->4904.2K(256.0M)]
```

- **`(G1 Humongous Allocation)`** 是个信号：本实验每块 1 MB，而 `G1HeapRegionSize` 实测被 ergonomics 设成 **1 MB**，超过 region 一半的对象就是**巨型对象（humongous）**，要占用连续多个 region，回收代价高；
- 紧接着的 `(initial-mark)` 说明巨型对象分配**顺带触发了一轮并发标记周期**；
- 日志里还出现了 **`(to-space exhausted)`** —— 复制目标空间不够，这是 G1 走向 Full GC 的前兆；
- **`Eden: ...->... (24.0M)->(19.0M)` 括号里是"目标回收区大小"**，实测在 `19M → 33M → 55M → 110M` 之间自适应扩张，说明 G1 在按停顿目标动态调整"一次回收多少"（机制见 6.3）。

### 5.5 G1 的停顿预测模型（`-XX:+PrintAdaptiveSizePolicy`）

```text
[G1Ergonomics (CSet Construction) start choosing CSet, _pending_cards: 0, predicted base time: 10.00 ms, remaining time: 190.00 ms, target pause time: 200.00 ms]
[G1Ergonomics (CSet Construction) finish choosing CSet, eden: 1 regions, survivors: 0 regions, old: 0 regions, predicted pause time: 24.70 ms, target pause time: 200.00 ms]
```

这段日志把 G1 的核心讲完了：**在"剩余时间预算"（`remaining time 190.00 ms`）里挑 region 组成本次回收集（CSet），并预测停顿不超 `MaxGCPauseMillis=200 ms`**。看 G1 是"怎么做到可预测停顿"的，看这两行就够了。

---

## 六、堆和代比例怎么调？

**本节要点**：调参真正的关键是"**先看什么数，再动哪个旋钮**"。

### 6.1 实测：只改 `-Xmx`（同压力）

固定 512 MB 分配压力、存活 32 MB，只改最大堆：

| `-Xmx` | 实测堆上限 | Minor GC 次数 | Minor 总耗时 | 单次均摊 | Full GC | Eden 峰值 | Old 峰值 |
|---|---|---|---|---|---|---|---|
| 64m | 62 MB | **37** | 109 ms | 2.9 ms | 0 | 17408 KB | 34376 KB |
| 256m | 241 MB | **8** | 52 ms | 6.5 ms | 0 | 64799 KB | 21024 KB |
| 1024m | 910 MB | **4** | 43 ms | 10.8 ms | 0 | 148114 KB | 15368 KB |

⭐ 三点读出来的结论：

1. **堆越大，GC 次数越少但单次越贵**：次数 37 → 4（9 倍），单次均摊 2.9 ms → 10.8 ms（3.7 倍）—— 因为每次要复制的 Eden 更大。**总耗时仍降了**（109 → 43 ms），但降幅（2.5 倍）远小于次数降幅；
2. **老年代峰值反而随堆增大而下降**（34 MB → 21 MB → 15 MB）：Eden 大，对象有更多机会在新生代里死去，晋升变少；
3. **别只看次数**：线上常有人吹"我把 GC 次数降了十倍"，但每次停顿变长了 —— 延迟看的是**单次停顿与 P99**，不是次数。

> ⚠️ 这张表是**单次运行**的读数：GC 次数受并发调度影响，重复跑有 ±20% 左右波动（复核实测 38 / 8 / 4 次、单次均摊 4.8 / 8.5 / 9.5 ms）。
> **稳定的只有两条趋势**：次数随堆增大而减少、单次停顿随堆增大而上升 —— 引用数字时请带上这两条趋势，而不是某个具体值。

### 6.2 实测：只改 `NewRatio`（`-Xmx512m`）

`NewRatio = N` 表示 **新生代 : 老年代 = 1 : N**（⚠️ 不是"新生代的占比"，这是最容易记反的一个）：

| `NewRatio` | 新生代占比 | Minor GC 次数 | 总耗时 | Eden 峰值 | Old 峰值 |
|---|---|---|---|---|---|
| 1 | 1/2 | **3** | 27 ms | 196404 KB | 9224 KB |
| 2（默认） | 1/3 | 4 | 26 ms | 140237 KB | 15368 KB |
| 8 | 1/9 | **13** | 45 ms | 50144 KB | 31304 KB |

**新生代越小 → Minor GC 越频繁、老年代涨得越快**（`NewRatio=8` 时老年代峰值 31 MB，是 `NewRatio=1` 的 3.4 倍）。复核实测次数为 4 / 6 / 13，同样是单调递增（次数值有波动，"越小的新生代 → 越频繁"这条趋势稳定）。

调新生代大小的方向性原则：

| 症状 | 动作 |
|---|---|
| Minor GC 太频繁、每次停很短 | **放大新生代**（降 `NewRatio` 或直接 `-Xmn`） |
| 单次 Minor GC 停顿太长 | **缩小新生代**（复制量正比于存活集，但 Eden 大→扫描/复制面大） |
| 老年代增长快、Full GC 频繁 | 先查**晋升速率**（是不是 Survivor 太小导致过早晋升），再考虑放大老年代 |

### 6.3 调优顺序（先看数，再动手）

```text
① 打开 GC 日志，先算三个量
   - 分配速率 = 两次 GC 之间的分配量 / 时间间隔
   - 晋升速率 = 每次 Minor GC 后老年代的增量
   - 暂停分布 = Minor / Full 各自的次数、单次最大、占比
② 分配速率高 → 先改代码（别急着调 JVM）
   - 热路径避免隐式装箱、临时字符串拼接、循环里建集合
   - 对象复用 / 池化；缩短对象生命周期（对应 Go 侧看逃逸分析）
③ 看代比例：让"对象死在新生代"这条假说成立
   - Survivor 太小 → 过早晋升 → 放 Survivor 或降低晋升压力
④ 内存上限与收集器
   - 容器里一定显式 -Xmx（别让 JVM 看到宿主机的 1/4）
   - 停顿目标明确就上 G1 + -XX:MaxGCPauseMillis
⑤ 最后才换收集器：换的收益上限，由第 ② 步欠的账决定
```

> ⚠️ **`-Xmx` 不是越大越好**：堆大 → 单次回收更贵（6.1 实测）、且更容易被 Full GC 一次收很长的停顿。容器里还要预留堆外内存（元空间、线程栈、直接内存），别把容器限额全给 `-Xmx`。

---

## 使用：用 jstat / jcmd / jmap 做一次现场诊断

### 第一步：先确认「是不是 GC 的问题」

```bash
# 每 1 秒打一次，共 5 次；看 O（老年代使用率）、FGC 是否在涨
jstat -gcutil <pid> 1000 5
```

实测输出（`-Xmx64m` + Serial，程序里已放 12 MB 大对象进老年代）：

```text
  S0     S1     E      O      M     CCS    YGC     YGCT    FGC    FGCT     GCT
  0.00   0.00  10.02  28.11  17.38  19.94      0    0.000     0    0.000    0.000
```

`O = 28.11%` 正好对上"老年代 12288 KB / 容量 43712 KB"。⭐ **判据：`O` 长期高位不降 + `FGC` 持续增长 = 存活集顶到上限（对应 1.3 那种"收不动"的 Full GC）；`YGC` 涨得快而 `YGCT` 很小 = 分配太猛。**

### 第二步：要精确数字，用 `jstat -gc` 或 `jcmd`（不要用 JMX）

```bash
jstat -gc <pid>          # 各代容量与使用量（单位 KB），比 -gcutil 精确
jcmd <pid> GC.heap_info  # 堆的分代实况，含元空间
```

```text
# jstat -gc
   S0C    S1C    S0U    S1U      EC       EU        OC         OU       MC     MU    CCSC   CCSU   YGC  YGCT  FGC  FGCT   GCT
2176.0 2176.0   0.0    0.0   17472.0   1749.9   43712.0    12288.0   4480.0 778.5  384.0   76.6    0  0.000    0 0.000 0.000

# jcmd <pid> GC.heap_info
def new generation   total 19648K, used 1749K
 eden space 17472K,  10% used
tenured generation   total 43712K, used 12288K
Metaspace       used 3614K, capacity 4600K, committed 4864K, reserved 1056768K
```

⚠️ **实测踩到的坑**：用 `MemoryPoolMXBean.getUsage()`（JMX）读老年代用量，同一个程序连读三次得到 `0 / 4096 / 8192 KB` 三个不同值 —— **读数明显滞后于实际分配**。要做精确对照（比如验证 2.4 的 `PretenureSizeThreshold`），必须用 `jstat` / `jcmd` / GC 日志，**JMX 的峰值可用、瞬时值不可信**。

### 第三步：看"到底是谁占着内存"

```bash
jmap -histo <pid> | head -20            # 直方图：按类和对象数排序
jmap -dump:live,format=b,file=heap.hprof <pid>   # 堆快照，交给 MAT 看支配树
```

实测节选：

```text
 num     #instances         #bytes  class name
----------------------------------------------
   1:           174       12701008  [B
   2:           643         632248  [I
   3:          5293         622672  [C
   4:          4102          98448  java.lang.String
   5:           684          77704  java.lang.Class
```

`[B` = `byte[]`、`[I` = `int[]`、`[C` = `char[]`（JVM 内部类型签名）。⭐ **排第一的 `[B` 占了 12.1 MB**，与"12 个 1 MB 数组进老年代"完全吻合 —— **排查内存问题，先看数组类型，它们往往是大头**。

### 附：查"生效参数"，别靠记忆

```bash
java -XX:+PrintFlagsFinal -version | grep <关键字>   # 启动前：看默认值与 ergonomics 的选择
jinfo -flags <pid>                                   # 运行中：看实际生效值
```

```text
-XX:MaxHeapSize=67108864
-XX:PretenureSizeThreshold=2097152
-XX:+UseSerialGC
```

### 诊断套路速查

| 症状 | 先看什么 | 常见根因 |
|---|---|---|
| **CPU 高** | `top -Hp <pid>` 找线程 → 转十六进制 → `jstack <pid>` 对照 | 死循环、锁竞争、GC 线程抢 CPU |
| **停顿长** | GC 日志的单次 `secs`、`O` 使用率 | 存活集太大、Full GC、`finalize` 队列、元空间不足 |
| **内存只涨不降** | `jstat -gcutil` 的 `O` + `jmap -histo` | 泄漏（缓存无上限 / 静态集合 / 长生命周期监听器） |
| **频繁 Full GC 但回收量小** | Full GC 前后老年代差值 | 存活集顶到堆上限 → **加堆或减对象**，调收集器无效 |

---

## 面试官会追问什么

- **JVM 的 GC 是什么类型？和 Go 有什么不一样？** → 分代 + 复制/压缩（会移动对象）、Minor 与 Full 都是 STW；Go 是不分代、不压缩、并发标记清除，只有两次极短 STW。JVM 用"少干活 + 无碎片"换较长的停顿，Go 用"内存水位 + 碎片"换低延迟。
- **怎么判断对象已死？** → **可达性分析**：从 GC Roots 出发遍历引用图，不可达即可回收；引用计数法收不掉循环引用（实测两个互引对象置空后照样被回收），且有写放大与计数器占位两笔成本。原理与根集合的通用构成见 [../垃圾回收/GC基础算法.md](../垃圾回收/GC基础算法.md)。
- **哪些对象可以作为 GC Roots？** → 虚拟机栈（栈帧局部变量表）引用的对象、方法区静态属性引用的对象、方法区常量引用的对象、本地方法栈 JNI 引用的对象，加上活跃线程、被同步锁持有的对象等 JVM 内部引用。
- **对象什么时候进老年代？** → ① 年龄达到 `MaxTenuringThreshold`（默认 15，实测常被**动态年龄判定**提前到 6~7）；② 超过 `PretenureSizeThreshold` 的大对象（⚠️ **该参数对 Parallel Scavenge 无效**，实测 Parallel 下老年代增量为 0、Serial 下为 12288 KB）；③ 动态年龄判定（survivor 中同龄对象总和超一半）；④ 空间分配担保失败。
- **"新生代 8:1:1"什么时候不成立？** → 默认 `UseAdaptiveSizePolicy=true` 时会动态调整，实测同一负载下 Eden:Survivor 是 **4.3:1** 而不是 8:1；要固定比例得 `-XX:-UseAdaptiveSizePolicy -XX:SurvivorRatio=8`，此时实测正好 8.00:1。
- **一次 Minor GC 都做了什么？** → 到安全点 → 从根（含"老年代→新生代"的卡表引用）标记存活 → 复制到 to 空间、年龄+1、达标者晋升 → 清空 Eden 与 from → from/to 互换。
- **什么情况下会 Full GC？** → 老年代 / 元空间不足、显式 `System.gc()`、CMS 并发失败退化、自适应策略判定（日志里写 `Ergonomics`）。**判据是"Full GC 后老年代回收量接近 0"**（实测 `31468K->31460K`，只回收 8 KB）→ 说明存活集顶到上限，只能加堆或减对象。
- **CMS 与 G1 的区别？** → CMS：分代、老年代标记-清除不压缩、并发标记用**增量更新**、有碎片、JDK 14 移除；G1：整堆 Region 化、整体标记-压缩、并发标记用 **SATB**、有可预测停顿模型（`-XX:MaxGCPauseMillis`）。实测 CMS 一个完整周期内**两次 STW 合计 2.38 ms，而周期墙钟跨度 410 ms**。
- **`-XX:MaxGCPauseMillis` 是怎么实现的？** → G1 的 CSet 选择：在"剩余时间预算"内挑 region，并预测停顿不超目标。实测日志 `predicted base time: 10.00 ms, remaining time: 190.00 ms, target pause time: 200.00 ms` 就是它在算这道题。
- **调堆和调代码，先做哪个？** → 先看**分配速率与晋升速率**：分配速率高时扩堆只是把问题推后；判据是"老年代增长快 + Full GC 收不动"，那时先查对象生命周期（临时对象、缓存无上限），再动参数。
- **`-Xmx` 是不是越大越好？** → 不是。实测同压力下 64 MB / 256 MB / 1024 MB 的 Minor GC 次数是 37 / 8 / 4，但**单次停顿从 2.9 ms 涨到 10.8 ms**；总停顿虽降，延迟看的是单次与 P99。且容器里要预留堆外内存。
- **怎么排查线上内存问题？** → `jstat -gcutil` 看 `O` 与 `FGC` 趋势 → `jstat -gc` / `jcmd GC.heap_info` 拿精确分代用量 → `jmap -histo` 看谁占内存（先看 `[B`/`[I` 这类数组）→ 必要时 `jmap -dump` + MAT 看支配树；CPU 高则 `jstack` 对照线程栈。
- **为什么不建议用 `finalize()`？** → 重写它的对象第一次不可达时只进 `F-Queue` 等一次执行（实测 finalize 被调用一次、对象复活），**至少多活一轮 GC**、终结线程串行且时机不确定，还只跑一次；清理应该用 try-with-resources 或 `Cleaner`。

---

## 关联

- [../垃圾回收/GC基础算法.md](../垃圾回收/GC基础算法.md) — **本篇的算法层入口**：判定垃圾的两条路线与根集合、算法选型总表、安全点、调优顺序
- [../垃圾回收/并发标记与写屏障.md](../垃圾回收/并发标记与写屏障.md) — 三色抽象、漏标两条件、增量更新 vs SATB（CMS 与 G1 的分野）
- [../垃圾回收/复制与分代回收.md](../垃圾回收/复制与分代回收.md) — 复制算法、分代假说与卡表（本篇 Minor GC 的机制来源）
- [../垃圾回收/标记类GC算法.md](../垃圾回收/标记类GC算法.md) — 标记-清除与标记-压缩的完整过程（老年代算法）
- [../go/运行时/垃圾回收机制.md](../go/运行时/垃圾回收机制.md) — 另一种取舍：不分代、不压缩的并发标记清除，配 `gctrace` 实测
- [运行时数据区与栈帧.md](运行时数据区与栈帧.md) — 局部变量表就是 GC Roots 的第一类
- [类加载机制.md](类加载机制.md) — 类何时可被卸载，直接决定元空间的 GC 行为
- [Stream流实战.md](Stream流实战.md) — 隐式装箱与临时对象是分配速率的主要来源之一
- [../容器/docker/资源限制与运维.md](../容器/docker/资源限制与运维.md) — 容器里 JVM 为什么要感知 cgroup、为什么必须显式 `-Xmx`

> 反向引用（本篇被下列文档引到）：[引用计数与增量回收.md](../垃圾回收/引用计数与增量回收.md)
