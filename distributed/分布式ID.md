# 分布式 ID 生成

> 分库分表后主键怎么生成：UUID / 数据库自增 / 号段 / Redis INCR / 雪花算法等方案全景对比，雪花的位结构、时钟回拨与 workerId 分配，以及 Go（bwmarrin/snowflake、sonyflake）与 Java（Hutool、MyBatis-Plus、手写）的落地代码
>
> 内容整理自个人学习笔记。分表后的路由方案见 [../mysql/数据迁移与分表.md](../mysql/数据迁移与分表.md)。

---

## 一、一句话定位与三条硬要求

> **分布式 ID 生成器 = 多节点 / 多库同时写入时，仍能产出全局唯一且趋势递增主键的基础组件。**

单机时代 `AUTO_INCREMENT` 够用，一旦**分库分表**（路由方案见 [../mysql/数据迁移与分表.md](../mysql/数据迁移与分表.md)）就失效了：

| 问题 | 说明 |
|---|---|
| **多库会冲突** | 每个库各自从 1 自增，`user_0` 和 `user_1` 都会生成 `id=1`，合起来不再唯一 |
| **ID 连续易被爬** | `/order/10001`、`/order/10002` 可被遍历，还会暴露业务量 |
| **无法提前生成 / 单库成瓶颈** | 必须先 INSERT 拿到库返回的 ID 才能写明细；写性能被单库上限卡死，主库挂了全站不可写 |

### 三条硬要求（必须满足）

| 要求 | 为什么 | 不达标的代价 |
|---|---|---|
| ⭐ **全局唯一** | 主键的底线 | 主键冲突、数据覆盖、分表路由错乱 |
| ⭐ **趋势递增** | InnoDB 主键是**聚簇索引**，B+ 树按主键有序组织，递增写入只在最右页追加 | 无序写入触发**随机 IO + 页分裂**，吞吐骤降、碎片飙升 |
| ⭐ **高可用高性能** | ID 生成在所有写请求的主链路上 | 生成器抖动 = 全站不可写 |
**理想特性（加分项）**：单调递增（同一节点内严格变大，便于增量拉取）；含时间信息（可反解生成时间）；不泄露业务量；长度短（索引占用小）。

---

## 二、方案全景

### 2.1 一张大对比表

| 方案 | ID 形态 | 有序性 | 吞吐上限 | 依赖组件 | 主要缺点 | 适用场景 |
|---|---|---|---|---|---|---|
| ⭐ **雪花 Snowflake** | 64 位 Long | 趋势递增、单节点单调 | 单节点 **4096/ms ≈ 409 万/s** | 无（纯内存），只需分配 workerId | ⚠️ 依赖时钟、**回拨会重复**；workerId 易冲突 | ⭐ 大规模、要求趋势递增的主链路 |
| **UUID v4** | 36 字符串 / 16 字节 | ❌ 完全无序 | 本地生成无上限 | 无 | ⚠️ **做主键导致页分裂**；16 字节让二级索引膨胀 | 允许无序的非主键（traceId、临时凭证） |
| **UUID v7** | 36 字符串（前 48 位为时间戳） | ✅ 时间有序 | 本地生成无上限 | 无 | 仍 16 字节，索引比 BIGINT 大 | 想要"本地生成 + 有序 + 无需 workerId" |
| **数据库自增 + 步长** | Long（递增不连续） | 递增 | 受单库写能力限制，**几千/s** | MySQL | ⚠️ **扩容要停机改步长 + 搬数据**；DB 单点故障 | 小系统、库数量固定 |
| **数据库号段（Segment）** | Long（连续号段） | 递增 | 号段长度 × 取号 QPS，**百万级** | MySQL + 本地缓存 | ⚠️ **号段耗尽瞬间阻塞**；重启后 ID 跳变 | 中小规模、不想处理时钟问题 |
| **Redis INCR / INCRBY** | Long | 递增 | 单 key **5~10 万/s**（批量更高） | Redis | ⚠️ 持久化没配好**重启发重号**；Redis 不可用即阻塞 | 已有 Redis 集群、量不大 |
| **美团 Leaf** | Long（segment / snowflake 双模式） | 递增 / 趋势递增 | 号段模式**十万级** | Leaf Server + MySQL + ZK | 需独立部署运维一套服务 | 想直接用成熟开源方案 |
| **百度 UidGenerator** | Long（位分配可配） | 趋势递增 | **600 万/s**（RingBuffer 预生成） | MySQL + ZK（可选） | 依赖较重，自定义位分配要慎用 | 超高吞吐 |
| **滴滴 Tinyid** | Long（号段） | 递增 | 号段模式，可水平扩展 | Tinyid Server + MySQL | 只有号段模式、无雪花 | 号段方案要开箱即用 |

### 2.2 逐方案详解

#### ① UUID / UUIDv7

- **v4**：122 位随机数，本地生成、零协调、天然全球唯一。
- ⚠️ **无序是致命伤**：作为聚簇主键时落点随机，**B+ 树频繁页分裂**、碎片多；每个二级索引都要存一份 16 字节主键，索引体积暴涨。
- **v7**：把 **48 位毫秒时间戳放在最高位** → 跨毫秒有序，解决页分裂（MySQL 8.4 内置 `UUID_V7()`）；折中做法是**对外用 UUID、对内有 BIGINT 主键**（UUID 只作业务标识列 + 唯一索引）。

#### ② 数据库自增（单点 + 步长法）

```sql
-- id_gen: id BIGINT AUTO_INCREMENT PRIMARY KEY + stub CHAR(1) UNIQUE
REPLACE INTO id_gen (stub) VALUES ('a');  -- REPLACE 保证表里始终只有一行
SELECT LAST_INSERT_ID();                  -- 拿到本次分配的 ID
```

多库靠**步长错开**（2 个库设 `auto_increment_increment = 2`，`auto_increment_offset` 分别为 1、2 → 产出 1,3,5 与 2,4,6）。
⚠️ **致命缺点：扩容麻烦** —— 2 库扩到 3 库要改步长、重排 offset，**历史数据可能与新规则冲突**，通常需停机 + 搬迁；且 DB 是单点瓶颈与单点故障。
#### ③ 数据库号段（Segment，美团 Leaf-segment）⭐

思路：**一次从 DB 取一段（如 1~1000）缓存在本机内存，用完再取**，把 DB 压力降低 `step` 倍。

```sql
CREATE TABLE leaf_alloc (
  biz_tag     VARCHAR(128) NOT NULL PRIMARY KEY COMMENT '业务标识，如 order',
  max_id      BIGINT NOT NULL DEFAULT 1 COMMENT '已分配到的最大号',
  step        INT    NOT NULL COMMENT '号段长度'
) ENGINE=InnoDB;
-- 取号段：靠 DB 行锁保证并发安全，本次可用区间 [max_id-step+1, max_id]
UPDATE leaf_alloc SET max_id = max_id + step WHERE biz_tag = 'order';
SELECT max_id, step FROM leaf_alloc WHERE biz_tag = 'order';
```

- **优点**：ID 连续递增、长短可控；DB 宕机后本机号段**还能撑一段时间**；step 可按业务调。
- ⚠️ **号段耗尽会阻塞（RT 尖刺）**：号段用完那一刻要**同步等 DB 返回新号段**；用**双 buffer** 解决 —— 消费到 10% 时异步预取下一段，用完直接切换（Leaf 实现）。
- ⚠️ 进程重启时内存里没用完的号被废弃 → ID **跳变**（不连续，但不影响唯一性）。

#### ④ Redis INCR / INCRBY

```bash
INCR   global:order:id        # 每次取一个：一次网络往返换一个号
INCRBY global:order:id 1000   # 一次取 1000：退化为"号段模式"，本地分配
```

- `INCR` 是**单线程原子操作**，天然无并发冲突。
- ⚠️ **持久化必须配好**：RDB 是分钟级快照，宕机丢一大段 → **重启从旧值继续发，ID 重复**；AOF `everysec` 仍可能丢 1 秒，**同样会重复**。要么 `appendfsync always`，要么启动后用 `INCRBY` 跳一段安全距离；另外需要哨兵 / Cluster 保证高可用，并考虑主从切换期间的数据丢失。
#### ⑤ 雪花算法 Snowflake ⭐

Twitter 开源，**纯内存位运算、不依赖任何中间件**，详见第三节。

#### ⑥ 成熟开源实现（各 3~5 行）

| 项目 | 模式 | 要点 |
|---|---|---|
| **美团 Leaf** | segment + snowflake 双模式 | 号段模式用 DB + 双 buffer；雪花模式靠 **ZK 顺序节点自动分配 workerId**（本地缓存 `workerID` 文件降级），并做时钟回拨告警 |
| **百度 UidGenerator** | snowflake 变体 | 位分配**可自定义**；用 **RingBuffer 预生成缓存 ID**，单实例约 **600 万/s**；workerId 默认由 DB `WORKER_NODE` 表分配 |
| **滴滴 Tinyid** | segment（双 buffer） | 支持多 DB（`tinyid.primary.db` / `secondary.db`）自动切换；提供 HTTP 与 SDK（本地缓存）两种接入 |

---

## 三、雪花算法深入 ⭐

### 3.1 位结构与各段取值范围

标准 Snowflake 是 **64 位 Long**（最高位固定为 0 保证正数）：

```
 0        1                          11       12                        63
 ├──┬────────────────────────────┬─────────┬────────────────────────────┤
 │0 │      41 bit 时间戳(ms)      │ 10 bit  │       12 bit 序列号        │
 │  │  (当前时间 - 起始时间戳)      │ workerId│        (毫秒内自增)         │
 └──┴────────────────────────────┴─────────┴────────────────────────────┘
```

| 段 | 位数 | 取值范围 | 说明 |
|---|---|---|---|
| 符号位 | 1 | 恒为 0 | 保证 ID 为正数（Java `long` / Go `int64` 不溢出） |
| **时间戳** | 41 | `2^41` ms ≈ **69.7 年** | 存**相对值**（`now - epoch`）；Twitter 用 `1288834974657`（2010-11-04），国内常用上线日（如 2024-01-01 = `1704067200000`） |
| **workerId** | 10 | `2^10` = **1024 个节点** | 可拆为 5 位 datacenterId + 5 位 workerId（32 × 32） |
| **序列号** | 12 | `2^12` = **4096** | 同一节点同一毫秒内自增 |

**关键推论**：
- 单节点理论 QPS = `4096 × 1000` = **409.6 万/s**，远超实际需求；
- 全局是**趋势递增**而非严格单调（同毫秒内由 workerId 定序），但对 B+ 树足够友好；
- 41 位会用完：以 2024 起算可用到 **2093 年**；沿用 Twitter 的 2010 起点则 **2028 年前后就要扩位**（workerId 缩到 8 位、时间戳扩到 43 位）。

### 3.2 ⚠️ 时钟回拨（为什么发生 / 为什么重复 / 怎么处理）

**为什么发生**：ID 高位取自 `System.currentTimeMillis()`，触发源有 NTP 校时（时间偏快时被拉回）、运维手工改时间、⚠️ **虚拟机 / 容器挂起恢复与宿主机时钟漂移**（云环境高频）、闰秒调整。
**为什么重复**：唯一性依赖 `(timestamp, workerId, sequence)` 三元组，时间跳回"过去"的某一毫秒时，若该毫秒的 sequence 已发过，重新从 0 计数必然撞号。

| 处理手段 | 做法 | 评价 |
|---|---|---|
| ⭐ **小回拨直接等待** | 回拨 ≤ 阈值（如 100ms）时 `sleep` 等时钟追平再发号 | 简单可靠，业务几乎无感，阈值内首选 |
| **大回拨直接拒绝** | 回拨 > 阈值 → **抛异常 / 返回错误**，宁可这次写入失败 | 安全边界，配合告警人工介入 |
| **备用时钟** | 用**单调时钟**（`System.nanoTime` / `time.Since`）推算时间增量，或参考最近 N 次上报时间做平滑时钟 | 从根上降低回拨概率，实现较复杂 |
| **扩展位** | 回拨时把 workerId 换成**预留号段**（如 +512），或用 1 bit 标记"回拨代" | 位分配变复杂，需保证预留号不冲突 |
| **历史上报** | 把 `lastTimestamp` 上报 / 落盘，启动或回拨时对比，发现更小的时间戳直接拒绝启动 | Leaf 采用类似思路（ZK + 本地缓存文件） |

> 口诀：**小回拨等一等，大回拨直接报错，别硬着头皮发号。**

### 3.3 workerId 怎么分配

| 方式 | 做法 | 优缺点 |
|---|---|---|
| **手动配置** | 写在配置文件 / 环境变量 | 最简单；⚠️ 节点多了易配重，容器重启 IP 会变 |
| **ZK / etcd 注册** | 启动创建**顺序节点**或抢锁拿编号（Leaf 做法），断线重连复用原号 | 自动不重复；依赖 ZK/etcd |
| **数据库分配** | 启动向 `worker_node` 表插一行拿自增 ID（UidGenerator 做法） | 依赖少；⚠️ 要注意**回收**，超过 1024 就满了 |
| ⭐ **K8s StatefulSet 序号** | Pod 名 `order-0/1/2`，取**主机名末尾数字**作 workerId | 天然稳定；⚠️ 仅适用于有状态服务，Deployment 不行 |
| **IP / MAC 哈希** | IP 后 8 位或 MAC 哈希取模 | 无需额外组件；⚠️ **可能碰撞**（MyBatis-Plus 默认即此类） |
⚠️ **workerId 重复 = 必然撞号**，且撞号后**无补救机制**（数据已写入），是雪花最容易踩的事故。

### 3.4 ⚠️ 前端 JS 精度丢失（53 位安全整数）

JS `Number` 是 IEEE 754 双精度，**精确整数范围仅 `±(2^53-1)`**（`9007199254740991`）；而雪花 ID 是 64 位、常见值约 `1.7e18`，**远超 2^53** → `JSON.parse` 后低位被舍入，末尾变 0，两个不同 ID 可能变成同一个。

```java
// Jackson：单个字段（全局则用 SimpleModule 注册 ToStringSerializer：Long.class / Long.TYPE）
@JsonSerialize(using = ToStringSerializer.class)
private Long orderId;
```

```go
// Go：struct tag 序列化为字符串
type Order struct {
    ID int64 `json:"id,string"`
}
```
> 同类问题也出现在 32 位 PHP、以及导出 Excel（长数字转科学计数法）时。

### 3.5 单节点每毫秒 4096 个够不够 / 如何扩展

4096/ms = **409 万/s**，DB 通常先扛不住，**绝大多数场景够用**。不够时按推荐顺序扩展：① **加节点**（workerId 有 1024 个，比改位分配安全）；② **调位分配**（序列位调大、机器位调小，如 `8 + 14` = 256 节点 × 16384/ms）；③ **换实现**（sonyflake / UidGenerator）；④ **预生成**（RingBuffer，600 万/s）。

---

## 四、选型建议（决策清单）

| 你的情况 | 建议 |
|---|---|
| 小规模、**允许无序**（内部后台、日志追踪） | **UUID** 对外标识；主键可用 UUIDv7 |
| 中小规模、要数字、想简单、不想处理时钟 | **号段模式**（DB + 双 buffer，或直接用 **Tinyid**） |
| 大规模、要求趋势递增、能运维 workerId | ⭐ **雪花算法**（自建或 **Leaf-snowflake**） |
| 已有 Redis 集群且量不大 / 不想自建 | `INCRBY` 批量取号（**务必配好 AOF**）/ **Leaf / Tinyid / UidGenerator** |

> ⭐ 决策口诀：**要数字 + 趋势递增 + 高吞吐 → 雪花；不想管时钟和 workerId → 号段；不在意顺序 → UUID。**

---

## 五、使用一：Go ⭐

### 5.1 `github.com/bwmarrin/snowflake`（标准 41+10+12）

安装：`go get github.com/bwmarrin/snowflake`

```go
// ① 创建节点：workerId ∈ [0,1023]，必须全局唯一（起始时间戳可改 snowflake.Epoch）
node, err := snowflake.NewNode(1)
if err != nil {
	panic(err)
}
id := node.Generate() // ② 生成：类型 snowflake.ID，底层是 int64
fmt.Println(id.Int64(), id.String(), id.Base36(), id.Base64(), id.Bytes()) // 转各类型
fmt.Println(id.Time(), id.Node(), id.Step())                    // 反解：时间戳 / workerId / 序列号
fmt.Println(time.UnixMilli(id.Time()), snowflake.Decompose(id)) // 生成时刻 / map[msb time node step]
```
包内常量：`snowflake.NodeBits = 10`、`snowflake.StepBits = 12`，包变量 `snowflake.Epoch`（毫秒）可改。

### 5.2 `github.com/sony/sonyflake`（机器多的场景）

安装：`go get github.com/sony/sonyflake`

```go
sf := sonyflake.NewSonyflake(sonyflake.Settings{
	StartTime:      time.Date(2024, 1, 1, 0, 0, 0, 0, time.UTC),
	MachineID:      func() (uint16, error) { return 3, nil }, // 必须全局唯一
	CheckMachineID: func(uint16) bool { return true },        // 启动自检，可接 etcd
})
if sf == nil {
	panic("sonyflake init failed")
}
id, err := sf.NextID() // uint64；返回 (uint64, error)
if err != nil {
	panic(err)
}
fmt.Println(id, sonyflake.Decompose(id)) // map[id:.. time:.. sequence:.. machine-id:..]
```

**位分配差异**（⚠️ 与标准雪花不同）：`0 | 39 bit 时间(单位 10ms) | 8 bit 序列号 | 16 bit 机器 ID`

- 机器位 **16 位 = 65536 个节点**；时间位 39 位、**单位 10ms**，覆盖约 **174 年**；
- 序列位仅 8 位 = **256/10ms ≈ 2.56 万/s**，低于标准雪花，耗尽时 sleep 到下一时间单元；✅ 它用单调的 `elapsedTime` 计数，**轻微时钟回拨不会直接产生重复 ID**。

### 5.3 可运行的封装示例（workerId 配置 + 时钟回拨兜底）

```go
package main

import (
	"errors"
	"fmt"
	"os"
	"strconv"
	"sync"
	"time"
)

const (
	epoch         int64 = 1704067200000 // 2024-01-01 UTC，41 位可用到 2093 年
	maxWorkerID   int64 = -1 ^ (-1 << 10) // 1023
	maxSeq        int64 = -1 ^ (-1 << 12) // 4095
	workerShift   uint  = 12              // 序列号位数
	timeShift     uint  = 22              // 序列号 + workerId 位数
	maxBackwardMS int64 = 100             // 容忍的最大回拨幅度
)

var (
	ErrClockBackward = errors.New("idgen: clock moved backwards")
	ErrInvalidWorker = errors.New("idgen: workerId out of range")
)

// Generator 并发安全的雪花 ID 生成器
type Generator struct {
	mu                    sync.Mutex
	workerID, seq, lastMS int64
}

func New(workerID int64) (*Generator, error) {
	if workerID < 0 || workerID > maxWorkerID {
		return nil, fmt.Errorf("%w: %d not in [0,%d]", ErrInvalidWorker, workerID, maxWorkerID)
	}
	return &Generator{workerID: workerID, lastMS: -1}, nil
}

func (g *Generator) Next() (int64, error) {
	g.mu.Lock()
	defer g.mu.Unlock()
	now := time.Now().UnixMilli()
	switch {
	case now < g.lastMS: // ⚠️ 时钟回拨
		back := g.lastMS - now
		if back > maxBackwardMS { // 大回拨：直接拒绝，宁可失败也不发重号
			return 0, fmt.Errorf("%w: %dms, refuse to generate", ErrClockBackward, back)
		}
		time.Sleep(time.Duration(back) * time.Millisecond) // 小回拨：等时钟追平
		if now = time.Now().UnixMilli(); now < g.lastMS {
			return 0, fmt.Errorf("%w: still behind after wait", ErrClockBackward)
		}
		g.seq = 0
	case now == g.lastMS: // 同一毫秒，序列自增
		g.seq = (g.seq + 1) & maxSeq // 等价于 (seq+1) % 4096
		if g.seq == 0 {              // 本毫秒 4096 个用完，等到下一毫秒
			for now <= g.lastMS {
				now = time.Now().UnixMilli()
			}
		}
	default: g.seq = 0 // 新的毫秒
	}
	g.lastMS = now
	return (now-epoch)<<timeShift | g.workerID<<workerShift | g.seq, nil
}

// WorkerIDFromEnv：容器环境必须显式注入
// K8s StatefulSet 可用 downward API 把 Pod 序号注入 WORKER_ID
func WorkerIDFromEnv() (int64, error) {
	n, err := strconv.ParseInt(os.Getenv("WORKER_ID"), 10, 64)
	if err != nil || n < 0 || n > maxWorkerID {
		return 0, fmt.Errorf("%w: %q", ErrInvalidWorker, os.Getenv("WORKER_ID"))
	}
	return n, nil
}

func main() {
	workerID, err := WorkerIDFromEnv() // ⚠️ 不要给默认 workerId，否则多实例必冲突
	if err != nil {
		panic(err)
	}
	g, _ := New(workerID)
	id, err := g.Next() // 回拨兜底：err 时可切备用生成器或直接失败告警
	if err != nil {
		panic(err)
	}
	fmt.Println(id, time.UnixMilli(epoch+(id>>timeShift))) // 反解生成时刻
}
// 交给前端务必转字符串，避免 JS 53 位精度丢失：type Order struct { ID int64 `json:"id,string"` }
```

### 5.4 两个库怎么选

| 维度 | bwmarrin/snowflake | sony/sonyflake |
|---|---|---|
| 位分配 | 41 时间(1ms) + 10 机器 + 12 序列（约 69 年） | 39 时间(10ms) + 8 序列 + 16 机器（约 174 年） |
| 节点上限 | 1024 | ⭐ **65536** |
| 单节点 QPS | ⭐ **4096/ms ≈ 409 万/s** | 256/10ms ≈ 2.56 万/s |
| ID 类型 | `int64`（`snowflake.ID`） | `uint64` |
| 时钟回拨 | ⚠️ 直接 panic，**需自行兜底** | 单调 `elapsedTime`，**容忍轻微回拨** |
| 适合 | 节点 ≤ 1024、追求吞吐的常规业务 | ⭐ 节点很多（容器集群、边缘节点） |

---

## 六、使用二：Java ⭐

### 6.1 Hutool `Snowflake`

```xml
<dependency>
  <groupId>cn.hutool</groupId>
  <artifactId>hutool-all</artifactId>
  <version>5.8.32</version>
</dependency>
```

```java
// workerId(0~31) + datacenterId(0~31)，合计 1024 种组合
Snowflake snowflake = IdUtil.getSnowflake(1, 1); // cn.hutool.core.lang.Snowflake
long   id    = snowflake.nextId();     // 1735689600000000001
String idStr = snowflake.nextIdStr();  // 直接给字符串，前端不会精度丢失
```
⚠️ 建议按 `(workerId, datacenterId)` 建**静态单例**复用；Hutool 内部已做时钟回拨判断（超阈值等待 / 抛异常）。

### 6.2 MyBatis-Plus `IdWorker` / `@TableId(type = IdType.ASSIGN_ID)`

```xml
<dependency>
  <groupId>com.baomidou</groupId>
  <artifactId>mybatis-plus-boot-starter</artifactId>
  <version>3.5.7</version>
</dependency>
```

```java
public class Order {
    @TableId(type = IdType.ASSIGN_ID)  // ⭐ 插入前就有 ID，无需等数据库回写
    private Long id;
}

long id = IdWorker.getId();      // 手动取号（com.baomidou.mybatisplus.core.toolkit.IdWorker）
String s = IdWorker.getIdStr();  // 字符串形式
```

```yaml
mybatis-plus:
  global-config:
    worker-id: 1          # ⚠️ 多实例必须显式配置，否则靠 MAC+进程号哈希，可能碰撞
    datacenter-id: 1
    db-config:
      id-type: assign_id  # 全局默认主键策略
```
`IdType`：`AUTO` 数据库自增（分库分表 ❌ 不适用）、`NONE` 跟随全局、`INPUT` 手动传入、⭐ `ASSIGN_ID` **雪花算法**（`DefaultIdentifierGenerator`，Long / String 均可）、`ASSIGN_UUID` 无横线 UUID。
⚠️ 主键用 `Long` 时，返回前端**必须配 `ToStringSerializer`**（见 3.4）。

### 6.3 手写雪花（关键位运算片段）

```java
public final class SnowflakeIdWorker {
    private static final long START_STAMP     = 1704067200000L;        // 2024-01-01
    private static final long SEQ_BITS        = 12L;
    private static final long WORKER_BITS     = 10L;
    private static final long MAX_SEQ         = ~(-1L << SEQ_BITS);    // 4095
    private static final long MAX_WORKER_ID   = ~(-1L << WORKER_BITS); // 1023
    private static final long WORKER_SHIFT    = SEQ_BITS;              // 12
    private static final long TIME_SHIFT      = SEQ_BITS + WORKER_BITS;// 22
    private static final long MAX_BACKWARD_MS = 100L;                  // 容忍的回拨幅度

    private final long workerId;
    private long sequence = 0L;
    private long lastStamp = -1L;

    public SnowflakeIdWorker(long workerId) {
        if (workerId < 0 || workerId > MAX_WORKER_ID) throw new IllegalArgumentException("bad workerId");
        this.workerId = workerId;
    }

    public synchronized long nextId() {
        long now = System.currentTimeMillis();
        // ⚠️ 时钟回拨：先等，等不平就拒绝发号
        if (now < lastStamp) {
            long backward = lastStamp - now;
            if (backward > MAX_BACKWARD_MS) throw new IllegalStateException("clock backwards " + backward + "ms");
            try {
                Thread.sleep(backward);
            } catch (InterruptedException e) {
                Thread.currentThread().interrupt();
                throw new IllegalStateException("interrupted while waiting for clock", e);
            }
            if ((now = System.currentTimeMillis()) < lastStamp) throw new IllegalStateException("still backwards");
        }
        if (now == lastStamp) { // 同一毫秒，序列自增
            sequence = (sequence + 1) & MAX_SEQ; // 等价于 (sequence + 1) % 4096
            if (sequence == 0L) { // 本毫秒 4096 个用完，自旋等到下一毫秒
                do { now = System.currentTimeMillis(); } while (now <= lastStamp);
            }
        } else {
            sequence = 0L; // 新的毫秒
        }
        lastStamp = now;
        return ((now - START_STAMP) << TIME_SHIFT) | (workerId << WORKER_SHIFT) | sequence; // 反解时间：START_STAMP + (id >> TIME_SHIFT)
    }
}
```

---

## 七、生产实践与坑

| 坑 | 说明与应对 |
|---|---|
| **⚠️ 容器环境 workerId 冲突（高频）** | Deployment 所有 Pod 环境一致，走 IP 哈希就可能撞号。对策：**StatefulSet 取 Pod 序号** / ZK 顺序节点 / 启动向 DB 申请并落库，并在启动时做一次**占用自检** |
| **⚠️ ID 作分片键的热点** | `id % N` 时分布**均匀**（低位是 workerId + 序列）；但若按**时间高位**分片（`(id >> 22) % N`），同一时间窗全落一个分片 → **写热点**。分片位应取自**低位**（或用基因法把分表位编进 ID 低位） |
| **ID 与时间换算** | `timestamp = epoch + (id >> 22)`；反过来可由时间范围算出 ID 区间，用于**按时间范围分页扫描** |
| **⚠️ ID 泄露业务量** | 连续 ID 可被遍历、可推算日单量。对策：对外**再映射一层**（内部 BIGINT → 哈希 / 加密成字符串），或对外直接用 UUID |
| **⚠️ 测试环境与生产冲突** | 测试库同步了生产数据、两边各用一套 workerId → 合并时主键冲突。对策：**epoch 错开** + workerId 段隔离（生产 0~511、测试 512~1023） |
| **生成器可用性监控** | 号段模式 DB 挂了还能撑一段；雪花模式纯本地，唯一外部依赖是**时钟与 workerId**。都要监控：回拨次数、号段耗尽次数、QPS |
| **字段类型 / 批量插入** | 雪花 ID 远超 `INT` 上限，MySQL 必须是 **`BIGINT`**；批量 INSERT 前先一次性生成 ID 列表，别在循环里反复取号 |

---

## 八、面试官会追问什么

1. **雪花算法的位结构？各段取值范围？** → 1 符号位 + 41 时间戳(ms) + 10 workerId + 12 序列号；41 位 ≈ 69 年、10 位 = 1024 节点、12 位 = 单毫秒 4096 个（约 409 万/s）。
2. **时钟回拨为什么会导致 ID 重复？怎么解决？** → 唯一性依赖 `(timestamp, workerId, sequence)`，时间跳回会让同一毫秒的序列号重新从 0 开始 → 撞号。小回拨（≤100ms）sleep 等追平；大回拨直接抛错拒绝发号；Leaf 用 ZK 上报历史时间校验；更彻底的是用单调时钟或扩展"回拨代"位。
3. **workerId 怎么分配才不会重复？** → 手动配置（易错）、ZK/etcd 顺序节点（Leaf）、DB 分配（UidGenerator）、**K8s StatefulSet 序号**、IP/MAC 哈希（可能碰撞，MP 默认实现）。
4. **为什么 UUID 不适合做数据库主键？** → 无序 → 聚簇索引 B+ 树**频繁页分裂**、随机 IO、碎片多；16 字节使二级索引膨胀。要用就选 **UUIDv7**，或只做业务标识列、主键仍用 BIGINT。
5. **号段模式的优点？号段耗尽怎么办？** → 优点：ID 连续递增、DB 压力被 step 摊薄、DB 短暂不可用仍能发号；耗尽时会**同步等 DB** 产生 RT 尖刺，用**双 buffer**（消费到 10% 时异步预取下一段）解决。
6. **Long 型 ID 返回前端为什么精度丢失？** → JS `Number` 是双精度浮点，安全整数只到 `2^53-1`，而雪花 ID 约 `1e18`；序列化成**字符串**（Jackson `ToStringSerializer` / Go `json:"id,string"`）。
7. **怎么保证 ID 绝对不重复？** → 三层：① 位结构保证 `(time, workerId, seq)` 唯一；② workerId 全局唯一分配 + 启动自检；③ 时钟回拨防护；再加唯一索引兜底与定期对账。
8. **数据库自增 + 步长法的缺点？** → 扩容要改步长和 offset，通常**停机 + 数据搬迁**；DB 是单点瓶颈与单点故障；ID 仍连续、易被遍历。
9. **41 位时间戳用完了怎么办？** → 以 2024 起算可用到 2093 年；Twitter 的 2010 起点约 2028 年耗尽。对策：**自定义 epoch**（最省事）、workerId 缩到 8 位 / 时间戳扩到 43 位，提前规划别等出事才改。
10. **雪花 ID 适合做分片键吗？** → `id % N` 分布均匀（低位是 workerId + 序列），可以；但按时间高位分片会造成**写热点**；分表键还要求**不可变**，ID 天然满足。
11. **号段模式和雪花模式怎么选？** → 号段：ID 连续、不依赖时钟，但 DB 仍是依赖源，适合中小规模；雪花：纯本地、无中间件、吞吐极高，但要管理 workerId 与时钟，适合大规模主链路。

---
