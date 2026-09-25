# 弹幕系统的 Java 实现

> 同一套四层设计，用 Java 逐类型搬一遍并**重新实测**——重点看两处最容易走样的原语：
> `atomic.Pointer[[]*Sub]` → `AtomicReference<List<Sub>>`、`select{case ch<-m: default}` → `offer` + 有界 `ArrayBlockingQueue`。
>
> 内容整理自大厂 Go 后端面试真题视频，并参考《大型网站技术架构：核心原理与案例分析》（李智慧）的伸缩性与可用性章节；参考资料与原始素材见 [素材清单](../../素材清单.md)。
>
> **校验口径**：JBR 17 主实验、JBR 21 做虚拟线程对照组，与 Go 侧的数字逐项对照。

## Java 版骨架：同一套四层，逐类型搬一遍并重新实测 ⭐

> **校验口径**：`BarrageDesign.java` 用 IDEA 自带的 JBR 实际编译并运行过——
> `javac -Xlint:all -encoding UTF-8` **无 error 无 warning**，跑了 5 次取数字区间。
> 第四组的**虚拟线程对照组** `BarrageVirtual.java` 用 GoLand 自带的 **JBR 21** 编译运行
> （`Thread.ofVirtual` 需要 JDK 19+，JBR 17 下把那两行换回 `new Thread(...)` 即可）。
> ⚠️ 环境是 Windows + JBR：**绝对值不能当 Linux 数据用**，只有"同一台机器上 Go 与 Java 的相对差"可信。

**跑法**（Git bash，不用另装 JDK）：

```bash
cd "$TEMP/verify-java/barrage"          # 你的目录换成实际存放路径即可

# 主实验：JBR 17
JBR="<IDEA 安装目录>/jbr/bin"    # 主实验用 IDEA 自带的 JBR 17
"$JBR/javac.exe" -Xlint:all -encoding UTF-8 -d out BarrageDesign.java
"$JBR/java.exe" -Dfile.encoding=UTF-8 -Dstdout.encoding=UTF-8 -cp out BarrageDesign

# 虚拟线程对照组：JBR 21（GoLand 自带）
JBR21="<GoLand 安装目录>/jbr/bin"  # 虚拟线程要 JDK 21，用 GoLand 自带的 JBR 21
"$JBR21/javac.exe" --release 21 -Xlint:all -encoding UTF-8 -d out21 BarrageDesign.java BarrageVirtual.java
"$JBR21/java.exe" -Dstdout.encoding=UTF-8 -cp out21 BarrageVirtual
```

**原语对照表——这张表就是"结构是语言无关的"那句话的证据**：

| Go（上面四段） | Java（本节） | 差在哪 |
|---|---|---|
| `chan Msg`（带缓冲） | `ArrayBlockingQueue<Msg>` | Java 没有 0 缓冲队列，`buf` 至少 1 |
| `select { case ch <- m: default }` | `queue.offer(m)` | **一模一样**：非阻塞、满了返回 false |
| `atomic.Pointer[[]*Sub]` 快照 | `AtomicReference<List<Sub>>` | Java 侧快照必须**不可变**（`List.copyOf`），否则读侧会看到写者的中间状态 |
| `close(s.out)` 广播式退出 | 塞一个 `POISON` 毒丸 | ⚠️ **这是真差异**：Java 没有"关一个通道让所有读者退出"的原语 |
| `go func(){ for range ch }` | `new Thread(...)` | ★ 一个连接 = **一个内核线程**，成本见下面第四组 |
| `context.WithCancel` | `volatile` 标志位 + 线程 `interrupt` | — |
| `sync.Once` | `AtomicBoolean.compareAndSet` | — |

**片段一：一条连接 = 有界队列 + 非阻塞投递 + 毒丸退出**（对应 `barrage_2.go` 的 `Sub`）

```java
	static final class Sub {
		final String id;
		/** 毒丸：见 close()。seq = -1 是正常弹幕不会用到的取值。 */
		static final Msg POISON = new Msg("", "", "", -1);

		private final ArrayBlockingQueue<Msg> out;
		private final AtomicLong sent = new AtomicLong();
		private final AtomicLong dropped = new AtomicLong();
		private final AtomicBoolean closed = new AtomicBoolean();

		Sub(String id, int buf) {
			this.id = id;
			this.out = new ArrayBlockingQueue<>(Math.max(1, buf)); // Java 没有 0 缓冲队列，见 main() 第四组的说明
		}

		/** 等价 Go 的 select+default：满了立刻返回 false，绝不阻塞调用方。 */
		boolean trySend(Msg m) {
			if (closed.get()) {
				return false;
			}
			if (out.offer(m)) {
				sent.incrementAndGet();
				return true;
			}
			dropped.incrementAndGet(); // 慢客户端：丢这一条，继续服务别人
			return false;
		}

		/** 丢弃率必须暴露给监控：只报 QPS 等于把"用户看不到弹幕"藏起来。 */
		long sent() {
			return sent.get();
		}

		long dropped() {
			return dropped.get();
		}

		/**
		 * 幂等：主动离开、心跳超时、连接断开三条路径都会走到这里。
		 *
		 * ⚠️ Java 没有 `close(chan)` 这种"关广播"的原语，等价做法是**塞一个毒丸**
		 * 让消费线程自己退出。队列满时毒丸可能塞不进——这里接受这个不完美，
		 * 因为消费线程是 daemon，进程退出时自然回收；
		 * 生产上要么给毒丸留一个空位（入队侧预留），要么改用 poll 超时轮询 closed 标志。
		 */
		void close() {
			if (closed.compareAndSet(false, true)) {
				out.offer(POISON);
			}
		}

		/**
		 * 该连接的写协程（真实项目里就是 ws WriteMessage 循环）。
		 * 用**无限期 take()**而不是 poll(超时)：轮询会把"1000 个连接各自每 20ms 醒一次"
		 * 变成一份稳定的空转开销，测出来的扇出成本就不干净了。
		 */
		void drain(Consumer<Msg> writer) throws InterruptedException {
			while (true) {
				Msg m = out.take();
				if (m == POISON) {
					return;
				}
				writer.accept(m);
			}
		}
	}
```

**片段二：房间的 COW 快照**（对应 `barrage_2.go` 的 `Room`，广播路径**不进锁**）

```java
	static final class Room {
		private final String name;
		private final Map<String, Sub> subs = new HashMap<>();
		private final AtomicReference<List<Sub>> snap = new AtomicReference<>(Collections.emptyList());
		private final AtomicLong online = new AtomicLong();

		Room(String name) {
			this.name = name;
		}

		String name() {
			return name;
		}

		synchronized void join(Sub s) {
			Sub old = subs.put(s.id, s);
			if (old != null && old != s) {
				old.close(); // 同 ID 重连：先摘旧的
			}
			publish();
		}

		synchronized void leave(String id) {
			Sub s = subs.remove(id);
			if (s == null) {
				return; // 幂等
			}
			publish();
			s.close();
		}

		/** 持锁时把 map 摊成一份不可变快照，读侧遍历它、不进临界区。 */
		private void publish() {
			snap.set(List.copyOf(subs.values()));
			online.set(subs.size());
		}

		long online() {
			return online.get();
		}

		/** 尽力投递给房间内所有连接，返回成功条数。<b>这里没有锁</b>。 */
		int broadcast(Msg m) {
			int ok = 0;
			for (Sub s : snap.get()) {
				if (s.trySend(m)) {
					ok++;
				}
			}
			return ok;
		}
	}
```

**片段三：批量落库的三个开关**（对应 `barrage_4.go` 的 `BatchWriter`）

```java
		/**
		 * 消费循环。Go 用 select{case ch / case ticker / case ctx.Done}，
		 * Java 这边让 poll 的超时<b>同时充当"取数据"和"定时"</b>——
		 * 少一个 ticker，但语义等价：拿到就攒批，等不到就把已有的写掉。
		 */
		void run() {
			List<Msg> batch = new ArrayList<>(max);
			while (true) {
				Msg m;
				try {
					m = queue.poll(flushMillis, TimeUnit.MILLISECONDS);
				} catch (InterruptedException e) {
					Thread.currentThread().interrupt();
					break;
				}
				if (m != null) {
					batch.add(m);
				}
				boolean timeUp = m == null;
				if (batch.size() >= max || (timeUp && !batch.isEmpty())) {
					sink.write(new ArrayList<>(batch));
					flushed.addAndGet(batch.size());
					batch.clear();
				}
				if (stop && queue.isEmpty()) {
					break;
				}
			}
			if (!batch.isEmpty()) { // 退出前把剩下的写掉：不要把已入队的直接丢了
				sink.write(new ArrayList<>(batch));
				flushed.addAndGet(batch.size());
			}
		}
```

**Java 侧实测**（5 次取的区间；完整文件是可直接跑的 `BarrageDesign.java`，
上面三处是**最容易走样的三个类型**，其余类型与 Go 版一一对应）：

| 实验 | Go | Java | 是否同一结论 |
|---|---|---|---|
| ① 慢连接丢弃（缓冲 1 / 8 / 64 / 1024） | 丢 999 / 992 / 936 / 0 | **丢 999 / 992 / 936 / 0** | ✅ 逐格相同，快连接始终收到 1000 |
| ② 无共享总线 | A 收 100 投 64，B 收 0 | **A 收 100 投 64，B 收 0** | ✅ 相同 |
| ② 接 Pub/Sub | A、B 各收 100，两端连接各 64 | **完全相同**；`Unsubscribed=1` | ✅ 相同 |
| ③ 队列 500 / 入队 2000 | 入队 500~600 | 出口不阻塞时入队 **600~1461**（5 次：1461 / 925 / 836 / 696 / 600）；出口 5ms 时 500~600 | ⚠️ 结论同（队满即丢、落库数=入队数），但 Java 浮动更大：消费者线程**启动时机**直接决定它抢到多少条 |
| ④ 单次投递（2000 条 × 1000 连接） | ≈242~303 ns | **≈3.2~4.1 µs** | ❌ **差 10 倍以上，这一条最值得讲** |

第四组拆开看（Java 侧，每轮内部再取 3 轮最小，共跑 5 次程序）：

| 配置 | 单次投递 | 说明 |
|---|---|---|
| 1000 个连接**没有**消费者 | **≈69~83 ns** | 队列 + 锁本身就这么贵 |
| 1000 个**内核线程**消费者 | **≈3.2~4.1 µs** | ★ 唤醒成本占了 **≈98%** |
| 1000 个**虚拟线程**消费者（JDK 21） | **≈180~264 ns** | 回到 Go 量级（Go ≈242~303 ns） |

**三条只有把两边都跑过才讲得出的话**：

1. **广播的贵不贵在队列，而在"叫醒 1000 个内核线程"**：
   同一套结构，把消费者摘掉是 69ns，接上内核线程是 3.2µs —— 98% 是唤醒。
   Go 之所以量不出这一层，是因为它那 1000 个"连接写协程"本来就是协程。
2. **Java 21 的虚拟线程让这道题重新变成"结构问题"**：
   `BarrageVirtual.java` 只改了线程的创建方式（`Thread.ofVirtual()`），
   房间/队列/投递逻辑一行没动，单次投递就落到 180~264ns ≈ Go 的 242~303ns。
   这就是 [进程与线程.md](../../linux/进程与线程.md) 里"协程轻在调度"最直接的证据。
3. **`close(chan)` 在 Java 里没有等价物**，只能塞毒丸，而毒丸**可能被满队列挡住**。
   这不是语法差异而是模型差异：Go 的通道是**一等公民**（可关闭、可广播给所有读者），
   Java 的队列只是一个容器。**面试里能指出这一条，比背"Java 并发包很丰富"有用得多。**

> 另外两处小差异也记一下，免得以为是 bug：
> ① Java 没有 0 缓冲队列，所以 Go 表里"缓冲 0 → 丢 1000"那档在 Java 侧不存在；
> ② 线格式体积两边完全一致（48 字节 vs JSON 82 字节），
> 但 Java 侧的 JSON 是按 `encoding/json` 的等价输出**手工拼**的（本机没引 Jackson），只用于体积对比。

---

## 一句话收口

> 四层的骨架其实只有三件事：**入口把不合规的挡掉（限流直接丢）、
> 扇出把慢的隔离掉（有界缓冲 + 非阻塞投递 + COW 快照）、
> 落库把散的攒起来（攒批 + 定时 + 背压丢）**；
> 而 `Node.Accept` 里那 15 行，就是"双路径分流"这句话的全部实现。

---

---

---

---

## 面试官会追问什么

### 一、为什么要专门做一遍 Java 版，直接翻译不行吗？
翻译最容易在**并发原语的语义差异**上翻车：
- Go 的 `atomic.Pointer[[]*Sub]` 是"把整个切片当**不可变值**整体替换"；Java 侧对应 `AtomicReference<List<Sub>>`，
  但若写成 `synchronizedList` 并**在原地 add/remove**，COW 语义就完全丢了；
- Go 的 `select { case ch <- m: default: }` 是"不阻塞地**试一次**"；Java 侧对应 `offer()` 返回 `false`，
  写成 `put()`（阻塞）或 `add()`（抛异常）都不是同一个东西。

所以**逐类型搬 + 重新实测**，比"照着翻译一遍"更能暴露这类问题。

### 二、虚拟线程在这里解决了什么？
解决的是 **"连接数 × 每连接一个阻塞点"的线程成本**：传统平台上"一个连接一个平台线程"在几千连接时就被线程栈与调度压垮，
虚拟线程把这条成本压到接近 goroutine 的量级。
⚠️ 但它**不解决慢消费者问题** —— 虚拟线程让"等待"变便宜，不会让"这个连接本身慢"变快，
所以第 4 层的**非阻塞投递 + 丢弃仍然必需**。

### 三、`AtomicReference<List<Sub>>` 和 Go 的 `atomic.Pointer[[]*Sub]` 语义一样吗？
**目标一致（无锁读快照），但保证强度不同。**
Go 侧是"原子替换一个指针"，读方拿到的是不可变快照；
Java 侧要成立，前提是**写入方绝不修改已有 List**（只能整体替换）—— 一旦有人拿到引用后 `.add()`，COW 就被破坏。
**语言不会替你保证这一点，只有代码纪律**（用不可变集合，或每次新建）。

### 四、有界队列满了 `offer` 返回 `false`，这和 Go 的 `default` 分支是一回事吗？
**是同一类语义："不阻塞地试一次，失败后自己决定怎么办"。**
差别在**失败后的动作**由调用方定义：Go 的 `default` 分支里你自己写（丢弃 / 计数 / 换队列）；
Java 的 `offer` 返回 `false` 也只是告诉你"没放进去"，丢不丢、怎么记同样由调用方决定。
两边都必须**显式处理这个失败信号** —— 忽略它（或误用阻塞版 `put`）就等于把"可丢"变成了"会卡"。

## 关联

- [直播弹幕系统.md](直播弹幕系统.md) — 四层设计
- [弹幕系统的接入与推送.md](弹幕系统的接入与推送.md) — Go 版前两段骨架
- [弹幕系统的编排与落库.md](弹幕系统的编排与落库.md) — Go 版后两段骨架
- [../../java/运行时数据区与栈帧.md](../../java/运行时数据区与栈帧.md) — 虚拟线程对照实验涉及的线程与栈
- [../../数据存储/redis/发布订阅.md](../../数据存储/redis/发布订阅.md) — Java 侧同样靠订阅实现跨节点扇出
