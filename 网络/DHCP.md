# DHCP

> DHCP 的四个阶段（DORA）与 T1 / T2 两个续租时间点，以及这套「租约」形状在别处的复用。
>
> 内容整理自大厂 Go 后端面试真题视频，参考资料与原始素材见 [素材清单](../素材清单.md)。

---

## 一、DHCP 的具体流程是怎样的？什么时候续租？

**来源**：`p=16` 百度 Go 开发日常实习面试 · 时长 10分56秒

**考察意图**：面试官给了三个递进问题——**DHCP 的作用、工作流程、何时续租**。
"续租"是大多数人答不上来的部分。

### 一、DHCP 的作用与适用场景

**作用：动态分配 IP 地址。**

**为什么需要动态分配？** 典型场景：

| 场景 | 说明 |
|---|---|
| **虚拟机** | 大量实例动态创建/销毁，逐个手工配 IP 不现实 |
| **企业内网** | 所有机器接入内网，通过内网访问公网 |
| **手机 / 笔记本连 WiFi** | 设备作为客户端，只需能访问公网服务 |

**共同特征**：这些设备都是**客户端**，只需要**主动访问服务端**，
**不需要被公网上的其他设备访问**。既然如此，就**不需要一个固定的公网唯一 IP**。

> 所以：动态 IP 适用于"**客户端接入网络去访问服务端资源**"的场景。
> 反过来，如果自己的机器要**作为服务器对外发布**，就不适合用动态分配。

### 二、动态分配带来的三个好处

1. **不需要显式管理地址** → 只要池子够，**不会出现 IP 冲突**；
2. **避免手工配置网络参数**（网关、DNS 等），配置本身也可能填错；
3. **地址租约管理由 DHCP 自动完成**（含续期），不用自己操心。

### 三、四个阶段的工作流程（DORA）

角色说明：**路由器**通常就充当 DHCP 服务器；**手机/电脑**是客户端设备。
前提：客户端**已经接入网络**（网线插好 / WiFi 连上），只是**还没有 IP 地址**。

> ⚠️ 关键前提带来的约束：**客户端没有 IP，就无法点对点通信**，
> 所以整个流程**几乎全靠广播**。

| 阶段 | 方向 | 说明 |
|---|---|---|
| **① Discover（发现）** | 客户端 → **广播** | 客户端向 `255.255.255.255` 发送发现请求。网内所有设备（含 DHCP 服务器）都能收到。因为是广播，且客户端**无地址**，服务器无法直连它，所以**服务器也只能用广播回应**。 |
| **② Offer（提供）** | DHCP 服务器 → **广播** | 服务器提供可用 IP、**租期**、网关等网络参数。客户端可能收到**多个** Offer，通常选**第一个到达**的。客户端会携带自己的 **MAC 地址**作为唯一标识，各客户端靠 MAC 匹配确认是不是给自己的。 |
| **③ Request（请求）** | 客户端 → **广播** | 客户端声明"我想用哪个 IP"。这一步也必须广播——因为**服务器并不知道客户端选了谁家的 Offer**。多个服务器都能收到，但**只有一个匹配**。 |
| **④ Ack（确认）** | DHCP 服务器 → **广播** | 服务器确认同意使用该地址。至此客户端才真正获得 IP，可以接入网络通信。 |

> **重点**：前四个阶段**全部使用广播**——因为客户端在拿到地址前根本没有单播能力。

### 四、何时续租（`T1` / `T2` 两个时间点）

现实观察：**只要不换网络，IP 基本不会变**——这正是**续租**的效果。

以**租期 24 小时**为例：

| 时间点 | 触发动作 |
|---|---|
| **50%（12 小时）** | 客户端**单播**向 DHCP 服务器请求续租（此时客户端已有 IP，**可以单播，不需要广播**） |
| **87.5%（21 小时）** | 若上一次续租未成功，再次发起续租请求 |
| 租期到期 | 仍未成功则释放地址，重新走 DORA 流程 |

> **关键区别**：**续租阶段是单播，DORA 阶段是广播**——
> 因为续租时客户端已有 IP 地址，具备点对点通信能力。

### 面试答题要点

1. 讲清 **DHCP 的作用 + 现实场景举例**（虚拟机、企业内网、手机连 WiFi）；
2. 讲清 **DORA 四步流程**，并强调"**全是广播**"及其原因；
3. 讲清 **续租的两个时间点（50% / 87.5%）**，并点出**续租是单播**这一差异。

---


---

## 使用一：Go（租约状态机：T1/T2 的形状在别处还会用到三次）⭐
### 1. 租约状态机：T1/T2 的形状在别处还会用到三次

正文「50% 续租、87.5% 再续、到期重来」之所以取 50% 而不是 95%，
是因为**要留出足够长的失败重试窗口**。这个形状对下面这些东西完全通用：

| 场景 | Renew（=T1） | Rebind（=T2） | 到期（100%） |
|---|---|---|---|
| **DHCP** | 单播问原服务器续 | 广播问任意服务器 | 释放地址，重新 DORA |
| **OAuth access token** | 用 refresh token 换新 | 重新走授权 | 强制用户登录 |
| **Redis 分布式锁** | watchdog 提前续期 | 续期失败要停写 | TTL 到 → 可能被别人抢到 |
| **K8s ServiceAccount token** | 提前 20%~30% 刷新 | 重建 client | 请求 401 |

```go
package leasedemo

import (
	"context"
	"errors"
	"math/rand"
	"sync"
	"time"
)

var ErrLeaseLost = errors.New("leasedemo: lease lost, must re-acquire")

type State int

const (
	StateBound State = iota
	StateRenewing
	StateRebinding
)

func (s State) String() string {
	switch s {
	case StateRenewing:
		return "RENEWING"
	case StateRebinding:
		return "REBINDING"
	default:
		return "BOUND"
	}
}

// Renewal 是一份新的有效期。三个时间点是正文那张表的直接翻译。
type Renewal struct {
	RenewAt  time.Time // T1 = 50%
	RebindAt time.Time // T2 = 87.5%
	ExpireAt time.Time // 100%
}

// Split 按正文比例切分租期。
// 为什么是 87.5% 而不是 90%？——因为 24h 的 87.5% 正好是 21h，
// 而 50%→87.5% 之间那 37.5%（9 小时）就是"续租失败后还能重试多久"的预算。
func Split(start time.Time, duration time.Duration) Renewal {
	return Renewal{
		RenewAt:  start.Add(duration / 2),
		RebindAt: start.Add(duration * 7 / 8),
		ExpireAt: start.Add(duration),
	}
}

// Lease 是"带自动续期的授权"。
// ⚠️ Renew / Rebind 两个回调必须是**并发安全且幂等**的：
// 一次网络抖动可能让它们被重复触发（DHCP 里对应"发了 REQUEST 没收到 ACK 又发一次"）。
type Lease struct {
	mu     sync.Mutex
	cur    Renewal
	state  State
	Renew  func(context.Context) (Renewal, error) // 单播给原服务端
	Rebind func(context.Context) (Renewal, error) // 广播/问任意服务端
	// Jitter 给续期时刻加随机偏移：一万个客户端在同一个 12 小时点齐刷刷续租，
	// 就是正文没提但一定会遇到的"DHCP 服务器被自己打挂"
	Jitter time.Duration
	Now    func() time.Time
}

func New(r Renewal, renew, rebind func(context.Context) (Renewal, error)) *Lease {
	return &Lease{cur: r, Renew: renew, Rebind: rebind, Now: time.Now}
}

func (l *Lease) State() State {
	l.mu.Lock()
	defer l.mu.Unlock()
	return l.state
}

// Wait 阻塞直到租约彻底失效或 ctx 取消；正常情况它永不返回（相当于一个续租守护协程）。
func (l *Lease) Wait(ctx context.Context) error {
	for {
		now := l.Now()
		r := l.snapshot()

		var (
			target time.Time
			useReb bool
		)
		switch {
		case now.Before(r.RenewAt):
			target, useReb = r.RenewAt, false
		case now.Before(r.RebindAt):
			target, useReb = r.RenewAt, false // 已过 T1：立刻补做续租
		case now.Before(r.ExpireAt):
			target, useReb = r.RebindAt, true // 已过 T2：换广播路径
		default:
			return ErrLeaseLost // 100%：调用方要重新走"申请"流程（DHCP 的 DISCOVER）
		}

		if !useReb {
			// 只有 T1 这一档加抖动：T2 是兜底，不能再往后推
			target = target.Add(-time.Duration(rand.Int63n(int64(max(l.Jitter, time.Millisecond)))))
		}

		if d := time.Until(target); d > 0 {
			select {
			case <-ctx.Done():
				return ctx.Err()
			case <-time.After(d):
			}
		}

		if err := l.attempt(ctx, useReb); err != nil {
			if errors.Is(err, ErrLeaseLost) {
				return err
			}
			// 续期失败不退出：短退避后进入下一轮判断，
			// 时间自然会把它推向 T2 → 到期，行为与 DHCP 客户端一致
			select {
			case <-ctx.Done():
				return ctx.Err()
			case <-time.After(time.Second):
			}
		}
	}
}

func (l *Lease) attempt(ctx context.Context, rebind bool) error {
	fn := l.Renew
	l.mu.Lock()
	l.state = StateRenewing
	if rebind {
		fn = l.Rebind
		l.state = StateRebinding
	}
	l.mu.Unlock()

	if fn == nil {
		return ErrLeaseLost
	}
	next, err := fn(ctx)
	if err != nil {
		return err
	}

	l.mu.Lock()
	l.cur, l.state = next, StateBound // 逐字段赋值：Renewal 是值类型，不会把外层的 mutex 一起复制
	l.mu.Unlock()
	return nil
}

func (l *Lease) snapshot() Renewal {
	l.mu.Lock()
	defer l.mu.Unlock()
	return l.cur
}
```

> **为什么这套值得单独写一遍**：绝大多数"token 过期突然全线 401"「锁 TTL 到点被抢」的事故，
> 根因都是**在到期那一刻才去处理**——也就是只有 100% 一档、没有 T1/T2。
> 用 50% 提前量，就是把"失败重试的时间"显式买下来。

---

## 使用二：Java（租约式刷新：同一个形状）
### 1. 租约式刷新：同一个形状

```java
package notes.appproto;

import java.time.Duration;
import java.time.Instant;
import java.util.concurrent.Executors;
import java.util.concurrent.ScheduledExecutorService;
import java.util.concurrent.TimeUnit;
import java.util.function.Supplier;

// 对应 Go 的 Lease：OAuth token、Kerberos ticket、Redis 锁续期都是这个形状。
public final class LeaseKeeper<T> implements AutoCloseable {

    private final ScheduledExecutorService exec = Executors.newSingleThreadScheduledExecutor(r -> {
        Thread t = new Thread(r, "lease-renew");
        t.setDaemon(true);
        return t;
    });

    private final Supplier<Renewed<T>> acquire;
    private volatile Renewed<T> current;

    public record Renewed<T>(T value, Instant expireAt) {
        public Duration lifetime() {
            return Duration.between(Instant.now(), expireAt);
        }
    }

    public LeaseKeeper(Supplier<Renewed<T>> acquire) {
        this.acquire = acquire;
        this.current = acquire.get();
        schedule();
    }

    private void schedule() {
        // ⚠️ 关键：在 50% 处续期而不是到期时（正文 DHCP T1 的教训）
        long delayMs = Math.max(current.lifetime().toMillis() / 2, 1000);
        exec.schedule(this::renew, delayMs, TimeUnit.MILLISECONDS);
    }

    private void renew() {
        try {
            current = acquire.get();
        } catch (RuntimeException e) {
            // 失败不取消守护：缩短间隔重试，留出 T2 的语义
            exec.schedule(this::renew, 1, TimeUnit.SECONDS);
            return;
        }
        schedule();
    }

    public T get() {
        return current.value();
    }

    @Override
    public void close() {
        exec.shutdownNow();
    }
}
```

---

## 使用三：抓包与命令实操（DORA 与续租）
### 1. DHCP：看 DORA 与续租

```bash
# 抓四个广播包：注意源地址全是 0.0.0.0、目的 255.255.255.255、端口 67/68
sudo tcpdump -i eth0 -nn -e 'port 67 or port 68'

# 观察一次完整的 DISCOVER→OFFER→REQUEST→ACK（renew 会看到 Request→Ack 走**单播**）
sudo tcpdump -i eth0 -nn '(port 67 or port 68) and not broadcast'

# 客户端侧：租约文件里能直接读到 T1(renew)/T2(rebind)/expiry 三个值
cat /var/lib/dhcpd/dhclient.*.leases   # 或 networkd：resolvectl status / networkctl status
```

租约字段与正文的对应关系：

| `dhclient` 租约里的字段 | 正文概念 |
|---|---|
| `renew 1769...;` | **T1 = 50%** → 单播 `REQUEST` 给原服务器 |
| `rebind 1769...;` | **T2 = 87.5%** → **广播** `REQUEST` 给任意服务器 |
| `expire ...;` | 100% → 释放地址，重新走 DORA |
| `interface "eth0";` + `uid`/`hw-address` | 正文「靠 MAC 作为唯一标识」 |

```bash
# 快速验证"续租是单播、DORA 是广播"：只过滤单播的 DHCP 包，能抓到说明已进入 RENEWING
sudo tcpdump -i eth0 -nn 'port 67 and not src 0.0.0.0'

# 容器/虚机里排查"IP 拿不到"：先看有没有收到 Offer，再看有没有发出 Request
sudo tcpdump -i eth0 -nn -c 20 'port 67 or port 68' -vv
```

---

---

## 面试官会追问什么

- **为什么 DISCOVER 要用广播？** → 客户端此刻**还没有 IP**，只能靠二层广播；到了 RENEW 阶段已经有地址，就改成单播——这一点常被记反。
- **DHCP 和静态 IP 怎么选？** → 服务器/网关用静态或 DHCP 保留地址，终端用动态。K8s 里则是 IPAM / CNI 分配，**和 DHCP 是两套机制**，别混为一谈。
- **租期到了但没续上会怎样？** → T1 单播续租、T2 广播重绑，都失败就放弃地址、重新走 DORA；用户侧表现为「网络突然断一下」。
- **为什么会有 DHCP 欺骗？** → 协议本身**无认证**，伪造的 DHCP 能下发恶意网关/DNS 做中间人；防护靠 DHCP snooping 或 802.1X。
- **容器里的 IP 是 DHCP 拿的吗？** → 不是。Docker 由 daemon 的 IPAM 分配、K8s 由 CNI 插件分配；只有裸机/虚机网卡才走 DHCP。
- **DHCP 报文走哪个端口？** → 服务端 67、客户端 68；⚠️ 抓包时 DISCOVER/REQUEST 的源地址是 `0.0.0.0`、目的 `255.255.255.255`，RENEW 阶段才是单播。

## 关联

- [HTTP与gRPC.md](HTTP与gRPC.md) — 连接池与连接复用（另一种「租约」视角）
- [DNS解析.md](DNS解析.md) — 同为网络配置类问题的对照（TTL 与缓存）
- [Raft协议.md](../分布式/Raft协议.md) — etcd 租约（Lease）、Election 与 KeepAlive
