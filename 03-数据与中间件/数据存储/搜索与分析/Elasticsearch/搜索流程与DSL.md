# Elasticsearch 搜索流程与 DSL

> 一次搜索从协调节点到分片的两阶段执行（query-then-fetch）、查询 DSL 的核心结构
> （match / term / range / bool）、相关性打分（BM25），以及怎么观测一条查询的代价。
>
> 内容整理自个人学习笔记；执行流程与 BM25 的补充参考《Elasticsearch 数据搜索与分析实战》（王深湛）。
> 索引结构见 [索引原理.md](索引原理.md)；分页与聚合见 [分页与聚合.md](分页与聚合.md)。

---

## 一、一次搜索走哪几个分片

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

## 二、query-then-fetch：两阶段执行与协调节点 reduce

⭐ 深分页为什么贵、聚合的 `doc_count` 为什么会错，都能从这个两阶段模型推出来。

```text
阶段1 query : 每个分片本地执行查询，各自维护一个大小为 (from + size) 的优先队列，
              只回传 docId + 排序值（不回传文档内容）
协调节点    : 归并各分片候选 → 重排出全局前 (from + size) → 截出真正的 size 条
阶段2 fetch : 只对最终这一批文档，按分片分组回捞 _source / 高亮片段
```

+ **成本模型**：分片侧的堆占用和比较次数 ∝ `from + size`；协调节点的归并量 ∝ `分片数 × (from + size)`。翻得越深，**前 from 条是纯白算的**——每个分片都得为它们维护队列、协调节点得把它们排完再丢掉。这就是 `index.max_result_window` 存在的原因（它是护栏，不是性能开关）。
+ **聚合的 reduce 在协调节点**：各分片先算本地结果（`sum`/`count` 可直接相加；`avg` 要拆成 sum+count 再合并；`percentiles` 要合并 t-digest 之类的压缩结构，`tdigest.compression` 就是"精度换内存"的旋钮），协调节点合并成全局结果后再跑 pipeline 聚合。⚠️ 分片级的 top-N 截断正是 `doc_count_error` 的来源（见 [分页与聚合.md](分页与聚合.md) 第四节）。
+ **排序/聚合为什么便宜**：`doc_values` 是按文档顺序**列式**存放的正排结构，聚合时顺序扫一遍列即可，没有随机访问、能被 CPU 向量化；倒排的方向不对，所以不能拿来聚合（这也是 text 不能排序的根因）。
+ **两阶段的另一笔代价**：fetch 阶段按 docId 回捞 `_source` 可能是随机 IO。只要 ID 列表就 `"size": 0` / `"_source": false`；只要部分字段用 `_source: ["a", "b"]`（⚠️ 过滤发生在返回之后，读取整个 `_source` 的成本省不掉，省的是网络与序列化）。

## 三、搜索数据与查询 DSL

最好不要在精准级查询的字段中使用 text 字段，因为 text 字段会被分词，这样做既没有意义，还很有可能什么也查不到。

前缀查询用于搜索某个字段的前缀与搜索内容匹配的文档，前缀查询比较耗费性能，如果是 text 字段，你可以在映射中配置 `index_prefixes` 参数，它会把每个分词的前缀字符写入索引，从而大大加快前缀查询的速度。

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
⚠️ **`term` 与 `match` 的区别（常见易混点）**：

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

## 四、相关性打分：BM25 相比 TF-IDF 改了什么

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

## 五、观测查询代价：profile 与 slowlog

+ `POST /idx/_search` 带 `"profile": true`：返回每个分片上每棵查询子树的 `build_query / create_weight / rewrite / weight.count`、collector 的 `shard_min/max/mean` 耗时、每个段的 `advance / next_doc / set_min_competitive_score` 次数，以及聚合的 `reduce` 时间。看两件事：**时间集中在 query 阶段还是 fetch 阶段**、**哪个子句/哪个分片的 shard time 最大**（最慢分片决定整体延迟）。
+ ⚠️ profile 自身有开销、输出巨大，只用于单条查询复现，不要挂在生产流量上；长期观测靠 slowlog。
+ 慢日志：`index.search.slowlog.threshold.query.*` 与 `...fetch.*`（分别对查询阶段/取回阶段计时）、`index.indexing.slowlog.threshold.index.*`，配合 `index.search.slowlog.level` 能把慢 DSL 原文落到节点日志。默认阈值基本不输出，需要显式设成毫秒级；具体键名与默认值以官方文档为准。
+ ⭐ 看代价时最容易混淆的三件事：**排队时间 vs CPU 时间**（search/write 线程池打满时 profile 里看不到，但客户端延迟很高，要看 `_cat/thread_pool`）；**分片本地时间 vs 协调节点 reduce 时间**（聚合 reduce 不在任何分片的 profile 里）；**一个分片 vs 最慢分片**（数据倾斜/热点分片时平均值会骗人）。

---

## 使用：把 filter / must / profile 三件事一次跑通

```bash
ES=http://localhost:19200
# ① 同一条查询：过滤条件放进 filter（不掉分、可命中 bitset 缓存）
curl -s --noproxy '*' -X POST "$ES/my_index/_search" -H 'Content-Type: application/json' -d '
{ "size": 5,
  "query": { "bool": {
    "must":   [ { "match": { "title": "elasticsearch" } } ],
    "filter": [ { "term":  { "userid": "u001" } },
                { "range": { "visittime": { "gte": "2024-01-01 00:00:00" } } } ] } } }'

# ② 想知道时间花在 query 阶段还是 fetch 阶段、哪个子句最贵：加 profile
curl -s --noproxy '*' -X POST "$ES/my_index/_search" -H 'Content-Type: application/json' -d '
{ "size": 5, "profile": true,
  "query": { "bool": { "must": [ { "match": { "title": "elasticsearch" } } ] } } }'
# → profile.shards[*].searches[*].query[*].breakdown：逐个子树的 build_query / create_weight / next_doc 耗时
```

## 延伸追问

### 1. `term` 查 `text` 字段为什么查不到？
`text` 索引的是分词后的词项，`term` 不对查询串分词、拿整串去比对词典。"Elasticsearch 入门"在词典里以两个分词存在，整串这个 term 根本不存在，所以必然查不到。要么改用 `match`，要么这个字段本来就是 `keyword`。

### 2. `filter` 比 `must` 快在哪？什么时候 filter 的缓存反而不生效？
`filter` 只回答"匹配吗"，答案是布尔，Lucene 用 **bitset** 按段缓存，同一 filter 下次直接位运算；放进 `must` 就变成"每个文档算一次分"。⚠️ 缓存不生效的两种情形：值分布**高基数**（每个值只命中几条，没有复用价值还占堆）；随时间滑动的 `range: {now-1h}` **每次都不同**。所以粗粒度条件（租户 / 状态 / 日期分桶）放 filter，滑动的精确条件留在 must 之外或改成按天 filter。

### 3. profile 和 slowlog 该怎么分工？
profile 是**单条复现**用的（自身有开销、输出巨大，不能挂生产流量）；slowlog 是**长期观测**用的，靠 `index.search.slowlog.threshold.query.*` / `...fetch.*` 把慢 DSL 原文落到节点日志。两者不能互换：要"复现这一条为什么慢"用 profile，要"发现哪类查询在变慢"用 slowlog。

## 关联

- [索引原理.md](索引原理.md) — 倒排索引与 doc_values：为什么打分与聚合的方向相反
- [写入流程.md](写入流程.md) — 文档怎么落到分片、写入后的可见性语义
- [分页与聚合.md](分页与聚合.md) — from/size、search_after、scroll、PIT 与聚合
- [向量与AI搜索.md](向量与AI搜索.md) — 用向量相似度替代词项匹配的另一种检索
- [索引设计与EXPLAIN.md](../../关系型/MySQL/索引设计与EXPLAIN.md) — ESR 与联合索引顺序的对照
- [Kafka.md](../../../中间件/消息队列/Kafka.md) — CDC 到 ES 的同步链路
> 反向引用（本篇被下列文档引到）：[客户端.md](客户端.md)、[常见事故.md](常见事故.md)
