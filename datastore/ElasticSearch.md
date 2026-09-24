# Elasticsearch 搜索引擎

> 一句话说明本文件覆盖什么：Elasticsearch 的简介与选型、核心原理（倒排索引、分片、写入与搜索流程）、工程应用（状态管理、文本分析、搜索 DSL），以及 Go / Java 官方客户端的使用方法。
>
> 内容整理自个人学习笔记；本轮补充（mapping 与字段开关、写入与段生命周期、BM25 与聚合代价、深翻页四方案、集群与 ILM、事故清单）参考《Elasticsearch 数据搜索与分析实战》（王深湛）。

## 简介

Elasticsearch 是一个分布式、RESTful 的搜索和数据分析引擎。
### 优点

+ 高性能：倒排索引
+ 易扩展：分布式
+ 容错性好：副本机制
+ 上手快：RESTful API，社区活跃
### 使用场景

+ 在线实时日志分析，ELK(Elasticsearch、Logstash、Kibana)和 Elastic Stack
+ 物联网(internet of things，IoT)数据监控
+ 文献检索和文献计量
+ 商务智能(business intelligence，BI)大屏展示
### 选型分析

+ 写少读多
+ 不支持事务
+ 数据量大、响应要快
+ 使用 SQL 、NoSQL 也无法满足（避免过度设计）
## 原理
### 基本原理

搜索引擎的使用在我们的日常生活中应该已经司空见惯，通常搜索引擎包括数据采集模块、文本分析模块、索引存储模块、搜索模块等，这些模块的协作流程是：

+ 数据采集模块：负责采集数据；
+ 文本分析模块：负责将原始文本数据切分成有意义的分词以便于搜索；
+ 索引存储模块：负责将数据组建成倒排索引以实现高速搜索；
+ 搜索模块：负责根据用户的查询条件返回最相关的搜索结果。
### 索引
#### 倒排索引

⭐ **为什么倒排索引比正排快**：

+ 正排索引(forward index)以「文档 → 内容」为方向组织数据，要判断「某个词出现在哪些文档里」只能逐篇扫描全文，代价随文档总量线性增长。
+ 倒排索引(inverted index)反转了这个方向，以「词 → 文档列表」组织数据，查询时先定位词典里的词，再直接取出对应的文档 ID 列表求交并，从而避免全表扫描。

**结构**：倒排索引由「词典 + 倒排表」两部分组成。

| 组成 | 说明 |
| --- | --- |
| 词典(term dictionary) | 保存索引中出现过的所有词项(term)，通常**有序**，可用二分查找或 FST 结构快速定位 |
| 倒排表(posting list) | 每个词项对应一个有序文档 ID 列表，列表项通常还记录词频(freq)、位置(position)、字符偏移 |

查询 `term = "elasticsearch"` 时，先在词典中定位词项，再取出倒排表得到命中文档集合；多条件查询就是对多个倒排表做**求交 / 求并**。

**与 B+ 树的对比**：

| 对比项 | 倒排索引 | B+ 树 |
| --- | --- | --- |
| 适用场景 | 全文检索、多值匹配 | 精确等值 / 范围查询 |
| 查询方式 | 词典定位 + 倒排表求交并 | 从根到叶逐层查找 |
| 分词 / 模糊匹配 | 天然支持（按分词匹配） | 不支持，只能前缀 like |
| 结果相关性 | 可结合 TF-IDF / BM25 计算得分 | 不返回相关性得分 |
| 典型系统 | Elasticsearch / Lucene | MySQL InnoDB 等关系型数据库 |
#### 索引字段类型

⭐ 映射(mapping)的核心是给每个字段指定类型，类型决定了**是否分词**、**能做什么查询**、**能否排序和聚合**。

| 类型 | 用途 | 是否分词 | 典型查询方式 |
| --- | --- | --- | --- |
| `text` | 全文检索字段（正文、标题） | ✅ 会分词 | `match`、`match_phrase`、`multi_match` |
| `keyword` | 精确值（标签、枚举、ID、状态） | ❌ 整值建索引 | `term`、`terms`、排序、`aggs` |
| `long` / `integer` / `short` / `byte` | 整数 | ❌ | `term`、`range` |
| `float` / `double` / `half_float` | 浮点数 | ❌ | `range` |
| `scaled_float` | 需精度的浮点，按 `scaling_factor` 存成整数（如价格存「分」） | ❌ | `range` |
| `date` | 日期时间，底层存为 long 时间戳 | ❌ | `range`、`term`、按日期聚合 |
| `boolean` | 真假值 | ❌ | `term` |
| `geo_point` | 经纬度点(lat/lon) | ❌ | `geo_distance`、`geo_bounding_box` |
| `object` | JSON 对象，默认被**扁平化**为 `a.b` 点号字段 | 子字段各自决定 | 按子字段查询 |
| `nested` | 对象数组需保留**元素间关联**时使用 | ❌ | 专用 `nested` query |
| `array` | 数组（ES 无独立数组类型），任意字段写多值即成数组 | 依元素类型 | 同其元素类型 |
| `binary` | Base64 编码的二进制 | ❌ | ⚠️ 不可搜索，仅存储 |

**文本类型(text)**：文本类型是索引中常用的字段类型，索引 `mysougoulog` 的两个字段都是文本类型。文本类型是一种默认会被分词的字段类型，如果不指定，Elasticsearch 会使用标准分词器切分文本，并会把切分后的文本保存到索引中。搜索时，只有搜索文本和索引中的文本相匹配的文档才会出现在搜索结果中。
```json

PUT mysougoulog
{ "settings": { "number_of_shards": "5", "number_of_replicas": "1" },
  "mappings": { "properties": { "userid": { "type": "text" } } } }
```
**日期(date)**：默认情况下，索引中的日期为 UTC 时间格式，其比北京时间晚 8h，使用时每次查询都需要进行格式转换，很不方便。所以在实际项目中，你可以使用 `format` 参数自定义时间格式，例如：
```json

PUT sougoulog-date
{ "mappings": { "properties": { "visittime": {
    "type": "date", "format": "yyyy-MM-dd HH:mm:ss ||epoch_millis" } } } }
```
这里新建的索引 `sougoulog-date`，使用 `format` 格式化日期，这个字段允许接收两种日期格式，其中 `epoch_millis` 代表时间戳的毫秒数。

使用时间戳格式表示时间是个不错的办法，可以避免引起时区问题。由于 `yyyy-MM-dd HH:mm:ss` 不带有时区信息，写入数据时默认时区为 0，Elasticsearch 会把传入的日期看作 UTC 时间，存储时会把这个 UTC 时间转换为长整型的时间戳来保存，当你基于这个字段进行条件查询或统计分析时，实际上使用的是这个时间戳。

**关键字(keyword)**：与 `text` 相对，`keyword` 不做分词，把整个字段值当作一个词项建索引，适合精确匹配、排序与聚合。

**布尔(boolean)、经纬度(geo_point)、对象(object)、数组(array)、二进制文件(binary)**：用途与注意事项见上表；其中 `object` 与 `nested` 的差异是高频考点——对象数组若需维持元素间关联，必须用 `nested`。
#### 忽略映射中不合法的数据

默认情况下，若写入的数据与映射定义的类型不兼容（例如向 `date` 字段写入无法解析的字符串），Elasticsearch 会直接抛错并**拒绝整条文档**，批量写入时尤为麻烦。可以在字段级别设置 `ignore_malformed: true`，让 ES 忽略这条不合法的数据（该字段不被索引，但整条文档仍可写入）：
```json

PUT my_index
{ "mappings": { "properties": { "age": { "type": "integer", "ignore_malformed": true } } } }
```
#### 字段复制和字段存储

Elasticsearch 允许在映射中为某个字段定义 `copy_to` 参数，以实现复制多个其他字段的内容，这样在搜索一个字段时能够达到同时搜索多个字段的效果，使用字段复制比使用多字段匹配 `multi_match` 性能更好。
```json

PUT my_index
{ "mappings": { "properties": {
    "first_name": { "type": "text", "copy_to": "full_name" },
    "last_name":  { "type": "text", "copy_to": "full_name" },
    "full_name":  { "type": "text" } } } }
```
字段存储(`store`)指字段是否**独立**保存原始值。默认 `_source` 已保存完整原始文档，一般无需 `store`；只有在关闭 `_source`、或确实需要在结果中单独取出某字段时才设置为 `true`。
#### 动态映射

若向 Elasticsearch 的索引中添加数据的字段是原先未定义的，数据也依然可以被成功添加。Elasticsearch 拥有动态映射机制，会根据添加的数据内容自动识别对应的字段类型，这也正是索引的映射可以根据写入的数据自动“扩张”的原因。

#### text 与 keyword：为什么排序和聚合只认 keyword

⭐ 根本原因：倒排索引的方向（词 → 文档）和聚合需要的方向（文档 → 值）是**反的**。

| 维度 | `text` | `keyword` |
| --- | --- | --- |
| 索引内容 | 分词后的每个 term → 文档列表 | 整个字段值当作一个 term → 文档列表 |
| 能做什么 | `match` / 打分 / 高亮 / 短语查询 | `term` 精确匹配、排序、聚合、`wildcard` |
| `doc_values`（正排列式） | ❌ 默认关闭且**无法开启** | ✅ 默认开启 |
| `norms`（长度归一因子） | ✅ 默认开启，打分要用 | 不打分可关 |
| 典型坑 | 排序报 `Fielddata is disabled`；`term` 查整句查不到 | 搜"华为手机"漏掉分词命中的文档 |

**为什么 ES 拒绝为 text 开 doc_values**：要做聚合就必须有「文档 → 值」的正排结构，否则只能把整份倒排现场反转（即 fielddata，见「常见事故」）。而 text 字段反转出来的不是"这个字段的值"，是"这个文档里出现过的一堆无序 term"（同一文档的分词还被去重合并进倒排），语义上没法用来分组或排序——所以 ES 直接在 mapping 层禁掉，而不是等到运行时慢。

**标准解法是子字段（多字段），不是二选一**：
```json

{ "mappings": { "properties": { "title": {
    "type": "text",
    "fields": { "kw": { "type": "keyword", "ignore_above": 256 } } } } } }
```
查询/高亮用 `title`，排序/聚合用 `title.kw`。⚠️ 代价：这份字段的索引体积变大（两套结构），子字段默认还要各自算 norms。

#### 字段级开关：doc_values / norms / ignore_above / index

| 参数 | 控制什么 | 关掉的收益 | 关掉的代价 |
| --- | --- | --- | --- |
| `doc_values` | 排序/聚合/脚本用的正排列式结构 | 省磁盘与段内存 | 该字段不能再排序/聚合（`_source` 仍在） |
| `norms` | 每文档的字段长度归一信息（BM25 用） | 省内存；日志、纯 filter 字段基本无意义 | 该字段的打分失去长度归一 |
| `index` | 是否建倒排 | 只展示不检索的字段省掉词典与倒排 | 该字段完全不可查 |
| `ignore_above` | keyword 值超过该长度就**不索引**（`_source`/doc_values 仍保留） | 防长 URL / base64 撑爆词典 | ⚠️ 超长值 `term`、`wildcard` 静默查不到 |
| `eager_global_ordinals` | 段刷新时就构建 global ordinals | 换掉聚合首查的延迟 | 写入/刷新侧变慢，占用内存 |

⭐ **global ordinals 是 keyword 聚合的隐藏成本**：`terms` 聚合要把每个段内部的局部 ordinal 映射成跨段的全局 ordinal（一张字典树/数值数组），段发生 refresh 或 merge 之后需要重建。表现就是"聚合首次查询很慢、后面变快"，以及段很多的小索引聚合反而更贵。

#### 动态映射的坑：字段数爆炸与类型猜错

+ **字段数爆炸（field limit）**：每个新出现的 JSON 键都会生成一条 mapping。把动态 key 当字段名（`user_defined.123`、metric 名做字段）、深层 `object` 被扁平化成 `a.b.c`，都能让字段数失控。ES 用 `index.mapping.total_fields.limit`（默认 1000）兜底，超了之后该索引**整体拒写**，报 `too_many_fields`。正解：动态键改写成 `[{name, value}]` 的 nested 结构、用 `dynamic_templates` 统一映射成 keyword，或整个字段 `"enabled": false`（只存 `_source`，不可查）。
+ **猜错类型只能重建**：第一个值是整数就定成 `long`，后续来浮点直接写失败；字符串看着像日期就定成 `date`，格式一变即报错；`"00123"` 被猜成数值丢了前导零。**已有字段的类型不能原地修改**（`PUT _mapping` 只能新增字段），唯一路径是 reindex。
+ ⚠️ 同一字段被不同服务写入不同类型时，往往只有部分文档/部分分片失败，测试环境难复现——上线前用 `_mapping` 固化，别指望动态映射。

生产建议：**收紧动态映射**。业务索引用 `"dynamic": "strict"`（未知字段报错）；日志索引用 `"dynamic": false`（未知字段只进 `_source`）；再配 `dynamic_templates` 声明 `*_id → keyword`、`@timestamp → date` 这类规则。

#### 索引分片的分配

对于一个总分片数为 N 的索引而言，当集群节点的数目达到 N 以后，继续添加新节点无法再提升该索引的读写性能，这一点在实际应用时需要注意。

假如 node-1 和 node-2 是同一个服务器的两个虚拟机，node-3 是另一个服务器的虚拟机。如果某个时刻 node-1 和 node-2 所在的那台物理机宕机或停电，而分片 P0 和它的副本分片 R0 都在这台物理机上，则会直接导致索引丢失分片不能使用。为了解决这一问题，可以使用分片分配的感知，对副本分片分配的位置进行人为干预。分片分配的感知允许把 Elasticsearch 的节点划分为属于不同的区域，当分配一个副本分片时，不允许将它分配到它的主分片所在的区域。这样做的好处是，即使某个区域的节点全部“挂掉”，其他区域依然有相应的副本分片，不影响集群的使用。

分片分配需满足的基本条件：同一分片的主分片和副本分片不能分配到同一节点；同一分片的多个副本分片也不能在同一节点。
#### 索引分片的恢复

分片的恢复指的是把一个分片复制一份产生新的分片的过程。通常在分片的分配、索引分片的副本数改变、快照恢复时都会伴随有分片的恢复。

**什么情况会触发恢复**：

+ 分片分配：新节点加入或节点下线，主节点重新分配分片位置；
+ 修改索引的副本数；
+ 从快照(snapshot)恢复索引数据；
+ 节点重启、网络临时断开后重新加入集群，导致分片重新分配。

**为什么要避免启动时大量恢复**：

+ 恢复本质是**跨节点复制大量数据**，会占用网络带宽、磁盘 IO 与 CPU；集群规模大时可能持续数十分钟甚至更久。
+ 恢复期间集群 IO 被抢占，正常查询会出现抖动、超时。
+ 恢复完成前副本分片状态为 `INITIALIZING`，集群健康度显示为 `yellow`，分片的容错能力下降。

常见缓解手段：延长未分配分片的延迟分配时间 `index.unassigned.node_left.delayed_timeout`（默认 1m），让短暂离线的节点有机会回归从而避免重建副本；限制并发恢复数 `cluster.routing.allocation.node_concurrent_recoveries`；必要时通过 `_cluster/settings` 降低恢复吞吐。
#### 索引的写入

假如包含 3 个节点的集群中有一个索引，拥有 3 个主分片，每个主分片有 1 个副本分片，当一个文档写入请求到来时，Elasticsearch 的处理过程如图 2.12 所示。

图 2.12 索引一条数据的流程

这个过程的实现分为 4 个步骤。

(1) node-1 接到一个文档写入的请求。

(2) 根据索引数据的路由规则计算当前文档应当被写入哪一主分片，假如此时决定路由到 P1，则使用传输模块把当前的写入请求转发到 node-3 上进行写入。如果是 P0 或者 P2 则直接写入 node-1 的本地分片即可。

(3) 主分片 P1 写入完成后，把请求转发到 node-2 上，将数据写入副本分片 R1，使得 P1 和它的副本分片数据保持一致；如果主分片写入失败，直接返回 False。

(4) 向客户端报告写入的结果。

Elasticsearch 还支持在一个请求中批量写入多个文档，过程如图 2.13 所示。

图 2.13 索引文档的批量写入过程

这个过程的实现也可以分为 4 个步骤。

(1) node-2 接到一个批量写入文档的请求。

(2) 对写入的文档按照路由规则进行分组，在这个例子中有 3 个主分片会因此分为 3 组，每组包含对应的主分片上需要写入的文档列表，并要把请求转发到每个主分片所在的节点上。

(3) 在每个主分片上写入对应的文档，每个文档写入时，先写入主分片再写入副本分片，直到 3 个组的文档全部写入完毕。

(4) 汇总每个节点上各个文档写入的结果并进行返回。

从这个过程可以看出，向索引写入数据时，总是先写入主分片再写入副本分片。只有主分片和副本分片都写入成功，整个请求才会成功。**由于批量写入文档相比单独写入文档减少了请求次数，所以批量写入能够大幅提高文档写入的效率，推荐在生产环境中使用批量写入。**

#### 写入路径全链路：translog → refresh → flush → merge

⭐ 「索引的状态管理」里的刷新/冲洗讲的是 API 动作，这里讲代价链：**一次写请求到底在等什么、宕机到底会丢什么**。

```text

① 写 in-memory buffer + 追加 translog（顺序写）      ← 请求返回前发生
② refresh（默认 1s）：buffer 生成新 segment 进入 OS page cache，打开新 searcher
   → "能被搜到"的可见点在 ②，不在磁盘
③ flush：Lucene commit + fsync 数据文件 + 截断 translog
   → "不会丢"的持久化点在 ③
④ merge：小段合成大段，顺带物理清除被标记删除的文档
```

+ **translog 就是那个丢失窗口**。`index.translog.durability` 默认 `request`：每个写请求 fsync 一次 translog，节点掉电不丢已确认数据，代价是 fsync 次数 ∝ 写 QPS（顺序写也很吃磁盘能力）。高吞吐日志场景改成 `async`，按 `index.translog.sync_interval` 周期 fsync，用"最多丢一个同步间隔"换吞吐——这是**业务可容忍度决策**，必须写进设计文档，不能当成"嫌慢就改"的调优旋钮。（page cache / fsync 的底层代价见 [Linux 文件系统与 I/O](../linux/文件系统与IO.md)。）
+ **"近实时(NRT)"的真实含义**：refresh 只是让新数据组成一个**段**并被新的 searcher 打开，段还在 page cache、translog 也没截断。所以「能搜到」≠「已落盘」，「返回 200」≠「能搜到」。需要写后立即可查用 `?refresh=wait_for`（等下一个刷新点，比 `true` 温和）；⚠️ 每次强制 refresh 都会造一个几乎空的段 → 段数暴涨 → merge 压力 → 搜索变慢。批量导数时把 `index.refresh_interval` 设 `-1`、副本设 0，写完再恢复。
+ **flush 不该手动频繁做**：flush = commit + 轮转 translog，频繁 flush 制造大量小段，只是把成本推给 merge。ES 按 translog 体积/时间自动触发，一般不需要人为干预。
+ **merge 的写放大**：合并 K 个段要把这 K 段的倒排与列式数据全部读出、重写成一个新段，累计写盘量远大于原始文档体积；这就是"批量导入 + 事后 force merge"比"边写边查"省资源的原因。merge 也解释了为什么删除/更新多之后搜索变慢（见下一小节）。
+ 副本一致性：文档先写主分片再并行同步副本，两者都成功才返回——这套"确认语义 + 主分片任期"和 Raft 的多数派确认不是同一套东西（对比见 [一致性与 Raft](../distributed/Raft协议.md)），ES 默认是"全部 in-sync 副本"而不是多数派。

#### 删除与更新为什么贵：标记删除 + 追加

+ 删除只是在该段的 live-docs 位图（`.liv`）上把文档标 0；**更新 = 标记删除旧文档 + 追加一个新文档**（新的 `_seq_no`，通常落进最新的小段）。物理回收只发生在 merge 恰好读入含死文档的段时。
+ 代价分三层：① 写放大（更新一条等于索引两条，倒排、列式全部重建）；② 存储放大（高删除率的索引"数据不多、磁盘很满"）；③ **搜索变慢**——每个段仍然要扫描死文档再用位图过滤，删除比例高时打分/聚合都要多走一跳。
+ 所以：`doc` 级部分更新比"读出来改完整条写回"省一次往返，但**都躲不掉重索引**；高频变化的状态字段（`status`）如果想按状态统计，考虑把状态变更做成追加事件 + 按时间取最新，或者把它单独拆到小索引。
+ ⚠️ `_delete_by_query` / `_update_by_query` 本质是"搜索 + 逐条重写"：只作用于**已 refresh** 的段（刚写入的数据可能不在其中）、默认遇到版本冲突就 `abort`、量大时必须 `wait_for_completion=false` 后台跑并轮询 task。

#### 乐观并发：_seq_no 与 _primary_term

分布式下"读-改-写"必然有竞态，ES 给每个文档维护一对版本标识：

| 字段 | 含义 | 什么时候变 |
| --- | --- | --- |
| `_seq_no` | 该文档在主分片上的操作序号（单调递增） | 每次写入/删除该文档 |
| `_primary_term` | 主分片任期号 | 主分片切换（failover / 重新分配）时递增 |

`_primary_term` 用来挡掉"旧主分片残留的写"，`_seq_no` 用来做条件更新，两者一起才构成可靠的 CAS：
```json

POST my_index/_update/1?if_seq_no=42&if_primary_term=1
{ "doc": { "status": "paid" } }
```
不匹配就返回 `409 conflict`。三种典型用法：

+ 业务侧"状态必须是 A 才能改 B"：读时带上版本号、写时用 `if_seq_no`；或把条件写进 painless 脚本（脚本更新在分片内原子，但每个候选文档都要解释执行一次脚本，贵）。
+ 内部重试：`_update` 的 `retry_on_conflict`（默认 0）；⚠️ 别靠调大它掩盖竞态，冲突率本身就是业务并发度的监控指标。
+ 从 MySQL / Kafka 单向同步：`version_type=external` + 业务单调版本（更新时间、binlog 位点），天然丢弃乱序到达的旧数据（链路设计见 [Kafka](../middleware/Kafka.md)）。注意 external version 只保证"旧的覆盖不了新的"，不给你并发更新的检测能力。

#### 索引的搜索过程

索引写入数据时总是先寻找主分片，搜索时则不然，一个搜索请求既可以使用主分片，也可以使用副本分片。当请求选择分片时，会采取轮询的方式进行分片的选择。如果搜索数据时不带路由值，则需要选择包含整个索引数据的分片（每个分片的主分片和副本分片二选一），此时 Elasticsearch 处理的过程如图 2.14 所示。

图 2.14 不带路由值的搜索过程

这个过程的实现分为 4 个步骤。

(1) node-1 接到一个搜索请求，该请求不包含路由值，接到请求的节点成为协调节点。

(2) 选择搜索要用的分片，假如本次选择 P0、P1 和 P2，由于 P1 在 node-3 节点上，需要使用传输模块把搜索请求转发到 node-3 上。

(3) 在选择的每个分片上进行搜索，默认情况下，每个分片最多会搜索出匹配的前 10 条记录作为局部结果，局部结果会交给协调节点 node-1。

(4) 协调节点 node-1 汇总这 30 条记录，按照搜索请求的参数进行排序，默认返回的是全局结果的前 10 条数据。

当搜索请求带有路由值时，搜索的过程会变得更简化，Elasticsearch 处理的过程如图 2.15 所示。

图 2.15 带路由值的搜索过程

这个过程的实现可以分为 3 个步骤。

(1) node-1 接到一个搜索请求，该请求包含路由值，该节点成为协调节点。

(2) 根据请求的路由值计算搜索的分片，假如为搜索分片 1，则按照轮询的策略从 P1 和 R1 中选择一个，假定本次搜索选择 P1 分片，协调节点 node-1 将请求转发到 node-3。

(3) 在 P1 分片上按照搜索条件完成搜索，取出搜索结果的列表并排序返回，默认会得到符合条件的前 10 条数据。

⭐ **从上面的过程可以看出，副本分片也可以分担搜索请求，所以增加索引的副本分片数可以加大搜索的并发量。但是增加副本分片并不能提升搜索的性能，因为搜索用到的分片数并未减少。而使用路由条件搜索减少了搜索请求要用到的分片数，可以明显提升搜索性能。**

#### 搜索执行内幕：query-then-fetch 两阶段与协调节点 reduce

⭐ 深分页为什么贵、聚合的 `doc_count` 为什么会错，都能从这个两阶段模型推出来。

```text

阶段1 query : 每个分片本地执行查询，各自维护一个大小为 (from + size) 的优先队列，
              只回传 docId + 排序值（不回传文档内容）
协调节点    : 归并各分片候选 → 重排出全局前 (from + size) → 截出真正的 size 条
阶段2 fetch : 只对最终这一批文档，按分片分组回捞 _source / 高亮片段
```

+ **成本模型**：分片侧的堆占用和比较次数 ∝ `from + size`；协调节点的归并量 ∝ `分片数 × (from + size)`。翻得越深，**前 from 条是纯白算的**——每个分片都得为它们维护队列、协调节点得把它们排完再丢掉。这就是 `index.max_result_window` 存在的原因（它是护栏，不是性能开关）。
+ **聚合的 reduce 在协调节点**：各分片先算本地结果（`sum`/`count` 可直接相加；`avg` 要拆成 sum+count 再合并；`percentiles` 要合并 t-digest 之类的压缩结构，`tdigest.compression` 就是"精度换内存"的旋钮），协调节点合并成全局结果后再跑 pipeline 聚合。⚠️ 分片级的 top-N 截断正是 `doc_count_error` 的来源（见「聚合进阶」）。
+ **排序/聚合为什么便宜**：`doc_values` 是按文档顺序**列式**存放的正排结构，聚合时顺序扫一遍列即可，没有随机访问、能被 CPU 向量化；倒排的方向不对，所以不能拿来聚合（这也是 text 不能排序的根因）。
+ **两阶段的另一笔代价**：fetch 阶段按 docId 回捞 `_source` 可能是随机 IO。只要 ID 列表就 `"size": 0` / `"_source": false`；只要部分字段用 `_source: ["a", "b"]`（⚠️ 过滤发生在返回之后，读取整个 `_source` 的成本省不掉，省的是网络与序列化）。

#### 相关性：BM25 相比 TF-IDF 改了什么

| 项 | TF-IDF 的问题 | BM25 的做法 |
| --- | --- | --- |
| 词频 TF | 线性增长：出现 10 次≈1 次的 10 倍分，堆关键词就能刷上前排 | **饱和函数**：增益随次数递减并趋于上限，`k1` 控制饱和速度（Lucene 默认 1.2） |
| 文档长度 | 不区分长短，长文档天然命中多、分高 | 用字段长度 / 平均长度做**归一**，`b` 控制强度（默认 0.75；`b=0` 即完全不看长度） |
| IDF | 稀有词权重线性放大 | 形式更平滑，稀有词仍高权但不会失控 |

+ `norms` 存的就是 BM25 需要的"这个文档这个字段有多长"（见字段级开关）——不打分字段关掉 norms，省的就是这份内存。
+ ⭐ **长度归一什么时候该关掉**：短文本（标题、日志 message、商品 SKU 名）长度差异小，`b` 调小甚至 0 更稳；长正文场景 `b` 太大会把"确实内容更全"的文档压死。相似度可按字段指定（字段 mapping 的 `similarity` 指向一个命名相似度），改配置后**只影响新写的段**，历史段要 merge/reindex 才生效。
+ **`filter` 上下文为什么不打分还能缓存**：filter 只回答"匹配吗"，答案是布尔 → Lucene 用 **bitset** 表示，节点级查询缓存按段缓存这个 bitset，下次同样的 filter 直接位运算。放进 `must` 就变成"每个文档算一次分"，既不能复用 bitset，也无法参与"只要命中集合"的短路。**什么时候失效**：① 值分布高基数（每个值只命中几条）没有复用价值，缓存反而占堆并频繁淘汰；② 随时间滑动的 `range: {now-1h}` 每次都不同，几乎不命中——把粗粒度条件（租户/状态/日期分桶）放 filter，把滑动的精确条件留在 must 之外或改成按天 filter。
+ `constant_score`：把包装查询的得分固定成 `boost`，跳过打分计算，适合"只要过滤"的查询；代价是所有命中同分，排序必须显式给 `sort`。
+ `function_score`：在基础分之上按字段值/脚本/衰减函数改分（热度、时间新鲜度）。代价是**对每个候选文档求值**，打断了"分数超过当前 top-k 最小值就跳过"的提前终止优化，命中集一大 CPU 立刻爆炸。能表达成 `field_value_factor` / `rank_feature` 的别写 script；能用几个 `filter` 分段加权的别用连续函数（分段还能命中缓存）。

#### 观测查询代价：profile 与 slowlog

+ `POST /idx/_search` 带 `"profile": true`：返回每个分片上每棵查询子树的 `build_query / create_weight / rewrite / weight.count`、collector 的 `shard_min/max/mean` 耗时、每个段的 `advance / next_doc / set_min_competitive_score` 次数，以及聚合的 `reduce` 时间。看两件事：**时间集中在 query 阶段还是 fetch 阶段**、**哪个子句/哪个分片的 shard time 最大**（最慢分片决定整体延迟）。
+ ⚠️ profile 自身有开销、输出巨大，只用于单条查询复现，不要挂在生产流量上；长期观测靠 slowlog。
+ 慢日志：`index.search.slowlog.threshold.query.*` 与 `...fetch.*`（分别对查询阶段/取回阶段计时）、`index.indexing.slowlog.threshold.index.*`，配合 `index.search.slowlog.level` 能把慢 DSL 原文落到节点日志。默认阈值基本不输出，需要显式设成毫秒级；具体键名与默认值以官方文档为准。
+ ⭐ 看代价时最容易混淆的三件事：**排队时间 vs CPU 时间**（search/write 线程池打满时 profile 里看不到，但客户端延迟很高，要看 `_cat/thread_pool`）；**分片本地时间 vs 协调节点 reduce 时间**（聚合 reduce 不在任何分片的 profile 里）；**一个分片 vs 最慢分片**（数据倾斜/热点分片时平均值会骗人）。

### 本章小结

本章的主要内容总结如下。

● 搜索引擎一般由数据采集模块、文本分析模块、索引存储模块和搜索模块组成。数据采集模块负责采集数据，文本分析模块负责将原始的文本数据切分成有意义的分词以便于搜索，索引存储模块负责将数据组建成倒排索引以实现高速搜索，搜索模块负责根据用户的查询条件返回最相关的搜索结果。

● Elasticsearch 的节点发现模块可以用于形成集群，每个集群包含唯一的主节点，主节点维护着整个集群的状态，当集群状态改变时需要把最新的状态发布到整个集群中进行同步。

● 分片的分配指的是 Elasticsearch 会把索引分片均匀地分配到尽可能多的节点上的过程，它由主节点来完成。集群数改变、索引副本数改变都会触发分片的分配。通过分片的分配可以让索引在读写时利用更多集群节点的资源，提升整体的性能。

● 分片分配时需要满足的基本条件是，同一分片的主分片和副本分片不能分到同一节点上，同一分片的多个副本分片也不能在同一节点上。

● 可以使用分片分配的感知和分片分配的过滤干预分片分配的结果，分片分配的感知可以把集群分成不同的区域，一个分片和它的副本分片不能分配到同一区域的节点上。分片分配的过滤允许配置索引分片可以分配到哪些节点及不能分配到哪些节点。

● 分片的恢复指的是把一个分片复制一份产生新的分片的过程。通常在分片的分配、索引分片的副本数改变、快照恢复时都会伴随有分片的恢复。

● 虽然分片的恢复能够保持索引在节点数发生变化时依然维持分片的容错性并让分片均匀分布，但是网络临时断开或节点重启时触发的大量分片恢复是应当通过恰当的配置来尽量避免的。

● 索引数据写入时可以分为单个文档写入和批量文档写入，写入时总是先写入主分片再写入副本分片，副本分片写入完成才进行返回。

● 索引数据搜索时可以分为带路由值的搜索和不带路由值的搜索。带路由值的搜索可以直接定位到要搜索的分片，跳过无关的分片并获取搜索结果，它可以提升搜索性能。不带路由值的搜索需要搜索每个分片（主分片和副本分片二选一）。副本分片可以用于搜索，因此，增加副本分片可以提升搜索的并发量。
## 应用
### 写入索引

如果你在建索引的时候把数据一条一条地发给 Elasticsearch，这会导致发送太多的请求，使得建索引的速度变得很慢。在实际项目中经常需要使用可实现批量写入的 API 来把数据一组一组地提交给 Elasticsearch，这样做能大大加快索引的构建速度。
#### 批量提交(bulk)

批量提交的操作一共有 4 种类型：index、create、update、delete。index 操作和 create 操作都能往索引中添加数据，区别是使用 create 操作时，文档主键如果在索引中已存在则会报错，使用 index 操作则会直接覆盖原有的文档。
```json

POST _bulk
{"index":{"_index":"my_index","_id":"1"}}
{"title":"文档一","userid":"u001"}
{"index":{"_index":"my_index","_id":"2"}}
{"title":"文档二","userid":"u002"}
```
⚠️ bulk 请求体是 NDJSON（每行一个 JSON），**行尾必须换行**，且不能用格式化过的多行 JSON。
#### 索引重建(reindex)

索引在使用一段时间以后，你可能会想修改索引的静态设置(settings)。但是这些设置（例如主分片的数目、分词器等）又无法直接修改，而重新导入一遍数据又太过麻烦，这个时候索引重建就特别有用。
```json

POST _reindex
{ "source": { "index": "old_index" }, "dest": { "index": "new_index" } }
```
#### 索引数据路由的原理

写入一条文档时，Elasticsearch 需要决定它落到哪个主分片上，公式是：
```text

shard = hash(routing) % number_of_primary_shards
```
+ `routing` 默认就是文档的 `_id`（也可以通过 `?routing=xxx` 自定义）；
+ `hash` 对 routing 取哈希后，再对主分片数取模，结果即目标主分片编号（0 ~ number_of_primary_shards-1）；
+ 副本分片不参与路由：文档先写入主分片，再同步到该主分片对应的所有副本分片。

⚠️ **为什么主分片数建索引后不能改**：路由结果依赖 `number_of_primary_shards`。一旦这个数被改动，同一个 `_id` 计算出的分片编号就会变化，原先写入的文档将再也「找不到」，等同于数据丢失。因此 Elasticsearch 把主分片数定义为**静态设置**，只能在建索引时指定。需要「改主分片数」的正确做法是**索引重建(reindex)**：按新主分片数新建目标索引，用 `_reindex` 搬迁数据，最后用别名(alias)切换读写入口。

⭐ 路由规则同样用于搜索：带 routing 的搜索能直接算出分片、跳过无关分片，明显提升性能。
### 索引的状态管理
#### 清空缓存

Elasticsearch 之所以能够成为高性能的搜索引擎是因为它拥有强大的缓存机制，可将很多数据直接放在内存中，可以大大提升查询速度。

Elasticsearch 使用的缓存分为 3 种类型：

+ 节点的查询缓存
+ 分片的请求缓存
+ 字段数据(fielddata) 加缓存
#### 刷新索引

当外部数据写入索引时，数据并不会直接提交到磁盘上，因为提交数据的过程成本高昂，会按照一定的流程将数据周期性地提交到磁盘上进行持久化，如图 3.1 所示。

图 3.1 索引数据写入磁盘的过程

整个过程的实现可以分为 3 个步骤。

(1) 将索引请求的文档数据写入内存中的缓冲区和事务日志，此时这些数据还不能被搜索到。

(2) 刷新索引数据，把缓冲区的数据写入文件系统缓存，此时数据已能够被搜索到。

(3) 冲洗索引数据，把文件系统缓存中的数据写入磁盘并清空事务日志，完成数据提交。

默认情况下，Elasticsearch 会对过去 30s 内被搜索的索引提供自动化刷新机制，刷新间隔默认是 1s，其可以在 `index.refresh_interval` 的索引配置中进行修改。
#### 冲洗索引

如果说刷新索引就是把数据写入内存的话，那么冲洗索引就是把数据存储到外存。

冲洗索引时，Elasticsearch 会一次性把文件系统缓存的数据写入磁盘，然后把事务日志清空，这个过程默认是每隔一段时间自动完成的。如果 Elasticsearch 突然宕机，它会在下次启动时自动将事务日志中的数据恢复到磁盘上，从而最大限度地减少数据丢失。
#### 强制合并

随着数据的不断写入、修改和删除，分片中的段信息会越来越多，这也是索引需要定期进行段合并的原因。

强制合并索引的段时，会把分片内部很多零碎的小段合并成大段并去除被删除的文档，这样做的好处是每个分片中的段会减少并会腾出被删除文档所占据的外存空间。
```bash

POST /my_index/_forcemerge?max_num_segments=1
```
⚠️ `_forcemerge` 消耗大量 IO 且期间索引不可写，只应在写入停止的历史索引上执行。

⭐ 补充三条边界条件（都是踩过的坑）：

+ **判定标准不是"最近没人查"，而是"这个索引未来还会不会有写/删"**。只要还有写入，force merge 出来的巨大段会立刻被新增小段"污染"，而且删除标记要等下一次 merge 才回收——白做一次大 IO，还得再来一次。所以对象是"已经 rollover 掉、只读"的索引。
+ **`max_num_segments=1` 不是万能最优**：按时间范围过滤（日志最常见的查询形态）时，Lucene 可以靠段内 `@timestamp` 的 min/max 直接跳过整段；合并成 1 段反而失去这个跳过能力。带时间过滤的索引通常合并到"少量段"即可，真正适合压成 1 段的是不再写、且查询不按时间切片的索引（例如配置类/维度类数据）。
+ **force merge 与只读设置配套**：合并前后把索引设为只读（`index.blocks.write`），能避免合并期间新写入引发重复 merge；⚠️ 别在 force merge 期间做 `shrink`/`close` 之类的结构操作。8.x 的 force merge 会被 throttling 限制以保护在线查询，进度用 `_tasks` 看。
#### 关闭索引

部分索引在业务中不需要使用但是又不能够将其直接删除，这时可以使用关闭索引的操作使索引不再接收读写请求。索引被关闭后，该索引在集群中相关的内部数据也会被销毁，这有利于减少集群的负担。
```bash

POST /my_index/_close   # 关闭
POST /my_index/_open    # 重新打开
```
#### 冻结索引

如果集群中存在一些旧索引，不再有新的数据写入它们，查询频率很低但是又不能直接关闭它们，因为偶尔存在查询的需要，这时候可以考虑使用冻结索引。索引一旦被冻结，就会变成只读的状态，不可写入新的数据，查询时 Elasticsearch 会实时构建冻结索引的每个分片的瞬态数据结构，并在搜索完成后立即丢弃这些数据结构。这样做可以避免大量的旧索引占用集群的缓存，拖累整体的查询性能。

由于被冻结索引查询的频率很低，即使响应速度稍慢也是可以接受的。
```bash

POST /my_index/_freeze    # 冻结
POST /my_index/_unfreeze  # 解冻
```
### 集群与分片规划

#### 分片不是越多越好

⭐ 每个分片都是一个独立的 Lucene 索引，它的固定开销**与数据量无关**：

+ **每分片常驻成本**：词典/FST 与文件句柄、段元数据、keyword 聚合用的 global ordinals、一份 translog 文件，以及至少一个段（哪怕是空索引）。分片数翻倍，这部分开销也翻倍。
+ **搜索侧**：不带 routing 的查询要在**每个分片**上各执行一次，端到端延迟由最慢的分片决定——分片越多，尾部延迟越差；每次执行都要占 search 线程（线程池大小与 CPU 核数挂钩，约为核数的 1.3 倍 + 1，系数以官方文档为准；队列满会抛 `es_rejected_execution_exception` / HTTP 429）。
+ **集群侧**：cluster state 体积随分片数增长，主节点每次发布都要全集群同步（大量分片变更时主节点 CPU/网络被打满是经典事故）；总量还会撞 `cluster.max_shards_per_node`（默认值随版本不同，以官方文档为准）。
+ **恢复侧**：分片越多，节点故障时触发的重新分配与复制动作越多（见「索引分片的恢复」）。
+ 经验方向：**按数据量与查询延迟倒推分片数**（先定单分片目标规模，再算需要几个），并且让"分片数 ≈ 可搜索该索引的节点数或其因数"，便于均匀分布。规模量级要靠压测确定，不存在通用的"每分片多少 GB"标准值。

#### 主分片数怎么"改"：reindex / shrink / split

| 手段 | 方向 | 硬性条件 | 代价 / 注意 |
| --- | --- | --- | --- |
| `_reindex` | 任意 | 目标索引先建好正确的分片数 + mapping | 全量搬数据，需要追增量与别名切换 |
| `_shrink` | 变少 | 目标主分片数必须能**整除**源主分片数；源索引需 `index.blocks.write` 停写；源分片需集中到同一节点 | 需要双份磁盘；期间不可写；适合历史索引降分片数 |
| `_split` | 变多 | 目标必须是源主分片数的**整数倍**；源索引同样要停写 | 新分片只是从一个旧分片切出来的，**数据倾斜不会因为 split 被修正**（路由仍是按原始分片数算的） |

⚠️ 三者都改不了"已经建好的倒排内容"：分词器、字段类型、`text`/`keyword` 的选择仍然只能 reindex（路由公式见「索引数据路由的原理」）。shrink 的典型正当用途是 rollover 之后把过多的历史小分片合并，降低主节点与堆开销。

#### green / yellow / red 与 unassigned 排查

| 状态 | 少的是什么 | 实际影响 |
| --- | --- | --- |
| green | 什么都不缺（所有主+副分片 started） | — |
| yellow | **副本**分片没分配上（带副本的索引在新集群是常态） | 读写都正常，但**容错降级**：主分片所在节点再挂就变 red |
| red | 至少一个**主**分片没分配 | 这部分数据完全不可用；查询会 partial failure，写入命中该分片直接失败 |

```bash

GET _cluster/health                     # 状态 / unassigned 数 / 正在恢复的分片
GET _cat/nodes?v                        # 节点、堆占用、磁盘
GET _cat/shards?v&h=index,shard,prirep,state,docs,store,node  # 谁没分配、谁在初始化
GET _cat/allocation?v                   # 每节点分片数与磁盘余量
GET _cat/indices?v&health_status=yellow # 只看不健康的索引
GET _cluster/allocation/explain         # ⭐ 直接问"这个分片为什么不分配"（可指定 index/shard）
GET _cat/pending_tasks?v                # 主节点是否在排队发布 cluster state
GET _cat/thread_pool/search,write?v&size=  # 队列长度与 rejected 计数
GET _cat/fielddata?v                    # fielddata 吃了多少堆
GET _nodes/stats/indices,os,jvm         # 段数、堆、GC、page cache
```

unassigned 的高频原因（`allocation/explain` 的 `explanation` 会直接点名是哪条）：

+ 节点离开且超过 `index.unassigned.node_left.delayed_timeout` → 正在重建副本（属于正常恢复，等）；
+ **磁盘水位**：超过 high 阈值不再往该节点迁入分片；达到 flood 阶段会给索引挂上只读块（7.x 是 `index.blocks.read_only_allow_delete`，8.x 改为写入阻塞，键名以官方文档为准）——此时"所有写入都失败"其实是磁盘问题，清盘后还要**手动解除**这个块；
+ 副本数 ≥ 可用节点数（同一分片的主副不能同节点，见「索引分片的分配」）；
+ 分配感知 / 包含-排除过滤规则 / `index.routing.allocation.total_shards_per_node` 把候选节点排空；
+ 分片总数或每节点分片数触顶；索引处于 close / 冻结 / 快照恢复中。

处理顺序：先解决"为什么不能分配"（腾磁盘、改规则），再用 `POST /_cluster/reroute?retry_failed=true` 让主节点重试被标记 failed 的分配；⚠️ 不要一上来用 `_cluster/reroute` 的 `allocate_*` 命令强制分配——可能把分片"分配"到空目录或过期副本上，造成数据不一致，那是最后手段（且要清楚自己在恢复什么）。

#### 冷热分层与 ILM（索引生命周期）

+ 核心思路：**索引沿时间变冷，节点按能力分层**，用路由把两者绑起来。新索引在热节点（SSD、大堆、多核）扛写入与最近查询；老索引逐步迁到大磁盘低配节点，只读、降副本、段合并。
+ 落地三件套：`rollover`（按主分片文档数/大小/最大年龄滚出新索引，别名 write index 始终指向最新，老索引自然成为只读候选）+ **ILM policy**（phase 划分与 `min_age`）+ 节点属性与 `index.routing.allocation.require.*` 的匹配。⚠️ 8.x 提供内置 data tiers（hot/warm/cold/frozen 对应 `node.roles`），设置名与 7.x 的属性路由不同，以官方文档为准。
+ 每个阶段该做的事，正好对应前面的原理：**warm** 里 `shrink`（降分片数 → 减主节点与堆开销）+ `force_merge`（段数下降 → 搜索少扫段）+ 副本降到 1（省一半存储，代价是容错）；**cold** 里 `read_only` + 迁低配节点；**frozen** 用可搜索快照把数据放在对象存储、本地只留元数据，首查慢但极便宜。
+ ⭐ ILM 的常见坑：phase 的 `min_age` 是从**索引创建时间**起算的，不是从写入结束时间起算——所以按"天"分索引和按 rollover 分索引，同一个 `min_age` 的语义完全不同。rollover 的触发条件（`max_age` / `max_docs` / 按主分片大小的阈值，键名随版本有差异，以官方文档为准）比 `min_age` 更可靠，因为它直接约束"单个分片别长太大"。分片过碎的热索引在 rollover 后会制造海量小分片，靠 warm 阶段的 shrink 收口。
+ 只追加的日志/时序数据用 **data stream**（隐藏后端索引 + 自动 rollover + 只能 append 的语义）；业务主数据不要用 data stream，它的更新/删除语义不友好。

### 文本分析

Elasticsearch 在两种情况下会用到文本分析，

+ 一、原始数据写入索引时，如果索引的某个字段类型是 text，则会将分析之后的内容写入索引；
+ 二、对 text 类型字段的索引数据做全文检索时，搜索内容也会经过文本分析。

文本分析的顺序是先进行字符过滤器的处理，然后是分词器的处理，最后是分词过滤器的处理。

在 Elasticsearch 中，触发文本分析的时机有两个。

● 索引时：当索引映射中存在 text 字段时，默认会使用标准分析器进行文本分析，如果不喜欢默认的分析器，也可以在映射中指定某个 text 类型字段使用其他分析器。

● 全文检索时：当你对一个索引的 text 类型字段做全文检索时也会触发文本分析，这时文本分析的对象是搜索的内容。默认的分析器也是标准分析器，如果需要改变分析器，可以通过搜索参数 `analyzer` 进行设置。为了保持搜索效果的一致性，索引时的分析器和全文检索时的分析器一般会设置成相同的。
#### 字符过滤器

字符过滤器(character filter)是文本分析的第一道工序，作用在**原始字符流**上，用于在分词前做简单的字符过滤和转换。

+ 输入输出都是字符串，可以替换、删除或保留某些字符；
+ 一个分析器可配置 0 个或多个字符过滤器，按顺序链式执行；
+ ⚠️ 字符过滤器不能改变分词边界（那是分词器的职责），只能改变字符本身。

| 名称 | 作用 |
| --- | --- |
| `html_strip` | 剔除文本中的 HTML 标签，如 `<b>abc</b>` → `abc` |
| `mapping` | 按配置替换字符，如把 `:)` 统一替换为 `happy` |
| `pattern_replace` | 用正则替换匹配到的内容 |
#### 分词器

分词器的功能就是把原始的文本按照一定的规则切分成一个个单词，对于中文文本而言分词的效果和中文分词器的类型有关。分词器还会保留每个关键词在原始文本中出现的位置数据。Elasticsearch 内置的分词器有几十种，通常针对不同语言的文本需要使用不同的分词器，你也可以安装一些第三方的分词器来扩展分词的功能。

分词器会把原始文本切分为一个个分词(token)，通常分词器会保存每个分词的以下 3 种信息。

● 文本分析后每个分词的相对顺序，主要用于短语搜索和单词邻近搜索。

● 字符偏移量，记录分词在原始文本中出现的位置。

● 分词类型，记录分词的种类，例如单词、数字等。

| 分词器 | 说明 |
| --- | --- |
| `standard` | 默认分词器，按 Unicode 文本分段算法切分；英文按词，中文按**单字** |
| `simple` | 按非字母字符切分并转小写 |
| `whitespace` | 仅按空格切分 |
| `keyword` | 不切分，整段文本作为一个分词 |

**中文分词 ik**：ES 自带分词器对中文支持很差（`standard` 会把中文切成一个个单字），中文场景一般安装 IK 分词器(`analysis-ik`)，它提供两种模式：

| 模式 | 切分粒度 | 特点 | 选用场景 |
| --- | --- | --- | --- |
| `ik_max_word` | 最细粒度，穷尽所有可能的词 | 分词多，召回率高，索引体积大 | ⭐ **索引时(index)使用** |
| `ik_smart` | 粗粒度，做最少切分 | 分词少更准，召回率略低 | ⭐ **全文检索时(search)使用** |

⭐ 索引时的文本分析使用 `ik_max_word` 更加合适，而全文检索时的文本分析使用 `ik_smart` 较为多见：索引时尽量多切词以提高召回，查询时切得粗一些以免把用户意图切碎。
#### 分词过滤器

分词过滤器(token filter)作用在分词器切出的 token 流上，用于对单词做进一步过滤和转换，例如，停用词分词过滤器(stop token filter)可以把分词器切分出来的冠词 a、介词 of 等无实际意义的单词直接丢弃，避免它们影响搜索结果。

| 名称 | 作用 |
| --- | --- |
| `lowercase` | 统一转小写，保证大小写不敏感 |
| `stop` | 丢弃停用词（a、of、the 等） |
| `stemmer` | 词干提取，如 running → run |
| `synonym` | 同义词替换，如「手机」→「移动电话」 |
| `asciifolding` | 去掉重音符号，如 café → cafe |
### 搜索数据

最好不要在精准级查询的字段中使用 text 字段，因为 text 字段会被分词，这样做既没有意义，还很有可能什么也查不到。

前缀查询用于搜索某个字段的前缀与搜索内容匹配的文档，前缀查询比较耗费性能，如果是 text 字段，你可以在映射中配置 `index_prefixes` 参数，它会把每个分词的前缀字符写入索引，从而大大加快前缀查询的速度。
#### 查询 DSL 的核心结构

一个搜索请求体由 `query`（查询条件，决定命中与打分）、`from` / `size`（分页）、`sort`（排序）、`highlight`（高亮）、`_source`（返回字段）与 `aggs`（聚合）组成：
```json

{ "query": {}, "from": 0, "size": 10, "sort": [], "highlight": {}, "_source": [], "aggs": {} }
```
**`match`**：全文查询，会对查询串分词，再与字段倒排索引匹配，返回相关性得分。
```json

{ "query": { "match": { "title": "Elasticsearch 入门" } } }
```
**`term`**：精确查询，**不对查询串做分词**，直接拿整串去匹配词典中的词项。
```json

{ "query": { "term": { "userid": "u001" } } }
```
⚠️ **`term` 与 `match` 的区别（高频考点）**：

| 对比项 | `term` | `match` |
| --- | --- | --- |
| 是否对查询串分词 | 否，整串匹配 | 是，先分词再逐个匹配 |
| 匹配单位 | 单个词项(term) | 分词后的多个词项 |
| 相关性打分 | 通常不计分（多作 filter） | 计算相关性得分 |
| 适用字段 | `keyword`、数值、日期、`boolean` | `text` |
| 典型坑 | 对 `text` 字段用 `term` 查「Elasticsearch 入门」几乎查不到 | 对 `keyword` 字段用 `match` 语义等同 `term` |

⭐ 核心结论：**`text` 字段用 `match` 搜，`keyword` 字段用 `term` 搜**。

**`range`**：范围查询。
```json

{ "query": { "range": { "visittime": { "gte": "2024-01-01 00:00:00", "lt": "2024-02-01 00:00:00" } } } }
```
**`bool`**：组合查询，含 4 个子句：

| 子句 | 是否影响得分 | 语义 |
| --- | --- | --- |
| `must` | ✅ 计分 | 必须匹配（AND） |
| `should` | ✅ 计分 | 应该匹配（OR），由 `minimum_should_match` 控制至少匹配数 |
| `must_not` | ❌ 不计分 | 必须不匹配（NOT） |
| `filter` | ❌ 不计分，可命中缓存 | 必须匹配但不参与打分 |

⭐ 能用 `filter` 表达的过滤条件就优先用 `filter`：不掉分、可缓存、更快。
```json

{ "query": { "bool": {
  "must":   [ { "match": { "title": "elasticsearch" } } ],
  "should": [ { "match": { "title": "入门" } } ],
  "filter": [ { "term":  { "userid": "u001" } },
              { "range": { "visittime": { "gte": "2024-01-01 00:00:00" } } } ],
  "must_not": [ { "term": { "status": "deleted" } } ],
  "minimum_should_match": 1 } } }
```
#### 分页

⭐ **浅分页**：`from + size`，如 `{ "from": 0, "size": 10 }`。

⚠️ **深分页问题**：ES 是分布式的，`from + size` 时协调节点必须从**每个分片**取出 `from + size` 条数据再汇总排序截取。例如 `from=10000, size=10` 且 5 个分片，就要汇总 5 × 10010 = 50050 条，内存与耗时随 `from` 急剧增长。`index.max_result_window` 默认限制 `from + size <= 10000`。

| 方案 | 适用场景 | 说明 |
| --- | --- | --- |
| `search_after` | 顺序翻页（无限滚动） | 用上一页最后一条的排序值作为下一页起点，不能跳页 |
| `scroll` | 一次性导出大量数据 | 创建快照式游标逐批拉取；⚠️ 占用资源，用完必须 `clear_scroll` |
| `point_in_time`(PIT) | 8.x 推荐的深分页 / 一致性遍历 | 配合 `search_after`，保证遍历期间数据视图一致 |
```json

{ "size": 100,
  "sort": [ { "visittime": "asc" }, { "_id": "asc" } ],
  "search_after": [ "2024-01-01 10:00:00", "abc123" ] }
```
`search_after` 要求排序字段能唯一确定顺序，通常用「业务时间 + `_id`」兜底。

⭐ **深翻页方案对照：一致性语义与内存代价**（上面的表给方向，这张表给"能不能用"的判定条件）

| 方案 | 快照一致性 | 资源代价 | 能否跳页 | 什么时候必须用它 |
| --- | --- | --- | --- | --- |
| `from + size` | ❌ 每页都是最新视图，中途有写入就重复/漏 | 每分片堆 ∝ from+size，受 `max_result_window` 限制 | ✅（但深了就慢） | 页码可点、只看前几页的后台列表 |
| `scroll` | ✅ 建立时刻的段快照（段被引用，不会被 merge 清） | 每个分片保留 search context 到 `keep_alive` 到期；打开数量受 `search.max_open_scroll_context` 限制 | ❌ | 一次性全量导出；用完必须 `DELETE _search/scroll` |
| `search_after`（无 PIT） | ❌ 每页各搜一次，依赖排序键稳定 | 几乎为零（只带上页排序值） | ❌ | 无限下拉、量大的实时列表 |
| **PIT + `search_after`** | ✅ 遍历期间固定同一版本视图 | 持有 PIT = 钉住一批段，阻止其被清理，必须带 `keep_alive` 并及时 close | ❌ | ⭐ 一致性遍历（导出 + 中途还在写）、需要可恢复的深度翻页 |

+ 共同前提：排序键必须**全序**，否则同一排序值的文档会被跳过或反复出现——带 PIT 时用 `_shard_doc` 兜底最省（它就是内部文档序），否则用 `_id`。
+ `search_after` / PIT 没有 `from`，因此**不受 `max_result_window` 约束**；这个限制只是 from/size 的护栏。
+ ⚠️ PIT/scroll 的隐性代价都是"段被钉住"：遍历时间超过 `keep_alive` 就会报 `No search context found`；长期持有还会让 merge 无法回收删除的文档，段膨胀。
+ 如果"深翻页"的真实需求是"看某个时间段的全部数据"，正确解法是**缩小查询范围 + 按时间落到不同索引**，而不是换游标方案。

#### 排序
```json

{ "sort": [ { "visittime": { "order": "desc" } }, { "_score": "desc" } ] }
```
+ 默认按 `_score` 降序；
+ 一旦指定 `sort`，`_score` 默认不再参与排序（除非显式加入）；
+ ⚠️ `text` 字段默认不能排序（分词后的顺序无意义），要排序必须使用其 `keyword` 子字段（`fields.keyword`）。
#### 聚合

聚合 `aggs` 用于统计分析，常见两类：**桶聚合(bucket)**（把文档分组，典型是 `terms`）与**指标聚合(metric)**（对每组计算 `avg`、`sum`、`max`、`min`、`cardinality` 等）。
```json

{ "size": 0,
  "aggs": { "by_user": {
      "terms": { "field": "userid", "size": 10 },
      "aggs": { "avg_age": { "avg": { "field": "age" } } } } } }
```
⭐ `"size": 0` 表示只返回聚合结果、不返回命中文档，能显著减少传输量。⚠️ 参与聚合的字段必须是 `keyword` 或数值等不分词的字段。

#### 聚合进阶：nested 为什么贵、cardinality 别当账单数字、terms 的误差从哪来

+ **bucket 与 metric 的真正区别不是"两类聚合"，而是两种角色**：bucket 决定"哪些文档进入这一组"（`terms` / `date_histogram` / `range` / `nested`），metric 决定"对这一组算什么"（`avg` / `sum` / `percentiles` / `top_hits`）。关键在于 **bucket 会重定义后续子聚合的文档集**，metric 不会——所以每多一层 bucket，就要重新界定一次文档集合，成本随层数而不是随文档数增长。
+ **nested 聚合贵在哪**：`nested` 把数组元素索引成独立的隐藏子文档，父子靠段内相邻的 doc id 关联。聚合时要按 block 做子 → 父的关联（`reverse_nested` 再反向一次），代价大致 ∝ 子文档数 × 层数。⚠️ 最容易被业务误读的一点：nested 聚合的 `_count` / `doc_count` 统计的是**子文档条数**，把"订单里商品数"当成"订单数"就来自这里。
+ **父子文档（join field）比 nested 更贵**：`has_child` / children 聚合的父子关系靠每个段现场构建的 bitset 表达，不能像 nested 那样利用物理相邻访问；跨页/深遍历的关联代价更高。能用 nested 解决的不要用父子文档，唯一理由是"父文档不随子文档变更而重索引"。
+ **`cardinality` 是 HyperLogLog 近似**：内存固定、结果近似，`precision_threshold`（默认 3000，可上调，上限以官方文档为准）决定"阈值内接近精确、阈值外只保证数量级"。⚠️ 计费、对账、对外承诺的"独立用户数"不要用 cardinality 直接出——要么按天精确去重后落库，要么用 `terms` + `composite` 全遍历。另外若字段是 keyword 且设了 `ignore_above`，超长值根本没进索引，cardinality 与 terms 都统计不到它们（表现为"少了几个"，很难查）。
+ **`terms` 的 `doc_count_error_upper_bound` 从哪来**：每个分片只回传**本地** top `size`（实际取 `shard_size` 条），协调节点再汇总。一个全局高频的词可能恰好在每个分片上都排不进前 `size` → 被静默丢掉；`doc_count_error_upper_bound` 是"各分片第 size+1 名计数"的最大值，是误差上界而非实际误差。`sum_other_doc_count` 不为 0 说明长尾被截断。调大 `shard_size` 能收敛（7.x+ 可在 terms 里配），代价是分片和协调节点都更贵。
+ **`composite` 分页聚合**：按 bucket 的 **key 有序**逐页翻（请求带 `after`，响应给 `after_key`），每页 bucket 数固定。因为按 key 归并不存在"各分片抢 top by count"的歧义，**没有 doc_count_error**；代价是拿不到"全局按 count 排序的前 N"，也拿不到 bucket 总数。需求是"遍历所有 term 并拿到精确计数"→ composite；需求是"只要前 10 名"→ terms。
+ ⭐ 两个 size 的交互坑：`terms` 默认 `size: 10`，而同一层里 cardinality 常给出几千的基数——**把这两个数字放在一起看，立刻能发现"长尾被砍掉了 99%"**，这是排查"聚合结果和 BI 对不上"最快的第一步。
+ 减少聚合成本的顺序：先用 `query`/`filter` 把参与聚合的文档集缩小（聚合是在命中文档上做的）→ 再考虑 `size`/`shard_size` → 最后才想 `execution_hint: map`（内存换速度，高基数会打爆堆）与 `eager_global_ordinals`。

#### 字段设计建议

通常写入 Elasticsearch 的字段需要符合以下几种情况。

(1) 该字段被用作检索条件。(2) 该字段用于统计分析。(3) 该字段经常用于前端展示。(4) 该字段是文档主键。

### 常见事故与坑

#### 1. fielddata 打爆堆

对 `text` 字段排序/聚合，默认报 `Fielddata is disabled on text fields by default`。如果照着报错提示把 `"fielddata": true` 打开，ES 会把整份倒排索引**反转成「文档 → 值」的结构装进 JVM 堆**：词典有几百万 term 时这一步足以把节点打到 `CircuitBreakingException` 甚至 OOM；更糟的是这份结构按段常驻，段一变（refresh/merge）就要重建，堆占用随之抖动。

正解不是调大堆，而是**改 mapping 加 `keyword` 子字段 + reindex**。排查：`GET _cat/fielddata?v`（看哪个字段吃了多少堆）、`GET _nodes/stats/indices/fielddata`；缓解：`POST /idx/_cache/clear?fielddata=true`（清掉之后首查会很难看，属于止血）。

#### 2. 脚本排序 / 脚本聚合

脚本排序要对**每个候选文档**求值一次，既走不到 `doc_values` 的列式快路径，也打断了"分数不如当前 top-k 就跳过"的提前终止——症状就是"DSL 很短、深页很慢、CPU 很高但 IO 很低"。等价替代：写入时把排序要用的值算好落成独立数值字段（`score_num`、`boost_flag`），查询侧只排字段。`aggs` 里的 `script` 同理：需要"按组合键分组"就在写入端把组合键拼成一个 keyword 字段。

#### 3. 前导通配符与 wildcard 查询

`{"wildcard": {"title": "*elastic*"}}`、`query_string` 里的 `~`/`*abc`、`regexp` 都要**遍历整个词典**：词典是有序的，前缀能二分定位，中缀/后缀只能全量扫描，代价 ∝ **不同词项数**而不是文档数（所以"数据量小但很慢"）。加上 `keyword` 的 `ignore_above` 会让超长值根本不在词典里，结果既慢又不完整。

替代方案（按代价从低到高）：`prefix` + mapping 的 `index_prefixes`（把前缀查询变成等值查询）→ `edge_ngram`（搜索自动补全的标准解；⚠️ 索引时 analyzer 用 edge_ngram、**search-time analyzer 必须回退成 keyword/standard**，否则查询词也被切碎，匹配失效）→ `ngram`（召回中缀/模糊，索引膨胀明显）→ `wildcard` 字段类型（内部 ngram + 精确复核，专门为"必须中缀匹配"准备）。⚠️ 最后兜底才是 `script` 做 `contains`——那是全表扫描级别。

#### 4. "写后读不到"：refresh 语义的正确理解

+ `GET /idx/_doc/1` 是**实时**的（走主分片的 in-memory buffer 与 translog 版本查找），刚写完一定能拿到。
+ 拿不到的是**搜索类**操作：`_search`、`_count`、`_reindex`、`_delete_by_query`、`_update_by_query` 只能看到**已 refresh 的段**。默认 1s 刷新间隔，所以"写一条立刻搜索查不到"是**设计如此**，不是丢数据。
+ 于是三类真实事故：① 业务把"写后查"实现成 search 列表 → 偶发空结果，重启 ES 好像就好了；② 用 `_delete_by_query`/`_reindex` 追增量，漏掉最后 1s 内写入的数据；③ 为了修 ① 把 `refresh_interval` 调到几十毫秒或写入全带 `refresh=true` → 段爆炸、merge 跟不上、整个集群搜索变慢（把一个语义问题升级成性能事故）。
+ 正确姿势：确实要写后可查就用 `?refresh=wait_for`（等下一个刷新点，不额外造段）；或者按"以写入方为准"设计（读自己的 `_id`）；追增量靠业务时间字段（`updated_at >= T - overlap` 重跑一遍幂等覆盖），而不是依赖 refresh 时序。
+ 另一个相关坑：`_source` 关闭后 `update` 与 `_reindex` 都会失效（部分更新要读旧文档、reindex 要读原始文档），只关 `_source` 省空间是典型的"省小钱赔大钱"。

#### 5. reindex + 别名原子切换的正确做法

⭐ 目标：切换瞬间不出现"写进旧索引 + 新索引没别名"的窗口。错误做法是先 `DELETE alias` 再 `PUT alias`——两步之间写请求会失败，或者落到已被废弃的索引上。

```json

POST _aliases
{ "actions": [
  { "remove": { "index": "logs_v1", "alias": "logs" } },
  { "add":    { "index": "logs_v2", "alias": "logs" } },
  { "add":    { "index": "logs_v2", "alias": "logs_write", "is_write_index": true } } ] }
```
同一个 `_aliases` 请求里的 actions 是**原子**生效的（一次集群状态发布），这就是正确姿势。

完整流程（改 mapping、改分片数都走这条路）：

1. 建新索引：正确的分片数 + 固化 mapping + `refresh_interval: -1` + 副本先设 0；
2. `POST _reindex?wait_for_completion=false&slices=auto` 拿 task id，`GET _tasks/<id>` 轮询进度；body 里 `"conflicts": "proceed"` 容忍迁移期间的并发更新（⚠️ 这会跳过冲突文档，所以必须有第 3 步）；
3. 记录切换时刻 T（或短暂停写），按业务时间字段 `>= T - overlap` 跑第二个 reindex 追增量，同 `_id` 天然覆盖；
4. 校验：新旧 `_count`、关键字段非空数、抽样比对若干 `_id` 的 `_source`；
5. 恢复副本数与 `refresh_interval`，等待分片 green；
6. 原子切换别名（上面那段），并把旧索引置 `index.blocks.write: true` 兜底——防还有客户端硬编码旧索引名在写；
7. 旧索引保留观察期后删除，或直接交给 ILM 转冷。

⚠️ 别名切换只解决"读入口"，写入路径若用 rollover 别名，必须靠 `is_write_index` 指定唯一写索引；一个别名匹配到两个索引且没标 write index，写入会直接报错（这是 feature，不是 bug）。

## 使用方法

客户端与 ES 版本必须匹配，见下表。
### 版本对应关系

| ES 版本 | Java 客户端 | Go 客户端 | 关键说明 |
| --- | --- | --- | --- |
| 5.x ~ 6.x | `TransportClient`（已淘汰） | `olivere/elastic/v6` | 旧版 |
| 7.0 ~ 7.14 | `RestHighLevelClient` | `olivere/elastic/v7` | |
| 7.15 ~ 7.17 | `RestHighLevelClient`（7.15 起**弃用**） | `go-elasticsearch/v7` | 7.16 起官方推荐新客户端 |
| 8.x | `co.elastic.clients:elasticsearch-java` 8.x | `go-elasticsearch/v8` | ⚠️ 8.x 默认开启 HTTPS + 认证 |

⚠️ 匹配原则：客户端主版本 = ES 服务端主版本。跨主版本一般能通，但存在 API 不兼容与弃用告警。
### Go 客户端

安装官方客户端，⭐ 社区客户端 `github.com/olivere/elastic` 已停止维护，新项目请直接使用官方客户端：
```bash

go get github.com/elastic/go-elasticsearch/v8@latest
```
#### 建立客户端
```go

// 依赖: github.com/elastic/go-elasticsearch/v8
// 另需: crypto/tls、net/http、encoding/json、bytes、strings、time、fmt、log
func newClient() *elasticsearch.Client {
	cfg := elasticsearch.Config{
		Addresses: []string{"https://localhost:9200"},
		Username:  "elastic",
		Password:  "changeme",
		Transport: &http.Transport{
			// ⚠️ 仅本地自签证书调试使用，生产环境应配置正确的 CA
			TLSClientConfig: &tls.Config{InsecureSkipVerify: true},
		},
	}
	es, err := elasticsearch.NewClient(cfg)
	if err != nil {
		log.Fatalf("创建客户端失败: %s", err)
	}
	return es
}
```
#### 创建索引（含 mapping）
```go

res, err := es.Indices.Create("my_index",
	es.Indices.Create.WithBody(strings.NewReader(`{
		"settings": { "number_of_shards": 5, "number_of_replicas": 1 },
		"mappings": { "properties": {
			"title":     { "type": "text" },
			"userid":    { "type": "keyword" },
			"visittime": { "type": "date", "format": "yyyy-MM-dd HH:mm:ss||epoch_millis" }
		} } }`)),
)
if err != nil {
	log.Fatalf("创建索引失败: %s", err)
}
defer res.Body.Close()
```
#### 单条写入 / 查询 / 更新 / 删除
```go

// 写入
doc := map[string]any{
	"title":     "Go 与 Elasticsearch",
	"userid":    "u001",
	"visittime": time.Now().Format("2006-01-02 15:04:05"),
}
body, _ := json.Marshal(doc)
res, err := es.Index("my_index", bytes.NewReader(body),
	es.Index.WithDocumentID("1"),
	es.Index.WithRefresh("true"), // 立即刷新便于测试；生产环境批量写不要加
)
defer res.Body.Close()

res, err = es.Get("my_index", "1") // 按 ID 查询
res, err = es.Update("my_index", "1", // 部分更新
	strings.NewReader(`{"doc": {"title": "更新后的标题"}}`))
res, err = es.Delete("my_index", "1") // 删除
```
#### 批量写入 Bulk

⭐ 生产环境必须使用 Bulk。请求体是 NDJSON：**每行一个 JSON，行尾必须换行**，由「动作行 + 文档行」成对组成，最后一行也要有换行。
```go

var buf bytes.Buffer
enc := json.NewEncoder(&buf)
enc.SetEscapeHTML(false) // 避免 URL/HTML 字符被转义

write := func(v any) {
	_ = enc.Encode(v) // Encode 自动补行尾换行符，符合 NDJSON 要求
}

write(map[string]any{"index": map[string]any{"_index": "my_index", "_id": "1"}})
write(map[string]any{"title": "文档一", "userid": "u001"})
write(map[string]any{"index": map[string]any{"_index": "my_index", "_id": "2"}})
write(map[string]any{"title": "文档二", "userid": "u002"})

res, err := es.Bulk(bytes.NewReader(buf.Bytes()))
defer res.Body.Close()
// ⚠️ 即使 HTTP 200，也要逐条检查 items[*].index.status / error
```
#### 查询（bool + term/match/range，含高亮与分页）
```go

query := map[string]any{
	"from": 0, "size": 10,
	"query": map[string]any{"bool": map[string]any{
		"must": []any{map[string]any{"match": map[string]any{"title": "elasticsearch"}}},
		"filter": []any{
			map[string]any{"term": map[string]any{"userid": "u001"}},
			map[string]any{"range": map[string]any{
				"visittime": map[string]any{"gte": "2024-01-01 00:00:00"}}},
		},
	}},
	"highlight": map[string]any{"fields": map[string]any{"title": map[string]any{}}},
}
body, _ := json.Marshal(query)

res, err := es.Search(es.Search.WithIndex("my_index"),
	es.Search.WithBody(bytes.NewReader(body)))
defer res.Body.Close()

var r map[string]any
_ = json.NewDecoder(res.Body).Decode(&r)
for _, h := range r["hits"].(map[string]any)["hits"].([]any) {
	m := h.(map[string]any)
	fmt.Println(m["_id"], m["_source"], m["highlight"])
}
```
### Java 客户端

8.x 使用官方 Java API Client `co.elastic.clients:elasticsearch-java`。Maven 依赖：
```xml

<dependency>
  <groupId>co.elastic.clients</groupId>
  <artifactId>elasticsearch-java</artifactId>
  <version>8.13.4</version>
</dependency>
<!-- 客户端依赖 HTTP 层与 JSON 库，需显式引入 -->
<dependency>
  <groupId>org.apache.httpcomponents.client5</groupId>
  <artifactId>httpclient5</artifactId>
  <version>5.3.1</version>
</dependency>
<dependency>
  <groupId>com.fasterxml.jackson.core</groupId>
  <artifactId>jackson-databind</artifactId>
  <version>2.17.0</version>
</dependency>
```
⚠️ `RestHighLevelClient` 自 7.15 起被标记为**弃用**，7.16+ 官方推荐迁移到 `elasticsearch-java`。新项目不要再写 `RestHighLevelClient`。
#### 建立 ElasticsearchClient
```java

// 依赖: co.elastic.clients:elasticsearch-java + org.apache.httpcomponents.client5:httpclient5
// 需 import: ElasticsearchClient、JacksonJsonpMapper、RestClientTransport、RestClient 等
BasicCredentialsProvider creds = new BasicCredentialsProvider();
creds.setCredentials(AuthScope.ANY, new UsernamePasswordCredentials("elastic", "changeme"));

RestClient restClient = RestClient.builder(new HttpHost("localhost", 9200, "https"))
        .setHttpClientConfigCallback(hc -> hc.setDefaultCredentialsProvider(creds))
        .build();

// ⚠️ 8.x 默认开启 TLS 与认证，自签证书需额外配置 SSLContext；生产环境务必启用 HTTPS
ElasticsearchTransport transport = new RestClientTransport(restClient, new JacksonJsonpMapper());
ElasticsearchClient client = new ElasticsearchClient(transport);
```
#### 索引创建 / 文档 CRUD
```java

// 创建索引（含 mapping）
client.indices().create(c -> c
        .index("my_index")
        .settings(s -> s.numberOfShards("5").numberOfReplicas("1"))
        .mappings(m -> m
                .properties("title", p -> p.text(t -> t))
                .properties("userid", p -> p.keyword(k -> k))
                .properties("visittime", p -> p.date(d ->
                        d.format("yyyy-MM-dd HH:mm:ss||epoch_millis")))));

Map<String, Object> doc = Map.of(
        "title", "Java 与 Elasticsearch",
        "userid", "u001",
        "visittime", "2024-01-01 10:00:00");

client.index(i -> i.index("my_index").id("1").document(doc)); // 写入

GetResponse<Map> got = client.get(g -> g.index("my_index").id("1"), Map.class); // 查询
Map<?, ?> source = got.source();

client.update(u -> u.index("my_index").id("1") // 部分更新
        .doc(Map.of("title", "更新后的标题")), Map.class);

client.delete(d -> d.index("my_index").id("1")); // 删除
```
#### bulk 批量写入
```java

List<BulkOperation> ops = new ArrayList<>();
ops.add(BulkOperation.of(b -> b.index(i -> i.id("1").document(doc))));
ops.add(BulkOperation.of(b -> b.index(i -> i.id("2").document(doc))));

BulkResponse bulk = client.bulk(b -> b.index("my_index").operations(ops));
if (bulk.errors()) { // ⚠️ 需逐条检查错误
    bulk.items().stream()
            .filter(it -> it.error() != null)
            .forEach(it -> System.err.println("写入失败: " + it.error().reason()));
}
```
#### search 查询
```java

// import co.elastic.clients.json.JsonData;
SearchResponse<Map> resp = client.search(s -> s
        .index("my_index")
        .from(0)
        .size(10)
        .query(q -> q.bool(b -> b
                .must(m -> m.match(mt -> mt.field("title").query("elasticsearch")))
                .filter(f -> f.term(t -> t.field("userid").value("u001")))
                .filter(f -> f.range(r -> r.field("visittime")
                        .gte(JsonData.of("2024-01-01 00:00:00"))))))
        .highlight(h -> h.fields("title", hf -> hf)),
        Map.class);

long total = resp.hits().total() == null ? 0 : resp.hits().total().value();
resp.hits().hits().forEach(h -> {
    System.out.println(h.id() + " -> " + h.source());
    System.out.println("高亮: " + h.highlight());
});
```
⭐ 小结：Go 与 Java 客户端的调用形态不同——Go 偏 REST + JSON 拼装，Java 偏 builder 强类型——但底层是同一套 REST API，**查询 DSL 与 mapping 完全通用**。

## 面试官会追问什么

### Q1. 为什么 `text` 字段不能排序和聚合？
分词后倒排里存的是 term → 文档，方向是"词找文档"；排序聚合需要"文档找值"的正排结构（`doc_values`）。text 反转出来的是无序 term 集合而不是原值，语义上就不成立，所以 ES 在 mapping 层直接禁止。解法是 `fields` 子字段：`title` 做全文、`title.kw` 做聚合。

### Q2. `term` 查 `text` 字段为什么查不到？
`text` 索引的是分词后的词项，`term` 不对查询串分词、拿整串去比对词典。"Elasticsearch 入门"在词典里以两个分词存在，整串这个 term 根本不存在，所以必然查不到。要么改用 `match`，要么这个字段本来就是 `keyword`。

### Q3. 写入返回 200 之后多久能搜到？为什么？
默认最迟约 1 秒（`index.refresh_interval`）。因为请求返回只保证数据进了 in-memory buffer 并写了 translog，refresh 才会把 buffer 变成一个可被 searcher 看到的段。⚠️ 关键点：可见性发生在 page cache 里的段，不是磁盘——所以"能搜到"不代表"宕机不丢"，不丢靠 translog。

### Q4. 那宕机到底会丢数据吗？
看 translog 的 fsync 策略。默认 `durability: request`，每个请求 fsync 一次 translog，已确认的写不丢；改成 `async` 后最多丢一个 `sync_interval` 内的数据，换来写入吞吐。这是"能接受丢多少"的业务决策，不是性能开关。

### Q5. 为什么更新很贵？删除之后空间会立刻释放吗？
删除只在段的 live-docs 位图上标记，更新等于"标记删除旧文档 + 追加新文档"，倒排和列式结构全部重做一遍。空间不会立刻释放，只有 merge 读到含死文档的段时才物理回收；副作用是删除比例高时搜索仍需扫描死文档，表现为"数据变少了查询变慢了"。

### Q6. 并发更新同一个文档怎么保证不写花？
`_seq_no` + `_primary_term` 做乐观并发：读时拿版本号，写时用 `if_seq_no`/`if_primary_term` 条件提交，不匹配返回 409。`_primary_term` 的作用是防止旧主分片残留的写在主切换后被错误接受。从 DB 单向同步则用 `version_type=external` + 单调业务版本丢弃乱序旧数据。

### Q7. 深分页为什么慢？给三个方案并说明怎么选。
`from + size` 时每个分片都要维护 from+size 大小的堆，协调节点归并 `分片数 × (from+size)` 条，前 from 条纯白算。选：后台列表能跳页且只看前几页 → from/size；无限下拉 → `search_after`（近乎零开销但不能跳页）；导出/一致性遍历 → PIT + `search_after`（scroll 是旧方案，段被钉住且不能跳页）。

### Q8. `terms` 聚合的 doc_count 一定是准的吗？
不一定。各分片只回传本地 top size，协调节点汇总，某 term 可能全局高频但每个分片都进不了本地前 N，被静默丢掉。响应里的 `doc_count_error_upper_bound` 是误差上界，`sum_other_doc_count` 非 0 说明长尾被截。要精确遍历全部 bucket 用 `composite`（按 key 有序归并，因此没有这个误差）。

### Q9. `cardinality` 能用来算钱吗？
不能。它是 HyperLogLog 近似，内存固定，基数在 `precision_threshold` 内接近精确，超出后只保证数量级。对账/计费要精确去重：按天去重落库，或者 terms/composite 全遍历。另外 keyword 上的 `ignore_above` 会让超长值不进索引，近似值之外还漏值。

### Q10. 分片是不是越多越好？
不是。每个分片是独立 Lucene 索引，有与数据量无关的固定开销（词典、段元数据、global ordinals、translog、search 线程占用）；不带 routing 的查询要在每个分片各跑一次，延迟由最慢分片决定；cluster state 体积也随分片数增长，压主节点。分片数按数据量和目标延迟倒推，历史索引靠 shrink 收口。

### Q11. 主分片数建错了怎么办？shrink 和 split 有什么限制？
路由是 `hash(routing) % 主分片数`，改了旧数据就找不到，所以它是静态设置。三条路：`_reindex`（任意方向，代价是全量搬）；`_shrink`（目标必须整除源分片数，源要停写、分片需集中）；`_split`（目标必须是源的整数倍，同样停写，且不修正数据倾斜）。

### Q12. 集群 yellow 需要处理吗？red 呢？
yellow 表示副本没分配上——数据读写都正常，但容错已降级，主分片所在节点再挂就变 red，所以是"要尽快查原因"而不是"立刻救火"。red 表示有主分片未分配，这部分数据完全不可用、查询 partial failure，必须处理。第一步统一是 `_cluster/allocation/explain`，最常见的根因是磁盘水位触顶。

### Q13. 查询突慢，你的排查顺序是什么？
先看是不是集群层面的（线程池 rejected、pending tasks、GC、磁盘水位），再看是不是查询本身的（`profile: true` 看时间落在 query 还是 fetch、哪个子句/哪个分片最慢），最后看索引形态（段数是否爆炸 = refresh/merge 出问题；删除比例高；字段用错 text/keyword；聚合的 doc_count_error 与 cardinality 组合暴露长尾被砍）。slowlog 负责长期观测，profile 负责单条复现，两者不能互换。

### Q14. force merge 什么时候能做、什么时候不能做？
只做"未来不再写"的索引（rollover 之后的历史索引），因为它把死文档物理清除、把段数压到很小，代价是大 IO 且期间不可写。⚠️ 还在写的索引做等于白做（新段立刻污染）；按时间范围过滤的日志索引压成 1 段反而失去"按段的 min/max 跳过整段"的能力，通常留若干段更合适。

## 延伸

- 段落盘与 fsync、page cache 的底层机制：[Linux 文件系统与 I/O](../linux/文件系统与IO.md)、[Linux 内存管理](../linux/内存管理.md)
- 副本确认语义与多数派共识的差别：[Raft 协议](../distributed/Raft协议.md)
- 从 binlog/CDC 单向同步到 ES 的链路设计：[Kafka](../middleware/Kafka.md)
