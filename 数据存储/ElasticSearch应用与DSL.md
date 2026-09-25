# Elasticsearch 应用与搜索 DSL

> 面向工程的 ES 用法：写入与索引状态管理、集群与分片规划、文本分析、搜索 DSL（含深翻页与聚合），以及常见事故清单与 8 个高频追问。
>
> 内容整理自个人学习笔记；补充（写入与段生命周期、BM25 与聚合代价、深翻页四方案、集群与 ILM、事故清单）参考《Elasticsearch 数据搜索与分析实战》（王深湛）。
> 核心原理（倒排索引、分片、写入与搜索流程）见 [ElasticSearch.md](ElasticSearch.md)；客户端用法见 [ElasticSearch客户端.md](ElasticSearch客户端.md)。

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

---

## 面试官会追问什么

### 一、深分页为什么慢？给三个方案并说明怎么选。
`from + size` 时每个分片都要维护 from+size 大小的堆，协调节点归并 `分片数 × (from+size)` 条，前 from 条纯白算。选：后台列表能跳页且只看前几页 → from/size；无限下拉 → `search_after`（近乎零开销但不能跳页）；导出/一致性遍历 → PIT + `search_after`（scroll 是旧方案，段被钉住且不能跳页）。

### 二、`terms` 聚合的 doc_count 一定是准的吗？
不一定。各分片只回传本地 top size，协调节点汇总，某 term 可能全局高频但每个分片都进不了本地前 N，被静默丢掉。响应里的 `doc_count_error_upper_bound` 是误差上界，`sum_other_doc_count` 非 0 说明长尾被截。要精确遍历全部 bucket 用 `composite`（按 key 有序归并，因此没有这个误差）。

### 三、`cardinality` 能用来算钱吗？
不能。它是 HyperLogLog 近似，内存固定，基数在 `precision_threshold` 内接近精确，超出后只保证数量级。对账/计费要精确去重：按天去重落库，或者 terms/composite 全遍历。另外 keyword 上的 `ignore_above` 会让超长值不进索引，近似值之外还漏值。

### 四、分片是不是越多越好？
不是。每个分片是独立 Lucene 索引，有与数据量无关的固定开销（词典、段元数据、global ordinals、translog、search 线程占用）；不带 routing 的查询要在每个分片各跑一次，延迟由最慢分片决定；cluster state 体积也随分片数增长，压主节点。分片数按数据量和目标延迟倒推，历史索引靠 shrink 收口。

### 五、主分片数建错了怎么办？shrink 和 split 有什么限制？
路由是 `hash(routing) % 主分片数`，改了旧数据就找不到，所以它是静态设置。三条路：`_reindex`（任意方向，代价是全量搬）；`_shrink`（目标必须整除源分片数，源要停写、分片需集中）；`_split`（目标必须是源的整数倍，同样停写，且不修正数据倾斜）。

### 六、集群 yellow 需要处理吗？red 呢？
yellow 表示副本没分配上——数据读写都正常，但容错已降级，主分片所在节点再挂就变 red，所以是"要尽快查原因"而不是"立刻救火"。red 表示有主分片未分配，这部分数据完全不可用、查询 partial failure，必须处理。第一步统一是 `_cluster/allocation/explain`，最常见的根因是磁盘水位触顶。

### 七、查询突慢，你的排查顺序是什么？
先看是不是集群层面的（线程池 rejected、pending tasks、GC、磁盘水位），再看是不是查询本身的（`profile: true` 看时间落在 query 还是 fetch、哪个子句/哪个分片最慢），最后看索引形态（段数是否爆炸 = refresh/merge 出问题；删除比例高；字段用错 text/keyword；聚合的 doc_count_error 与 cardinality 组合暴露长尾被砍）。slowlog 负责长期观测，profile 负责单条复现，两者不能互换。

### 八、force merge 什么时候能做、什么时候不能做？
只做"未来不再写"的索引（rollover 之后的历史索引），因为它把死文档物理清除、把段数压到很小，代价是大 IO 且期间不可写。⚠️ 还在写的索引做等于白做（新段立刻污染）；按时间范围过滤的日志索引压成 1 段反而失去"按段的 min/max 跳过整段"的能力，通常留若干段更合适。

---

## 关联

- [ElasticSearch.md](ElasticSearch.md) — 倒排索引与写入流程等原理
- [ElasticSearch客户端.md](ElasticSearch客户端.md) — 这些 DSL 在 Go / Java 里怎么写
- [../中间件/消息队列/Kafka.md](../中间件/消息队列/Kafka.md) — binlog/CDC 到 ES 的同步链路
- [mysql/索引与优化.md](mysql/索引与优化.md) — ESR 与联合索引顺序的对照
- [../linux/文件系统与IO.md](../linux/文件系统与IO.md) — merge 与磁盘 IO
