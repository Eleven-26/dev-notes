# 分布式一致性与 CAP

> 强一致、弱一致与最终一致的区别，CAP 的取舍，以及 etcd 把「一致性等级」做成一行代码的写法。
>
> 内容整理自大厂 Go 后端面试真题视频，并参考《大型网站技术架构：核心原理与案例分析》（李智慧）；参考资料与原始素材见 [素材清单](../素材清单.md)。

---

## 一、分布式一致性是什么？强一致性和弱一致性有什么区别？

**来源**：`p=22` 百度 Go 实习一面 · 时长 10分53秒

**考察意图**：一句话定义 + 两种一致性 + 落到 CAP 定理，并**能举出实际的系统做对比**。

### 一句话定义

> **分布式一致性 = 分布式系统下，节点之间数据的一致性。**
>
> 具体说：一个有 3 个节点的集群，**如何保证这 3 个节点上的数据是一致的**——
> 这就是分布式一致性要解决的问题。

### 一、强一致性（以 etcd 为例）

**写入过程**：

```text
客户端写请求 → Leader 节点 → Leader 同步数据给所有 Follower
    → 半数以上节点同步成功后，才认为"写成功"→ 才返回给客户端
```

**代价**：**写速度明显变慢**——必须等集群中**多数以上节点**确认成功才能返回。

**结果特征（面试要讲这句）**：

> 无论请求打到哪个节点，都会得到**相同的结果**——
> **要么都有，要么都没有**。
> 不可能出现"访问 1 号节点有、2 号节点没有、3 号节点有"这种情况。

### 二、弱一致性（以 Redis Cluster 为例）

**前提**：这里说的是 **Redis 集群**，不是"Redis 单实例 + 高可用"。

**写入过程**：

```text
客户端写请求 → 主节点写入成功 → 立即返回给客户端
    → 后台异步进程再把数据同步到其他节点
```

**结果特征**：

> 在"**写成功但还没同步完成**"这个时间窗口内，
> 同一时刻的多个请求访问不同节点，**可能得到不一样的结果**——
> 有的节点返回了最新数据，有的还没有。

**这就是弱一致性：牺牲一致性，换取可用性和低延迟。**

### 三、特殊情形：最终一致性（最常用的是消息队列）

**背景**：跨实例的**分布式事务**复杂度高、性能慢。

**做法**：
1. 请求持续过来时，先**构建队列**，把请求放进队列；
2. 由专门的进程**消费消息**，按顺序**逐个写入数据库**；
3. 后续请求停止、消息全部消费完之后，**数据最终达成一致**。

**典型载体**：Kafka 这类消息队列。
> 注意：**分布式一致性里的"最终一致性"用得相对少**，通常出现在数据库或消息队列场景。

### 四、CAP 定理（面试官大概率追问）

**C（Consistency）一致性、A（Availability）可用性、P（Partition tolerance）分区容错性。**

**关键前提**：
> 只要是分布式系统，**P（分区容错性）就是必须保证的**——
> 网络分区是客观存在的，不做分区容错，系统根本无法作为分布式系统运行。

**所以真正的取舍发生在 C 和 A 之间**：

| 系统 | 保证 | 牺牲 | 表现 |
|---|---|---|---|
| **etcd** | **C + P** | **A** | 数据在所有节点上一致；但**一个节点挂了，数据就写不进去**（可用性受损） |
| **Redis Cluster** | **A + P** | **C** | 弱一致性；任一节点可写可读，但**可能读到不一致的数据** |
| etcd 的全量复制 | 每个节点都保存**完整数据** | — | 一致性依赖全量同步 |
| Redis 的分片复制 | 3 节点各存 **1/3 数据**，并各备份另外 2/3 | — | 分片 + 副本，是无中心的高可用 |

### 五、⚠️ 一个很容易答错的地方：MySQL 的高可用 ≠ 集群

| 架构 | 数据分布 | 本质 |
|---|---|---|
| **MySQL 单实例（+ 主从/双机热备）** | **数据都在一个节点上**，同时备份到另一个节点 | **高可用架构**，不是集群 |
| **MySQL 集群模式** | 3 个节点各存 **1/3 数据**，并备份其他部分 | **真正的集群**（分区容错） |

> **为什么这很重要**：我们说"MySQL 要做全量备份 / 增量备份"，
> 是因为**通常用的是单实例或单实例+高可用模式**，数据集中在一个节点上，所以必须备份。
>
> 而真正的集群模式下，**某个节点故障后，剩余节点能组织出全量数据**，
> 新节点加入即可补齐数据——这是**分区容错性**的体现。

### 面试建议（原视频强调的）

> **可以背，但一定要有自己的理解，把理解加进去。**
> 面试时按部就班地"背出来"，不如用自己的话讲一遍——**别让面试官觉得你在死记硬背**。

---


---

## 使用一：Go（etcd 客户端：把「一致性等级」变成一行代码）⭐
### 0. 先补一个正文没讲透、但一写代码就会撞上的点

正文 [Raft协议.md](Raft协议.md) 说「**读可以通过 Follower 分担，写不行**」——这句话在 **etcd 上默认不成立**：

| 读法 | etcd 默认？ | 要不要过 quorum | 能读到旧数据吗 | 谁在扛压力 |
|---|---|---|---|---|
| **linearizable 读**（默认） | ✅ | **要**（ReadIndex 一轮多数派确认） | **不能**（保证不过期） | **还是 Leader 为主** |
| **serializable 读**（`WithSerializable()`） | ❌ 要显式开 | 不要 | **可能读到旧值** | 真正落到 Follower |

> 所以准确的表述是：**etcd 想"读写分离"必须主动放弃强一致**。
> 这一句话在面试里非常加分——它把正文第一节的 CAP 表格落成了**一个函数选项**：
> **选 C 还是选 A，不是"选哪个数据库"的问题，而是"这一行传不传 option"的问题。**

### 1. 客户端单例，以及"为什么这里不能用 `sync.OnceValue`"

```go
package raftdemo

import (
	"context"
	"fmt"
	"strings"
	"sync"
	"time"

	clientv3 "go.etcd.io/etcd/client/v3"
)

var (
	mu     sync.Mutex
	client *clientv3.Client
)

// Client 是 etcd 客户端单例：clientv3.Client 内部是一条 gRPC 连接，
// 自带多端点故障转移与重连，每次请求 New 会把建连/认证摊到每个请求上。
//
// 这里刻意**不用** sync.OnceValue：clientv3.New 可能失败，
// OnceValue 会把第一次的错误永久缓存，之后再也拿不到客户端。
func Client() (*clientv3.Client, error) {
	mu.Lock()
	defer mu.Unlock()
	if client != nil {
		return client, nil
	}
	c, err := clientv3.New(clientv3.Config{
		Endpoints:   []string{"127.0.0.1:2379", "127.0.0.1:2479", "127.0.0.1:2579"},
		DialTimeout: 3 * time.Second,
		// 生产还要配 Username/Password（RBAC）与 TLS。
		// ⚠️ 开 Auth 前先确认用户已存在，否则客户端会拿不到权限、排查成本极高（见使用三的坑表）
	})
	if err != nil {
		return nil, err
	}
	client = c
	return client, nil
}

// ClusterView 打印每个节点自认的 leader 与 term。
// 正文 [Raft协议.md](Raft协议.md) 说"任期像届一样单调递增"——这是**在生产上直接看到它**的方式：
// 选一次主，term 就会涨（etcd 还会跳号，因为每轮选举都先自增）。
func ClusterView(ctx context.Context) (string, error) {
	c, err := Client()
	if err != nil {
		return "", err
	}
	var sb strings.Builder
	for _, ep := range c.Endpoints() {
		st, err := c.Status(ctx, ep)
		if err != nil {
			fmt.Fprintf(&sb, "%-20s DOWN   %v\n", ep, err)
			continue
		}
		// leader==0 表示这个节点此刻认为"集群没有 Leader"——正文 [Raft协议.md](Raft协议.md)「存活不过半就停摆」的实物
		fmt.Fprintf(&sb, "%-20s member=%d leader=%d term=%d index=%d v=%s dbsize=%d\n",
			ep, st.Header.GetMemberId(), st.Leader, st.RaftTerm, st.RaftIndex, st.Version, st.DbSize)
	}
	return sb.String(), nil
}
```

> **Endpoints 要写全三个节点，不要只写一个**：客户端的重连是"换一个端点重连"，
> 只写一个地址等于把单点写进了配置——这与正文「etcd 保证 CP、牺牲 A」的取舍正好相反。

### 2. 同一个 key 的两种读法（正文第一节的代码版）

```go
package raftdemo

import (
	"context"

	clientv3 "go.etcd.io/etcd/client/v3"
)

// ReadLinearizable 是 etcd 的**默认**读：读之前要先向 Leader 确认"我的数据不过期"
// （ReadIndex，一轮多数派往返），所以**慢**，而且**没有多数派就读不出来**。
func ReadLinearizable(ctx context.Context, key string) (string, int64, error) {
	c, err := Client()
	if err != nil {
		return "", 0, err
	}
	resp, err := c.Get(ctx, key)
	if err != nil {
		return "", 0, err
	}
	if len(resp.Kvs) == 0 {
		return "", resp.Header.Revision, nil
	}
	return string(resp.Kvs[0].Value), resp.Header.Revision, nil
}

// ReadSerializable 走**本地读**：不经过 Leader、不占 quorum，
// 能真正把读压力分摊到 Follower 上，代价是**可能读到旧值**。
//
// ★ 这两个函数就是正文第一节「强一致 vs 弱一致」的实物版。
//
//	返回值里带上 Revision 是刻意的：**只有把版本号一路传下去，
//	"读到旧数据"才可观测**；否则线上永远只能靠猜。
func ReadSerializable(ctx context.Context, key string) (string, int64, error) {
	c, err := Client()
	if err != nil {
		return "", 0, err
	}
	resp, err := c.Get(ctx, key, clientv3.WithSerializable())
	if err != nil {
		return "", 0, err
	}
	if len(resp.Kvs) == 0 {
		return "", resp.Header.Revision, nil
	}
	return string(resp.Kvs[0].Value), resp.Header.Revision, nil
}
```

**用法上的三条判断**：

| 场景 | 选哪个 | 原因 |
|---|---|---|
| 抢锁、读配置后马上执行、"读己之写" | linearizable | 旧值会直接导致业务错误 |
| 高频只读的列表/状态页、能容忍几十毫秒陈旧 | serializable | 省一轮 quorum 往返，Leader 压力显著下降 |
| 缓存回填、限流计数 | 都行，但**要写明容忍度** | 拿不准就用默认，别为了性能偷偷改语义 |

---

---

## 面试官会追问什么

- **CAP 里的 P 到底指什么？** → 指**网络分区**（节点间消息丢失或延迟），不是进程崩溃。分区是客观会发生的，所以 C 与 A 只能在**分区期间**二选一。
- **系统一定要「选 CP 或 AP」吗？** → 不必。分区只占少数时间，绝大多数时候两者都能满足；工程问题是「分区期间怎么降级、降级多久」。
- **「最终一致」到底是多久？** → 没有普适答案，必须给出**可度量的收敛窗口**（如 P99 < 1s）并配监控；说不出窗口的「最终」等于「不知道」。
- **linearizable 和 serializable 的区别？** → linearizable 是**单对象**的实时性保证；serializable 是**多对象事务**等价于某个串行顺序，但不含实时约束；两者叠加才是 strict serializable。
- **代码里怎么选一致性等级？** → 默认读走强一致（Leader）；只有明确能容忍旧值的路径（报表、推荐）才切到 local read，并且**把理由写进注释**（见本文「使用一」的两种读法）。
- **「半数以上同步成功就返回」准确含义是什么？** → 指已提交（多数派落盘），不代表已应用到某个特定节点；所以 linearizable 读还要额外一轮确认。

## 关联

- [Raft协议.md](Raft协议.md) — 强一致在读写下怎么落地
- [分布式事务.md](分布式事务.md) — 一致性问题的另一半：跨服务事务
- [服务发现与负载均衡.md](服务发现与负载均衡.md) — etcd 的生产用法
- [并发控制与MVCC.md](../数据存储/mysql/并发控制与MVCC.md) — 单机侧的读写一致
