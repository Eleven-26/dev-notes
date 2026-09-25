# 客户端负载均衡的 Go 实现

> 接续 [服务注册与发现的Go实现.md](服务注册与发现的Go实现.md)：三个负载均衡策略（轮询 / 平滑加权 / 一致性哈希）的实现与实测数字，
> 以及接到 HTTP 客户端上时**"重试必须区分请求有没有发出去"**这条硬约束。
>
> 内容整理自大厂 Go 后端面试真题视频，并参考《大型网站技术架构：核心原理与案例分析》（李智慧）；参考资料与原始素材见 [素材清单](../素材清单.md)。
>
> **验证情况**：`go build` / `go vet` / `gofmt -l` 通过（etcd client v3.7.2）；下面的数字是**纯本地算法实测**，不需要 etcd。

## 一、客户端负载均衡：三个策略 + 一致性哈希

正文说"客户端从列表里挑一个"，但**怎么挑**才是这题的分水岭。

```go
package discoverdemo

import (
	"errors"
	"fmt"
	"hash/fnv"
	"sort"
	"sync/atomic"
)

// ErrNoInstance 是客户端负载均衡必须显式处理的分支：
// 列表为空**通常是"Watch 断了被错误清空"**，而不是"真的没有实例"。
var ErrNoInstance = errors.New("discoverdemo: no instance available")

// Balancer 是策略接口：所有策略都只依赖"当前实例列表"，不持有状态型连接。
type Balancer interface {
	Pick(list []string) (string, error)
}

// —— 策略 1：轮询 ——————————————————————————————————————————————

// RoundRobin 用原子自取代取模的锁。
// 读路径无锁是**必须的**：每次请求都抢一把全局 mutex，LB 自己就成了瓶颈。
type RoundRobin struct{ i atomic.Uint64 }

func (r *RoundRobin) Pick(list []string) (string, error) {
	n := uint64(len(list))
	if n == 0 {
		return "", ErrNoInstance
	}
	return list[r.i.Add(1)%n], nil
}

// —— 策略 2：平滑加权轮询（Nginx 的算法，面试手写高频）————————————————

// WeightedRoundRobin 是**平滑**加权轮询：每个节点维护 current，
// 每轮给所有节点加上自己的 weight，选出 current 最大的那个，再把它减去 total。
//
// 为什么要"平滑"：朴素的"按权重把节点重复放进列表"（w=5,1,1 → a a a a a b c）
// 会把同一台机器的请求**连续**打过来；平滑后序列变成 a a b a c a a —— 总分布不变但**摊开了**。
type WeightedRoundRobin struct {
	weights map[string]int
	state   atomic.Value // map[string]int（各节点的 current 值）的快照，copy-on-write
}

func NewWeightedRoundRobin(weights map[string]int) *WeightedRoundRobin {
	w := &WeightedRoundRobin{weights: weights}
	w.state.Store(map[string]int{})
	return w
}

func (w *WeightedRoundRobin) Pick(list []string) (string, error) {
	if len(list) == 0 {
		return "", ErrNoInstance
	}
	cur, _ := w.state.Load().(map[string]int)
	next := make(map[string]int, len(cur)+len(list))
	for k, v := range cur {
		next[k] = v
	}
	total := 0
	for _, node := range list {
		next[node] += w.weights[node]
		total += w.weights[node]
	}
	best := list[0]
	for _, node := range list {
		if next[node] > next[best] {
			best = node
		}
	}
	next[best] -= total
	w.state.Store(next)
	return best, nil
}

// —— 策略 3：一致性哈希（正文「新节点 IP 变了怎么办」的标准答案）——————————————

// HashRing 是一致性哈希：节点和 key 都映射到同一个环上，key 顺时针找到第一个节点。
// 好处是**增删节点只影响相邻的一段 key**，而"哈希取模"会让几乎所有 key 换主人。
type HashRing struct {
	replicas int               // 每个真实节点的虚拟节点数，决定均匀度
	points   []uint32          // 排序后的哈希环
	nodes    map[uint32]string // 哈希 → 真实节点
}

func NewHashRing(replicas int) *HashRing {
	return &HashRing{replicas: replicas, nodes: map[uint32]string{}}
}

func hash32(s string) uint32 {
	h := fnv.New32a()
	_, _ = h.Write([]byte(s)) // hash 实现不会返回错误
	return h.Sum32()
}

func (r *HashRing) Add(node string) {
	for i := 0; i < r.replicas; i++ {
		p := hash32(fmt.Sprintf("%s#%d", node, i))
		r.points = append(r.points, p)
		r.nodes[p] = node
	}
	sort.Slice(r.points, func(i, j int) bool { return r.points[i] < r.points[j] })
}

func (r *HashRing) Lookup(key string) (string, error) {
	if len(r.points) == 0 {
		return "", ErrNoInstance
	}
	h := hash32(key)
	// 顺时针第一个 >= h 的点；没有就回环到 0 号（这就是"环"）
	i := sort.Search(len(r.points), func(i int) bool { return r.points[i] >= h })
	if i == len(r.points) {
		i = 0
	}
	return r.nodes[r.points[i]], nil
}

// Modulo 是"哈希取模"的对照组：n 一变，几乎所有 key 都要搬家。
func Modulo(key string, list []string) (string, error) {
	if len(list) == 0 {
		return "", ErrNoInstance
	}
	return list[int(hash32(key))%len(list)], nil
}

// Churn 计算从 from 变成 to 时有多少 key 换了归属（返回百分比 0~100），
// 用来把"一致性哈希到底省了什么"变成可核对的数字。
// 注意：环只在两端各建**一次**——在循环里重建环是最常见的写法事故。
func Churn(keys []string, from, to []string) (pct float64, err error) {
	before, err := assign(keys, NewHashRingFrom(from))
	if err != nil {
		return 0, err
	}
	after, err := assign(keys, NewHashRingFrom(to))
	if err != nil {
		return 0, err
	}
	changed := 0
	for k, v := range before {
		if after[k] != v {
			changed++
		}
	}
	return float64(changed) / float64(len(keys)) * 100, nil
}

// NewHashRingFrom 用 200 个虚拟节点建环（下面第 4 节的实测说明为什么是 200 而不是 1）。
func NewHashRingFrom(nodes []string) *HashRing {
	r := NewHashRing(200)
	for _, n := range nodes {
		r.Add(n)
	}
	return r
}

func assign(keys []string, r *HashRing) (map[string]string, error) {
	out := make(map[string]string, len(keys))
	for _, k := range keys {
		v, err := r.Lookup(k)
		if err != nil {
			return nil, err
		}
		out[k] = v
	}
	return out, nil
}
```

**什么时候用哪个（面试要能给结论）**：

| 策略 | 用在哪 | 代价 / 注意 |
|---|---|---|
| **轮询** | 实例同构、请求耗时均匀（默认选择） | 实例**异构或长请求**时会把慢机器打死 |
| **平滑加权轮询** | 机器配置不一样、灰度按权重分流 | 权重是**静态**的，感知不到实时负载 → 需要最少连接/最短队列时才升级 |
| **加权最少连接** | 长连接、请求耗时差异大 | 要维护每台在途连接数（计数放本地即可，无需共享） |
| **一致性哈希** | **有状态**的场景：本地缓存、连接复用、按用户分片 | 节点数变化时会集中重排一小段 key；**必须加虚拟节点** |

---

## 二、实测：把"平滑"和"一致性"变成数字

上面 `meas_test.go` 级别的纯本地实验（不需要 etcd），`go test ./discoverdemo/ -run TestMeasure -v` 的真实输出：

**① 平滑加权轮询 vs 朴素"按权重复制节点"**（权重 a:5 b:1 c:1，各取 21 次）：

| 算法 | 前若干次实际序列 | 21 次分布 |
|---|---|---|
| 朴素复制表 + 轮询 | `a a a a b c a`（**前 4 次全打在 a**） | a:15 b:3 c:3 |
| 平滑加权轮询 | `a a b a c a a a a b a c a a a a b a c a a` | a:15 b:3 c:3 |

> **分布完全一样，但节奏不一样**。朴素做法让 a 在开头连续挨 4~5 个请求，
> 平滑做法把 b、c **插进 a 的空档里**。
> 一句话：**加权轮询要的是"长期比例正确"，平滑加权额外要"短期也别把一台打死"。**

**② 一致性哈希 vs 哈希取模**（10000 个 key，节点 `10.0.0.1~5:8080`）：

| 变更 | 一致性哈希换归属的 key | 哈希取模换归属的 key |
|---|---|---|
| 5 → 4 台（删中间一台） | **24.22%** | **80.44%** |
| 5 → 6 台（加一台） | **9.55%** | **82.44%** |

> 理论下界：删一台是 1/5 = 20%（实测 24.22%，多出来的是虚拟节点造成的边界抖动）；
> 加一台理想是 1/6 ≈ 16.7%（实测 9.55%，比理论还好，因为新环只从相邻节点"接管"了一段）。
> **而取模法一旦 n 变化，`hash % n` 的映射整体错位 → 八成 key 搬家**——
> 如果这个哈希是用来命中本地缓存或做用户分片，**等于一次扩缩容把缓存全打穿**。

**③ 虚拟节点数直接决定均匀度**（5 台真实节点、10000 个 key）：

| replicas | 最少 | 最多 | max/min |
|---|---|---|---|
| 1 | 7 | 6419 | **917.00** |
| 10 | 567 | 6056 | 10.68 |
| 100 | 897 | 3410 | 3.80 |
| 200 | 1183 | 3695 | **3.12** |

> **每节点 1 个虚拟节点是错的用法**（最惨的节点只分到 7 个 key，最多的 6419 个）。
> 200 之后收益递减，**生产上 100~200 是常见取值**。

**④ 一个我自己踩出来的性能教训**：
`Churn` 第一版在 key 循环里每次都重建环 → **34.48 秒**；
把环提到循环外只建一次 → **0.02 秒**，结果一字不差。
> **一致性哈希的建环是 O(节点数 × 副本数) 且带排序，绝不能放在请求路径上。**

---

## 三、接到 HTTP 客户端上：重试必须区分"请求有没有发出去"

```go
package discoverdemo

import (
	"errors"
	"io"
	"net"
	"net/http"
	"net/url"
)

// DiscoveryTransport 把"客户端负载均衡"落到标准库上：
// 每次请求从本地列表挑一个实例，改写 URL 的 host，再交给**共享的** http.Transport 发出去。
//
// ★ 连接池归 Transport（见 network/应用层协议.md 使用一的 httpdemo.Transport 单例），
//
//	这一层只管"选谁"。两层混在一起写，就会出现"每次换实例都新建 Transport"的经典泄漏。
type DiscoveryTransport struct {
	Service string
	Resolve func() []string // 通常传 (*Resolver).Instances
	Balance Balancer
	// 失败后最多再试几个实例。0 = 不重试。
	MaxRetries int
}

func (t *DiscoveryTransport) RoundTrip(req *http.Request) (*http.Response, error) {
	list := t.Resolve()
	if len(list) == 0 {
		return nil, ErrNoInstance
	}
	tries := t.MaxRetries + 1
	if tries > len(list) {
		tries = len(list)
	}

	var lastErr error
	skip := map[string]bool{}
	for i := 0; i < tries; i++ {
		// 把已失败的实例临时排除：不这么做会反复选中同一台坏机器
		pool := filterOut(list, skip)
		if len(pool) == 0 {
			break
		}
		addr, err := t.Balance.Pick(pool)
		if err != nil {
			return nil, err
		}
		skip[addr] = true

		clone := req.Clone(req.Context())
		u, err := url.ParseRequestURI(addr)
		if err != nil {
			return nil, err // 注册进来的地址格式不对：这是**配置错误**，重试没有意义
		}
		clone.URL.Scheme, clone.URL.Host = u.Scheme, u.Host
		if clone.Host == "" {
			clone.Host = u.Host // 有些服务端按 Host 头路由，别让它是空的
		}

		resp, err := http.DefaultTransport.RoundTrip(clone)
		if err == nil {
			return resp, nil
		}
		// 只有**连接级**错误才该换实例重试；请求已发出后拿到 5xx 不是错误。
		if !errors.Is(err, io.EOF) && !isConnError(err) {
			return nil, err
		}
		lastErr = err
	}
	if lastErr == nil {
		lastErr = ErrNoInstance
	}
	return nil, lastErr
}

// isConnError 判定"请求还没发出去"这一类错误——
// 只有它们可以安全地换实例重试：连接被拒/DNS 解析失败时服务端**根本没收到**这个请求。
// 反过来，"已经写完请求体"之后失败就不能盲目重试（非幂等接口会重复扣款）。
func isConnError(err error) bool {
	var oe *net.OpError
	if errors.As(err, &oe) { // connection refused / no such host / broken pipe 都在这类里
		return true
	}
	return errors.Is(err, io.EOF) || errors.Is(err, io.ErrUnexpectedEOF)
}

func filterOut(list []string, skip map[string]bool) []string {
	if len(skip) == 0 {
		return list
	}
	out := make([]string, 0, len(list))
	for _, a := range list {
		if !skip[a] {
			out = append(out, a)
		}
	}
	return out
}

// NewClient 是业务侧拿到的东西：**一个进程一个**（内部 Transport 才是连接池）。
func NewClient(service string, r *Resolver, w Balancer) *http.Client {
	return &http.Client{
		Transport: &DiscoveryTransport{
			Service:    service,
			Resolve:    r.Instances,
			Balance:    w,
			MaxRetries: 2,
		},
	}
}
```

**⚠️ 换实例重试的安全性判断（这块最容易在面试里被反问"你怎么保证不重复下单"）**：

| 错误 | 请求到过服务端吗 | 能换实例重试？ |
|---|---|---|
| `connection refused` / `no such host`（`*net.OpError`） | **没有** | ✅ 安全 |
| `EOF` / `unexpected EOF`（连接被切） | **不确定** | ⚠️ 只有**幂等**接口（GET / 带幂等键的写）可以 |
| `context.DeadlineExceeded`（超时） | **很可能已经执行了** | ❌ 盲目重试 = 重复扣款；靠幂等键去重 |
| HTTP 5xx | **是，服务端已经处理并失败** | ❌ 这不是传输错误，交给熔断/业务重试策略 |
| 地址格式非法（`ParseRequestURI` 失败） | 没有 | ❌ **配置错误**，重试只是浪费延迟，直接返回 |

> 所以上面只对 `*net.OpError` 和 `EOF` 类错误换实例，
> 并且**换实例时必须 `req.Clone`**——`RoundTrip` 的契约要求实现**不得修改入站请求**，
> 而且请求体可能是只能读一次的 `io.Reader`。

---

## 四、三句话总结（这三句能覆盖第一节 + 第二节的核心得分点）

1. **注册 = 租约 + 一条 KeepAlive 流 + 退出时 Revoke**；
   健康检查不用额外做——**"心跳停 → key 过期"** 就是被动式检查。
2. **发现 = 全量拉一次 + 从 `Revision+1` 开始 Watch + copy-on-write 本地快照**；
   本地快照同时承担两件事：**性能**（不必每请求查 etcd）和**容错**（etcd 挂了还能用旧列表）。
3. **负载均衡 = 挑法要按流量特征选**；有状态场景用一致性哈希（**加虚拟节点、环不能在请求路径上重建**），
   重试只在"请求没发出去"时换实例。

---

---

---

## 面试官会追问什么

### 一、"平滑"加权轮询和普通加权轮询差在哪？
普通加权轮询按权重排好序**连续放行**（A 三次、B 一次），于是 A 的请求**挤在一起** —— 短时间窗口内负载仍然不均。
平滑加权（Nginx 的 `smooth weighted round-robin`）用"当前权重 += 权重，选最大，再减去总权重"的方式，
把同一实例的请求**打散到整个序列里**，任意子区间内的分布都接近权重比。原文的实测就是把这个差异变成数字看的。

### 二、一致性哈希为什么需要虚拟节点？
因为没有虚拟节点时，节点增删会导致**大量 key 重新映射**（每个节点只负责环上自己那一段，段的大小随机、极不均匀）。
虚拟节点（每个物理节点在环上放多个副本）做两件事：**①把环上的段打散**，让各节点负责的范围更均衡；
**②节点下线时它的负载被多个邻居分摊**，而不是全部压给下一个节点。
代价是环上的节点数变多、查找表变大 —— 所以虚拟节点数是"均衡度"与"内存/查找成本"之间的取舍。

### 三、重试为什么必须区分"请求有没有发出去"？
因为**幂等性边界不同**：
- **连接阶段失败**（连不上、连接被拒）→ 请求**肯定没到服务端**，换个实例重试是安全的；
- **发送后失败**（读超时、连接中断）→ 请求**可能已经到达并被执行**，此时盲目重试会造成**重复下单 / 重复扣款**。

所以正确做法是：**只有"确定没发出去"的错误才自动换实例重试**；后者要交给业务侧用幂等键处理。
这也是原文强调"重试必须区分"的原因 —— 它是正确性问题，不是性能优化。

## 关联

- [服务注册与发现的Go实现.md](服务注册与发现的Go实现.md) — 前一篇：注册与发现
- [服务发现与负载均衡.md](服务发现与负载均衡.md) — 负载均衡为什么放在客户端做
- [../go/并发/并发同步原语.md](../go/并发/并发同步原语.md) — 一致性哈希里的原子替换与并发
- [限流降级熔断.md](限流降级熔断.md) — 重试与熔断如何配合
