# MongoDB 文档数据库

> 覆盖 MongoDB 入门概念、文档建模、索引体系、副本集与选举机制、与 MySQL 的选型对比，以及 Go / Java 客户端用法。
>
> 内容整理自个人学习笔记。

# 入门

## 什么是MongoDB
该数据库基于灵活的JSON文档模型，非常适合敏捷式的快速开发。

### 面向文档设计
**数据的分层模型：database → collection → document → field**

MongoDB是基于JSON来描述数据的，所有的“数据行”都可以通过一个JSON格式的文档（document）来表示。

```javascript
{
  "_id": ObjectId("66f1a2b3c4d5e6f7a8b9c0d1"),
  "name": "alice",
  "age": 30,
  "tags": ["go", "database"],        // 数组字段
  "address": { "city": "Hangzhou" }, // 内嵌子对象
  "createdAt": ISODate("2024-09-24T08:00:00Z")
}
```
很明显，基于JSON格式的数据模型可读性非常强，也更加灵活；除了基本的数据类型，文档中还可以使用数组、内嵌子对象等高级的字段类型。

### 特性

#### 完备的索引
MongoDB支持各种丰富的索引类型，包括单键索引、复合索引，唯一索引等一些常用的结构。由于采用了灵活可变的文档类型，因此它也同样支持对嵌套字段、数组进行索引。通过建立合适的索引，我们可以极大地提升数据的检索速度。值得一提的是，MongoDB的索引实现与一般的关系型数据库索引并没有太多不同，因此，我们几乎可以使用某种“一致的思路”来设计索引或完成一些性能调优的任务。

MongoDB还支持地理空间索引、文本检索索引、TTL索引等不同的特性，这些特性在很大程度上简化了应用程序的开发工作，同时也使MongoDB获得了大量使用者的青睐。

#### 跨平台，支持各种编程语言
官方与社区驱动覆盖 Go、Java、Python、Node.js、C#、Rust、PHP；`mongosh` 提供交互式 Shell。各语言驱动 API 语义一致，跨语言迁移成本低。

#### 强大的聚合计算
聚合（aggregation）计算是MongoDB面向数据分析领域的重要特性，可以用于实现数据的分类统计或一些管道计算；作为对照，聚合框架能轻松完成关系型数据库的group by语句的分组功能，又或是大数据领域的map-reduce计算。

聚合管道由若干 stage 串联：`$match` → `$group` → `$sort` → `$project` → `$lookup`（左外连接）→ `$unwind`（展开数组）→ `$facet`（多路统计）。

#### 复制、分布式
MongoDB通过副本集（replication set）来实现数据库的高可用，这点类似于MySQL的Master/Slave复制架构，不同的是，一个副本集可以由一个主节点和多个备节点组成，主节点和备节点基于oplog来实现数据同步。在主节点发生故障时，备节点将重新选举出新的主节点以继续提供服务，整个切换过程是自动完成的。

在海量数据处理方面，MongoDB原生就支持分布式计算能力。在一个分布式集群中，多个文档被划入一个逻辑数据块（chunk），这些数据块可以被存储于不同的计算节点（分片）上，在新的计算节点（分片）加入时，数据块可以借助自动均衡的算法机制被迁移到合适的位置（通常是压力较小的分片）。通过这种自动化的调度及均衡工作，整个集群的数据库读写压力可以被分摊到多个节点上，从而实现负载均衡和水平扩展。

### 优势

#### 易用性
简单易用是MongoDB的一大优势。MongoDB是基于JSON格式的，这点对于开发人员来说显然更加友好，尤其是对全栈式开发者来说，JSON是前后端开发领域中最通用、易读的描述性语言。

#### 高性能
基于内存的二级的缓存提供了高速读取数据能力，在写方面则是根据磁盘I/O的特点做了缓冲式写入，这是基于空间、时间因素权衡的一种择优设计。

#### 高可靠
对于单个MongoDB节点来说，可以通过开启Journal机制来实现断电保护，这是一种WAL预写日志机制，在发生异常断电后，可以通过Journal日志进行数据恢复。在默认情况下，Journal仅允许最多丢失50ms内更新的数据。

对于集群节点来说，MongoDB则提供了副本集架构来支持数据库的高可用，在节点发生宕机时，可以实现秒级的切换，这个过程对于应用是透明的。

#### 高扩展性
分片（sharding）是水平扩展的核心：分片键（shard key）决定数据落在哪个分片，Balancer 负责迁移 chunk 让各分片的数据量与写入压力均衡。⚠️ 分片键一旦选定极难更改，且直接决定查询是命中单分片（定向查询）还是广播到所有分片（scatter-gather）。

#### 强大的社区支持
文档完善、生态成熟：Atlas 托管云服务、Compass 图形客户端、BI Connector（把 MongoDB 暴露为 MySQL 协议供 Tableau / PowerBI 查询）。

### 类比SQL模型
⭐ 把已有 SQL 经验直接映射到 MongoDB：

| 概念 | MySQL | MongoDB | 说明 |
| --- | --- | --- | --- |
| 库 | database | database | 同名概念 |
| 表 | table | collection | 无需预定义 schema |
| 行 | row | document（BSON） | 一条记录即一个文档 |
| 列 | column | field | 可动态增减 |
| 主键 | `PRIMARY KEY` | ⭐ `_id` | 默认自动生成 ObjectId，自带唯一索引 |
| 索引 | index（B+ 树） | index（B 树） | 设计思路基本一致 |
| 关联 | `FOREIGN KEY` + `JOIN` | 内嵌子文档 / `$lookup` | 优先内嵌，跨集合才 `$lookup` |
| 聚合 | `GROUP BY` / `HAVING` | Aggregation Pipeline | `$group` / `$match` |
| 事务 | 单机强事务 | 4.0 副本集事务、4.2 分片事务 | 能用，但代价高 |
| 约束 | `NOT NULL` / `UNIQUE` / `CHECK` | `unique` 索引 + JSON Schema | 约束能力弱于 MySQL |

# 与 MySQL 的选型
⭐ 没有“谁更好”，只有“谁更合适”。

| 判断维度 | 更该选 MongoDB | 更该选 MySQL |
| --- | --- | --- |
| Schema | 字段多变、快速迭代 | 结构稳定、需要强约束 |
| 数据形态 | 天然嵌套（订单+明细、内容+评论） | 高度规范化、多表拆分 |
| 读写模式 | 以单文档为中心，一次取出整棵子树 | 大量跨表 JOIN、多维度组合查询 |
| 事务 | 弱事务，或只要单文档原子性 | 强 ACID，跨表转账类场景 |
| 统计 | 简单分组、实时聚合够用 | 复杂多表统计、报表、数仓 |
| 规模 | 海量写、日志类、需水平扩展 | 单机/主从即可满足 |
| 一致性 | 可接受最终一致（读从节点） | 以强一致为主 |

**适合 MongoDB**：内容管理 / 商品详情 / 用户画像（文档天然聚合）；日志、埋点、IoT（写吞吐高 + TTL 自动清理）；快速原型（字段随时加）；地理位置与全文检索。
**不适合 MongoDB**：强事务（账户、库存扣减等多表联动，事务代价高、运维复杂）；⚠️ 复杂多表关联统计（`$lookup` 能力与性能远不如 MySQL 的 JOIN 优化器）；强 schema 约束场景（外键、`CHECK`、复杂唯一性要应用层保证）。

# 数据模型
建模的核心只有一个问题：关联数据是**内嵌（Embedding）**还是**引用（Referencing）**？⭐ 判据：**一对少用内嵌，一对多用引用。**

| 对比维度 | 内嵌 Embedding | 引用 Referencing |
| --- | --- | --- |
| 适用关系 | 1:1、1:少（子文档数量有界） | 1:多、多:多，尤其无界增长的从属集合 |
| 读取成本 | ⭐ 一次查询拿到完整文档，无二次 IO | 需要二次查询或 `$lookup` |
| 写入成本 | 子文档膨胀可能触发文档搬迁 | 各自独立更新，互不影响 |
| 原子性 | ⭐ 单文档写入天然原子，无需事务 | 跨文档，需事务保证 |
| 数据冗余 | 可能重复，更新要多处改 | 无冗余，单一数据源 |
| 典型场景 | 收货地址、标签、订单明细、配置项 | 用户↔文章、日志、评论、好友关系 |

- 用**内嵌**：子数据总是与主文档一起被读取，且数量有明确上界（几十条量级）。
- 用**引用**：子数据会**无界增长**（日志、事件流、评论），或需被独立查询、独立分页，或多处引用同一份数据。

⚠️ 单个文档上限 **16MB**（BSON 硬限制），内嵌数组是踩坑重灾区，数组无界时绝不要内嵌；文档膨胀超过预留空间时 WiredTiger 会搬迁文档，写放大明显，可用 `$push` + `$slice` 限制数组长度。

# 索引介绍

## 什么是索引
索引的本质是**用空间与写入开销换查询速度**：维护一份“字段值 → 记录位置”的有序映射，让查询从全表扫描（`COLLSCAN`）变成定点定位（`IXSCAN`）。

- 底层结构：B 树（WiredTiger 下的变体，不同于 InnoDB 的聚簇 B+ 树）。
- 每个索引条目 = 索引键值 + 指向文档的 `RecordId`；复合索引的键按**声明顺序**拼接，顺序决定可用性。
- ⚠️ 每个索引都占内存与磁盘，并让写入多维护一棵树——只建真正被查询用到的索引。

```javascript
db.users.createIndex({ age: 1 })   // 1 升序，-1 降序
db.users.getIndexes()              // 查看索引
db.users.dropIndex({ age: 1 })     // 删除索引
```

## 单键、复合索引
**单键索引**：对单个字段建索引。**复合索引**：对多个字段按顺序建一棵索引，形如 `{ a: 1, b: 1, c: 1 }`。

⭐ **最左前缀原则**：复合索引可用于查询**从最左字段开始的任意前缀**。

| 索引 `{a:1, b:1, c:1}` | 能否命中 |
| --- | --- |
| `{a}` / `{a,b}` / `{a,b,c}` | ✅ 命中 |
| `{b}` / `{c}` / `{b,c}` | ❌ 无法命中 |
| `{a,c}` | ⚠️ 只用到 `a`，`c` 无法用于定位 |

| 比较项 | MySQL | MongoDB |
| --- | --- | --- |
| 最左前缀原则 | 有 | ✅ 完全一致 |
| 排序利用 | 索引有序可消除 filesort | 一致；⚠️ 支持混合方向，`{a:1,b:-1}` 可同时满足 `a ASC, b DESC` |
| 覆盖索引 | Using index | 覆盖查询：`totalDocsExamined == 0` |
| 选择率 | 重要 | 同样重要；数组索引的基数计算方式不同 |

⭐ **ESR 原则**（复合索引字段顺序口诀）：**Equality（等值）→ Sort（排序）→ Range（范围）**。

## 数组索引
数组字段可建索引，即 **multikey index**：数组每个元素生成一个索引条目。

```javascript
db.articles.createIndex({ tags: 1 })
db.articles.find({ tags: "go" })    // 命中 multikey 索引
```
- ⚠️ 一个复合索引中**最多只能有一个数组字段**，否则索引条目呈笛卡尔积式膨胀。
- 支持嵌套字段索引：`{ "address.city": 1 }`；大数组建索引会显著放大索引体积。

## 地理空间索引

| 类型 | 索引声明 | 用途 |
| --- | --- | --- |
| `2dsphere` | `{ loc: "2dsphere" }` | ⭐ 球面坐标，GeoJSON 格式，支持真实地球距离 |
| `2d` | `{ loc: "2d" }` | 平面坐标，legacy 用法，仅限小范围 |

```javascript
db.places.createIndex({ loc: "2dsphere" })
db.places.find({ loc: { $near: {
  $geometry: { type: "Point", coordinates: [120.15, 30.28] },
  $maxDistance: 1000                       // 米
} } })
```
操作符：`$near`（按距离排序）、`$geoWithin`（范围内）、`$geoIntersects`（相交）、`$geoNear`（聚合阶段，附带距离字段）。

## 唯一索引
```javascript
db.users.createIndex({ email: 1 }, { unique: true })
```
⚠️ **坑**：字段缺失会被视为 `null`，多个缺失该字段的文档会互相冲突。解法：`sparse`（只对存在该字段的文档建索引）或 `partialFilterExpression`（只对满足条件的文档建唯一约束，更灵活，推荐）：

```javascript
db.users.createIndex({ email: 1 },
  { unique: true, partialFilterExpression: { email: { $exists: true } } })
```
唯一索引无法保证数组字段的“全局唯一”，只能保证单文档内不重复；分片集合上唯一键必须包含完整分片键。

## TTL 索引
TTL（Time To Live）索引让文档**到点自动过期删除**，非常适合会话、验证码、临时日志、缓存。

```javascript
db.sessions.createIndex({ createdAt: 1 }, { expireAfterSeconds: 3600 })
db.tokens.createIndex({ expireAt: 1 }, { expireAfterSeconds: 0 }) // 字段值即删除时刻
db.runCommand({ collMod: "sessions",                               // 改过期时间只能走 collMod
  index: { keyPattern: { createdAt: 1 }, expireAfterSeconds: 7200 } })
```
- 后台线程**每 60 秒**扫描一次，因此删除**不精确**（可能延迟 1 分钟以上）；TTL 字段必须是 **BSON Date**（或 Date 数组）。
- ⚠️ TTL 索引必须是**单字段索引**，不能是复合索引；删除不可恢复。

## 分析工具 explain
⭐ 判断索引是否生效的唯一可靠手段：`db.users.find({ age: { $gte: 20 } }).explain("executionStats")`

第一步：看 `queryPlanner.winningPlan.stage`。

| stage | 含义 | 判断 |
| --- | --- | --- |
| `COLLSCAN` | 集合全表扫描 | ⚠️ 坏，检查索引与最左前缀 |
| `IXSCAN` | 使用索引扫描 | ✅ 好 |
| `FETCH` | 回表取完整文档 | 正常；`totalDocsExamined` 远大于 `nReturned` 则需优化 |
| `SORT` | 内存排序 | ⚠️ 排序未走索引；超 32MB 直接报错 |
| `PROJECTION_COVERED` | 覆盖查询 | ✅ 最佳，无需回表 |

第二步：看 `executionStats` 关键字段。

| 字段 | 含义 | 理想值 |
| --- | --- | --- |
| `executionTimeMillis` | 查询总耗时 | 越低越好 |
| `totalKeysExamined` | 扫描的索引键数 | 接近 `nReturned` |
| `totalDocsExamined` | 扫描的文档数 | ⭐ 尽量等于 `nReturned` |
| `nReturned` | 实际返回文档数 | 业务预期值 |
| `rejectedPlans` | 被淘汰的候选计划 | 越少越好 |

第三步：`db.users.aggregate([{ $indexStats: {} }])` 查看各索引使用次数，**长期为 0 的索引应删除**。

# 副本集
副本集由**一个主节点（Primary）+ 多个从节点（Secondary）**组成，可选**仲裁节点（Arbiter）**。写入都走主节点，从节点持续复制主节点的 oplog。三个步骤：

+ 选举机制
+ 实时复制
+ 故障转移

### 选举机制
- 节点间持续发送**心跳**（默认每 2 秒）。从节点超过 `electionTimeoutMillis`（默认 10 秒）未收到主节点心跳，判定主节点下线，随后自荐为候选人向所有**有投票权**的成员拉票。
- ⭐ 候选人须获得**多数派票数**（`majority = floor(投票成员数 / 2) + 1`）才能当选；投票排序：`priority` 高者优先 → oplog 更全（更靠后）者优先 → 否则维持原主。
- 选举对应用透明，驱动会自动重连新主（`serverSelectionTimeout` 默认 30 秒）。

### 实时复制
- 主节点把每次写操作追加到 **oplog**（local 库中的固定大小 capped collection）；从节点以**拉取（pull）方式**持续读取新条目并在本地重放，保证成序、幂等。
- 复制是**异步**的，主节点宕机时未被多数派确认的数据可能被**回滚**。
- ⚠️ 若从节点落后到主节点 oplog 尾部已被覆盖，则无法增量追赶，只能转 `RECOVERING` 做**全量重同步**。oplog 大小必须按写入峰值估算。

### 故障转移
1. 主节点不可达 → 从节点发起选举 → 选出新主（通常数秒到十余秒）。
2. 其他从节点改用新主作为复制源；驱动自动发现拓扑变化，把写请求路由到新主。
3. 原主恢复后以**从节点**身份重新加入，并回滚未被多数派确认的写入。

⚠️ 应用需容忍切换窗口内的短暂写失败（建议开启 retryable writes）。

### oplog 与写关注
主节点写本地数据后写 oplog，从节点按 `ts` 拉取回放。⚠️ mongod 会把非幂等操作（如 `$inc`）转换为**幂等形式**（转成 `$set`）写入 oplog，因此从节点是“结果一致”而非“操作一致”。

⭐ **写关注（Write Concern）**决定“写成功”的含义：

| 取值 | 含义 | 取舍 |
| --- | --- | --- |
| `w: 0` | 不等待确认 | 最快，最不安全 |
| `w: 1` | 主节点内存确认（默认） | 快；⚠️ 主节点宕机可能丢数据 |
| `w: majority` | ⭐ 多数节点确认 | 抗切换、不会回滚；生产推荐 |
| `j: true` | 等待 Journal 落盘 | 抗断电，可与 `w` 叠加 |
| `wtimeout` | 超时时间 | 避免无限等待 |

⭐ **读关注与读偏好**：

| 配置 | 类型 | 说明 |
| --- | --- | --- |
| `local` | Read Concern | 默认，最快；⚠️ 可能读到随后被回滚的数据 |
| `majority` | Read Concern | 只读已被多数派确认的数据 |
| `snapshot` | Read Concern | 快照隔离，配合事务使用 |
| `primary` | Read Preference | 默认，强一致 |
| `primaryPreferred` | Read Preference | 主优先，主不可用时读从 |
| `secondary` | Read Preference | 从节点读，扩展读吞吐；⚠️ 有复制延迟，可能读到旧数据 |
| `secondaryPreferred` | Read Preference | 从优先，从不可用时读主 |
| `nearest` | Read Preference | 按网络延迟就近选择 |

⚠️ 典型“读己之写”问题：写用 `w:1`、读走 `secondary`，会读到比刚写入更旧的数据。避免方式：写 majority + 读 majority，或读写都走 primary。

### 集群选举

#### Raft协议
⭐ MongoDB 使用**类 Raft 的复制一致性协议**（官方称 replication consensus protocol，pv1；3.2 起默认，5.0 起移除旧协议 pv0）。核心与 Raft 同源：**以多数派投票选出唯一 Leader，并用日志复制保证一致**。

| 差异点 | 标准 Raft | MongoDB |
| --- | --- | --- |
| 成员角色 | Leader / Follower / Candidate | Primary / Secondary / **Arbiter** / Hidden / Delayed |
| 仲裁者 | 无此角色 | ⭐ 有 Arbiter：只投票、**不存数据** |
| 选举权重 | 无优先级 | ⭐ 支持 `priority`，高优先级优先当选（可实现指定主） |
| 投票权 | 成员均可投票 | ⭐ 可配 `votes: 0`（保留数据但不投票） |
| 日志 | 各 Follower 独立日志 | 统一 oplog，且做幂等转换 |
| 心跳/超时 | 固定周期 | `heartbeatIntervalMillis`(2s) / `electionTimeoutMillis`(10s) 可调 |
| 日志修复 | 覆盖 Follower 冲突日志 | 通过 rollback 回滚未获多数派确认的写入 |

**为什么需要奇数个投票节点**

- 选举需要**多数派**：`majority = floor(N/2) + 1`。
- 奇数节点能最大化容错比，并避免出现两个多数派（脑裂）：3 个投票成员 → majority = 2，容忍 1 个故障；4 个投票成员 → majority = 3，**同样只容忍 1 个故障**，多花的钱没换来容错。
- 故推荐 3 节点（PSS / PSA）或 5 节点，**不要 4 节点**；⚠️ Arbiter 不存数据，一旦一个数据节点故障，剩余数据节点可能凑不齐多数派而无法选举，`majority` 写关注也可能永久阻塞。

# 使用方法
连接串速查：`mongodb://localhost:27017`（单机）；`mongodb://u:p@host:27017/?authSource=admin`（带认证库）；`mongodb://u:p@h1:27017,h2:27017,h3:27017/?replicaSet=rs0&w=majority`（副本集）；`mongodb+srv://u:p@cluster0.abcde.mongodb.net/`（Atlas，隐含 TLS）。

## Go 客户端（go.mongodb.org/mongo-driver）
```bash
go get go.mongodb.org/mongo-driver/mongo          # v1
go get go.mongodb.org/mongo-driver/v2@latest      # v2（模块路径带 /v2）
```
⭐ v1 → v2 的高频改动：① import 路径都加 `/v2`；② `mongo.Connect` 不再接收 `context`；③ `primitive.ObjectID` 合并进 `bson`（`bson.NewObjectID()`）。

### 建立连接
需要导入 `go.mongodb.org/mongo-driver/mongo`、`.../bson`、`.../mongo/options`。

```go
ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
defer cancel()
opts := options.Client().ApplyURI("mongodb://localhost:27017").
	SetMaxPoolSize(50).                        // ⚠️ 默认 100，多实例部署要下调
	SetServerSelectionTimeout(5 * time.Second) // 服务器不可达时快速失败
// ⚠️ Connect 是惰性的，错误往往到首次操作才暴露
client, err := mongo.Connect(ctx, opts) // v2: 去掉 ctx 参数
if err != nil { log.Fatal(err) }
defer func() { _ = client.Disconnect(ctx) }() // ⚠️ 必须释放，否则连接池泄漏
if err := client.Ping(ctx, nil); err != nil { // ⭐ Ping 才是真正的连通性检查
	log.Fatalf("ping: %v", err)
}
coll := client.Database("demo").Collection("users")
```

### 插入
```go
res, err := coll.InsertOne(ctx, bson.M{
	"name": "alice", "age": 30, "tags": []string{"go", "db"}, "createdAt": time.Now(),
})
if err != nil { log.Fatal(err) }
log.Println("inserted id:", res.InsertedID) // 未指定 _id 时自动生成 ObjectID
_, err = coll.InsertMany(ctx, []interface{}{
	bson.M{"name": "bob", "age": 25, "createdAt": time.Now()},
	bson.M{"name": "carol", "age": 41, "createdAt": time.Now()},
}, options.InsertMany().SetOrdered(false)) // ⚠️ false=遇错继续，适合批量导入
```

### 查询
结构体字段用 bson tag 映射，如 `Name string \`bson:"name"\``。

```go
var u User
err = coll.FindOne(ctx, bson.M{"name": "alice"}).Decode(&u)
if errors.Is(err, mongo.ErrNoDocuments) { log.Println("not found") }
filter := bson.M{
	"age":  bson.M{"$gte": 20, "$lt": 40},        // 20 <= age < 40
	"tags": "go",                                 // 数组字段匹配任一元素
	"name": bson.M{"$regex": "^a", "$options": "i"},
}
opts := options.Find().SetSort(bson.D{{Key: "age", Value: -1}}).
	SetLimit(10).SetProjection(bson.M{"name": 1, "age": 1, "_id": 0})
cur, err := coll.Find(ctx, filter, opts)
if err != nil { log.Fatal(err) }
defer cur.Close(ctx) // ⚠️ 游标必须关闭，否则连接无法归还池中
var users []bson.M
for cur.Next(ctx) {
	var item bson.M
	if err := cur.Decode(&item); err != nil { log.Fatal(err) }
	users = append(users, item)
}
if err := cur.Err(); err != nil { log.Fatal(err) } // ⚠️ 必须显式检查，区分正常结束与中途出错
```
常用操作符：`$eq $ne $gt $gte $lt $lte $in $nin $exists $regex $and $or $not $elemMatch $all $size`。

### 更新
```go
res, err := coll.UpdateOne(ctx, bson.M{"name": "alice"}, bson.M{
	"$set":  bson.M{"age": 31},
	"$inc":  bson.M{"loginCount": 1}, // 原子自增
	"$push": bson.M{"tags": "mongo"},
})
if err != nil { log.Fatal(err) }
log.Println("matched:", res.MatchedCount, "modified:", res.ModifiedCount)
// upsert：不存在则插入
_, err = coll.UpdateOne(ctx, bson.M{"name": "eve"},
	bson.M{"$set": bson.M{"age": 28, "createdAt": time.Now()}},
	options.Update().SetUpsert(true))
_, err = coll.UpdateMany(ctx, bson.M{"age": bson.M{"$lt": 30}}, bson.M{"$set": bson.M{"vip": true}})
```
常用更新操作符：`$set $unset $inc $mul $min $max $rename $push $pull $addToSet $pop`；⚠️ `MatchedCount` 是命中条数，`ModifiedCount` 是真正变化的条数；⚠️ 更新对象不带 `$` 操作符会被当作**整体替换**。

### 删除
```go
res, err := coll.DeleteOne(ctx, bson.M{"name": "dave"})
log.Println("deleted:", res.DeletedCount)
res, err = coll.DeleteMany(ctx, bson.M{"age": bson.M{"$lt": 18}})
```
⚠️ 批量删除前先用同样的 filter 跑一次 `CountDocuments` 确认影响范围。

### 聚合
```go
pipeline := mongo.Pipeline{
	{{Key: "$match", Value: bson.M{"age": bson.M{"$gte": 18}}}}, // 尽早过滤，最好命中索引
	{{Key: "$group", Value: bson.D{
		{Key: "_id", Value: "$city"},
		{Key: "count", Value: bson.M{"$sum": 1}},
		{Key: "avgAge", Value: bson.M{"$avg": "$age"}},
	}}},
	{{Key: "$sort", Value: bson.D{{Key: "count", Value: -1}}}},
	{{Key: "$limit", Value: 10}},
	{{Key: "$project", Value: bson.M{"_id": 0, "city": "$_id", "count": 1, "avgAge": 1}}},
}
cur, err := coll.Aggregate(ctx, pipeline)
if err != nil { log.Fatal(err) }
defer cur.Close(ctx)
var stats []bson.M
if err := cur.All(ctx, &stats); err != nil { log.Fatal(err) } // All 会自动关闭游标
```
其他常用 stage：`$lookup`、`$unwind`、`$facet`、`$bucket`、`$out` / `$merge`、`$graphLookup`。

### 创建索引
```go
// 单键索引
_, err = coll.Indexes().CreateOne(ctx, mongo.IndexModel{
	Keys: bson.D{{Key: "name", Value: 1}}, Options: options.Index().SetName("idx_name"),
})
// ⭐ 复合索引（ESR：等值 → 排序 → 范围）
_, err = coll.Indexes().CreateOne(ctx, mongo.IndexModel{
	Keys: bson.D{{Key: "city", Value: 1}, {Key: "age", Value: -1}},
})
// ⭐ 唯一索引（sparse 规避缺失字段的 null 冲突）
_, err = coll.Indexes().CreateOne(ctx, mongo.IndexModel{
	Keys:    bson.D{{Key: "email", Value: 1}},
	Options: options.Index().SetUnique(true).SetSparse(true),
})
// ⭐ TTL 索引：createdAt 超过 1 小时后自动删除（字段必须是 Date）
_, err = coll.Indexes().CreateOne(ctx, mongo.IndexModel{
	Keys:    bson.D{{Key: "createdAt", Value: 1}},
	Options: options.Index().SetExpireAfterSeconds(3600),
})
names, _ := coll.Indexes().ListNames(ctx) // 数组/嵌套字段同理：bson.D{{Key: "tags", Value: 1}}
```

## Java 客户端（org.mongodb:mongodb-driver-sync）
Maven 坐标：`org.mongodb:mongodb-driver-sync:5.2.1`（Gradle 同坐标；驱动走 SLF4J 门面，需自行引入日志实现）。
```xml
<dependency>
    <groupId>org.mongodb</groupId>
    <artifactId>mongodb-driver-sync</artifactId>
    <version>5.2.1</version>
</dependency>
```

### 连接、CRUD 与聚合
```java
import com.mongodb.client.*;
import com.mongodb.client.model.*;
import com.mongodb.client.result.*;
import org.bson.Document;
import org.bson.conversions.Bson;
import java.util.Date;
import java.util.List;
import java.util.concurrent.TimeUnit;
import static com.mongodb.client.model.Aggregates.*;
import static com.mongodb.client.model.Filters.*;
import static com.mongodb.client.model.Indexes.*;
import static com.mongodb.client.model.Projections.*;
import static com.mongodb.client.model.Sorts.*;
import static com.mongodb.client.model.Updates.*;

public class MongoDemo {
    public static void main(String[] args) {
        // ⭐ MongoClient 线程安全且自带连接池，全应用只应创建一个（单例 / Spring Bean）
        try (MongoClient client = MongoClients.create(
                "mongodb://localhost:27017/?retryWrites=true&w=majority")) {
            MongoDatabase db = client.getDatabase("demo");
            MongoCollection<Document> coll = db.getCollection("users");
            // 插入
            coll.insertOne(new Document("name", "alice").append("age", 30)
                    .append("tags", List.of("go", "db")).append("createdAt", new Date()));
            coll.insertMany(List.of(new Document("name", "bob").append("age", 25),
                                    new Document("name", "carol").append("age", 41)),
                    new InsertManyOptions().ordered(false));
            // 查询
            Document one = coll.find(eq("name", "alice")).first(); // 可能为 null
            Bson filter = and(gte("age", 20), lt("age", 40),
                              in("tags", "go"), regex("name", "^a", "i"));
            for (Document d : coll.find(filter).sort(descending("age"))
                    .projection(fields(include("name", "age"), excludeId())).limit(10)) {
                System.out.println(d.toJson());
            }
            // 更新
            UpdateResult ur = coll.updateOne(eq("name", "alice"),
                    combine(set("age", 31), inc("loginCount", 1), push("tags", "mongo")));
            System.out.println("matched=" + ur.getMatchedCount()
                    + ", modified=" + ur.getModifiedCount());
            coll.updateOne(eq("name", "eve"),                      // upsert
                    combine(set("age", 28), set("createdAt", new Date())),
                    new UpdateOptions().upsert(true));
            coll.updateMany(lt("age", 30), set("vip", true));      // 批量更新
            // 删除
            DeleteResult dr = coll.deleteOne(eq("name", "dave"));
            coll.deleteMany(lt("age", 18));
            // 索引
            coll.createIndex(ascending("name"), new IndexOptions().name("idx_name"));
            coll.createIndex(compoundIndex(ascending("city"), descending("age"))); // ESR
            coll.createIndex(ascending("email"),
                    new IndexOptions().unique(true).sparse(true).name("uniq_email"));
            coll.createIndex(ascending("createdAt"),               // ⭐ TTL：1 小时过期
                    new IndexOptions().expireAfter(3600L, TimeUnit.SECONDS));
            // 聚合
            List<Bson> pipeline = List.of(
                    match(gte("age", 18)),
                    group("$city", Accumulators.sum("count", 1),
                                   Accumulators.avg("avgAge", "$age")),
                    sort(descending("count")), limit(10),
                    project(fields(include("count", "avgAge"), excludeId())));
            for (Document d : coll.aggregate(pipeline)) {
                System.out.println(d.toJson());
            }
        }
    }
}
```

### Spring Data MongoDB 简述
```yaml
spring:
  data:
    mongodb:
      uri: mongodb://user:pass@localhost:27017/demo?authSource=admin&w=majority
      auto-index-creation: true   # ⭐ 启动时按注解自动建索引
```
```java
@Document(collection = "users")          // 不写则默认取类名小写
public class User {
    @Id private String id;               // 映射 _id
    @Indexed private String name;        // 普通索引
    @Indexed(unique = true, sparse = true) @Field("email") private String email;
    @Indexed(expireAfterSeconds = 3600) private Date createdAt;  // ⭐ TTL，必须是 Date
    @Indexed private List<String> tags;  // 数组字段 → multikey 索引
}

public interface UserRepository extends MongoRepository<User, String> {  // 方法名派生查询
    List<User> findByAgeGreaterThan(int age);
}

// 模板式：适合聚合等复杂操作
Aggregation agg = Aggregation.newAggregation(
        Aggregation.match(Criteria.where("age").gte(18)),
        Aggregation.group("city").count().as("count").avg("age").as("avgAge"),
        Aggregation.sort(Sort.Direction.DESC, "count"),
        Aggregation.limit(10));
List<User> top = mongoTemplate.aggregate(agg, "users", User.class).getMappedResults();
```
⚠️ Spring Boot 3.x 引入 `spring-boot-starter-data-mongodb` 即可；`@Indexed` 仅在 `auto-index-creation: true` 时生效，生产建议用迁移脚本显式建索引，避免启动时并发建索引阻塞。
