# Elasticsearch 搜索引擎

> 一句话说明本文件覆盖什么：Elasticsearch 的简介与选型、核心原理（倒排索引、分片、写入与搜索流程）、工程应用（状态管理、文本分析、搜索 DSL），以及 Go / Java 官方客户端的使用方法。
>
> 内容整理自个人学习笔记。
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
#### 字段设计建议

通常写入 Elasticsearch 的字段需要符合以下几种情况。

(1) 该字段被用作检索条件。(2) 该字段用于统计分析。(3) 该字段经常用于前端展示。(4) 该字段是文档主键。
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
