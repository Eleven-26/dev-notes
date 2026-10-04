# Elasticsearch 向量与 AI 搜索

> Elasticsearch 怎么做「语义相似」这件事：向量索引（dense_vector / HNSW / 暴力精确）、
> 量化（int8 / int4 / BBQ）把向量压小的代价、语义搜索（`semantic_text`），以及把关键词与向量两路结果合起来的混合检索。
>
> 内容整理自个人学习笔记。**向量与量化部分**基于官方文档、Release Notes 与本机容器实测独立整理
> （`elasticsearch:8.19.0`，单节点、堆 512m、端口 19210）；
> 需要模型推理的能力（`semantic_text` / ELSER / Elastic Rerank）**本机无可用推理端点，明确标注为未实测**。
> 索引结构见 [索引原理.md](索引原理.md)；写入与可见性见 [写入流程.md](写入流程.md)。

---

## 一、向量检索是怎么接进 ES 的

### 1.1 `dense_vector`：从「词项匹配」到「向量相似」

倒排索引回答的是「哪些文档**含有**这个词」（见 [索引原理.md](索引原理.md)），所以它天然处理不了同义词、错别字、跨语言和「意思相近但用词完全不同」。向量检索换了个问题：

+ 用模型把文档与查询都编码成**定长浮点数组**（embedding，例如 384 / 768 / 1024 维）；
+ 相似度用 **cosine / dot_product / l2_norm** 度量，而不是词项交集；
+ 「最相关」变成「距离最近」——这就是 **ANN（近似最近邻）** 要解决的问题。

```json
PUT my_vec
{ "mappings": { "properties": {
    "v": { "type": "dense_vector", "dims": 384, "index": true,
           "similarity": "cosine", "index_options": { "type": "hnsw" } } } } }
```

⚠️ `dims` 必须与实际写入的向量长度一致，且 `index: true` 与 `index: false` 是**两种完全不同的检索形态**（前者用图索引，后者只能靠脚本暴力算）。

### 1.2 两种检索角色：ANN 与「精确暴力」

| 形态 | 写法 | 返回 | 代价 |
| --- | --- | --- | --- |
| **ANN（近似）** | `index: true` + `"knn": {...}` | 近似最近邻（召回 < 100%） | 靠 HNSW 图索引，**亚线性**，规模越大优势越明显 |
| **精确暴力** | `index: false`，或 `index_options.type: flat`，或用 `script_score` 逐条算 | 精确 top-k（召回 = 100%） | **O(N)**：候选集全扫一遍，只适合小数据或离线校验 |

```json
{ "knn": { "field": "v", "query_vector": [0.12, -0.03, ...],
           "k": 10, "num_candidates": 100 } }
```

⭐ **`num_candidates` 是召回率与延迟的旋钮**：每个分片先取 `num_candidates` 个候选，再算精确相似度取前 `k`。调大召回上升、延迟上升；它就是 Lucene 的 `efSearch` 语义。

⭐ **精确暴力还有一条独立价值**：它是**评估 ANN 召回率的基准**。回召率（recall@k）= ANN 的 top-k 与精确 top-k 的交集比例——本节的实测就靠它。

---

## 二、量化：把每个向量压小

### 2.1 三档量化

一个 768 维的 float32 向量是 `768 × 4 = 3KB`。1 亿条就是 **~286GB**，这还没算 HNSW 的图结构。量化就是「用更少的位表示每一维」：

| 类型 | 每维位宽 | 每个向量（D 维） | 相对 float32 |
| --- | --- | --- | --- |
| 原始 `float32` | 32 bit | `D × 4` 字节 | 1× |
| `int8_*`（标量量化） | 8 bit | `D × 1 + 4` 字节 | ≈ 1/4 |
| `int4_*`（半字节） | 4 bit | `D × 0.5 + 4` 字节 | ≈ 1/8 |
| **`bbq_*`（Better Binary Quantization）** | 1 bit | `D × 0.125 + 4` 字节 | ≈ **1/32** |

> ⚠️ 上表是**官方给出的每向量开销口径**（`+4` 是量化参数，如分位数 / 校正项）。本机实测的索引级存储体积**不可复现**（同一配置两次跑出相差约 1.7 倍的读数），所以本篇**不引用本机存储数字**，只讲机制与官方口径。

**BBQ 为什么能到 1 bit 还不至于不可用**（官方口径，非本机实测）：

+ **每个向量以所在段的质心为中心**做量化，而不是全段共用一组静态分位数——这让二值化后仍能区分方向；
+ 逐向量求一组**优化的分位数**（优化步数有限，误差不再下降就停），这一步在 8.18 起换成「优化的标量量化」；
+ 存的不只是 1 bit：还有上下分位数、量化分量之和与一个**误差校正项**（欧氏距离存中心化向量的平方范数，点积存质心与原向量的点积）；
+ 检索时**全量扫预测向量 → 过采样 → 用更大的向量重打分**，所以精度损失主要在「第一次筛」。

### 2.2 配置怎么写

```json
"v": { "type": "dense_vector", "dims": 384, "index": true, "similarity": "cosine",
       "index_options": { "type": "bbq_hnsw" } }
```

⚠️ **量化走 `index_options.type`，不是 `index.codec`**。本机实测：8.19 上写 `"index.codec": "bbq"` 直接被拒——

```text
illegal_argument_exception: unknown value for [index.codec] must be one of [default, best_compression] but was: bbq
```

可选的 `index_options.type`：`flat` / `hnsw` / `int8_hnsw` / `int4_hnsw` / `bbq_hnsw`（各量化类型也都有对应的 `*_flat` 变体）。⭐ 本机实测 8.19 的 mapping 回显默认参数：`hnsw` → `{"m": 16, "ef_construction": 100}`；`bbq_hnsw` → `{"m": 16, "ef_construction": 100, "rescore_vector": {"oversample": 3.0}}`。

### 2.3 oversample 与 rescore：量化掉的召回怎么补回来

第一次筛用的是**压缩后的向量**，分数必然有偏差。补救办法是**过采样再重打分**：

```json
{ "knn": { "field": "v", "query_vector": [...], "k": 10, "num_candidates": 100,
           "rescore_vector": { "oversample": 3 } } }
```

`oversample: 3` = 先取 `3 × k` 个候选，再用**原始向量**精排取前 `k`（8.18 之前的老写法是 `rescore_vector.oversample` 之外再配 `oversample`+`rescore`，8.18 起简化为一个字段）。

⭐ **本机实测（`bbq_hnsw` 的 mapping 默认就带 `oversample: 3`）**：这正是「BBQ 不重打分也够用」的原因——见下一节的对照表。

---

## 三、语义搜索

### 3.1 `semantic_text`：把推理端点藏进 mapping

9.0 起 `semantic_text` 正式 GA。它把「调模型生成向量」这件事**下推到写入与查询**：

```json
PUT my-data
{ "mappings": { "properties": { "my_semantic_field": { "type": "semantic_text" } } } }

POST my-data/_search
{ "query": { "match": { "my_semantic_field": "哪个向量数据库最先做成了 BBQ？" } } }
```

⭐ 关键点：**不指定 `inference_id` 时用默认模型（ELSER）**；也可以显式指向自己的端点（如 JinaAI、e5）。于是业务代码里写的是 `match`，底下走的是向量检索——**不需要自己维护「编码 → 写入向量字段」的管道**。

⚠️ **本节未实测**：`semantic_text` 依赖推理端点（默认 ELSER 需要下载并部署模型），本机容器没有可用的推理端点，**只做文档口径转述，未做任何运行验证**。此外它需要企业订阅等级。

### 3.2 稀疏 vs 稠密：ELSER 与 e5 的分工

| 路线 | 代表 | 向量形态 | 特点 |
| --- | --- | --- | --- |
| **稀疏（learned sparse）** | ELSER | 高维稀疏（词表级权重） | 可解释（能看到哪些词权重大）、与倒排天然兼容、召回偏"词义扩展" |
| **稠密（dense）** | e5 / 自训模型 | 低维稠密（384~1024） | 语义泛化更强、跨语言好；但必须靠 ANN + 量化才撑得住规模 |

⭐ 选型判据：**只需要"同义词扩展"→ 稀疏；需要跨语言、跨表述的语义等价 → 稠密**。两者不冲突，主流做法是**混合**（下一节）。

---

## 四、混合检索：把两路结果合起来

### 4.1 为什么必须混合

纯向量的两个硬伤：

+ **精确串会被"语义化"**：查商品 SKU、订单号、错误码，向量检索可能给出"意思相近但不对"的结果；
+ **量化丢了信息**：BBQ 掉的召回，靠关键词那一路「精确命中」正好补上。

反过来，纯关键词处理不了同义词与语义泛化。**两路合流**（hybrid）是当前最稳的工程解。

### 4.2 三种合流方式与 license 边界

| 方式 | 写法 | license | 适用 |
| --- | --- | --- | --- |
| **`bool` + 顶层 `knn`** | `{"query": {...}, "knn": {...}}` | ✅ 免费 | 最省事：两路各取一部分，按加权分合并 |
| **`linear` retriever** | `retriever.linear` 给两路分配权重 | ⚠️ 需订阅 | 需要显式控制两路权重 |
| **`rrf` retriever** | `retriever.rrf`（Reciprocal Rank Fusion） | ⚠️ **需订阅** | 只看**排名**融合，不用调分数归一化 |

⭐ **本机实测（重要）**：在免费（basic）license 上用 `retriever.rrf` 直接被拒——

```text
security_exception: current license is non-compliant for [Reciprocal Rank Fusion (RRF)]
```

换成 **`bool` + 顶层 `knn`** 则正常返回结果，所以**没有订阅时混合检索要用这种写法**：

```json
{ "size": 5,
  "query": { "match": { "t": "keyword7" } },
  "knn":   { "field": "v", "query_vector": [...], "k": 10, "num_candidates": 100, "boost": 0.5 } }
```

⚠️ RRF 的直觉：**它只看排名不看分数**，所以两路分数尺度不同也不用归一化——这是它最大的工程价值，也是它必须按 license 计费的原因。

---

## 使用：起一个容器，量一遍量化与召回

下面这套流程在**本机 Docker** 里跑通（`elasticsearch:8.19.0` 单节点、堆 512m、端口 19210），
用**精确暴力**做基准来量各量化类型的**召回率**。

```bash
# ① 起容器（本机实测用例；注意 8.x 默认开安全，本地调试要显式关掉）
docker run -d --name es-vec-lab -p 19210:9200 \
  -e discovery.type=single-node -e xpack.security.enabled=false \
  -e ES_JAVA_OPTS="-Xms512m -Xmx512m" elasticsearch:8.19.0

# ② 建四个索引，只改 index_options.type：flat(精确) / hnsw / int8_hnsw / bbq_hnsw
#    ⚠️ 不要写 index.codec: bbq —— 8.19 实测直接报 400
curl -s --noproxy '*' -X PUT "localhost:19210/v_flat" -H 'Content-Type: application/json' -d '
{ "settings": { "number_of_shards": 1, "number_of_replicas": 0 },
  "mappings": { "properties": {
    "v": { "type": "dense_vector", "dims": 384, "index": true,
           "similarity": "cosine", "index_options": { "type": "flat" } } } } }'

# ③ knn 查询：flat 上跑出来的就是精确 top-10，作为 recall 的基准
curl -s --noproxy '*' -X POST "localhost:19210/v_flat/_search" -H 'Content-Type: application/json' -d '
{ "knn": { "field": "v", "query_vector": [ ... ], "k": 10, "num_candidates": 100 }, "size": 10 }'

# ④ 量化掉召回时加 oversample（bbq_hnsw 的 mapping 默认就是 3）
curl -s --noproxy '*' -X POST "localhost:19210/v_bbq/_search" -H 'Content-Type: application/json' -d '
{ "knn": { "field": "v", "query_vector": [ ... ], "k": 10, "num_candidates": 100,
           "rescore_vector": { "oversample": 3 } }, "size": 10 }'
```

**实测口径**：3000 条 × 384 维，向量按 **32 个簇**生成（质心 + 0.12 噪声后归一化）——
⭐ **这一点很关键**：第一版用均匀随机向量跑，`hnsw` 的 recall@10 只有 0.79、`bbq` 反而 0.97（**方向都不对**），因为高维随机向量的相似度几乎无区分度，"前 10 名"本身就是噪声。换成聚簇向量后 top-k 才有确定含义。

| 检索方式 | `num_candidates` | oversample | recall@10（30 次查询） | 平均延迟 |
| --- | --- | --- | --- | --- |
| `flat`（精确暴力，基准） | — | — | 1.000 | 16.12 ms |
| `hnsw` | 100 | 无 | **1.000** | 15.82 ms |
| `hnsw` | 100 | 3 | 1.000 | 12.56 ms |
| `int8_hnsw` | 100 | **无** | ⚠️ **0.620** | 20.22 ms |
| `int8_hnsw` | 100 | 3 | **1.000** | 12.63 ms |
| `bbq_hnsw` | 100 | 默认 3 | 0.997 | 13.35 ms |
| `bbq_hnsw` | 400 | 5 | 0.997 | 19.30 ms |

**三条可复用的结论**：

1. ⭐ **量化掉的召回靠重打分补回来，不是靠"换个好模型"**。`int8_hnsw` 不加 `rescore_vector` 只有 **0.620**，加了 `oversample: 3` 直接回到 **1.000**——同一份数据、同一个索引，差的只是查询时多取两倍候选再精排。这解释了为什么 `bbq_hnsw` 的 mapping **默认**就带 `oversample: 3`。
2. ⚠️ **这个规模（3000 条）量不出 ANN 与量化的性能收益**：精确暴力 16.12ms，各 ANN 落在 12~20ms，**差别在噪声范围内**。向量索引的价值随规模上升——量化的存储收益、HNSW 的亚线性检索，都要在十万 / 百万级才显现，小数据集上跑基准只会得到"看起来差不多"的假结论。
3. ⚠️ **索引级存储体积在本机不可复现**：同一配置两次跑出约 1.7 倍差异（4591 vs 7836 B/文档），**本篇因此不引用存储数字**，只保留上面这张召回表与官方给出的每向量开销口径。

---

## 延伸追问

### 1. 向量检索能替代倒排索引吗？
不能，两者答的不是一个问题。倒排回答"含不含这个词"（精确、可解释、能排序聚合）；向量回答"意思像不像"（模糊、跨表述）。生产上的主流是**混合**：向量负责语义泛化，关键词负责精确命中与兜底——纯向量会在 SKU、订单号、错误码上翻车。

### 2. `num_candidates` 和 `k` 是什么关系？
`k` 是你要返回几条；`num_candidates` 是**每个分片**先捞多少个候选来精排。`num_candidates` 必须 ≥ `k`，调大召回上升、延迟上升。它等价于 HNSW 的 `efSearch`：图索引每次多探一点，就能少漏一点。

### 3. 量化的取舍怎么定？
先按"能不能接受掉召回"排序，再谈压缩比：`int8` 一般掉得最少、`int4` 居中、`bbq` 压得最狠（1 bit）。⭐ 但**它们都能靠 `oversample` 重打分把召回拉回来**，代价是查询时多算一些候选。所以判据是「**查询 QPS × 可接受的延迟**」对上「**存储预算**」，不是单纯比压缩比。

### 4. 免费版能不能做混合检索？
能，但**不能用 `rrf` / `linear` retriever**——本机实测在 basic license 上 `retriever.rrf` 直接 403（`license is non-compliant for [Reciprocal Rank Fusion (RRF)]`）。免费做法是 `bool` 查询 + 顶层 `knn` 并排写，靠 `boost` 调两路权重。

### 5. `semantic_text` 值不值得用？
它把"调模型"藏进 mapping，业务侧只写 `match`，省掉自己维护编码管道——如果你**没有**成熟的 embedding 服务，它很省事；如果你已经有自己的模型与管道，直接写 `dense_vector` 更可控。⚠️ 它依赖推理端点（默认 ELSER 要下载部署模型）且需要企业订阅等级，**本篇未在本机验证**。

---

## 关联

- [索引原理.md](索引原理.md) — 倒排索引与 doc_values：向量检索是另一条索引主线
- [写入流程.md](写入流程.md) — 向量字段同样受 translog / refresh / merge 的代价链约束
- [搜索流程与DSL.md](搜索流程与DSL.md) — 关键词那一路的 DSL 与打分（混合检索的另一半）
- [分页与聚合.md](分页与聚合.md) — 向量检索与聚合的配合（先过滤再 knn 的候选集控制）
- [README.md](README.md) — 版本坐标：BBQ 8.16 预览 → 8.18 GA，`semantic_text` 9.0 GA
- [基数与频率估计.md](../../../../02-计算机基础/数据结构/高级数据结构/基数与频率估计.md) — 另一种「用近似换空间」的结构
- [存储选型.md](../../存储选型.md) — 向量库与全文检索库的分工
- [README.md](../../NoSQL/MongoDB/README.md) — 另一类存储（文档库）的能力对照
