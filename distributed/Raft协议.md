# Raft 协议

> Raft 的角色、任期与日志复制，以及选主流程的逐步拆解；含 etcd 客户端的 Txn / Election / Watch 落地与三节点实验。
>
> 内容整理自大厂 Go 后端面试真题视频，参考资料与原始素材见 [素材清单](../interview/素材清单.md)。

---

## Q1. 介绍一下 Raft 协议

**来源**：`p=23` 百度 Go 实习一面 · 时长 22分33秒
**考察意图**：面试官会顺着**项目经历**里的分布式组件追问。原视频先给了一个**回答任何技术题的通用模板**，
非常值得学。

### 讲解任何技术题的通用模板（先掌握这个）

```
① 它是什么？有什么作用？
② 它有什么特点和优势？（与同类技术相比，优劣势）
③ 核心流程是怎样的？（关键机制的理解）
④ 应用场景有哪些？
```

### ① 是什么 / 作用

> **Raft 是一种分布式一致性算法**，用于确保分布式系统中**多个节点在数据一致性上达成共识**。

### ② 特点与优势

#### 核心特点：半数以上节点达成共识

这意味着**集群能容忍几台故障是可以算出来的**：

| 集群节点数 | 半数以上 | 可容忍故障 |
|---|---|---|
| 3 | 2 | **1 台** |
| 5 | 3 | **2 台** |

> ⚠️ **硬性约束**：集群**存活节点数必须超过半数**，
> 一旦**小于等于半数**，集群**就无法工作**。
> 这是所有多数派共识算法的共同要求。

#### 为什么会出现 Raft？

> **因为其他共识算法（如 Paxos）太难理解了。**
> Raft 诞生的初衷就是**"可理解性"**——让共识算法**容易理解和实现**。
> 这也直接决定了它后面三个区别于其他算法的设计。

#### Raft 的三大设计特点

**特点 1：强领导者（Strong Leader）**

- 集群中**只存在一个 Leader**；
- **所有写操作都必须经过 Leader**，数据流向**只能从 Leader → Follower**。

⚠️ **副作用（面试要主动讲）**：

> **不论集群规模多大，只有 Leader 能处理写请求**——
> 所以**无法通过扩展集群来提升写并发能力**。
> （读可以通过 Follower 分担，写不行。）

**特点 2：随机化选举定时器**

**问题**：如果所有 Follower 的选举超时时间相同会怎样？

```
所有 Follower 同时超时 → 全部转为 Candidate
→ 每个节点的第一件事都是把票投给自己
→ 每人只有一票且都投给了自己 → 永远选不出 Leader
```

> 类比干部选举：如果每个人都可以参选、且都先投自己一票，那就永远选不出人。

**解法**：**随机化选举超时时间**——每个节点的定时器不同，**谁先超时谁先成为 Candidate**，
从而打破僵局。

**特点 3：成员变更采用联合共识（Joint Consensus）**

**类比配置/版本上线**（这个类比很好用）：

> 旧版本有字段 A，新版本删掉了 A 并加了字段 B。
> **不能**"先删 A 再上新版"——删了 A 旧版立刻就不能用了，而新版还没部署完。
> **正确做法**：先**加上新版需要的字段**（此时新旧版都能跑）→ 部署新版 →
> 逐步下线旧版 → **最后再删旧字段**。**中间需要一个过渡期**。

**Raft 的成员变更同理**：过渡期间**新旧两套配置同时生效**。
如果不这么做，新旧配置**各自选出一个 Leader**，集群就出现**脑裂**（一个集群两个 Leader）。
联合共识的作用就是**保证始终只有一个 Leader**。

### ③ 核心流程

#### 三类角色

| 角色 | 说明 |
|---|---|
| **Leader** | 领导者 |
| **Candidate** | 候选者，参与选举、可能成为 Leader |
| **Follower** | 跟随者 |

> **正常情况下集群只有 Leader 和 Follower**；
> 只有**收不到 Leader 通讯（心跳超时）**时，Follower 才会转为 Candidate。

#### 流程一：Leader 选举

```
① 初始状态：所有节点都是 Follower（Leader 故障后也是这个状态）
② Follower 长时间收不到 Leader 心跳 → 选举超时 → 转为 Candidate
   （超时时间随机，谁先超时谁先转，避免同时选举）
③ 递增任期（term），第一件事：投自己一票；重置选举计时器
④ 向其他节点发送投票请求（RequestVote）
   - 发到 Follower：通常立刻把票投给它（先到先得）
   - 发到另一个 Candidate：对方已投给自己，不会给你
⑤ 统计票数：是否获得「大多数」？
   - 5 节点集群需要 ≥3 票（自己 1 票 + 其他 4 台中拿到 2 票）
   - ✅ 获得多数 → 成为 Leader → 向其他节点发送心跳/日志复制
   -    其他节点若自己是 Candidate，收到更高任期的 Leader 消息后转为 Follower
   - ❌ 未获得多数：
       a) 选举超时（整个周期都没选出结果）→ 任期 +1，重新发起选举
       b) 未超时但别人成了 Leader → 自己转为 Follower
```

#### 流程二：日志复制

```
① 客户端请求到达 Leader
② Leader 把日志追加到本地
③ Leader 通过 AppendEntries 请求把日志发送给所有 Follower
④ Follower 接收成功 → 向 Leader 响应确认
⑤ Leader 判断：是否「大多数」Follower 确认成功？
   - ✅ 是 → 认为该日志已达成共识 → 提交（Commit）该日志
           → 应用到状态机（执行日志里的命令）
   - ❌ 否 → Leader 持续重试发送，直到 Follower 接收成功
```

> ⚠️ **重试不是"重试几次就放弃"**，而是**不断重试直到成功为止**——
> 这是 Raft 保证一致性的关键（所以需要处理重试上限和幂等）。

### ④ 应用场景

**典型使用者**：分布式数据库、**etcd**、**Kubernetes**（K8s 的元数据存在 etcd 里）。

**共同特点：读多写少。**
> 比如 K8s，只有**应用资源和修改配置时才会写**，平时绝大多数操作都是读。
> 这恰好规避了"强领导者导致写性能无法横向扩展"这个短板。

---


---

## Q2. Raft 的三种角色如何转换？选举的详细流程是怎样的？

**来源**：`p=57`（携程云计算一面，节点状态转换，7分07秒）+ `p=58`（携程云计算一面，选主详细流程，12分06秒）
**考察意图**：Q1 讲的是 Raft 的"是什么"，本题问的是**"过程细节"**——
面试官会顺着"你项目里用了 etcd/强一致性"往下挖。

### 一、三种角色

| 角色 | 说明 |
|---|---|
| **Follower（跟随者）** | 节点的**默认状态**，服务器启动时就是这个 |
| **Candidate（候选者）** | **中间状态**，只在"没有 Leader、需要重新选举"时出现 |
| **Leader（领导者）** | 通过多数票当选 |

> **正常情况下集群只有 Follower 和 Leader**；
> Candidate 是选举期间的临时身份。
>
> **共识的基本逻辑**：**超过半数以上节点认可才算有效**——
> 写数据要**半数以上节点写入**才算成功；选 Leader 也要**超过半数节点同意**才能当选。
> （3 节点需 2 票，4 节点需 3 票，5 节点需 3 票以上）

### 二、角色转换的六条路径

| # | 转换 | 触发条件 |
|---|---|---|
| **①** | **启动 → Follower** | 服务器启动即 Follower |
| **②** | **Follower → Candidate** | **在选举周期内没有收到 Leader 的心跳/任何数据** → 选举超时，转为候选者 |
| **③** | **Candidate → Candidate（新一轮）** | 选举超时时间内没选出 Leader → **重新选举**（新任期、重置计时器） |
| **④** | **Candidate → Leader** | **拿到多数节点的投票** |
| **⑤** | **Candidate → Follower**（两条路径） | a) 发现集群**已存在 Leader**；<br>b) 发现**其他节点的任期（term）比自己更高** → 竞选已无意义，立刻退回 Follower |
| **⑥** | **Leader → Follower** | 发现集群中**存在更高任期**的节点 |

> **⑤b 和 ⑥ 的关键就是「任期（term）」**：任期像"届"一样单调递增。
> **只要发现别人的任期比自己高，就立即放弃当前身份。**

### 三、选举详细流程

#### 第 1 步：Follower 靠心跳维持

```
Follower 能收到 Leader 的心跳 → 不转换，一直保持 Follower
Follower 收不到心跳（超时）  → 进入选举流程
```
> **Leader 就是靠持续发心跳来维护自己的地位。**

#### 第 2 步：递增任期 + 转为候选者 + 投自己一票

```
① 递增自己的任期（term）
② 身份转为 Candidate（候选者）
③ 为自己投票 ← ★ 关键
④ 重置选举计时器
```

**⚠️ 为什么"为自己投票"这一步会导致死锁风险（这是随机化的动机）**：

> **任何节点在一个选举周期内只有一票的投票权，投给自己就不能投给别人。**
>
> 如果所有节点的选举计时器**都一样**（比如都是 150ms 过期）：
> 三个节点同时超时 → 全都变成 Candidate → **每人都把唯一一票投给自己**
> → **永远选不出 Leader**。
>
> 这和"干部选举中每个人都参选且都先投自己一票"是一模一样的问题。

#### 第 3 步：随机化选举计时器（Raft 的关键设计）

> **选举计时器取 150ms ~ 300ms 之间的随机值**（不同实现不同，
> 例如 etcd 可能到 1000ms）。

**效果**：
- 各节点过期时间不同（有的 152ms、有的 300ms）；
- **绝大多数情况下只有一个节点先过期** → 它先成为候选者并拉票，其他节点还在等；
- 偶尔有两个同时过期，也仍能选出多数派 Leader。

**这就是"随机化"存在的全部意义——避免选票碰撞。**

#### 第 4 步：向其他所有节点拉票（RequestVote）

**拉票结果取决于三个因素**：

| 因素 | 说明 |
|---|---|
| **① 对方是什么身份** | 如果对方也是 Candidate，它**已经投给自己了，拉不到票**；<br>是 Follower 则有投票权 |
| **② 先来后到** | 5 节点集群中 1 号和 2 号同时成为候选者：<br>谁的请求**先到达** 3、4 号节点，谁就能拿到那两票。<br>如 1 号先到 → **1 号得 3 票当选**，2 号只得自己 + 5 号共 2 票 |
| **③ 日志索引（log index）必须足够新** ⭐ | 见下方说明 |

**⭐ 为什么"日志不够新"会拉不到票（Raft 安全性的核心）**：

> 每个日志都有**任期（term）和索引位置（index）**。
> 候选者拉票时，其他节点会**比较最后一条日志的 index**：
>
> - 如果候选者的最后 index **落后于**对方（如候选者 5、对方 6），
>   对方**拒绝投票**；
> - 因为如果让它当选，**它的数据就不是最新的**，
>   而 **Raft 的数据流向只能是 Leader → Follower**——
>   它**没办法从 Follower 反向把数据拉回来**。

**所以规则是**：
> **日志不是最新的候选者，直接拉不到票 → 败选 → 退回 Follower。**
> 这保证了**当选 Leader 一定拥有最完整的日志**，从而保证已提交的数据不丢。

#### 第 5 步：三种结果

```
① 发现已有 Leader → 转为 Follower
② 拿到多数票     → 当选 Leader → 向所有节点发心跳，
                    其他节点收到更高任期的 Leader 后全部转为 Follower
③ 没拿到多数票   → 判断是否选举超时：
     ├─ 已超时 → 递增任期、重置选举计时器、再投自己、重新走流程
     └─ 未超时 → 竞选失败（败选）→ 转为 Follower
                  → 回到最初：等心跳、重置计时器，循环
```

### 一句话总结

> Raft 选举 = **心跳保位 → 超时转候选 → 递增任期投自己 → 随机计时器错峰 →
> 拉票（需多数票 + 日志足够新）→ 当选发心跳 / 失败退 Follower**。
> **两个关键设计**：**随机化计时器**（避免选票碰撞）、
> **日志新旧校验**（保证当选者数据最新，数据只能从 Leader 流向 Follower）。

---

## 使用一：Go（etcd 客户端：Txn / Election / Watch）⭐
### 1. 写操作：一条 Txn 就是"多数派提交"的原子单位

```go

package raftdemo

import (
	"context"

	clientv3 "go.etcd.io/etcd/client/v3"
)

// RegisterIfAbsent 用一条 Txn 实现"只创建一次"，这是服务注册的标准写法。
//
// Compare(ModRevision(key), "=", 0) 的含义是"这个 key 从未存在过"。
// 返回 false 表示别人已经注册了（走了 Else 分支）——**这个判断可信**，
// 因为 Txn 的 If/Then/Else 是在 Leader 上作为**一条日志**提交的，
// 只有多数派落盘后才返回（正文 Q1 流程二的 ①~⑤）。
//
// leaseID 传 0 表示不挂租约（永久配置）；**服务实例注册必须挂租约**，
// 否则进程崩溃后注册中心里会残留一个"活着"的脏实例。
func RegisterIfAbsent(ctx context.Context, key, val string, leaseID clientv3.LeaseID) (bool, error) {
	c, err := Client()
	if err != nil {
		return false, err
	}
	var ops []clientv3.OpOption
	if leaseID != 0 {
		ops = append(ops, clientv3.WithLease(leaseID))
	}
	resp, err := c.Txn(ctx).
		If(clientv3.Compare(clientv3.ModRevision(key), "=", 0)).
		Then(clientv3.OpPut(key, val, ops...)).
		Else(clientv3.OpGet(key)).
		Commit()
	if err != nil {
		return false, err
	}
	return resp.Succeeded, nil
}

// CompareAndSwap 表达"同一时刻只有一个实例改得动"（配置中心、状态机推进常用）。
// 常见误解是"这要不要先加个锁"——**CAS + 重试**就够了，而且不会阻塞别人。
func CompareAndSwap(ctx context.Context, key, want, next string) (bool, error) {
	c, err := Client()
	if err != nil {
		return false, err
	}
	resp, err := c.Txn(ctx).
		If(clientv3.Compare(clientv3.Value(key), "=", want)).
		Then(clientv3.OpPut(key, next)).
		Commit()
	if err != nil {
		return false, err
	}
	return resp.Succeeded, nil
}

// DeleteIfMatches 用来安全释放锁：只删"值还是我自己"的那个 key。
// 少了这个 Compare 就会出现经典事故：
// **我的租约刚过期、锁已被别人拿到，结果我把别人的锁删了。**
func DeleteIfMatches(ctx context.Context, key, owner string) (bool, error) {
	c, err := Client()
	if err != nil {
		return false, err
	}
	resp, err := c.Txn(ctx).
		If(clientv3.Compare(clientv3.Value(key), "=", owner)).
		Then(clientv3.OpDelete(key)).
		Commit()
	if err != nil {
		return false, err
	}
	return resp.Succeeded, nil
}

```

> ⚠️ **etcd 的"写成功"只保证"日志被多数派接受并已提交"**，不保证"每个 Follower 都已 apply 到状态机"。
> 这正是正文 [一致性与CAP.md](一致性与CAP.md) 那句"半数以上同步成功就返回"的准确含义——
> 所以**linearizable 读**才需要额外一轮确认，把"已提交"升级成"已应用到我的视图"。
> 租约与续租的写法（`Grant` / `KeepAlive` / 为什么必须单条 stream）已在
> [DHCP.md 使用一（租约状态机）](../network/DHCP.md) 与
> [redis/缓存问题与方案.md](../middleware/redis/缓存问题与方案.md) 里给全，本篇不重复。

### 2. 选举：把"强领导者"用成应用层的单写者

```go

package raftdemo

import (
	"context"
	"fmt"
	"time"

	"go.etcd.io/etcd/client/v3/concurrency"
)

// CampaignLeader 把正文 Q1「强领导者」变成应用层的**单写者**：
// 定时任务/对账/清理这类"全局只想跑一份"的作业，应该用 etcd 选举，而不是自己写锁。
//
// 返回的 release 必须由调用方执行。**Resign 与"只 Close"的差别很大**：
//   - Resign：主动放弃，key 立刻消失 → 下一个候选者**马上**上位；
//   - 只 Close Session（或进程崩溃）：要等 lease 的 TTL（这里 15s）才切换 →
//     这就是"Leader 挂了服务要卡十几秒"的根因，也就是正文 [一致性与CAP.md](一致性与CAP.md) 说的"写不进去"在应用层的形态。
func CampaignLeader(ctx context.Context, name, payload string) (release func(), err error) {
	c, err := Client()
	if err != nil {
		return nil, err
	}
	sess, err := concurrency.NewSession(c, concurrency.WithTTL(15))
	if err != nil {
		return nil, err
	}
	el := concurrency.NewElection(sess, "/leader/"+name)
	callCtx, cancel := context.WithTimeout(ctx, 10*time.Second)
	defer cancel()
	if err := el.Campaign(callCtx, payload); err != nil {
		_ = sess.Close()
		return nil, err
	}
	return func() {
		// 释放路径不能复用 ctx：调用方往往已经把它 cancel 了。
		_ = el.Resign(context.Background())
		_ = sess.Close()
	}, nil
}

// CurrentLeader 读当前 Leader 的 payload（"现在谁在跑任务"）。
// 任何节点都能查——**这一项是读，可以走 Follower**。
func CurrentLeader(ctx context.Context, name string) (string, error) {
	c, err := Client()
	if err != nil {
		return "", err
	}
	sess, err := concurrency.NewSession(c, concurrency.WithTTL(5))
	if err != nil {
		return "", err
	}
	defer func() { _ = sess.Close() }()

	el := concurrency.NewElection(sess, "/leader/"+name)
	resp, err := el.Leader(ctx)
	if err != nil {
		return "", err
	}
	if len(resp.Kvs) == 0 {
		return "", nil
	}
	return fmt.Sprintf("key=%s rev=%d payload=%s",
		resp.Kvs[0].Key, resp.Kvs[0].ModRevision, resp.Kvs[0].Value), nil
}

```

> `NewSession` 已经帮你做了**租约 + 后台 KeepAlive**（拿到 session 后 `sess.Lease()` 就是 LeaseID，
> 配合第 1 节的 `RegisterIfAbsent` 就是完整的服务注册）。
> 但**Session 一个只能服务一个租约**：想给多个 key 挂同一个 TTL，就显式 `Grant` 一次再复用 LeaseID。

### 3. Watch：服务发现最容易丢事件的地方

```go

package raftdemo

import (
	"context"
	"errors"
	"fmt"

	"go.etcd.io/etcd/api/v3/mvccpb"
	"go.etcd.io/etcd/api/v3/v3rpc/rpctypes"
	clientv3 "go.etcd.io/etcd/client/v3"
)

// ErrCompacted 表示"你订阅的起点已经被 etcd 压缩掉了"，
// 上层必须**丢弃本地缓存、重新全量拉一次**，再用新的 revision 续订（下面第 2 步就是它）。
var ErrCompacted = errors.New("raftdemo: watch start revision compacted")

// ChangeFunc 收到一次实例变化：typ 为 PUT（上线/更新）或 DELETE（下线）。
// DELETE 时 kv 只有 Key 有意义，**要看 prevKV 才知道下线的那个实例的地址**。
type ChangeFunc func(typ mvccpb.Event_EventType, kv, prevKV *mvccpb.KeyValue) error

// WatchInstances 是服务发现的真正入口：先全量拉一次，再增量订阅变化。
//
// ★ 两条必须记住的细节：
//  1. 全量 Get 与建立 Watch 之间**有窗口**，所以必须用 WithRev(那次 Get 的 Revision+1) 起订，
//     否则这中间发生的创建/删除会永久丢失——"实例早就下线了，注册中心还认为它在"就是这么来的。
//     （正文 [一致性与CAP.md](一致性与CAP.md) 说的"最终一致"，缺了这一步就退化成"可能永远不一致"。）
//  2. etcd 会周期性压缩历史版本（compact），订阅者掉线太久会收到 ErrCompacted；
//     此时**没有增量可补**，只能重新全量拉。
func WatchInstances(ctx context.Context, prefix string, onChange ChangeFunc) error {
	c, err := Client()
	if err != nil {
		return err
	}

	// 1) 全量灌一次本地视图
	snap, err := c.Get(ctx, prefix, clientv3.WithPrefix())
	if err != nil {
		return err
	}
	for _, kv := range snap.Kvs {
		if err := onChange(mvccpb.PUT, kv, nil); err != nil {
			return err
		}
	}

	// 2) 从"全量之后的那一个 revision"开始订阅，中间不留缝隙
	wch := c.Watch(ctx, prefix,
		clientv3.WithPrefix(),
		clientv3.WithPrevKV(),         // 删除事件带上旧值
		clientv3.WithProgressNotify(), // 空闲时也推一次 revision，用来判断"我是不是掉线了"
		clientv3.WithRev(snap.Header.Revision+1),
	)
	for wresp := range wch {
		if err := wresp.Err(); err != nil {
			if errors.Is(err, rpctypes.ErrCompacted) {
				return fmt.Errorf("%w: rev=%d", ErrCompacted, wresp.CompactRevision)
			}
			return err
		}
		for _, ev := range wresp.Events {
			if err := onChange(ev.Type, ev.Kv, ev.PrevKv); err != nil {
				return err
			}
		}
	}
	return ctx.Err()
}

```

**这张表是 Watch 侧最常见的四种"以为没问题"**：

| 现象 | 根因 | 修法 |
|---|---|---|
| 实例已下线，本地还往它发请求 | 删除事件里只读 `kv.Value`（DELETE 时为空） | 用 `WithPrevKV()`，读 `prevKV.Value` |
| 偶发"新实例上线了但没人知道" | 全量与订阅之间有缝隙 | `WithRev(getResp.Header.Revision+1)` |
| 断网几分钟后永久失联、再无事件 | 起点被 compact，`wresp.Err()` 没处理 | 收到 `ErrCompacted` 就**重建整个流程** |
| channel 关闭后循环静默退出 | `for range wch` 结束没上报 | 退出前返回 `ctx.Err()`（本函数就是这么写的），外层要**重连**而不是忽略 |

### 4. 三句可以直接背的总结

1. **多数派决定可用性**：3 节点容 1 挂、5 节点容 2 挂；`n` 台能容忍 `floor((n-1)/2)` 台，
   **所以集群规模一定是奇数**——4 台和 3 台容错能力一样，却多花一台、还多一个通信对象。
   > ⚠️ 顺带修正正文 [一致性与CAP.md](一致性与CAP.md) CAP 表格里那句"一个节点挂了，数据就写不进去"：
   > **3 节点集群挂 1 台仍然可写**（2/3 依旧过半）。写不进去发生在**挂 ≥ 半数**时。
   > 面试里把这句话说准，比背表格更能证明你真懂多数派。
2. **一致性是开关，不是信仰**：同一个 etcd，`Get` 传不传 `WithSerializable()` 就是 C 与 A 的分界。
3. **客户端侧的正确性靠"版本号一路传下去"**：`Revision` / `ModRevision` / CAS 的 `Compare`
   是把"我们最终会一致"变成"我们知道现在不一致"的唯一手段。

---

## 使用二：Java（jetcd 与 ZooKeeper/Curator 对照）
## 使用二：Java（jetcd，以及与 ZooKeeper/Curator 的概念对照）

> 校验说明：本节按 `jetcd 0.8.x` + `Curator 5.x` 的公开 API 书写，
> **未在本机编译校验**；标 ⚠️ 的那一条请以你实际版本的 javadoc 为准。

### 1. 客户端要做成 Spring 单例 Bean

```java

package demo.raft;

import io.etcd.jetcd.Client;
import io.etcd.jetcd.ByteSequence;
import io.etcd.jetcd.KV;
import io.etcd.jetcd.kv.GetResponse;
import java.util.concurrent.CompletableFuture;
import java.util.concurrent.ExecutionException;
import java.util.concurrent.TimeUnit;
import org.springframework.context.annotation.Bean;
import org.springframework.context.annotation.Configuration;

@Configuration
public class EtcdConfig {

    // ★ 单例：jetcd Client 内部持有 gRPC channel 与线程池，
    //   每请求新建会把建连/认证开销摊到每次调用上，还会耗尽 fd。
    @Bean(destroyMethod = "close")
    public Client etcdClient() {
        return Client.builder()
                .endpoints("http://127.0.0.1:2379", "http://127.0.0.1:2479", "http://127.0.0.1:2579")
                // .user(ByteSequence.from("root", UTF_8))
                // .password(ByteSequence.from("r00t", UTF_8))   // 开了 RBAC 才需要
                .build();
    }

    @Bean
    public KV etcdKV(Client client) {
        return client.getKVClient();   // KVClient 本身也是共享的、线程安全的
    }
}
```

```java

package demo.raft;

import io.etcd.jetcd.ByteSequence;
import io.etcd.jetcd.KV;
import io.etcd.jetcd.kv.GetResponse;
import java.nio.charset.StandardCharsets;
import java.util.concurrent.TimeUnit;

public class EtcdReader {

    private final KV kv;

    public EtcdReader(KV kv) { this.kv = kv; }

    // 默认读 = linearizable = 强一致（正文 [一致性与CAP.md](一致性与CAP.md) 的 etcd 行为）
    public String getStrong(String key) throws Exception {
        GetResponse resp = kv.get(bs(key)).get(3, TimeUnit.SECONDS);
        return resp.getKvsList().isEmpty() ? null
                : resp.getKvsList().get(0).getValue().toString(StandardCharsets.UTF_8);
    }

    // ⚠️ 弱一致（serializable）读在 jetcd 上是否暴露、叫什么，**各版本不一致**，
    //   用之前一定去你依赖的 GetOption javadoc 里确认；
    //   很多团队的实测做法是"自己加一层带 TTL 的本地缓存"来换 A，而不是去改读的语义。
}
```

### 2. 与 ZooKeeper（Curator）的概念对照——这条对照线在面试里最值钱

etcd 用 Raft，ZooKeeper 用 **ZAB**（Paxos 系）。**概念一一对应**，会一套就能讲另一套：

| 概念 | etcd（Go 侧见使用一） | ZooKeeper / Curator | 说明 |
|---|---|---|---|
| 会话与租约 | `Lease` + `KeepAlive`，TTL 15s | `Session` 的 `sessionTimeoutMs`（Curator 默认 60s） | **都是"心跳一断，临时数据自动消失"** |
| 临时节点 | 挂了 lease 的 key | `CreateMode.EPHEMERAL` | 服务注册的地基 |
| 顺序 + 临时 = 可等待的锁 | `/leader/xxx` + `Campaign` | `CreateMode.EPHEMERAL_SEQUENTIAL` + `PathLock`/`InterProcessMutex` | 锁的公平性靠序号 |
| 版本号 / CAS | `ModRevision` + `Txn.Compare` | `stat.version` + `setData(byte[], version)` | **语义完全一致**，都是"我读到的是 v，改的也必须是 v" |
| 变更通知 | `Watch` + `WithStartRevision` | `CuratorCache` / `PathChildrenCache` | **都要处理"事件之间丢了一次"** |
| 集群视图 | `Status` → `leader` / `raftTerm` | `zkServer.sh status` → `mode: leader/follower` | etcd 的 term ≈ ZAB 的 epoch |
| 过半存活 | 3 容 1、5 容 2 | 完全相同（**多数派是共识算法的通性**） | ZK **必须**有 leader；etcd 亦如此 |

```java

package demo.raft;

import java.util.Collection;
import org.apache.curator.framework.CuratorFramework;
import org.apache.curator.framework.CuratorFrameworkFactory;
import org.apache.curator.framework.recipes.leader.LeaderLatch;
import org.apache.curator.framework.recipes.leader.LeaderLatchListener;
import org.apache.curator.retry.RetryForever;
import org.springframework.context.annotation.Bean;
import org.springframework.context.annotation.Configuration;

@Configuration
public class ZkLeaderConfig {

    // ★ 单例：CuratorFramework 内部是 ZK 连接 + 事件线程，必须全应用共用一个。
    @Bean(destroyMethod = "close")
    public CuratorFramework zk() {
        return CuratorFrameworkFactory.builder()
                .connectString("127.0.0.1:2181,127.0.0.1:2182,127.0.0.1:2183")
                .sessionTimeoutMs(30_000)
                .connectionTimeoutMs(5_000)
                .retryPolicy(new RetryForever(1_000))   // 连接失败无限重试：ZK 抖动是常态
                .build();
    }

    /**
     * 与 etcd 的 Campaign 等价的"全局单写者"。
     * 注意两点（和正文 Q2 完全对应）：
     *   1. LeaderLatch 靠**临时顺序节点**实现，进程崩溃 → session 超时 → 节点消失 → 下一个上位；
     *      所以"切换要等一个 sessionTimeout"，不要指望它是 0 秒。
     *   2. 业务代码要**以 listener 的 stateChanged 为准**，不要缓存"我是 Leader"的判断，
     *      否则会出现"我以为我还是 Leader"（fencing 问题）。
     */
    @Bean(destroyMethod = "close")
    public LeaderLatch leaderLatch(CuratorFramework zk) {
        LeaderLatch latch = new LeaderLatch(zk, "/leader/my-job", "127.0.0.1:8080");
        latch.addListener(new LeaderLatchListener() {
            @Override public void isLeader() { /* 启动后台任务 */ }
            @Override public void notLeader() { /* 必须**立刻**停手 */ }
        });
        try {
            latch.start();
        } catch (Exception e) {
            throw new IllegalStateException("zk leader latch start failed", e);
        }
        return latch;
    }

    static Collection<String> participants(LeaderLatch latch) throws Exception {
        return latch.getParticipants().stream().map(p -> p.getId()).toList();
    }
}
```

> **选型对照（一句话）**：ZK 是"为协调而生的通用库"（临时节点、ACL、多种 watch 语义），
> etcd 是"用 Raft 重做了一遍、只给你 KV + Watch 的极简库"。
> 正文 Q1 说的"Raft 的初衷是可理解性"，在**客户端 API 上同样成立**：
> etcd 的接口更少，所以第 3 节那四类坑更容易在 review 中被看出来。

---

## 使用三：三节点实验（把每条结论都跑一遍）
## 使用三：三节点实验（把正文每一条结论都跑一遍）

> ⚠️ 校验说明：**本节命令未在本机实测**（Docker Desktop 未运行，且 etcd 集群需要 3 个容器）。
> 内容按 etcd v3.5/v3.6 的公开参数与 `etcdctl` 子命令书写，**跑之前请核对 `--help`**；
> 实验设计本身是可直接执行的。

### 1. 起一个 3 节点集群

```yaml

# docker-compose.yml —— 三个节点同一台机器，用不同端口区分
services:
  etcd1:
    image: quay.io/coreos/etcd:v3.5.17
    command: >
      etcd
      --name=n1 --data-dir=/etcd
      --initial-advertise-peer-urls=http://etcd1:2380
      --listen-peer-urls=http://0.0.0.0:2380
      --advertise-client-urls=http://etcd1:2379
      --listen-client-urls=http://0.0.0.0:2379
      --initial-cluster=n1=http://etcd1:2380,n2=http://etcd2:2380,n3=http://etcd3:2380
      --initial-cluster-state=new
      --auto-compaction-retention=1h
    environment:
      ETCDCTL_API: "3"
  etcd2:
    image: quay.io/coreos/etcd:v3.5.17
    # 同上，把 n1/etcd1 全换成 n2/etcd2
  etcd3:
    image: quay.io/coreos/etcd:v3.5.17
    # 同上，换成 n3/etcd3
```

```bash

# 别名与常用观测（ETCDCTL_ENDPOINTS 一次给全，别只写一个）
export ETCDCTL_API=3
export ETCDCTL_ENDPOINTS=http://127.0.0.1:2379,http://127.0.0.1:2479,http://127.0.0.1:2579

etcdctl endpoint status  -w table --cluster   # ★ 看 IS LEADER / RAFT TERM / DB SIZE
etcdctl endpoint health  -w table --cluster   # 谁不健康
etcdctl member list      -w table             # 成员 id / name / peer urls
```

### 2. 四个实验，对应正文四句结论

| # | 正文结论 | 操作 | 该看到什么 |
|---|---|---|---|
| **A** | 「Leader 靠心跳维护地位」 | `docker stop etcd1`（先记下谁是 leader） | 3~**几百毫秒**后（选举超时随机，150~300ms 量级）另一台升为 leader；`endpoint status` 里 `IS LEADER` 换行 |
| **B** | 「任期像届一样单调递增」 | 反复 `docker stop` 当前 leader，每次前后各跑一次 `endpoint status` | **RAFT TERM 只增不减**，而且经常**一次跳好几格**（每轮选举都先自增） |
| **C** | 「存活不过半，集群无法工作」 | `docker stop etcd2 etcd3`（3 台只剩 1 台） | `put` **失败**（拿不到多数派确认）；**`get` 默认也失败**（linearizable 读要 quorum） |
| **D** | 「CP 的系统牺牲的是 A」 | 实验 C 的同时，只连**存活那一台**做**本地读** | 读得到旧数据（serializable / `--consistency=s`，若该版本支持）→ **同一套 etcd，C 与 A 的差别只在这个开关** |

```bash

# 实验 B：连续赶 leader 三次，观察 term 递增（etcdctl 输出里 RAFT TERM 列）
for i in 1 2 3; do
  etcdctl endpoint status -w table --cluster
  etcdctl move-leader <另一个成员的ID>     # 主动换主，比 stop 更温和，适合线上演练
done

# 实验 C/D：只剩 1/3 节点时的读写表现
etcdctl put foo bar                          # 预期失败：拿不到多数派
etcdctl get foo --consistency=s              # ⚠️ 该 flag 是否存在以 etcdctl get --help 为准
```

### 3. 生产上最常撞的四件事

| 现象 / 报错 | 根因 | 处置 |
|---|---|---|
| `etcdserver: no leader` / `Unavailable`，且 `endpoint status` 里 `leader=0` | **quorum 丢了**（正文 Q1 的硬性约束） | 先恢复节点数，不要在客户端加重试风暴；**这正是 etcd 牺牲 A 的体现** |
| `etcdserver: mvcc: database space exceeded` | 存储超过 `--quota-backend-bytes`（默认 2MiB 配额很小，生产要显式给 8GiB） | `etcdctl compact <rev>` → `etcdctl defrag` → **`etcdctl alarm disarm`**（三条都要，顺序不能错） |
| 写入越来越慢、`DB SIZE` 一直涨 | 没开自动压缩（`--auto-compaction-retention`），历史版本堆积 | 配自动压缩 + 定期 defrag；**defrag 要一个节点一个节点来**（它会阻塞该节点） |
| watch 报 `ErrCompacted`，之后本地视图永久错乱 | 压缩掉了订阅起点，**却没有回退成全量重拉** | 使用一 §5 的写法：捕获 `ErrCompacted` → 丢弃缓存 → 重新 `Get`+`WithRev` |

> 面试时可以收一句：**"用 etcd 的难点从来不是理解 Raft，而是三件事——
> 租约 TTL 与业务超时的关系、quorum 丢失时你的服务该降级还是该拒绝、
> 以及 watch 断线后怎么把本地视图补齐。"**
> 这三问分别对应正文的 [一致性与CAP.md](一致性与CAP.md)（可用性取舍）、Q1（多数派硬约束）、Q2（数据只能从 Leader 流向 Follower）。

---

## 关联

- [一致性与CAP.md](一致性与CAP.md) — 为什么要强一致，linearizable 读的由来
- [服务发现与负载均衡.md](服务发现与负载均衡.md) — 租约注册与 Watch 的生产用法
- [../middleware/Nacos.md](../middleware/Nacos.md) — 另一套一致性协议（Distro + JRaft）
- [分布式ID.md](分布式ID.md) — 多节点下的单调与唯一
