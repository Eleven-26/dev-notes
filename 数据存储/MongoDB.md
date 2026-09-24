# MongoDB 文档数据库

> 覆盖 MongoDB 入门概念、文档建模、索引体系、副本集与选举机制、与 MySQL 的选型对比，以及 Go / Java 客户端用法。
>
> 内容整理自个人学习笔记；本轮补充（文档建模模式、ESR 索引规则、explain 读法、聚合管道、分片与架构管理、读写关注与因果一致性、事务边界、运维坑）参考《MongoDB 进阶与实战：微服务整合、性能优化、架构管理》（唐卓章）。

## 入门

### 什么是MongoDB
该数据库基于灵活的JSON文档模型，非常适合敏捷式的快速开发。

#### 面向文档设计
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

#### 特性

**完备的索引**
MongoDB支持各种丰富的索引类型，包括单键索引、复合索引，唯一索引等一些常用的结构。由于采用了灵活可变的文档类型，因此它也同样支持对嵌套字段、数组进行索引。通过建立合适的索引，我们可以极大地提升数据的检索速度。值得一提的是，MongoDB的索引实现与一般的关系型数据库索引并没有太多不同，因此，我们几乎可以使用某种“一致的思路”来设计索引或完成一些性能调优的任务。

MongoDB还支持地理空间索引、文本检索索引、TTL索引等不同的特性，这些特性在很大程度上简化了应用程序的开发工作，同时也使MongoDB获得了大量使用者的青睐。

**跨平台，支持各种编程语言**
官方与社区驱动覆盖 Go、Java、Python、Node.js、C#、Rust、PHP；`mongosh` 提供交互式 Shell。各语言驱动 API 语义一致，跨语言迁移成本低。

**强大的聚合计算**
聚合（aggregation）计算是MongoDB面向数据分析领域的重要特性，可以用于实现数据的分类统计或一些管道计算；作为对照，聚合框架能轻松完成关系型数据库的group by语句的分组功能，又或是大数据领域的map-reduce计算。

聚合管道由若干 stage 串联：`$match` → `$group` → `$sort` → `$project` → `$lookup`（左外连接）→ `$unwind`（展开数组）→ `$facet`（多路统计）。

**复制、分布式**
MongoDB通过副本集（replication set）来实现数据库的高可用，这点类似于MySQL的Master/Slave复制架构，不同的是，一个副本集可以由一个主节点和多个备节点组成，主节点和备节点基于oplog来实现数据同步。在主节点发生故障时，备节点将重新选举出新的主节点以继续提供服务，整个切换过程是自动完成的。

在海量数据处理方面，MongoDB原生就支持分布式计算能力。在一个分布式集群中，多个文档被划入一个逻辑数据块（chunk），这些数据块可以被存储于不同的计算节点（分片）上，在新的计算节点（分片）加入时，数据块可以借助自动均衡的算法机制被迁移到合适的位置（通常是压力较小的分片）。通过这种自动化的调度及均衡工作，整个集群的数据库读写压力可以被分摊到多个节点上，从而实现负载均衡和水平扩展。

#### 优势

**易用性**
简单易用是MongoDB的一大优势。MongoDB是基于JSON格式的，这点对于开发人员来说显然更加友好，尤其是对全栈式开发者来说，JSON是前后端开发领域中最通用、易读的描述性语言。

**高性能**
基于内存的二级的缓存提供了高速读取数据能力，在写方面则是根据磁盘I/O的特点做了缓冲式写入，这是基于空间、时间因素权衡的一种择优设计。

**高可靠**
对于单个MongoDB节点来说，可以通过开启Journal机制来实现断电保护，这是一种WAL预写日志机制，在发生异常断电后，可以通过Journal日志进行数据恢复。在默认情况下，Journal仅允许最多丢失50ms内更新的数据。

对于集群节点来说，MongoDB则提供了副本集架构来支持数据库的高可用，在节点发生宕机时，可以实现秒级的切换，这个过程对于应用是透明的。

**高扩展性**
分片（sharding）是水平扩展的核心：分片键（shard key）决定数据落在哪个分片，Balancer 负责迁移 chunk 让各分片的数据量与写入压力均衡。⚠️ 分片键一旦选定极难更改，且直接决定查询是命中单分片（定向查询）还是广播到所有分片（scatter-gather）。

**强大的社区支持**
文档完善、生态成熟：Atlas 托管云服务、Compass 图形客户端、BI Connector（把 MongoDB 暴露为 MySQL 协议供 Tableau / PowerBI 查询）。

#### 类比SQL模型
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

## 与 MySQL 的选型
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

## 数据模型
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

### 内嵌/引用的完整判据
⭐ 口诀（一对少内嵌、一对多引用）覆盖多数场景，剩下的边界再用三条轴量：**访问模式**（子数据有没有独立入口、独立分页）、**原子性边界**（更新是否必须与父文档同批生效——这条决定要不要开事务）、**复用与冗余**（一份数据是否被多个父文档共享——这条决定冗余会不会变成一致性负担）。

一句话：**内嵌让"一次读"变便宜，引用让"一次写"变便宜**；写多、且读的时候经常不带上父文档的关联数据，几乎总是该引用。

### 基数关系可以双向建模：expanded / contracted
| 方向 | 做法 | 换来什么 | 赔上什么 |
| --- | --- | --- | --- |
| expanded（把"一"复制进"多"） | `order_items[]` 每条内嵌一份商品快照（名称、单价） | 读订单列表零关联；价格天然是"下单时快照" | 商品改价要刷历史明细——但订单本就不该跟着变，这类冗余其实是正确语义 |
| contracted（把"多"压进"一"） | `products` 内嵌 `orders: [_id...]` | 商品维度一次拿到它全部订单 ID | 数组随销量无界增长直到撞 16MB；两端互为引用，删除要双向清理 |

⚠️ 两个方向同时存（既内嵌快照又维护反向数组）= 双写一致性自己扛，只有两侧都有真实读需求才做。

### 三种高频模式：attribute / subset / buckets
| 模式 | 形状 | 解决什么 | 代价 |
| --- | --- | --- | --- |
| attribute-pattern | 动态字段收进一个子文档、键当字段名：`metrics: { "cpu_idle": 12, "disk_used": 3 }` | 字段名本身也是数据时，避免顶层字段无限增长、索引建不完 | 想按某个指标过滤就得单独建 `{ "metrics.cpu_idle": 1 }`；字段名在每份文档里重复存，占空间 |
| subset-pattern | 主文档只留最热一小撮（`recentComments` 截断到固定条数），全量在 `comments` 集合 | 首屏一次读 + 子集合无限增长 | 两处要同步（插全量 + `$push` 带 `$slice` 截断），非原子：要么容忍短暂不一致，要么开事务 |
| buckets（分桶） | 按时间窗把高频小文档攒成"一个桶文档 + 内嵌数组"，桶内数组设上限 | 时序数据既不想留几亿个小文档、又不能撑爆单文档 | 最新桶是写热点（所有写入撞同一个 `_id`）；桶边界要预先可算，否则查一段时间要扫多个桶 |

### 16MB 是信号，不是容量参数
它不该用来"规划文档大小"，而是**预警**：只要文档尺寸随用户行为增长就迟早撞墙，而**撞墙前的每一次增长都在付写放大代价**——文档原地放不下就变成"删旧记录 + 插新记录"，该集合每个索引的条目都要跟着挪，一次改字段变成一次全索引维护。决策方式：给内嵌数组找一个**业务上不可能突破**的上界（不是"目前看够用"），找不到就用引用、别赌；上线后定期用 `aggregate([{ $project: { bytes: { $bsonSize: "$$ROOT" } } }, { $sort: { bytes: -1 } }, { $limit: 10 }])` 看最大文档，而不是等报错。

### 改模型走 migration-pattern：版本字段 + 灰度
文档没有强制 schema，代价是**同一集合会长期共存多种形状的文档**。改字段之前先给文档打版本，迁移才有"做到哪了"的抓手：

```javascript
// 条件里带版本 → 已迁移的文档天然被排除，脚本可中断可重放（幂等）
db.users.updateOne({ _id: id, schemaVersion: { $lt: 2 } },
  { $rename: { phone: "contact.phone" }, $set: { schemaVersion: 2 },
    $currentDate: { migratedAt: true } })
// 存量迁移：按游标小批量推（find + batchSize + limit），限速、可断点续跑
```
⚠️ 不要拿 `updateMany` 对全量存量一把梭：瞬时写放大 + oplog 洪峰 → 从节点 lag 抬头；中途失败也没有断点。

⭐ 灰度顺序（错一步就有一段"读不到数据"的窗口）：**① 先只上"读兼容"**（新字段优先、为空回落旧字段）——线上先能同时吃下两种形状，之后写侧怎么改都不致命；**② 写侧双写 + 对账**——从这一刻起新数据两种形状都完整，随时可回滚代码；**③ 后台限速迁移存量**——①的回落逻辑仍在兜底；**④ 删回落逻辑 → 停写旧字段 → 清理**——每步只依赖"上一步已全量"，回滚只是退回一版代码。

### "事务能开"不等于"可以照搬关系模型"
把 ER 图 1:1 翻成"每类实体一个集合 + 外键 + 每次写开事务"，两头都输：**读输**——详情页要 5 次查询或一次 `$lookup`，而内嵌一次 IO 就够，往返次数与连接占用直接相乘；**写也输**——多文档事务要把全部写攒到最后一次性提交、期间持锁且 oplog 不能增量对外可见（见「事务与一致性边界」），事务越大越久，lag 与冲突率越难看。

判据：如果一次业务操作**总**要跨 N 个文档开事务，先怀疑建模——不变量一般应该收在同一个文档里（单文档写天然原子）。事务是兜底，不是默认路径；真有大量跨表强一致需求，说明这数据本质是关系型的（对照 [事务与隔离级别.md](mysql/事务与隔离级别.md)）。

## 索引介绍

### 什么是索引
索引的本质是**用空间与写入开销换查询速度**：维护一份“字段值 → 记录位置”的有序映射，让查询从全表扫描（`COLLSCAN`）变成定点定位（`IXSCAN`）。

- 底层结构：B 树（WiredTiger 下的变体，不同于 InnoDB 的聚簇 B+ 树）。
- 每个索引条目 = 索引键值 + 指向文档的 `RecordId`；复合索引的键按**声明顺序**拼接，顺序决定可用性。
- ⚠️ 每个索引都占内存与磁盘，并让写入多维护一棵树——只建真正被查询用到的索引。

```javascript
db.users.createIndex({ age: 1 })   // 1 升序，-1 降序
db.users.getIndexes()              // 查看索引
db.users.dropIndex({ age: 1 })     // 删除索引
```

### 单键、复合索引
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

### ESR 为什么是这个顺序 ⭐
索引是一棵**按键整体排序**的 B 树。理解 ESR 只需要一句话：**范围条件之后，索引里剩下的字段就不再连续了**，因此既不能用来定界，也不能用来排序。拿 `orders` 举例：

```javascript
// Q1：状态等值 + 时间倒序取前 20 条 → ✅ { status: 1, createdAt: -1 }
//    E 定区间，S 就是区间内的自然顺序：反向扫到够 20 条就停，keysExamined ≈ 20，无 SORT
db.orders.find({ status: "PAID", createdAt: { $lt: ISODate("2026-09-01") } })
         .sort({ createdAt: -1 }).limit(20);
// Q2：范围条件换到 amount 上 → ✅ { status, createdAt, amount }（E-S-R）
//                       ⚠️ { status, amount, createdAt }（E-R-S）会退化成内存排序
db.orders.find({ status: "PAID", amount: { $gt: 100 } }).sort({ createdAt: -1 }).limit(20);
```
| 索引顺序 | 扫描行为 | 后果 |
| --- | --- | --- |
| `{status, createdAt, amount}`（E-S-R） | 区间锁在 `status="PAID"` 且 `createdAt < X`，按索引序输出；`amount` 在键里只能**边扫边过滤**（缩窄不了区间），但过滤发生在索引层，少回表 | 排序免费 + 靠 `limit` 提前停；命中几十万也只看 20 条左右 |
| `{status, amount, createdAt}`（E-R-S） | `amount` 是范围 → 同一个 `amount` 段内 `createdAt` 才有序，跨段就乱，**排序无法用索引** | 必须把 `status+amount` 命中的**全部**键取完 → `SORT` stage 内存排序，32MB 超限直接报错；`limit` 省不掉扫描，`totalKeysExamined` ≈ 全部命中数 |
| `{createdAt, status}`（S 在 E 前） | 最左字段没有等值约束，只能从最大 `createdAt` 开始全域反向扫，逐条比 `status` | 命中稀疏时扫几百万键才凑够 20 条，且每条都要回表 |

推论：多个等值字段之间**谁前谁后不重要**（都能定界），但必须**整体排在 sort 字段之前**（`{city, status, createdAt}` 服务 `city=eq + status=eq + sort createdAt`）；单方向取反可以**反向扫描**同一索引（`{a:1,b:1}` 满足 `a DESC, b DESC`），**混合方向**（`a ASC, b DESC`）必须建 `{a: 1, b: -1}`，否则一定内存排序；range 字段放末尾只是"顺手过滤"，选择性差时多一个键只是让索引更大。

### 覆盖索引与 projection：`_id` 是隐形字段 ⭐
要出现 `PROJECTION_COVERED`（`totalDocsExamined == 0`，完全不回表），两个前提同时成立：① filter、sort、projection 里出现的**每一个字段**都在这同一个索引里；② projection 显式 `_id: 0`——不写 projection 时 `_id` 默认返回，它就成了"必须覆盖但索引里没有"的字段，整个查询退化成 `FETCH` 回表。

```javascript
db.users.createIndex({ city: 1, age: 1 })
db.users.find({ city: "HZ" }, { city: 1, age: 1, _id: 0 })  // ✅ 覆盖
db.users.find({ city: "HZ" }, { city: 1, age: 1 })          // ❌ 只差一个 _id，每条都回表
```
⚠️ 多键（数组）索引**永远不能覆盖**，一定要回表取原文档；含数组元素的索引也**不能用于满足排序**（同一文档会因不同数组元素出现在多个位置，顺序无定义），排了就是 `SORT`。

### 部分索引 vs 稀疏索引
| | `sparse: true` | `partialFilterExpression` |
| --- | --- | --- |
| 收录哪些文档 | 索引字段**存在**的文档（含显式写成 `null` 的） | 满足给定表达式的文档（可小到"活跃子集"，体积能小一个数量级） |
| 表达能力 | 只有"字段存在与否"一种 | `$eq`/`$gt`/`$lt`/`$exists`/`$type` + 顶层 `$and`（可用操作符以官方文档为准） |
| 典型用法 | 老代码里给缺失字段开唯一约束 | 只对 `deleted: false` 建唯一；只对 `status: "OPEN"` 建索引（绝大多数文档已是 CLOSED） |
| ⚠️ 失效条件 | 无 | 查询 filter **推不出**部分条件时优化器根本不考虑它 → 意外 `COLLSCAN`，索引白建 |

最后一行是最贵的坑：建了 `partial on {deleted:false}` 的 `{status:1}`，但查询只写 `{status:"PAID"}` 不带 `deleted:false`，就吃不到索引。要么查询固定带上该条件，要么退回全量索引。

### 多个候选索引时，planner 怎么选 ⭐
1. **生成候选**：只在"能匹配查询前缀"的索引里生成候选（完全对不上的不进入竞速，也不会出现在 `rejectedPlans`）；再并发跑各候选、按"返回多少条 / 扫了多少"择优，胜出进 `winningPlan`。所以"哪个索引更快"是**数据分布决定的经验结果**，不是静态规则。
2. **计划缓存按 query shape**（与参数值无关的形状指纹）缓存，不是按参数缓存 → 典型故障"我明明建了更好的索引，老 shape 还在走旧计划"。用 `db.coll.getPlanCache().list()` 看、`clear()` 清。
3. **排查手段**：`.hint({ status: 1, createdAt: -1 })` 强制走某索引，对比两次 explain 才能确认"是索引不好还是选错了"；`planCacheListFilters`/`indexFilter` 可临时屏蔽坏计划，属应急开关，别当长期方案。

### 数组索引
数组字段可建索引，即 **multikey index**：数组每个元素生成一个索引条目。

```javascript
db.articles.createIndex({ tags: 1 })
db.articles.find({ tags: "go" })    // 命中 multikey 索引
```
- ⚠️ 一个复合索引中**最多只能有一个数组字段**，否则索引条目呈笛卡尔积式膨胀。
- 支持嵌套字段索引：`{ "address.city": 1 }`；大数组建索引会显著放大索引体积。
- 复合索引里那个唯一的数组字段，要匹配"同一个元素同时满足多个条件"必须用 `$elemMatch`，否则条件会被**不同元素分别满足**（假阳性）——这是多键索引最容易写错的一点。

### 地理空间索引

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

### 唯一索引
```javascript
db.users.createIndex({ email: 1 }, { unique: true })
```
⚠️ **坑**：字段缺失会被视为 `null`，多个缺失该字段的文档会互相冲突。解法：`sparse`（只对存在该字段的文档建索引）或 `partialFilterExpression`（只对满足条件的文档建唯一约束，更灵活，推荐）：

```javascript
db.users.createIndex({ email: 1 },
  { unique: true, partialFilterExpression: { email: { $exists: true } } })
```
唯一索引无法保证数组字段的“全局唯一”，只能保证单文档内不重复；分片集合上唯一键必须包含完整分片键。

### TTL 索引
TTL（Time To Live）索引让文档**到点自动过期删除**，非常适合会话、验证码、临时日志、缓存。

```javascript
db.sessions.createIndex({ createdAt: 1 }, { expireAfterSeconds: 3600 })
db.tokens.createIndex({ expireAt: 1 }, { expireAfterSeconds: 0 }) // 字段值即删除时刻
db.runCommand({ collMod: "sessions",                               // 改过期时间只能走 collMod
  index: { keyPattern: { createdAt: 1 }, expireAfterSeconds: 7200 } })
```
- 后台线程**每 60 秒**扫描一次，因此删除**不精确**（可能延迟 1 分钟以上）；TTL 字段必须是 **BSON Date**（或 Date 数组）。
- ⚠️ TTL 索引必须是**单字段索引**，不能是复合索引；删除不可恢复。

### 索引的写代价与重建 ⭐
每个索引 = 每笔写要多维护一棵 B 树 + 一条要在副本集间复制的索引变更。集合上 6 个索引，一次 `insertOne` 实际就是 7 次树操作。

| 写操作 | 代价来源 | 建模含义 |
| --- | --- | --- |
| 插入 | 所有索引各插一条 | 索引越多，写吞吐越接近线性下降 |
| 更新**未被索引**的字段 | 只改数据，索引不动 | 便宜——这是"少建索引"最直接的收益 |
| 更新**被索引**的字段 | 旧键删除 + 新键插入（数组字段是整组重算） | 高频翻转的状态字段 + 索引 = 写放大主力 |
| 删除 | 所有索引各删一条 + 空间不立即归还 | 见「运维与常见坑」的空间回收 |

- 单调追加的字段（时间戳、ObjectId）当索引键，写代价低于"会被反复改的字段"（只追加、不移动），但它把热点问题挪到了分片层（见「分片与架构管理」）。
- 索引数量要有预算，不能"先建着再说"：它同时抬高写延迟、cache 占用、启动与追赶时间（机制见「索引过多为什么拖累启动、切换与追赶」）。

重建/换索引的做法（定性）：
1. ⚠️ 不要 `dropIndex` 之后再 `createIndex`——中间那段窗口查询在裸奔（`COLLSCAN`），高 QPS 接口能直接把实例打满。
2. 正确顺序：**建新名字的索引 → explain / `$indexStats` 确认已被选中 → 再 drop 旧索引**，随时可回退。
3. 大集合上建索引是持续的 IO + CPU + oplog 压力，从节点 lag 一定会抬头：低峰做、做完留观察窗口、别连着建多个。
4. 构建方式与版本强相关（早期区分前台/后台构建、`background` 参数在后续版本被统一处理），**是否还生效以官方文档为准**；别靠"加个 background"来做在线变更的容量规划。

### 分析工具 explain
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

### explain 进阶读法 ⭐
三层各有分工，缺层的结论都不成立：`queryPlanner` 给**计划形状**（整条 `winningPlan.inputPipeline` 从叶子往根读：有没有 `SORT`、有没有 `FETCH`、`$or` 是否被拆成多路再 `OR`/`SORT_MERGE` 合并），`executionStats` 才有**真实计数**（默认 `explain()` 不给这一层），而回显的 `query` 才是服务端真正执行的形状（类型转换、正则、`$expr` 都可能改写你的意图）。

两个**比值**比绝对值有用：
- `totalKeysExamined / nReturned` ≫ 1 → 索引区间定得不准（前缀选错、范围字段排在前面），扫了大量用不上的键。
- `totalDocsExamined / nReturned` ≫ 1 → 回表白干了：过滤条件里还有字段没进索引，考虑把过滤前移或做覆盖索引。
- `hasCoveredStage: true` / 出现 `PROJECTION_COVERED` → 真的没回表；出现 `PROJECTION_FETCHED` 就是回了表，别被"用了索引"迷惑。

⭐ 同样是顶层带 `LIMIT`，两条 stage 链的量级完全不同：`LIMIT → FETCH → PROJECTION_COVERED → IXSCAN`（扫一点、够 limit 就停）vs `SORT → LIMIT → FETCH → IXSCAN`（先把命中项全捞完再排序、再截断）。

`executionTimeMillis` 的三点局限：
1. 它只包含这一次服务端执行，**第一次跑要背把数据读进 cache 的成本**；同一查询连跑两次第二次可能快得多。所以"要不要优化"看扫描量，不看单次耗时。
2. 它是"执行完这批结果"的时间，不是端到端延迟：网络往返、驱动、游标后续批次（`getMore`）都不在里面。
3. 分片集群上不带分片键的查询，耗时由**最慢的那个分片**决定，单机 explain 看不出倾斜。

`rejectedPlans` 为什么值得看：`winningPlan` 只是"竞速赢了的"，不等于最优。如果 `rejectedPlans` 里躺着一个明显更合理的索引计划，通常是三种原因之一：① query shape 命中了**旧缓存计划**（换了索引但没清缓存）；② 新索引只部分可用（前缀对但方向/字段不全），竞速时被老计划赢；③ 数据分布偏斜让试跑阶段估计失真。处置手法见上文「多个候选索引时，planner 怎么选」，重点是：别直接下"这个索引没用"的结论。

### 慢查询定位：profiler
```javascript
// level: 0 关闭 / 1 只记超过阈值的 / 2 全记（2 明显拖慢，只短时排查）
db.setProfilingLevel(1, { slowms: 100 })      // 阈值随命令/配置下发，不要用默认值上线
db.getProfilingStatus()
db.system.profile.find({ millis: { $gt: 100 } }).sort({ ts: -1 }).limit(20).pretty()
// 抓"正在跑"的，比事后翻 profile 更适合处理线上抖动
db.currentOp({ "secs_running": { $gt: 5 }, active: true })
db.killOp(<opid>)                             // ⚠️ 先确认不是长事务/迁移，杀之前想好补偿
```
阈值的思路（不给具体数字）：
- 全局 `slowms` 设成**业务可接受延迟的上界**（P99 目标附近），不是"目前最慢的那条"；设高了日志里全是本来就该优化的，设低了 profile 被写满。
- 想知道"谁在整体拖慢系统"，别按单条最慢排，要**按 `ns` + 查询形状聚合看总耗时**：偶发几条秒级批处理，远不如海量几十毫秒的接口查询重要。较新版本的 profile 记录带 query shape 指纹字段（字段名以官方文档为准），没有的话按 `ns` + filter 前缀聚合。
- `system.profile` 是 **capped collection**，写满滚动覆盖，只在**本机**留痕：要长期分析就定期 `$merge` 到独立集合，或直接采 `currentOp`。
- ⚠️ profiler 对每条操作都有判定与写盘开销，生产常开 level 1 + 较高阈值；开 level 2 排查完必须立刻回落到 0/1。

## 聚合管道
⭐ 聚合的执行模型是"文档流依次穿过 stage"，因此**优化只有两个方向：让更少的文档进入下游 stage；让每个 stage 少占内存**。

### stage 顺序与管道优化器
```javascript
// 反例：$unwind 在前、$match 在后 → 索引完全接不上，全集合先进管道再筛
db.orders.aggregate([{ $unwind: "$items" }, { $match: { status: "PAID", "items.qty": { $gt: 5 } } },
                     { $group: { _id: "$items.sku", n: { $sum: 1 } } }])
// 正例：先落索引过滤 → 收窄字段 → 才展开 → 再筛展开后的小部分
db.orders.aggregate([
  { $match: { status: "PAID", createdAt: { $gte: ISODate("2026-09-01") } } }, // 命中 {status,createdAt}
  { $project: { status: 1, "items.sku": 1, "items.qty": 1 } },
  { $unwind: "$items" }, { $match: { "items.qty": { $gt: 5 } } },
  { $group: { _id: "$items.sku", n: { $sum: 1 } } }, { $sort: { n: -1 } }, { $limit: 50 }
])
```
服务端有管道优化器，会做几类等价改写：把 `$match` 尽量**下移**到能命中索引的位置、把 `$project` 裁剪下移、把 `$sort + $limit` 合成 top-k（有索引顺序时不真的排序）。⚠️ 但它**只在能证明语义等价时才改写**：跨 `$group`、`$unwind`、`$lookup` 的 `$match` 不会硬推（`$unwind` 之后才有意义的字段条件，前移就改变结果）。所以顺序自己写对，别指望优化器救你。能被索引"接住"的入口 stage 很有限：`$match`（等价于 find 的 filter）、`$geoNear`（必须在管道最前）、`$sort`（与 `$match` 组合成 ESR 时）、`$limit`；放在 `$group` 之后的任何过滤都只发生在内存里。

### `$lookup` 的代价：本质是嵌套循环
```javascript
db.orders.aggregate([
  { $match: { createdAt: { $gte: ISODate("2026-09-01") } } },   // 先把驱动侧缩小
  { $lookup: { from: "users", localField: "uid", foreignField: "_id", as: "u" } },
  { $unwind: "$u" }                                             // ⚠️ 无匹配的订单会被丢掉，保留要 preserveNullAndEmptyArrays
])
```
- 对驱动集合的**每一个**文档都要到被 join 集合查一次：被 join 侧的 `foreignField` **必须有索引**，否则代价是"驱动侧文档数 × 被 join 侧全表扫描"，这是 `$lookup` 最常见的事故。只有**等值** join 能自然用上索引；带表达式/非等值的 pipeline 形式更贵（内层管道对每个外部文档跑一遍）。
- 什么时候该在**应用层 join**：① 驱动集合已被分页到几十上百条（先查页、再 `find({_id: {$in: ids}})` 批量取、内存里拼）；② 被 join 侧的结果可复用到缓存/其它请求；③ 分片集群上两个集合**没有按同一分片键共置**——这时 `$lookup` 只能广播到所有分片，而应用层按 `_id` 的批量 `$in` 可以定向。
- 判据：`$lookup` 适合"一次聚合里顺带补齐维度"，不适合当 OLAP 的 join 引擎（多表统计应考虑 [ElasticSearch.md](ElasticSearch.md) 或离线链路）。

### `$unwind` 放大与内存限制
`$unwind` 把 1 个文档变成 N 个（N = 数组长度；空数组默认整条消失），**下游 `$group` 处理的是放大后的流**：数组平均长度 20 时，"10 万订单"进入 `$group` 就是 200 万文档。
- 缓解手段（不改语义）：先 `$match` 缩小、先 `$project` 只留必要字段（减少每文档体积）、必要时把 `$group` 拆成"按父文档先聚合一次再按维度聚合"。
- 阻塞型 stage（`$group`、`$sort`、`$lookup` 内部缓冲）有**单 stage 内存上限**（默认量级在百 MB 一档，具体值与可调参数以官方文档为准），超了直接报内存超限而不是变慢；`allowDiskUse: true` 溢写临时文件（Go：`options.Aggregate().SetAllowDiskUse(true)`）只救"内存超限"，救不了"逻辑上把全表拉进管道"——那种情况先修 `$match`。
- ⚠️ 大管道 + 溢写在高峰期跑会抢磁盘 IO、抬高从节点 lag。报表类聚合的正解是**预聚合**：`{ $out: "daily_stats" }`（定时全量重建，替换语义）或 `{ $merge: { into: "daily_stats", on: "_id", whenMatched: "replace", whenNotMatched: "insert" } }`（按 key 增量刷新，读侧永远读得到）。

### 多路统计与取样
- `$facet`：一个输入并行喂多条子管道，**只扫一遍集合**就能同时得到"本页数据 + 总数 + 维度分布"。⚠️ 它的各路结果都要在内存里攒齐再一次性返回单个文档，所以 facet 里不能放大结果集（受文档 16MB 与内存上限双重约束）。
- `$sample`：随机抽 size 条，做数据体检、抽检、随机推荐。⚠️ 它是随机定位读取，大集合上等价于大量随机 IO，比顺序扫描更贵；分片集群上**每个分片各取 size 条再归并**，所以返回条数会偏多，要再套一层 `$limit`。
- `$bucket`（给定 `boundaries` + `default`）/ `$bucketAuto`（自动等分）：做直方图、金额分布、时长分布，比自己堆 `$cond` 清晰且只扫一遍。

### 大集合上的聚合分页
```javascript
// 一次拿到"第 3 页 + 总数"：总数与页数据共用同一个 $match
db.orders.aggregate([
  { $match: { status: "PAID" } },
  { $sort: { createdAt: -1, _id: -1 } },                 // 与 {status,createdAt,_id} 索引对齐
  { $facet: {
      page:  [ { $skip: 40 }, { $limit: 20 } ],
      total: [ { $count: "n" } ]
  } }
])
```
- `$skip` 不是"跳过"，是**扫过并丢弃**：翻到第 1000 页，前面 2 万条键与文档都被处理过。深分页只有两条出路：① 范围分页（记住上一页末条的 `{createdAt,_id}`，下一页用 `$match: {createdAt: {$lt: X}}`，等价于 MySQL/ES 笔记里的 search_after 思路）；② 只要"下一页"就不要给总数，`total` 那一路省下来最值钱。
- 排序必须带唯一兜底字段（通常是 `_id`），否则同一秒内多条文档的相对顺序不稳定，翻页会重复/漏。
- 游标只解决"网络分批"，不解决"服务端仍要扫过 skip 的部分"。

## 副本集
副本集由**一个主节点（Primary）+ 多个从节点（Secondary）**组成，可选**仲裁节点（Arbiter）**。写入都走主节点，从节点持续复制主节点的 oplog。三个步骤：

+ 选举机制
+ 实时复制
+ 故障转移

#### 选举机制
- 节点间持续发送**心跳**（默认每 2 秒）。从节点超过 `electionTimeoutMillis`（默认 10 秒）未收到主节点心跳，判定主节点下线，随后自荐为候选人向所有**有投票权**的成员拉票。
- ⭐ 候选人须获得**多数派票数**（`majority = floor(投票成员数 / 2) + 1`）才能当选；投票排序：`priority` 高者优先 → oplog 更全（更靠后）者优先 → 否则维持原主。
- 选举对应用透明，驱动会自动重连新主（`serverSelectionTimeout` 默认 30 秒）。

#### 实时复制
- 主节点把每次写操作追加到 **oplog**（local 库中的固定大小 capped collection）；从节点以**拉取（pull）方式**持续读取新条目并在本地重放，保证成序、幂等。
- 复制是**异步**的，主节点宕机时未被多数派确认的数据可能被**回滚**。
- ⚠️ 若从节点落后到主节点 oplog 尾部已被覆盖，则无法增量追赶，只能转 `RECOVERING` 做**全量重同步**。oplog 大小必须按写入峰值估算。

#### 故障转移
1. 主节点不可达 → 从节点发起选举 → 选出新主（通常数秒到十余秒）。
2. 其他从节点改用新主作为复制源；驱动自动发现拓扑变化，把写请求路由到新主。
3. 原主恢复后以**从节点**身份重新加入，并回滚未被多数派确认的写入。

⚠️ 应用需容忍切换窗口内的短暂写失败（建议开启 retryable writes）。

#### oplog 与写关注
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

#### 集群选举

**Raft协议**
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

#### 角色配置项与它们的适用边界
| 角色 | 怎么配 | 用来解决什么 | ⚠️ 风险/约束 |
| --- | --- | --- | --- |
| `priority: 0` | `members[i].priority = 0` | 永不发起也不会当选 → 纯读池、跨机房只读副本 | 主挂了它接不了班，容量要按"少一个可当选节点"预算 |
| `hidden: true` | 同时 `votes: 0` | 客户端在拓扑里**看不见**它 → 备份、报表、脏活专用 | 必然累积 lag 且没人看；不参与投票（配置上就不允许） |
| `votes: 0` | 数据成员但无投票权 | 数据/读扩容不抬高选举成本；投票成员数量控制在奇数且有限（上限个位数，以官方文档为准） | 不计入多数派 → `w: majority` 不等它，切换时它可能落后很久 |
| `arbiterOnly: true` | PSA 架构里的 A | 用零成本凑多数派，避免为选举多买一台机器 | 不存数据：数据节点挂 1 个就可能选不出主；仲裁者与主同时不可达 → 集群不可写 |
| 延迟成员 | 能力与配置项随版本变化（早期 `slaveDelay`，新版本以"延迟副本"形式重新提供），以官方文档为准 | 误删/误更新的"时间机器"，比备份恢复快得多 | 一定 lag，绝不能接业务读、绝不能算进多数派 |

⭐ **为什么"备份与报表读"要专门放一个 hidden 节点**：① 拓扑不可见 → 业务侧的 `secondaryPreferred`/`nearest` 不会把流量误投过来，读压力隔离是**结构性**的、不靠约定；② 不投票 + priority 0 → 它把磁盘打满也不会触发选举或换主；③ 可以单独配账号、单独设告警阈值、单独允许 `allowDiskUse` 级别的慢查询。⚠️ 代价：它读到的更旧（复制延迟 + 应用延迟叠加），且它挂了不影响多数派 → 备份任务会**静默失败**，必须单独监控。

#### stepDown 与滚动维护顺序
维护前先看 `rs.conf()`（谁是主、谁投票、谁是 hidden）与 `rs.printSecondaryReplicationInfo()`（有节点 lag 明显就别动）。顺序：**hidden / `votes: 0` 节点 → 逐个 secondary（每重启一个，等 lag 回到接近 0 再下一个）→ 最后 `rs.stepDown(秒数)` 让原主下台并阻止它在该时间内重新当选，再维护它**。
- 每次 stepDown 都是一个"多数派写不可用"的选举窗口；滚动 N 个节点 = N 个窗口，写侧要么靠 retryable writes 兜住，要么安排在低峰。
- ⚠️ 绝不在"已经有一个成员异常"时做滚动维护（会把集群推到零冗余）；大版本升级必须按官方版本路径逐跳，feature compatibility 不能提前抬高（路径以升级文档为准）。

#### oplog 窗口太短的连锁反应
落后超过 oplog 覆盖的时间窗 → 需要的条目已被覆盖 → 无法增量追赶 → 掉进 **initial sync 全量重同步**：该节点转 `RECOVERING`（不接读、当候选人也会失败）→ 有效副本数 -1；全量同步的 IO 又拖慢同机其余节点，lag 继续恶化；此时再挂一个成员就凑不齐多数派 → `w: majority` 阻塞、无法选举。所以 lag 告警要**按剩余窗口占比**设而不是固定秒数：`db.getReplicationInfo()` 看 `timeDiff`/`timeDiffSecs`（时间窗口），`logSizeMB` 只是间接量；窗口不够要么按流程扩 oplog，要么先压写入峰值。

#### readPreference 的业务后果与分档
`primaryPreferred` 最阴险：平时强一致，故障期**静默降级**去读从库，行为突然变"读到旧数据"；`nearest` 按网络延迟就近，数据新旧完全不可控。业务后果集中在三类投诉：提交后刷新列表看不到、余额/库存显示滞后、导出任务读不到刚写入的数据。判据按**界面**而不是按集群：写完立刻要看的界面（支付结果页、我的订单）走 primary 或因果一致会话；搜索/详情/列表这类能容忍秒级旧的才走从库；报表与备份走 hidden 专用节点。⚠️ "从库读到旧数据"本质是**用户预期**问题，先在产品层确认哪些界面能容忍，再谈技术档位。

#### writeConcern 与 `j: true` 的关系
两者正交：`w` 管**复制维度**（几个节点应用了这笔写，抗"主宕机导致回滚"），`j` 管**持久化维度**（是否进了 journal，抗"单机断电丢未刷盘"）。于是 `w: majority` 抗不住"多数成员同时断电且都未落盘"，`j: true` 也抗不住"主宕机时这笔写还没复制到任何从节点"；要两者都抗住就是 `w: majority, j: true`，剩下的靠多机房/异地。默认 `w: 1` 既不等待复制也不等刷盘，掉电窗口取决于 journal 提交周期（本文件「高可靠」小节的毫秒级说法随版本与参数变化，以官方文档为准）。⚠️ 记忆点：**别用副本数补持久化，也别用 journal 补复制**。

#### 因果一致性：把"读己之写"落到机制上
原理：会话记录写结果的 `operationTime`/`clusterTime`，随后的读把它作为 `afterClusterTime` 发给服务端 → 从节点**必须**把 oplog 应用过该时间点才返回（没追平就是等待，可能超时）。

```go
// 同一个会话里把读写串起来，驱动自动带 clusterTime（readpref 来自 .../mongo/readpref）
sess := client.StartSession()
defer sess.EndSession()
err := mongo.WithSession(ctx, sess, func(sc mongo.SessionContext) error {
	if _, err := coll.InsertOne(sc, doc); err != nil { return err }
	// 这句走 secondary，但仍保证能看到刚才那条写
	_, err := coll.Find(sc, filter, options.Find().SetReadPreference(readpref.Secondary()))
	return err
})
```
跨进程/跨服务时把 `operationTime` 随消息或请求头传下去，接收方读时显式指定，等于把因果链延续出去。⚠️ 它保证的是"不比自己旧"而不是"全局最新"；要严格不旧于任何写入就直接读 primary。驱动默认在会话内开启 causal consistency，关掉能省等待但要业务自己兜。

## 分片与架构管理 ⭐
### 集群角色
| 组件 | 职责 | 部署要点 |
| --- | --- | --- |
| `mongos` | 路由：用 chunk 映射剪枝，必要时广播 + 归并 | 无状态，多实例 + LB；它不是数据可靠性的来源 |
| 配置服务器（CSRS） | 集群元数据：chunk 路由表、分片与集合注册 | ⭐ 独立三节点副本集，不与业务分片混用；它不可用时 split/migrate 与所有元数据写停止，已缓存路由的读通常还能继续（以官方文档为准） |
| 分片 | 真正存数据 | 每个分片都必须是副本集（单节点分片只用于测试） |

### chunk 与 balancer 怎么工作
- chunk 是分片键上的一段**左闭右开区间**，不是固定字节块；有大小上限，超过就自动 split（阈值随版本变化，以官方文档为准）。balancer 搬的是**整个 chunk**（区间内文档 + 对应索引条目），触发依据是各分片 **chunk 数量的不均衡**，不是磁盘 GB。
- ⚠️ 推论：数据量最大的分片不一定是热点，"新写全撞在它最后一个 chunk"才最危险——而且那时 chunk 数量看着还挺均衡。
- 单 chunk 或 chunk 数很少的集合**不会被均衡**（没东西可搬）→ 别指望 balancer 修复"片键选错"，它只能延缓；倾斜是建模问题，不是调度问题。
- 可控手段：建集合时指定 split points 做预分片、限定 balancer 窗口（只在低峰搬，搬不完只是倾斜继续存在，不影响正确性）、必要时手工合并/拆分 chunk。

### 分片键选择的三个目标（互相冲突）
| 目标 | 想要什么 | 倾向的键 | 与谁冲突 |
| --- | --- | --- | --- |
| 写吞吐摊平 | 新写均匀落到所有分片 | 高基数、近随机（hash、打散后的 ID） | 与"查询可剪枝"冲突：hash 之后范围查询废掉 |
| 查询可剪枝 | 绝大多数查询带片键 → 命中 1 个分片 | 查询主维度做前缀（`tenantId`、`userId`） | 若该维度单调增长 → 热点 |
| 不产生热点/可迁移 | 单个 chunk 既不是写单点也不无限膨胀 | 分散且基数高的组合键 | 与"按时间归档、按时间范围扫"的直觉冲突 |

实操顺序：① 列出访问最频繁的 2~3 类查询，看哪个字段几乎**每次都出现在 filter 里**；② 检查它是否单调/低基数（是 → 加打散维度或改 hash）；③ 确认单个 chunk 不会塞下"无限增长的一个用户/租户"（片键基数低 → 倾斜且 balancer 搬不动）。

### range vs hashed，以及单调键热点
ObjectId、时间戳、自增 ID 做片键时，所有新写落在最大区间的**同一个 chunk**，该分片独扛全部写入并不断 split + 迁移 → 现象是"一个分片磁盘 IO 打满、其余空闲、balancer 日志刷不停"。

| 对比 | range（默认） | hashed |
| --- | --- | --- |
| 范围查询 | ✅ 定向到区间覆盖的分片 | ❌ 广播（"键相邻"在 hash 空间无意义） |
| 等值查询 | ✅ 定向 | ✅ 定向（hash 值确定 → 单分片） |
| 写入分布 | 单调键 → 尾部热点 | ✅ 均匀，但**必须建 `{ key: "hashed" }` 索引**且要预分片 |
| chunk 语义 | 真实业务键区间，可按区间预分片 | hash 空间区间，手工合并/拆分意义不大 |

打散手段按侵入性排序：hashed 片键（打散最彻底、代价是范围查询全广播）→ 复合片键带高基数维度（`{city, createdAt}`，维度不均时会倾斜，要预分片补齐）→ 加盐/后缀（把 ID 末两位前置，查询侧必须能算出同一个键）。

### 查询侧的三条硬规则
- 不带片键 = **scatter-gather**：广播到所有分片各自执行、mongos 归并排序再汇总，stage 链里出现 `SHARDING_FILTER`（丢弃迁移期间读到的、不属于本分片的文档）。⚠️ 代价随分片数**线性增长**，不是"慢一点"；大集合上的深分页/聚合会把 mongos 打爆。
- 更新与删除同样受路由约束：`updateMany(filter)` 不带片键会在**所有**分片上跑并各自加锁 → 批量作业按片键区间分片提交。
- 唯一性：分片集合上的 unique 索引必须包含**完整片键**（见「唯一索引」小节），因为跨分片唯一无法本地判断。要全局唯一：① 直接把业务唯一键当片键；② 用一个不分片（或片键即该唯一键）的"注册表"集合做 CAS；③ 用 hash 后的业务键做片键 + 等值查唯一。需要跨分片一致快照（集群备份、对账）则依赖 snapshot 读关注/事务，支持范围以官方文档为准。

### 片键一旦定了：现实做法
片键在集合分片后基本**视为不可改**（较新版本提供了 resharding 能力，但限制多、代价高、要看版本支持，以官方文档为准），生产上的真正路径是**换集合**：建新分片集合 → 双写（老集合权威）→ 按片键区间分批回填并对账 → 灰度切读 → 反转权威 → 老集合转冷下线。⚠️ 这条链路最贵的是**对账**而不是搬数据：没有可重放的比对（按片键区间 count + 抽样字段 hash 比对），双写产生的差异永远查不清。更省力的做法是建模时就把"未来两年主要查询维度"放进片键前缀。

### 迁移期间的读写正确性（定性）
chunk 从源分片搬到目标分片期间，这段区间的**所有权（ownership）在转移**：
- 落在迁移中区间的写会遭遇冲突/被拒（源已不再 owner，或目标尚未拿到所有权），服务端与驱动把它当**可重试错误**处理 → 现象是这段键区间上写延迟毛刺与重试计数上升，而不是丢数据。
- 目标分片可能暂时读到一个"归属未定"的文档，客户端结果仍正确（靠 `SHARDING_FILTER` 过滤）；迁移完成后源分片要清理**孤儿文档（orphan）**，没清完之前不带片键的广播查询会多扫一批文档——`totalDocsExamined` 莫名偏高就是这个信号。
- 结论：⚠️ 迁移不是"免费的负载均衡"，它在被搬的那段键区间上制造一个短暂的写抖动窗口。热点集合宁可手工预分片 + 限定 balancer 窗口，也不要让 balancer 在业务高峰自行决定搬哪块。

## 事务与一致性边界 ⭐
### 前提与硬边界
| 项 | 结论 |
| --- | --- |
| 拓扑 | 需要副本集或分片集群；**standalone 不支持**多文档事务（本地测试要起单节点副本集） |
| 会话 | 事务必须在客户端会话内执行；会话同时也是因果一致性的载体 |
| 隔离级别 | 事务内是 snapshot 读（跨文档同一快照）；事务外仍是普通读关注 |
| 时长 | 有默认超时（秒级量级，参数名与默认值以官方文档为准），服务端还会主动清理"跑太久的事务" |
| 体量 | 单个事务产生的 oplog 记录受与单文档同量级的大小上限约束 → 大批量改动塞进一个事务会直接失败 |
| 冲突 | 事务持锁到提交；两个事务改同一文档 → 后者报事务冲突，**必须由业务层重试整个事务** |

### 用法要点（Go）
```go
// ⭐ 事务体必须可重放：闭包里不能有外部副作用
// 下面是 v1 驱动的显式写法（Start/Commit/Abort）；驱动同时提供 WithTransaction 便捷方法，
// 它内部就是"跑闭包 → 失败就重试"，方法名与签名随驱动大版本有差异，以驱动文档为准。
err := client.UseSession(ctx, func(sctx mongo.SessionContext) error {
	if err := sctx.StartTransaction(); err != nil { return err }
	defer sctx.AbortTransaction(sctx)                  // 没走到 Commit 就回滚
	if _, err := acc.UpdateOne(sctx, qA, bson.M{"$inc": bson.M{"bal": -100}}); err != nil {
		return err
	}
	if _, err := acc.InsertOne(sctx, bson.M{"from": "A", "amount": 100, "ts": time.Now()}); err != nil {
		return err
	}
	return sctx.CommitTransaction(sctx)                 // 只有这里成功才对外可见
})
```
- ⚠️ `WithTransaction` 遇到"可重试的瞬时事务错误"时会**重跑整个闭包**：闭包里夹带发 HTTP、发 MQ、计数器自增、写审计日志，就会出现重复副作用——这是线上最常见的误用。事务内的操作也必须用回调给的 context，不能混用外层 context。
- 提交返回"结果未知"类错误时**不能当失败处理**（可能已经提交）：只有幂等设计 + 对账能兜。

### 为什么"能开事务"在生产上仍是最后手段
- **性能**：事务要把全部写攒到最后一次性提交、并持锁到提交；从节点必须**整笔原子应用**一个事务 → 长事务直接抬高 lag，还会让从库上的读看到"成批突然出现"的数据。分片事务还要多一轮协调，延迟更高。
- **运维**：冲突率、重试次数、平均事务时长这些指标默认不显眼；一次"卡在事务里"的外呼（下游 HTTP 3 秒）就能把锁持有时间变成不可控，进而把接口 P99 拖平，而这种抖动在常规监控里极难归因。
- **语义**：跨服务链路里事务边界往往盖不全所有参与者，"数据库层原子"给的是虚假安全感。

⭐ 替代手段的优先级（面试按这个顺序讲）：

| 优先级 | 手段 | 适用 |
| --- | --- | --- |
| 1 | **单文档原子性**：把不变量收进同一个文档（一次 `$set`/`$inc` 同时生效） | 绝大多数"看起来需要事务"的场景 |
| 2 | **条件更新当乐观锁**：`updateOne({_id, status:"PAID"}, {$set:{status:"SHIPPED"}})`，用 `MatchedCount==1` 判断是否抢到 | 状态机流转、防并发重复扣减、消息幂等消费 |
| 3 | **聚合根内子任务**：一个文档里维护步骤数组，逐条 `$set`/`$pull` 推进 | 多步但都在同一业务对象内，天然可重放 |
| 4 | **最终一致**：本地消息表 / 事务消息 + 消费幂等 + 定时对账修复 | 跨服务、跨库、跨消息队列 |
| 5 | **多文档事务** | 同库跨文档且必须同时可见（A 扣 + B 加） |

对照：MongoDB 多文档事务只解决"本库内 ACID"，跨服务仍需消息/Saga 的最终一致方案 → 见 [分布式事务.md](../分布式/分布式事务.md)。

## 使用方法
连接串速查：`mongodb://localhost:27017`（单机）；`mongodb://u:p@host:27017/?authSource=admin`（带认证库）；`mongodb://u:p@h1:27017,h2:27017,h3:27017/?replicaSet=rs0&w=majority`（副本集）；`mongodb+srv://u:p@cluster0.abcde.mongodb.net/`（Atlas，隐含 TLS）。

### Go 客户端（go.mongodb.org/mongo-driver）
```bash
go get go.mongodb.org/mongo-driver/mongo          # v1
go get go.mongodb.org/mongo-driver/v2@latest      # v2（模块路径带 /v2）
```
⭐ v1 → v2 的高频改动：① import 路径都加 `/v2`；② `mongo.Connect` 不再接收 `context`；③ `primitive.ObjectID` 合并进 `bson`（`bson.NewObjectID()`）。

#### 建立连接
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

#### 插入
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

#### 查询
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

#### 更新
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

#### 删除
```go
res, err := coll.DeleteOne(ctx, bson.M{"name": "dave"})
log.Println("deleted:", res.DeletedCount)
res, err = coll.DeleteMany(ctx, bson.M{"age": bson.M{"$lt": 18}})
```
⚠️ 批量删除前先用同样的 filter 跑一次 `CountDocuments` 确认影响范围。

#### 聚合
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

#### 创建索引
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

### Java 客户端（org.mongodb:mongodb-driver-sync）
Maven 坐标：`org.mongodb:mongodb-driver-sync:5.2.1`（Gradle 同坐标；驱动走 SLF4J 门面，需自行引入日志实现）。
```xml
<dependency>
    <groupId>org.mongodb</groupId>
    <artifactId>mongodb-driver-sync</artifactId>
    <version>5.2.1</version>
</dependency>
```

#### 连接、CRUD 与聚合
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

#### Spring Data MongoDB 简述
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

## 运维与常见坑 ⭐
### 连接与连接池
客户端小节说的"单例"不是风格问题：每个 `MongoClient` 自带连接池 + 拓扑监控线程，**每请求 new 一个**等于每请求建一批 TCP 与心跳，`serverStatus().connections.totalCreated` 会远大于 `current`。容量预算：总连接 ≈ 应用实例数 × `maxPoolSize`，多实例部署要按这个乘积下调每实例池大小，而不是指望服务端无限接。

⚠️ 中间层（LB / 云网关 / 防火墙）会掐空闲连接 → 表现是"偶发第一个请求慢或 reset"，被驱动重试掩盖：把驱动侧空闲时间设得**小于**中间层空闲超时。泄漏排查看服务端视角（长期挂着的游标/空闲会话）比翻应用日志准。

### WiredTiger cache 与内存
cache 目标大小按物理内存的一个比例算（默认量级约一半，公式与可调参数以官方文档为准），装的是**工作集**：热文档页 + 索引页。⚠️ 不是越大越好——它抢走的内存是从 OS 页缓存手里拿的（索引/数据文件更容易缺页），脏页阈值抬高后 eviction 会以"个别请求突然变慢"的形式冒出来；内存要留给四方：WT cache、OS 页缓存、连接与线程栈、聚合溢写的临时文件。

- OOM 的常见成因排序：索引总量远超 cache（工作集根本装不下）→ 大 sort/group → 连接数失控 → 无 `limit` 的全量查询把结果拉进应用进程。
- 判据（定性）：cache 长期贴顶 + 缺页持续增长 + 读延迟长尾 → 该做的是**缩小工作集**（冷热分离、TTL 归档、把正文/二进制拆到独立集合或 GridFS、删无用索引），不是调 cache 参数。
- 索引体积比想象中小/大有原因：WiredTiger 对索引做前缀压缩，重复前缀越多越省；高基数随机键（完整 ObjectId、UUID）几乎压不动。机制见 [压缩算法.md](../算法/压缩算法.md)。

### 大量删除后空间不还给 OS
`deleteMany` 删掉一半，磁盘占用**几乎不动**：空间被放进 WiredTiger 的空闲列表复用，不归还操作系统（归还意味着重写文件，代价更高）。检测碎片看 `db.coll.stats(1024 * 1024)` 的 `freeStorageSize`，以及 `db.coll.dataSize() / db.coll.storageSize()`（明显小于 1 = 空洞多）。

回收手段是 `db.runCommand({ compact: "coll" })`，先认清它的适用与限制（定性）：
- 它**重写集合及其索引**把空闲页挤出去，期间该命名空间被独占 → 该集合读写被阻塞，耗时随数据量线性走；碎片率不高时做了没差别，先看 `freeStorageSize`。
- 新版本中 compact 会**复制到副本集其他成员**执行（不再只是本地动作），所以"先在 hidden 上试一把"降不了风险，必须按维护窗口规划（行为随版本变化，以官方文档为准）。⚠️ 不要对 oplog 随手 compact：它拉长应用时间、抬高 lag，极端情况把从节点推出 oplog 窗口而触发全量重同步。
- 更稳的架构级替代：按时间/租户**切成多个集合**（应用层路由），过期直接 `drop` 整块（立即释放）；或 `$out` 到新集合 + `renameCollection` 换名，顺带完成压缩与索引整理。

### 索引过多为什么拖累启动、切换与追赶
机制链：mongod 启动要打开并恢复所有索引的元数据 → 从节点追 oplog 时**每笔写都要维护全部索引** → 回放越慢 → lag 越大。选举协议本身只看心跳和 oplog 位置，但 lag 严重的从节点即使当选也接不住读、还会拖住多数派写；切换瞬间新主要把大量索引页读进 cache（冷缓存）→ "切完之后整体慢一阵"，运维上就像"切不过去"。所以索引数量是**运维预算**，删索引要和删集合一样走评审。

### 监控指标清单
| 指标 | 怎么看 | 告警思路 |
| --- | --- | --- |
| 复制延迟 | `rs.printSecondaryReplicationInfo()` | 阈值按 oplog 窗口的占比设，不设固定秒数 |
| oplog 窗口 | `rs.printReplicationInfo()` / `db.getReplicationInfo()`（看**时间**窗口不是字节） | 窗口 < 可容忍故障恢复时长 → 扩 oplog 或压写入 |
| WT cache / 缺页 | `serverStatus().wiredTiger.cache`（当前量 vs 上限、dirty）、缺页改用 OS 侧指标更可靠（`extra_info.page_faults` 在新版本已被弃用/移除，以官方文档为准） | 贴顶 + 持续 eviction/缺页 = 工作集超内存 |
| 连接 | `serverStatus().connections`（current / available / totalCreated） | totalCreated 增速 ≫ current → 客户端在频繁重建连接 |
| 慢操作 | profiler + `db.currentOp()` | 按 `ns`+形状聚合看**总耗时**，不看单条最慢 |
| 索引健康 | `$indexStats`、`totalIndexSize` vs cache | 零使用索引 = 纯写代价；索引逼近 cache = 危险线 |
| 文档搬迁 | `serverStatus().metrics.record.moves` | 持续上升 → 内嵌数组在膨胀（回到建模层修） |
| 冲突与均衡 | `currentOp` 冲突计数、balancer 状态与迁移日志 | 突增 = 热点 chunk 或长事务；高峰出现迁移 = 抖动源 |

### 其他高频坑
| 坑 | 为什么 | 处置 |
| --- | --- | --- |
| 非锚定 `$regex` | 无法用索引定界，只能扫全部键 | 前缀锚定 `^abc` 才能吃索引；全文检索走 [ElasticSearch.md](ElasticSearch.md) |
| 拿 `$ne`/`$nin`/`$not` 当主力过滤 | 选择性差（"不等于"命中绝大多数），索引帮不上 | 改写成"等于哪几个值"的 `$in` |
| `$or` 某个分支没索引 | 各分支分别规划，**最差分支决定整体代价** | 保证每个分支都有可用索引 |
| `countDocuments({})` 数全量 | 走覆盖索引也要把键数完 | 无 filter 用 `estimatedDocumentCount()`（读元数据，不能带条件） |
| 无 `limit` 的 `find` | 结果集进应用内存 + 游标长时间占用连接 | 强制分页 + `projection` 裁字段 |
| 用完整文档做替换式更新 | 丢字段 + 触发文档搬迁与全索引维护 | 一律用 `$set` 只碰必要字段 |
| 把 TTL 当准点定时器 | 后台周期扫描 + 删除也要复制，不保证准点 | 需要准点触达用延迟消息（见 [Kafka.md](../中间件/消息队列/Kafka.md)） |
| 用 ObjectId 推业务时间 | 前 4 字节是**客户端**生成时间，时钟漂移会污染顺序 | 单独存 `createdAt`（服务端 `$currentDate`）并按它建索引 |

## 面试官会追问什么
1. **内嵌还是引用，30 秒怎么定？** 先问基数有没有**业务上界**（没有就是引用），再问三条轴：子数据是否总与父文档一起读、更新是否必须同批生效、是否被多个父文档共享。第一条决定读成本，第二条决定要不要开事务，第三条决定冗余会不会变成一致性负担。
2. **16MB 撞不上是不是就没事？** 不是。文档每次增长到原地放不下，就变成"删旧记录 + 插新记录"，该集合每个索引都要跟着挪条目——撞墙前一直在付写放大。判据是给内嵌数组找一个业务上不可能突破的上界。
3. **为什么 ESR 里 Sort 要在 Range 前面？** 范围条件之后，索引里更靠后的字段不再全局有序，也不再连续。`{eq, sort, range}` 能定区间并免排序，配合 `limit` 可以边扫边停；写成 `{eq, range, sort}` 就必须把全部命中项捞完再内存排序，超内存直接报错。
4. **覆盖索引为什么常被 `_id` 破坏？** 不写 projection 时 `_id` 默认返回，它就成为"必须覆盖"的字段之一，而二级索引里没有它 → 只能回表。要覆盖必须显式 `_id: 0`，并且 filter/sort/projection 的字段全在同一个索引里。
5. **partial index 比 sparse 强在哪，又有什么坑？** sparse 只能表达"字段存在"，partial 能表达任意条件（`deleted:false`、`status:"OPEN"`），因此能给"部分文档"建唯一约束、体积更小。坑是查询 filter 必须能推出满足部分条件，否则优化器完全不考虑它，会意外退化成 `COLLSCAN`。
6. **`docsExamined` 高和 `keysExamined` 高分别说明什么？** `keysExamined/nReturned` 高说明索引区间定不准（前缀或顺序选错，扫了一堆用不上的键）；`docsExamined/nReturned` 高说明回表白做了，还有过滤字段没进索引 → 做覆盖或把过滤前移。
7. **什么时候 `$lookup` 必须换成应用层 join？** 驱动侧已经分页到几十条时（一次 `$in` 批量取更便宜）；被 join 侧的 join 字段没索引（`$lookup` 是嵌套循环，代价 ≈ 驱动文档数 × 被 join 侧扫描量）；分片集群上两集合没按同一分片键共置（`$lookup` 只能广播，而按 `_id` 的 `$in` 可以定向）。
8. **`$unwind` 之后内存超限，怎么在不改语义的前提下救？** 先把 `$match` 前移缩小输入、用 `$project` 只留必要字段再展开、必要时两段 `$group`（先按父文档聚合再按维度聚合）；确实要跑完就 `allowDiskUse` 溢盘。要记住它是拿吞吐换"能跑完"，高峰期跑会抬高 lag。
9. **hashed 片键为什么让范围查询变贵？** 它按键的 hash 值区间分片，"键值相邻"在 hash 空间里没有任何意义，范围条件无法映射到有限分片 → 只能广播。只有对该键的等值查询能算出 hash 值、定向到单分片。
10. **单调片键为什么引发"迁移风暴"？** 所有新写落在最大区间的同一个 chunk，它反复超阈值 → 反复 split；balancer 又反复搬最新 chunk 去补空分片，而热点仍跟着最新键走。表现为一个分片 IO 打满 + 迁移日志不断，属于建模错误，不是调度参数能治的。
11. **从节点 lag 超过 oplog 窗口会连锁出什么？** 它无法增量追赶 → 掉进 initial sync 全量重同步 → 期间不接读、也帮不上投票 → 有效副本数下降；同时全量同步的 IO 拖慢同机其他节点，lag 更大。此时再挂一个成员就可能凑不齐多数派，`w: majority` 直接阻塞。
12. **为什么备份/报表要专门用 hidden 节点？** 它在客户端拓扑里不可见，`secondaryPreferred`/`nearest` 不会把业务流量误投过来，隔离是结构性的；它 priority 0 且不投票，把磁盘打满也不会触发选举。代价是它读到的更旧，而且它挂掉不影响多数派 → 备份任务会静默失败，必须单独监控。
13. **`w: majority` 和 `j: true` 能互相替代吗？** 不能，维度不同：`w` 管几个节点**应用**了这笔写（抗宕机回滚），`j` 管是否进了 journal（抗断电丢未刷盘）。默认 `w: 1` 既不等多数也不等落盘；只有 `w: majority, j: true` 才同时抗住这两类，剩下的靠异地。
14. **因果一致性具体怎么实现"读己之写"？** 会话记住写结果的 `operationTime`/`clusterTime`，后续读带上 `afterClusterTime`，从节点必须把 oplog 应用过这个时间点才返回。所以它保证的是"不比自己旧"而不是"全局最新"，没追平时是等待而非立刻成功，跨进程要把时间戳随请求传递。
15. **事务能开，你为什么还反对？替代顺序是什么？** 事务要攒到最后一次性提交并持锁到提交，从节点还得整笔原子应用 → 长事务抬高 lag、一次外呼就能把锁时间变成不可控；而且 `WithTransaction` 可能重跑闭包，夹带副作用就重复扣减。顺序：单文档原子性 → 条件更新（CAS）+ 幂等 → 聚合根内子任务 → 本地消息表 + 对账的最终一致 → 最后才是多文档事务。
16. **删了一半数据，磁盘不降，你怎么办？** 先确认碎片量（`freeStorageSize`、`dataSize/storageSize`），因为"不降"本身是设计：空间进空闲列表复用。真要回收才 `compact`，但它独占该集合、耗时随数据量走、且会复制到副本集其他成员，不能只在 hidden 上试。更稳的是"按时间/租户分集合，过期整块 drop"或"`$out` 新集合再换名"。

## 关联

- [ElasticSearch.md](ElasticSearch.md) — 检索型存储与文档库的边界
- [../算法/缓存淘汰算法.md](../算法/缓存淘汰算法.md) — WiredTiger 缓存淘汰的算法基础
- [../算法/压缩算法.md](../算法/压缩算法.md) — 页面块压缩与「压缩不省缓存内存」
- [mysql/事务与隔离级别.md](mysql/事务与隔离级别.md) — 单文档原子与跨文档事务的取舍
