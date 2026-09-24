# TCP 协议

> 报文结构、三次握手、滑动窗口、Nagle 算法与延迟确认
>
> 内容整理自大厂 Go 后端面试真题视频，参考资料与原始素材见 [素材清单](../interview/素材清单.md)。

---

## Q1. TCP 的滑动窗口是怎么滑动的？

**来源**：`p=30` 百度 Go 开发日常实习面试 · 时长 5分09秒
**考察意图**：要能**用具体数字把窗口滑动过程演算一遍**，而不是说"窗口会滑动"。

### 前置概念

- **报文结构里的"窗口"字段**：表示**接收方还能接收多少数据**——
  也就是**允许多少未确认数据在途**；
- **MSS（Maximum Segment Size）**：TCP 发送方一次可以携带的最大数据量。

### 设定假设（原视频的演算条件）

| 条件 | 值 |
|---|---|
| 客户端序列号 | 100 |
| MSS（最大报文段） | 1000 字节 |
| 窗口大小 | **5 个报文段 = 5000 字节** |
| 前提 | **不考虑拥塞导致的窗口变化**，**不考虑接收端处理能力导致的窗口变化** |

### 滑动过程演算

**① 初始窗口：`[100, 5100)`**

客户端可以**连续发送 5 个报文段**（每次 1000 字节），把 5 个都发出去：

```
发送方  100 → 1100 → 2100 → 3100 → 4100 → 5100   （5 个报文段全部发出）
                    ↑
        此时一个确认都没收到，窗口打满
```

**⚠️ 关键点**：窗口最多容忍 **5 个报文段的未确认数据**。
发满 5 个后**必须停止发送**，等待接收方确认。

**② 接收方回复累积确认**

接收方把这 5 个报文段组织完成后，回复 **ACK = 5100**：

> **确认号 5100 表示："5100 之前的所有字节我都收到了"**——
> 这是**累积确认**，一个 ACK 顶掉 5 个报文段的确认。

**③ 窗口滑动：`[100, 5100)` → `[5100, 10100)`**

窗口**向下滑动 5000 字节**，仍然是 5 个报文段大小。

**④ 继续发送，窗口继续滑动**

若又发送并确认了 1 个报文段（1000 字节）：

```
接收方回复 ACK = 6100  →  窗口再滑动 1000 字节
窗口变为 [6100, 11100)
```

**⑤ 循环往复**

> **发送方向接收方发送数据 → 接收方确认 → 窗口随确认不断向下滑动。**

### 窗口的本质（一句话总结）

> **窗口 = 已发送未确认的数据 + 还可以发送的数据。**
>
> - **窗口内的**：可以发送；
> - **窗口外的**（序号 ≥ 11100 的部分）：**不能发送**；
> - **窗口前面的**（已确认的部分）：已滑出窗口，也不需要再管。

### 面试官会追问什么

- **窗口大小是固定的吗？** → 不是。本题为便于演算做了简化假设。
  实际中窗口会因**接收端处理能力（接收窗口 rwnd）**和**网络拥塞（拥塞窗口 cwnd）**动态调整，
  实际发送窗口 = `min(rwnd, cwnd)`。
- **累积确认的优点？** → 一个 ACK 可以确认多个报文段，**减少确认开销**；
  丢包时后续 ACK 会重复同一序号，发送方可据此**快速重传**。
- **为什么要引入窗口？** → 若"发一条等一次确认"，**吞吐量根本上不去**（见下一题）。

---


---

## Q2. Nagle 算法、TCP 窗口与延迟确认

**来源**：`p=31` 百度 Go 开发日常实习面试 · 时长 7分43秒
**考察意图**：这三个机制各解决一个问题，**但 Nagle 与延迟确认同时开启会互相拖累**——
能讲出这个组合问题就是加分项。

### 前提假设

客户端序列号 100、服务端序列号 1000；**MSS = 1000 字节**（实际通常 1460）；**窗口 5 个 MSS = 5000 字节**。

### 一、Nagle 算法：合并小包

**要解决的问题**：应用层频繁发送**很小的数据包**（如 1 字节），
每个小包都要占 40 字节头部，**网络利用率极低**（"糊涂窗口综合征"的近亲）。

**Nagle 算法的作用**：**把多个微小数据段合并成一个尽可能大的报文段再发送**（默认开启）。

**四条规则**：

| 规则 | 触发条件 | 行为 |
|---|---|---|
| **① 无未确认数据** | 有新数据要发送，且**之前发出的数据都已确认** | **立即发送，不做合并**。<br>因为本来就没有需要等待的东西，强行缓存只会**增加延迟** |
| **② 数据满一个 MSS** | 缓存等待期间，**累积数据已达到一个最大报文段** | **立即发送** |
| **③ 收到确认** | 缓存等待期间，**接收方把之前数据的 ACK 回复过来了** | **立即发送**（不必等到超时） |
| **④ 等待超时** | 缓存时间到达（通常 **约 200ms**） | **一定发送**（兜底，保证不会无限等待） |

**核心思想**：**"能立即发就立即发，不能立即发就攒够再发，最多等 200ms"。**

### 二、TCP 窗口：解决"发一条等一条"的低吞吐

**没有窗口的世界**：

```
发送数据 → 等待确认 → 发送数据 → 等待确认 → ...
```

> 每发一条就要停下来等回复，**整个 TCP 的性能非常差，吞吐量上不去**。

**引入窗口后**：

> **窗口 = 允许多少数据处于"未确认"状态。**

有了窗口，**在没收到确认时依然可以继续发送**：

```
窗口 5 个 MSS：
发第 1 个（100→1100）→ 还剩 4 个额度 → 可以继续发
发第 2、3、4、5 个       → 窗口打满
→ 若接收方仍未给任何确认，则不能再发
```

**发送方一旦收到确认，就能腾出窗口继续发送下一批**——
这就是"**窗口大小决定了在途数据量，从而决定了吞吐上限**"。

### 三、延迟确认：合并确认

**做法**：接收方收到数据后，**不立即回复 ACK**，而是**延迟约 200ms** 再确认（超时则立即确认）。

**为什么值得延迟**（两个收益）：

1. **多个报文段只需回复一次确认**——收到 5 个报文段，只做一次回复就能确认全部收到；
2. **可以"捎带"确认**——如果接收方**自己也有数据要发给发送方**，
   那就在发送数据时**顺便把这个确认一起带过去**（piggybacking），
   **省掉一个独立的 ACK 报文**。

### ⚠️ 加分点：Nagle + 延迟确认 同时开启的"互相伤害"

> **Nagle 算法**：有小数据要发，但**前面还有未确认数据** → 攒着等 ACK。
> **延迟确认**：收到数据先不确认，**延迟最多 200ms** → ACK 迟迟不来。
>
> 两者叠加的结果：**发送方等确认才发小包，接收方等 200ms 才发确认**，
> 形成**额外的往返延迟**，典型表现是**请求-响应型小包交互出现明显卡顿**。
>
> **这就是为什么对延迟敏感的短连接请求场景需要关闭 Nagle（`TCP_NODELAY`）**，
> Redis、gRPC、游戏服务器等都会显式关闭它。

### 面试官会追问什么

- **什么时候该关 Nagle？** → 延迟敏感、请求-响应型交互（如 Redis 客户端、
  实时游戏、RPC 调用）。**关闭方式：** `conn.(*net.TCPConn).SetNoDelay(true)`（Go 默认就开启了 `NoDelay`）。
- **什么时候该开 Nagle？** → 大量小数据批量写入、对延迟不敏感的日志/文件传输。
- **窗口相关的"零窗口"是什么？** → 接收方缓冲区满时通告窗口 0，
  发送方停止发送并定期发**窗口探测**，直到接收方通告非零窗口。

---


---

## Q3. TCP 报文结构是怎样的？TCP 连接的本质是什么？

**来源**：`p=32` 百度 Go 开发日常实习面试 · 时长 11分00秒
**考察意图**：很多人能背字段名，但答不出"**TCP 连接到底是个什么东西**"——
原视频这段的洞察比字段表更有价值，先看这个。

### 一、先搞清"TCP 连接"的本质（这是加分点）

**常见误解**：认为客户端和服务端之间有一条**"虚拟通道"**。

**实际情况**：

> **虚拟通道并不存在**。真实存在的只有**物理层面的通道**——
> 客户端和服务端相互可达（能互相找到对方）。
>
> 客户端和服务端实际做的事情是：
> **各自创建一个"传输控制模块"（TCB）**，在里面**记录状态信息**，
> 并通过彼此通信来**了解对方的状态、维护这些信息**，
> 用这些信息去控制数据的**可靠传输和流量控制**。

**为什么需要记录状态？**

```
客户端发出数据 → 没有收到服务端的确认 → 两种情况：
  ① 数据根本没发出去
  ② 数据发出去了，服务端也收到了，但确认没能回来
```

两种情况都导致客户端**无法知道数据是否送达**。
所以规则是：**只要收不到确认，就认为没发送成功 → 触发重传**。

> **结论**：**我们常说的 TCP 连接，本质就是这些为了可靠传输而维护的状态信息/数据信息的集合。**
> 它是个**虚拟概念**，不是一条真实的软件通道。

### 二、TCP 在网络模型中的位置与三大保障机制

- TCP 位于 **OSI 第四层（传输层）**；
- **网络层（IP 层）是不可靠的**，传输过程中**可能丢包**；
- TCP 在 IP 层之上做控制机制，**保证数据不少**——注意措辞：
  **不是让 IP 层不丢包，而是保证数据完整，丢了能补上**。

| 保障机制 | 作用 |
|---|---|
| **① 三次握手** | 协商序号 + 确认双方都具备收发能力 |
| **② 流量控制** | 根据网络状况**动态调整窗口大小**，决定能发多少数据（带宽小、发太多会导致部分数据丢失） |
| **③ 重传机制** | 收到确认不全时**补发缺失的部分**，保证数据完整 |

### 三、报文结构：头部 + 数据部分

结构上**每行 4 字节**，前 5 个部分固定（20 字节），第 6 部分是**可选项**。

| # | 字段 | 长度 | 说明 |
|---|---|---|---|
| **1** | **源端口 + 目标端口** | 各 2 字节 | 源端口 = 发送方数据出来的端口；目标端口 = 被请求方（接收方）的端口。<br>2 字节 → **端口范围 0 ~ 65535** |
| **2** | **序列号（Sequence Number）** | 4 字节 | **当前报文数据第一个字节的编号**（关键定义，见下） |
| **3** | **确认号（Acknowledgment Number）** | 4 字节 | **序列号 + 数据长度 + 1**（关键算法，见下） |
| **4** | **数据偏移** | **4 bit** | 数据部分从哪个位置开始（即头部有多长）。**单位是 4 字节**<br>最小 `0101`=5 → 5×4 = **20 字节**（最小 TCP 头）<br>最大 `1111`=15 → 15×4 = **60 字节**（含最多 40 字节 Option） |
| **5** | **保留位** | 6 bit | 目前**无实际作用，但必须存在** |
| **6** | **6 个 flag 标志位** | 6 bit | 见下方 flag 表 |
| **7** | **窗口** | 2 字节 | 最大 65535（约 64KB）。**并非固定**——可通过 Option 中的**窗口缩放因子**放大，带宽好时一次发更多数据 |
| **8** | **校验和** | 2 字节 | 判断数据是否**完整到达** |
| **9** | **紧急指针** | 2 字节 | 配合紧急数据，**找到紧急数据优先处理** |
| **10** | **数据部分** | 变长 | 真正的 payload |

**6 个 flag**：

| flag | 含义 |
|---|---|
| **URG** | 紧急——数据里包含需要优先处理的内容 |
| **ACK** | 确认——接收方收到数据后向发送方回复确认 |
| **PSH** | 推送——要求数据**立即交由应用层**处理，而不是在本地做缓存（如实时聊天） |
| **RST** | 重置——**强制断开异常连接** |
| **SYN** | 同步——用于三次握手协商序号 |
| **FIN** | 结束——请求关闭连接 |

### 四、序列号与确认号的取值演算（这里最容易讲不清）

**场景**：三次握手时，客户端随机序列号 `x = 100`，服务端随机序列号 `y = 1000`（都是随机的，只是举例）。

**序列号**：假设要发送 0~9 共 10 个字节：

```
字节 0 所在的位置 = 101
字节 9 所在的位置 = 110
```

> **每个字节都有一个序号**——这样**中间哪一个字节缺失，就能轻易定位并重发**。

**确认号 = 序列号 + 数据长度 + 1**：

```
序列号 100，数据长度 50  →  100 + 50 = 149  →  确认号 = 149 + 1 = 150
```

**确认号 150 的含义**：

> **"150 之前的全部字节我都收到了，我期待你下一个发 150。"**

**丢失场景**：如果接收方回复的是 **130** 而不是 150：

```
发送方：已发到 149
接收方：确认号 130  →  意味着 130 ~ 149 之间的 20 个字节丢失了
→ 发送方触发重传：从 130 开始重传（其前的部分不用管）
```

**这就是重传机制的工作方式——靠确认号判断缺口位置。**

### 面试官会追问什么

- **为什么序列号要随机？** → **防止网络中延迟的旧报文干扰新连接**（同一四元组的新旧连接如果序号重合，会收到上一条连接的残留报文）。
- **窗口最大只有 64KB 够用吗？** → 靠 Option 中的**窗口缩放因子**按指数放大，可支持更大的带宽时延积。
- **MTU 和 MSS 的关系？** → MTU 是链路层能承载的最大帧（以太网 1500 字节），
  **MSS = MTU - IP 头(20) - TCP 头(20) = 1460 字节**。

---


---

## Q4. TCP 三次握手的详细过程是怎样的？为什么必须三次？

**来源**：`p=33` 百度 Go 开发日常实习面试 · 时长 6分22秒
**考察意图**：不能只背"SIN/ACK"，要讲清**每一步双方各自"确认了什么"**。

### 角色前提

**服务端启动服务、监听端口 → 进入 `LISTEN` 状态**。

> ⚠️ 一个容易说错的概念：**`LISTEN` 不是"某个连接"的状态，而是服务端服务本身的状态**——
> 表示它**具备了接收连接请求的能力**。
> 而 `CLOSED` 是一个**理论上存在**的状态（连接都还没建立，谈不上 close）。

### 三次握手逐步拆解

#### 第一次握手：客户端 → 服务端

```
客户端：初始化传输控制模块（TCP），生成随机序列号 x
       → 发送 SYN = x
```

**目的**：**把客户端生成的序列号发送给服务端，让服务端记录下来**。
（再次强调：两者之间没有"软件构建的逻辑通道"，只有物理网络通道。）

#### 第二次握手：服务端 → 客户端（一个报文，两种请求）

**为什么服务端也要发同步请求？**
因为 **TCP 是全双工的**——服务端**既收也发**，它有时也是发送方，
所以**它也需要同步自己的序列号**。

因此这个报文同时表达两件事：

| 内容 | 含义 |
|---|---|
| **SYN = y** | 服务端同步自己的随机序列号 y |
| **ACK = x + 1** | 确认"我已成功收到你上一次的请求" |

**客户端收到后**：完成了一次发送 + 一次接收 → **客户端能发也能收**
→ 客户端状态可标记为**已连接**（从客户端视角它已可用了）。

> ⚠️ 但**此刻连接还没真正建立**：服务端发了 SYN 只能证明"服务端能发"，
> **服务端自己并不知道自己能不能收**（客户端还没确认）。

#### 第三次握手：客户端 → 服务端（同样是"确认 + 同步"）

```
客户端发送：SYN = y + 1   （我收到了你的 y，期待你下一个发 y+1）
          + ACK = x + 1   （满足服务端对客户端序号 x+1 的期待）
```

**服务端收到后**：完成了一次接收 → **服务端也确认自己能收能发了**。

**至此三次握手完成，双方都确认了两件事**：
1. **彼此的收发能力都没问题**；
2. **序号已协商（同步）完毕**。

### 为什么必须三次，不能是一次或两次？

**三次握手的两个目的**（两个都必须达成，而**只有三次才能同时做到**）：

| 目的 | 为什么必须三次 |
|---|---|
| **① 协商（同步）序号** | 双方都要告知对方自己的初始序号，且都要收到对方的确认 |
| **② 确保客户端和服务端都具备收发能力** | 第二次握手结束时，**只有客户端确认了双方可收发**；<br>服务端还不知道自己能不能收——**必须靠第三次握手来确认** |

> 用一句话概括：**要让双方都确认"我能发、我能收、对方能发、对方能收"，至少需要三个报文。**

### 加分点：序号为什么必须随机？

> **防止网络中延迟的旧报文干扰新连接。**
> 若新连接的初始序号与旧连接重复，网络里残留的旧报文可能被误认为本次连接的数据。

### 面试官会追问什么

- **为什么断开连接要四次挥手？** → 因为 TCP 是全双工，**关闭需要两个方向各自关闭**：
  一方发 FIN 只表示"我没数据要发了"，对方可能还有数据要发，
  所以 ACK 和 FIN 通常不能合并（除非对方恰好也没数据了），因此需要四次。
- **SYN 洪泛攻击是什么？** → 攻击者只发第一次握手就不回应，
  服务端维持大量半连接（SYN_RCVD）耗尽资源。防御：**SYN Cookie**、缩短超时、限制半连接数。
- **三次握手的第三个报文丢了会怎样？** → 服务端会超时重传 SYN+ACK；
  客户端若已认为连接建立并发了数据，会因收到重传的 SYN+ACK 而重发 ACK。

---

## 使用一：Go（把窗口、Nagle、保活落到 socket 选项上）⭐

> 校验说明：本节 Go 代码只用标准库，`go build` + `go vet` + `gofmt` 通过；Windows/Linux 均可编译，**未做运行时验证**。
> 各代码块同属包 `tcpdemo`。

### 1. 先纠正一个默认值认知

正文 Q2 的追问说「`SetNoDelay(true)` 关闭 Nagle，**Go 默认就开启了 NoDelay**」——这句话值得记成表：

| 选项 | 内核默认 | **Go `net` 包的默认** | 结论 |
|---|---|---|---|
| Nagle（`TCP_NODELAY`） | **开启** Nagle | **`NoDelay = true`，即默认关闭 Nagle** | 从 Go 出发不需要"为了低延迟去关 Nagle"；反而要**为了批量小写而显式开启** |
| `SO_KEEPALIVE` | 关闭 | **Go 默认开启，且把 `net.Dialer.KeepAlive` 默认设为 15s（覆盖内核 7200s）** | 不用额外配置，但**别以为它等同于应用层心跳** |
| `TCP_CORK` / `TCP_QUICKACK` | — | **Go 标准库不暴露** | 要用得靠 `golang.org/x/sys/unix`，见第 5 节 |

> ⚠️ Go 的 `KeepAlive` 只是**打开 `SO_KEEPALIVE` + 设置空闲时间**，它探测的是"对端 TCP 栈还在不在"，
> **探测不到"进程卡死但内核仍在"**——所以业务心跳（应用层 ping）仍然必须自己写。
> 这也解释了正文「半开连接」为什么难搞（详见 [IO多路复用.md](IO多路复用.md) 的 `CLOSE_WAIT` 一节）。

### 2. 建连时一次性定好选项

连接一旦进了连接池，再改选项就得重建连接——所以**所有选项都放在拨号这一步**。

```go

package tcpdemo

import (
	"context"
	"errors"
	"fmt"
	"net"
	"time"
)

// Options 把"这条连接该怎么表现"显式化，而不是散落在各处 setsockopt。
type Options struct {
	NoDelay bool          // true = 关 Nagle（延迟敏感）；false = 开 Nagle（批量小写）
	Idle    time.Duration // keepalive 空闲探测起点
	Intvl   time.Duration // 探测间隔
	Count   int           // 探测失败次数上限
	Rbuf    int           // SO_RCVBUF；0 = 不设置（交给内核自动调优）
	Wbuf    int           // SO_SNDBUF；0 = 不设置
}

// LowLatency 是 RPC / Redis 型客户端的取向：关 Nagle + 快速发现死连接。
func LowLatency() Options {
	return Options{NoDelay: true, Idle: 10 * time.Second, Intvl: 3 * time.Second, Count: 3}
}

// BulkWrite 是日志、埋点、文件同步的取向：让内核攒够 MSS 再发，换取吞吐。
func BulkWrite() Options {
	return Options{NoDelay: false, Idle: 30 * time.Second, Intvl: 10 * time.Second, Count: 3, Rbuf: 512 << 10, Wbuf: 512 << 10}
}

// Dial 拨号并把所有选项设好；**任何一步失败都要 Close**，否则连接泄漏。
func Dial(ctx context.Context, addr string, o Options) (*net.TCPConn, error) {
	d := net.Dialer{
		Timeout: 3 * time.Second, // ⚠️ 只管"建连"，不管读写——读写超时见第 3 节
		// 这里故意不设 KeepAlive：我们要用更细粒度的 SetKeepAliveConfig
	}

	c, err := d.DialContext(ctx, "tcp", addr)
	if err != nil {
		return nil, fmt.Errorf("tcpdemo: dial %s: %w", addr, err)
	}

	tc, ok := c.(*net.TCPConn)
	if !ok { // 用自定义 DialContext/代理时拿到的可能是包装过的 Conn
		_ = c.Close()
		return nil, errors.New("tcpdemo: dialer returned a non-TCP connection")
	}

	if err := setup(tc, o); err != nil {
		_ = tc.Close()
		return nil, err
	}
	return tc, nil
}

func setup(tc *net.TCPConn, o Options) error {
	if err := tc.SetNoDelay(o.NoDelay); err != nil {
		return fmt.Errorf("set no-delay: %w", err)
	}

	// Go 1.23+ 才有：可以精确控制 idle/interval/count，而不是只能设一个开关
	if err := tc.SetKeepAliveConfig(net.KeepAliveConfig{
		Enable:   o.Idle > 0,
		Idle:     o.Idle,
		Interval: o.Intvl,
		Count:    o.Count,
	}); err != nil {
		return fmt.Errorf("set keepalive: %w", err) // 老版本 Go / 不支持的平台上要能降级而不是崩
	}

	// ⚠️ 在 Linux 上显式 setsockopt(SO_RCVBUF) 会**关掉该连接的接收缓冲自动调优**，
	// 高带宽链路上等于把窗口能力人为封死。所以默认值必须是 0 = 不设置。
	if o.Rbuf > 0 {
		if err := tc.SetReadBuffer(o.Rbuf); err != nil {
			return fmt.Errorf("set read buffer: %w", err)
		}
	}
	if o.Wbuf > 0 {
		if err := tc.SetWriteBuffer(o.Wbuf); err != nil {
			return fmt.Errorf("set write buffer: %w", err)
		}
	}
	return nil
}
```

> **窗口在应用层的唯一杠杆就是这个发送/接收缓冲**：正文说的 `min(rwnd, cwnd)` 是内核的事，
> 但 `rwnd` 的上限来自接收缓冲的大小。真正"窗口不够用"的表现是
> `ss -ti` 里 `rcv_space` 一直在涨而 `rcv_ssthresh` 被压着——那时要调的是内核参数与缓冲策略，
> 不是在这段代码里加个更大的数字。

### 3. 字节流没有边界：长度前缀编解码

正文 Q3 的「序列号是**字节**编号」就是这句话的来源：**TCP 交付的是流，不是消息**。
`Write` 返回 `nil` 只表示进了发送缓冲，**不表示对方收到**；一次 `Read` 也可能只拿到半个包。
所以任何自定义协议都必须自己划界——**生产上最常用的就是 4 字节长度前缀**。

```go

package tcpdemo

import (
	"bufio"
	"encoding/binary"
	"errors"
	"fmt"
	"io"
	"net"
	"time"
)

// MaxFrame 是硬性上限：**长度字段来自网络，属于不可信输入**。
// 不设上限时，一个恶意/损坏的 "长度=4G" 会让服务端立刻分配 4GB 内存。
const MaxFrame = 4 << 20

// WriteFrame 头和体**合成一个 buffer 一次写出**。
// 分两次 Write 在语义上没错（TCP 会拼起来），但会多一次系统调用，
// 而且在关闭 Nagle 的连接上更容易被拆成两个段发出去。
func WriteFrame(w io.Writer, payload []byte) error {
	if len(payload) > MaxFrame {
		return fmt.Errorf("tcpdemo: payload %d bytes exceeds frame limit %d", len(payload), MaxFrame)
	}

	buf := make([]byte, 4+len(payload))
	binary.BigEndian.PutUint32(buf[:4], uint32(len(payload))) // 统一大端：网络字节序
	copy(buf[4:], payload)

	_, err := w.Write(buf)
	return err
}

// ReadFrame 必须配 *bufio.Reader："读满 N 字节"是它的基本职责。
// 直接对 conn 做 4 次 Read 看似等价，实际会在半包处把协议解析写成一堆分支。
func ReadFrame(r *bufio.Reader) ([]byte, error) {
	var hdr [4]byte
	if _, err := io.ReadFull(r, hdr[:]); err != nil {
		// io.ErrUnexpectedEOF：对端在"半个头"处关闭连接，属于协议级错误，不能当普通 EOF 忽略
		return nil, err
	}

	n := binary.BigEndian.Uint32(hdr[:])
	if n > MaxFrame {
		return nil, fmt.Errorf("tcpdemo: peer announced %d bytes, limit is %d", n, MaxFrame)
	}

	payload := make([]byte, n)
	if _, err := io.ReadFull(r, payload); err != nil {
		return nil, err
	}
	return payload, nil
}

// Serve 演示"每条连接一个 goroutine + 逐帧处理"，以及为什么读写都要单独设 deadline。
func Serve(ln net.Listener) error {
	for {
		c, err := ln.Accept()
		if err != nil {
			return err
		}
		go handle(c.(*net.TCPConn))
	}
}

func handle(c *net.TCPConn) {
	defer c.Close() // ⚠️ defer Close 的位置只能在函数入口：中途任何 return 都不能漏

	r := bufio.NewReader(c)
	for {
		// 每帧重设 deadline：一次性设 30s 只覆盖第一条请求，长连接会莫名超时
		if err := c.SetReadDeadline(time.Now().Add(30 * time.Second)); err != nil {
			return
		}
		frame, err := ReadFrame(r)
		if errors.Is(err, io.EOF) {
			return // 对端正常关闭
		}
		if err != nil {
			return // 含超时（net.Error.Timeout）与半包；生产上这里要分开打点
		}

		if err := c.SetWriteDeadline(time.Now().Add(5 * time.Second)); err != nil {
			return
		}
		// ⚠️ 对端不收 → 发送缓冲写满 → Write 阻塞。没有写 deadline 时，
		// 这个 goroutine 会永久挂着，连接与内存一起泄漏（这就是协程泄漏最常见的形态之一）。
		if err := WriteFrame(c, append([]byte("echo:"), frame...)); err != nil {
			return
		}
	}
}
```

> 三种划界方式的选择：

| 方式 | 适用 | 缺点 |
|---|---|---|
| **长度前缀** | 通用，HTTP/2、gRPC、绝大多数私有协议 | 需要双方约定宽度与字节序 |
| **分隔符**（如 `\r\n`） | 文本协议（Redis RESP、HTTP 头） | 值里出现分隔符必须转义 |
| **定长** | 极简单的固定结构 | 浪费或需二次变长 |

### 4. 半关闭：`FIN` 只关一个方向

正文「四次挥手」的实现层对应物就是 `CloseWrite`——**这是"我发完了"而不是"连接结束了"**：

```go

package tcpdemo

import (
	"errors"
	"io"
	"net"
)

// RequestThenDrain 表达正确的请求/结束语义：
// 把 req 写进 socket，然后 CloseWrite 告知对端"我没有更多数据了"，但**仍然继续读完响应**，
// 最后才 Close 整条连接。反过来"写完直接 Close"会让对端的响应永远发不回来。
func RequestThenDrain(c *net.TCPConn, req io.Reader, resp io.Writer) error {
	if _, err := io.Copy(c, req); err != nil { // 这里是"写进 socket"，不是读
		return err
	}
	if err := c.CloseWrite(); err != nil {
		return err
	}

	if _, err := io.Copy(resp, c); err != nil && !errors.Is(err, io.ErrUnexpectedEOF) {
		return err
	}
	return c.Close()
}
```

> `UDPConn` 也有 `SetWriteBuffer` 之类选项，但 UDP 没有连接概念，
> 上面的划界与半关闭对它完全不成立——这正是正文「连接是双方各自维护的状态集合」的直接推论。

### 5. 观测：怎么确认 Nagle / 窗口 / 重传在起作用

| 想验证的东西 | 命令 / 手段 | 看什么 |
|---|---|---|
| **Nagle 是否生效** | 关掉 `TCP_NODELAY` 后连续发 2 个 1 字节包，`tcpdump -i lo -nn -s0 'tcp port 9000' -tt` | 两个包之间是否出现 ~40ms（Linux 上 Nagle 超时是 40ms，不是正文举例的 200ms）或"第二个 ACK 到达才发下一包" |
| **实际通告窗口** | `ss -tin dst 127.0.0.1:9000` | `rcv_space`（内核自动调优目标）、`rcv_ssthresh`、`snd_cwnd` |
| **零窗口 / 窗口阻塞** | `ss -tni` + `nstat -az \| grep -i 'TcpExt.TCPBacklogDrop\|TcpExt.TCPZeroWindowDrop\|TcpExt.TCPWantZeroWindowAdv'` | 接收方处理不过来 → 正文说的"窗口探测"计数会涨 |
| **重传与快速重传** | `nstat -az \| grep -i retrans`；`ss -ti` 里的 `retrans:...` | 丢包后是否走 SACK 快速重传（正文"累积确认→快速重传"的现实版） |
| **SYN 洪泛 / 半连接** | `ss -s`（看 `TCP: ... timewait`）、`ss -tn state syn-recv` | 半连接堆积 = 正文 Q4 追问里的 SYN 洪泛现场 |
| **`CLOSE_WAIT` 堆积** | `ss -tn state close-wait` | 对端已发 FIN 而**我方代码没 `Close`**——99% 是漏了 defer Close 或 goroutine 卡在写 |

```bash

# 一个能直接跑出"Nagle 合并小包"现象的观测组合
sudo tcpdump -i lo -nn -s0 -tt 'tcp port 9000 and greater 60' &
go run ./cmd/nagledemo -nodelay=false   # 每 5ms 写 1 字节，观察抓包里小包是否被攒成大包
```

---

## 使用二：Java（Socket 选项与 Netty 的对应物）

> ⚠️ 本节 Java 代码按 JDK 17 + Netty 4.1 书写，**未编译校验**。三条结论与 Go 侧一致。

### 1. `Socket` / `SocketOption` 对照

```java

package notes.tcp;

import java.io.IOException;
import java.net.InetSocketAddress;
import java.net.StandardSocketOptions;
import java.nio.channels.SocketChannel;
import java.time.Duration;

public class Channels {

    // 低延迟：对应 Go 的 LowLatency()
    public static SocketChannel lowLatency(String host, int port, Duration connectTimeout) throws IOException {
        SocketChannel ch = SocketChannel.open();
        // ⚠️ JDK 的 TCP_NODELAY 默认值是 **true**（即默认关 Nagle）——和 Go 一致，和内核默认相反
        ch.setOption(StandardSocketOptions.TCP_NODELAY, true);
        ch.setOption(StandardSocketOptions.SO_KEEPALIVE, true);
        // 显式设接收缓冲同样会让 Linux 关掉自动调优：能不设就不设
        // ch.setOption(StandardSocketOptions.SO_RCVBUF, 256 * 1024);

        // JDK 11+ 才有扩展选项；JDK 8 只能用内核默认值（idle 7200s），
        // 这就是"老应用发现死连接要等两小时"的原因
        ch.setOption(ExtendedSocketOptions.TCP_KEEPIDLE, 10);          // 秒
        ch.setOption(ExtendedSocketOptions.TCP_KEEPINTERVAL, 3);
        ch.setOption(ExtendedSocketOptions.TCP_KEEPCOUNT, 3);

        // configureBlocking(false) 之后 connect() 立即返回 false，必须靠 finishConnect() 收尾
        if (!ch.connect(new InetSocketAddress(host, port))) {
            long deadline = System.nanoTime() + connectTimeout.toNanos();
            while (System.nanoTime() < deadline) {
                if (ch.finishConnect()) {
                    return ch;
                }
                Thread.onSpinWait(); // 生产上这里应该是 Selector，不是自旋
            }
            ch.close();
            throw new IOException("connect timeout: " + host + ":" + port);
        }
        return ch;
    }
}
```

### 2. Netty：`LengthFieldBasedFrameDecoder` 就是第 3 节的工业版

```java

package notes.tcp;

import io.netty.channel.ChannelInitializer;
import io.netty.channel.socket.SocketChannel;
import io.netty.handler.codec.LengthFieldBasedFrameDecoder;
import io.netty.handler.codec.LengthFieldPrepender;
import io.netty.handler.timeout.IdleStateHandler;
import java.util.concurrent.TimeUnit;

public class FrameInitializer extends ChannelInitializer<SocketChannel> {

    private static final int MAX_FRAME = 4 << 20;

    @Override
    protected void initChannel(SocketChannel ch) {
        ch.config().setTcpNoDelay(true);          // 对应 Go 的 SetNoDelay
        ch.config().setSoKeepAlive(true);
        ch.config().setWriteBufferWaterMark(new io.netty.channel.WriteBufferWaterMark(32 << 10, 64 << 10));
        // ↑ 高水位 = 发送缓冲积压上限，触发后 isWritable() 变 false —— 这就是正文"窗口打满"在应用层的镜像

        // 4 字节大端长度前缀 + 不剥离（保留原始帧）
        ch.pipeline().addLast(new LengthFieldBasedFrameDecoder(MAX_FRAME, 0, 4, 0, 4));
        ch.pipeline().addLast(new LengthFieldPrepender(4, false));

        // 应用层心跳：SO_KEEPALIVE 探测不到"进程卡死"，必须靠它
        ch.pipeline().addLast(new IdleStateHandler(30, 0, 0, TimeUnit.SECONDS));
    }
}
```

> **Netty 与 Go 的一个方向性差异**：Netty 显式区分 EventLoop / 工作线程（见
> [IO多路复用.md](IO多路复用.md) 的 Reactor 一节），**Handler 里绝对不能做阻塞调用**；
> Go 用「每连接一个 goroutine」把这件事交给了调度器，所以 Go 侧的坑是
> **忘记 `SetReadDeadline`**（ goroutine 挂死），Netty 侧的坑是 **EventLoop 被阻塞**（一批连接全部卡住）。

---

## 使用三：最小可运行的对照实验

正文 Q1/Q2 的机制都可以自己复现一遍，比背结论牢。

### 1. 服务端与客户端（Go）

```go

package tcpdemo

import (
	"bufio"
	"context"
	"errors"
	"fmt"
	"io"
	"net"
	"time"
)

// EchoServer 供下面的实验使用。
func EchoServer(addr string) (net.Listener, error) {
	ln, err := net.Listen("tcp", addr)
	if err != nil {
		return nil, err
	}
	go func() {
		for {
			c, err := ln.Accept()
			if err != nil {
				return
			}
			go func(c net.Conn) {
				defer c.Close()
				_, _ = io.Copy(c, c) // 原样回吐；长度前缀协议下等价于 echo
			}(c)
		}
	}()
	return ln, nil
}

// MeasureRoundTrip 连发 n 个 1 字节帧，测平均 RTT：
// 把 noDelay 改成 false 再跑一次，延迟会明显变大——那就是 Nagle 与延迟确认互相拖累的现场。
func MeasureRoundTrip(addr string, noDelay bool, n int) (time.Duration, error) {
	if n <= 0 {
		return 0, fmt.Errorf("tcpdemo: n must be > 0, got %d", n)
	}

	c, err := Dial(context.Background(), addr, Options{
		NoDelay: noDelay, Idle: 10 * time.Second, Intvl: 3 * time.Second, Count: 3,
	})
	if err != nil {
		return 0, err
	}
	defer c.Close()

	r := bufio.NewReader(c)
	total := time.Duration(0)

	for i := range n {
		start := time.Now()
		if err := WriteFrame(c, []byte{byte(i)}); err != nil {
			return 0, err
		}
		if err := c.SetReadDeadline(time.Now().Add(5 * time.Second)); err != nil {
			return 0, err
		}
		if _, err := ReadFrame(r); err != nil && !errors.Is(err, io.EOF) {
			return 0, err
		}
		total += time.Since(start)
	}

	return total / time.Duration(n), nil
}
```

```bash

# 观测三件套（Linux 机器上跑，Windows 用 WSL）
sudo ss -tinp 'dst :9000'                      # 看 cwnd / rcv_space / retrans
sudo nstat -az | egrep -i 'TcpExtTCPSlowStartRetrans|TcpExtTCPFastRetrans|TcpExtTCPRenoRecovery'
sudo ss -tn state close-wait                   # 实验结束若这里有残留 = 代码漏了 Close
```

> ⚠️ 上面这段实验代码为了单文件可编译，把 `context()` 写成了同名占位函数——
> **贴进自己项目时删掉它，直接用 `context.Background()`**。真实项目里
> 连接池、指标打点、优雅关闭（对应 [../docker/K8s与镜像优化.md](../docker/K8s与镜像优化.md) 的优雅停机）都要接上。

### 2. 用 `tc` 制造丢包，看快速重传

```bash

# 在 10% 丢包、100ms 延迟的链路上跑同一个实验，感受"窗口 = 吞吐上限"
sudo tc qdisc add dev lo root netem loss 10% delay 100ms
go run ./cmd/tcpbench -addr 127.0.0.1:9000 -nodelay=true
sudo tc qdisc del dev lo root               # ⚠️ 一定记得删，忘了删本机网络会一直"丢包"
```

| 观察点 | 机制 | 对应正文 |
|---|---|---|
| 吞吐随 RTT 上升而下降 | 带宽时延积固定，窗口决定在途数据量 | Q1「窗口决定了能发多少」 |
| 少量丢包时不是等超时，而是 **3 个重复 ACK 触发快速重传** | SACK + 重复 ACK | Q1 追问「累积确认 → 快速重传」 |
| 打开 Nagle 后小包 RTT 呈阶梯式变差 | Nagle 等 ACK × 延迟 ACK 等 40ms | Q2 的"互相伤害" |

---
