# TCP 滑动窗口

> 窗口怎么滑、窗口的本质是什么、窗口如何决定吞吐上限，以及把窗口与保活落到 socket 选项上的 Go / Java 写法与可复现实验。
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

## 使用一：Go（把窗口、Nagle、保活落到 socket 选项上）⭐

> 校验说明：本节 Go 代码只用标准库，`go build` + `go vet` + `gofmt` 通过；Windows/Linux 均可编译，**未做运行时验证**。
> 各代码块同属包 `tcpdemo`。

### 1. 建连时一次性定好选项

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
		Timeout: 3 * time.Second, // ⚠️ 只管"建连"，不管读写——读写超时见 [TCP报文结构.md](TCP报文结构.md) 使用一
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

### 2. 观测：怎么确认 Nagle / 窗口 / 重传在起作用

| 想验证的东西 | 命令 / 手段 | 看什么 |
|---|---|---|
| **Nagle 是否生效** | 关掉 `TCP_NODELAY` 后连续发 2 个 1 字节包，`tcpdump -i lo -nn -s0 'tcp port 9000' -tt` | 两个包之间是否出现 ~40ms（Linux 上 Nagle 超时是 40ms，不是正文举例的 200ms）或"第二个 ACK 到达才发下一包" |
| **实际通告窗口** | `ss -tin dst 127.0.0.1:9000` | `rcv_space`（内核自动调优目标）、`rcv_ssthresh`、`snd_cwnd` |
| **零窗口 / 窗口阻塞** | `ss -tni` + `nstat -az \| grep -i 'TcpExt.TCPBacklogDrop\|TcpExt.TCPZeroWindowDrop\|TcpExt.TCPWantZeroWindowAdv'` | 接收方处理不过来 → 正文说的"窗口探测"计数会涨 |
| **重传与快速重传** | `nstat -az \| grep -i retrans`；`ss -ti` 里的 `retrans:...` | 丢包后是否走 SACK 快速重传（正文"累积确认→快速重传"的现实版） |
| **SYN 洪泛 / 半连接** | `ss -s`（看 `TCP: ... timewait`）、`ss -tn state syn-recv` | 半连接堆积 = [TCP三次握手.md](TCP三次握手.md) 追问里的 SYN 洪泛现场 |
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

## 使用三：最小可运行的对照实验

本文件与 [TCP的Nagle与延迟确认.md](TCP的Nagle与延迟确认.md) 讲到的机制都可以自己复现一遍，比背结论牢。

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
> 连接池、指标打点、优雅关闭（对应 [../docker/K8s部署与生命周期.md](../docker/K8s部署与生命周期.md) 的优雅停机）都要接上。

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
| 打开 Nagle 后小包 RTT 呈阶梯式变差 | Nagle 等 ACK × 延迟 ACK 等 40ms | [TCP的Nagle与延迟确认.md](TCP的Nagle与延迟确认.md) 的"互相伤害" |

---

## 关联

- [TCP的Nagle与延迟确认.md](TCP的Nagle与延迟确认.md) — 发送端合并小包与接收端合并 ACK 的另一半
- [TCP报文结构.md](TCP报文结构.md) — 窗口字段所在的首部与字节流边界问题
- [TCP三次握手.md](TCP三次握手.md) — 窗口协商发生在建连阶段
- [IO多路复用.md](IO多路复用.md) — 半开连接、心跳与事件循环
