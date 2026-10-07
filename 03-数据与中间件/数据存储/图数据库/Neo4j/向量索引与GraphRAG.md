# 向量索引与GraphRAG

> 原生向量索引、Cypher SEARCH、混合检索（向量 + 全文 + 图遍历）、GraphRAG 流程与 Agentic AI 集成。
>
> 内容整理自个人学习笔记。**基础框架**参考官方 Cypher Manual「Vector index / SEARCH」与
> **neo4j-graphrag-python** 文档；**5.11 引入的原生向量索引**在本仓实测，
> **2026.x 的 `SEARCH` 子句与带过滤向量搜索**在 5.26 上不可用，属官方文档与 Release Notes 陈述（已逐处标注）。
>
> ⚠️ **实测环境**：Docker `neo4j:5.26-community`（内核 **5.26.31**）。
> 向量检索用自建的 8 个 `:Doc` 节点（4 维 embedding 分布在 4 个方向 + 一段文本）演示，
> 只为让"相似度排序"有区分度；另用 200 个带 embedding 的 `:User` 演示"向量 + 图遍历"。

---

## 一、向量索引基础

**本节要点**：向量索引（**5.11 起原生支持**）把"高维向量 + 相似度"做进图库：
**按向量找最像的节点**，而不是按属性等值匹配。底层是 **HNSW** 近似最近邻，属性是**维度**与**相似度函数**。

```cypher
CREATE VECTOR INDEX doc_vec IF NOT EXISTS
FOR (d:Doc) ON (d.embedding)
OPTIONS {indexConfig: {
  `vector.dimensions`: 4,
  `vector.similarity_function`: 'COSINE'
}};
```

本仓实测索引配置（`SHOW INDEXES`，provider `vector-2.0`）：

```text
name     : doc_vec
type     : VECTOR
labels   : ["Doc"]
property : ["embedding"]
config   : {`vector.dimensions`: 4,
            `vector.similarity_function`: "COSINE",
            `vector.hnsw.m`: 16,
            `vector.hnsw.ef_construction`: 100,
            `vector.quantization.enabled`: TRUE}
provider : "vector-2.0"
```

| 概念 | 说明 | 选择建议 |
|---|---|---|
| **维度** | 向量长度，必须与写入的 embedding 一致 | **由 embedding 模型决定**（不是越大约好） |
| **相似度函数** | `COSINE` / `EUCLIDEAN`（欧几里得） | 文本语义常用 **余弦**；量纲敏感的用欧氏 |
| **HNSW `m` / `ef_construction`** | 图结构与建图精度参数 | 默认（16 / 100）够用；召回不够再调大 |
| **量化（quantization）** | 压缩向量以省内存 | 本仓默认 **开启**；高维大库时收益明显 |

⚠️ **两个必须记住的约束**：

1. **维度写死**：索引声明 `dimensions: 4`，写入的 embedding 就必须是 4 维 —— 换 embedding 模型
   （维度变了）**必须重建索引并重算所有向量**。生产里"换模型"是个大动作，要提前规划。
2. **它是"近似"检索**：HNSW 给的是**近似最近邻**（ANN），不是精确 top-K。
   `m` / `ef_construction` / 查询时的候选数都会影响召回率 —— 这是**速度与召回**的取舍。

⭐ **5.11 之前 vs 之后**：之前要自己做 kNN（全表算距离，O(n)），5.11 之后交给 **HNSW 索引**，
大库上才可用。本仓 `user_emb_vec`（dim 4 / cosine）与 `doc_vec` 都是这一代原生向量索引。

⚠️ **2026.02 的"带过滤的向量搜索"（GA）在本仓 5.26 上不可用**，属官方 Release Notes 陈述：
它把**属性过滤下推到索引内部**（而不是"先取 top-K 再过滤"，后者会因过滤而召回不足）。
这是向量检索的一个重要优化方向，用到时以官方文档为准。

---

## 二、Cypher SEARCH 子句（2026.01+）

**本节要点**：`SEARCH` 是**向量搜索的原生 Cypher 语法**，用来替代
`CALL db.index.vector.queryNodes(...)` 这种**过程调用**写法，让向量检索"长得像普通 Cypher"。

```cypher
// 2026.01+ 的 SEARCH 写法（语法示意，⚠️ 本仓 5.26 不可用、未实测）
MATCH (d:Doc)
SEARCH d IN (VECTOR INDEX doc_vec FOR [1.0, 0.0, 0.0, 0.0] LIMIT 5) SCORE AS score
RETURN d.id AS id, score;
```

⚠️ **声明边界**：`SEARCH` 语法**在本仓 5.26 上不存在**，上面是**按官方文档整理的示意**，
**未实测**。能实测的是**当前主流的 `db.index.vector.queryNodes` 过程调用**（见第三节）。

| 维度 | `db.index.vector.queryNodes`（过程） | `SEARCH`（子句，2026.01+） |
|---|---|---|
| 形态 | `CALL … YIELD node, score` | 内联在 `MATCH` 里 |
| 可组合性 | 结果是过程输出，写在 `CALL` 链里 | **可与其它模式更自然地组合**（更像声明式查询） |
| 带过滤 | 需要自己写 `WHERE`（top-K 后再过滤） | 官方支持**索引内过滤**（2026.02 GA） |
| 本仓状态 | ✅ 实测可用 | ❌ 5.26 无此语法，**未实测** |

⭐ **迁移视角**：`SEARCH` 不是新能力，是**同一能力的语法进化** ——
把"过程调用"变成"查询子句"。对已有代码，`queryNodes` 仍是兼容选项；新代码可关注 `SEARCH`。

---

## 三、混合检索

**本节要点**：单一检索各有短板 —— **向量**强在语义、弱在精确关键词；**全文**强在精确词、弱在语义；
**图遍历**强在"关系上下文"、弱在"从哪开始"。**混合检索**就是把它们串起来：**一个召回、另一个打分/过滤**。

```text
       用户查询
          │
   ┌──────┴───────┐
   ▼              ▼
向量召回       全文召回         ← 各自给一批候选（带分数）
（语义相近）   （精确关键词）
   └──────┬───────┘
          ▼
     分数融合 / 交集 / 加权      ← 归一化后合并（本仓演示：向量分 + 关键词加分）
          │
          ▼
     图遍历扩展（可选）          ← 沿关系把"上下文节点"一并捞出来
          │
          ▼
        最终结果
```

### 3.1 纯向量 vs 纯全文（本仓实测）

```cypher
// 纯向量：语义相近（query = [1,0,0,0]）
CALL db.index.vector.queryNodes('doc_vec', 5, [1.0, 0.0, 0.0, 0.0])
YIELD node, score
RETURN node.id AS id, node.topic AS topic, round(score, 4) AS score;
```

```text
id, topic,     score
1,  "graph",   1.0
5,  "graph",   0.9969
6,  "vector",  0.556
8,  "cluster", 0.5009
7,  "tx",      0.5009
```

```cypher
// 纯全文：精确关键词「存储」
CALL db.index.fulltext.queryNodes('doc_ft', '存储')
YIELD node, score
RETURN node.id AS id, node.topic AS topic, round(score, 4) AS score;
```

```text
id, topic,   score
5,  "graph", 1.1645
1,  "graph", 1.0564
```

⭐ **读法**：向量检索给出了"语义排序"（`graph` 主题最靠前，但 `vector`/`cluster` 也进来了）；
全文检索**只**给出含"存储"的两个（`5`、`1`）。
**两者都不是完美答案** —— 所以需要融合。⚠️ 注意两个分数**量纲不同**（向量是 `[-1,1]`/`[0,1]`，
Lucene 全文分是无上界实数 1.16 这种），**融合前必须归一化**。

### 3.2 混合：向量召回 + 关键词加分（本仓实测）

```cypher
CALL db.index.vector.queryNodes('doc_vec', 5, [1.0, 0.0, 0.0, 0.0])
YIELD node, score AS vecScore
WITH node, vecScore,
     CASE WHEN node.text CONTAINS '存储' THEN 1.0 ELSE 0.0 END AS kw
RETURN node.id AS id, node.topic AS topic,
       round(vecScore, 4) AS vec, kw,
       round(vecScore + kw, 4) AS hybrid
ORDER BY hybrid DESC;
```

```text
id, topic,     vec,     kw,  hybrid
1,  "graph",   1.0,     1.0, 2.0
5,  "graph",   0.9969,  1.0, 1.9969
6,  "vector",  0.556,   0.0, 0.556
8,  "cluster", 0.5009,  0.0, 0.5009
7,  "tx",      0.5009,  0.0, 0.5009
```

⭐ **这就是最朴素的"分数融合"**：向量分 + 关键词加分。要点有三：

- **归一化**：本例的关键词加分是 `0/1`（粗），真实系统要把全文分**归一到 `[0,1]`** 再加权
  （`w1 × 向量分 + w2 × 全文分`），否则量纲大的那一侧会主导；
- **加权要可调**：`w1` / `w2` 是业务参数（偏语义还是偏精确），要靠评测集调；
- **可以只做交集**：也可以"向量召回 → 全文过滤"（只要两边都命中的），召回更保守。

### 3.3 混合：向量召回 + 图遍历扩展（本仓实测）

```cypher
// 向量召回种子 → 沿 FOLLOWS 扩一跳 → 把"关系上下文"一并带出来
CALL db.index.vector.queryNodes('user_emb_vec', 3, [1.0, 0.0, 0.0, 0.0])
YIELD node AS seed, score
MATCH (seed)-[:FOLLOWS]->(n)
RETURN seed.id AS seed, count(n) AS neighbors, round(score, 4) AS score;
```

```text
seed, neighbors, score
60,   3,         0.9977
90,   3,         0.9977
30,   3,         0.9977
```

⚠️ **一个诚实的提醒**：本仓 `:User` 的 embedding 是**合成向量**（几乎共线），
所以余弦分**几乎全是 `0.9977`，没有区分度** —— 和 [GDS图算法实战.md](GDS图算法实战.md) 里
"规则图上的 PageRank 也没区分度"是同一类现象。**演示的是机制（向量召回 + 图扩展）而不是排序质量**；
真实 embedding（语义分布分散）才会有明显分差。

⭐ **"向量 + 图遍历"是 GraphRAG 的关键动作**：向量找到"语义入口"，图遍历把
**入口周围的实体、关系、邻域**一并取出来，给 LLM 的上下文就不再是"一段孤立文本"，
而是**带结构的知识片段**（下一节）。

---

## 四、GraphRAG

**本节要点**：**GraphRAG = 知识图谱 + 向量检索 + LLM**。它在传统 RAG 的"向量检索"之外，
多了**沿图的多跳扩展**，让 LLM 拿到的上下文**带实体关系**而非孤立文本块。

| 维度 | 传统 RAG（向量库） | GraphRAG（图 + 向量） |
|---|---|---|
| 上下文单位 | 文本块（chunk），彼此独立 | **实体 + 关系**（带结构） |
| 检索方式 | 向量相似度 top-K | 向量召回 **+ 图遍历扩展**（多跳） |
| 多跳推理 | ❌ 靠"多捞几个 chunk" | ✅ 沿关系一跳跳走出来 |
| 可解释性 | 弱（"就是这段文字像"） | **强**（能说清"经过哪条关系推出来的"） |
| 成本 | 低（只需向量库） | 高（要建图、维护实体关系） |

```text
传统 RAG：问题 → 向量检索 → 若干文本块 → LLM → 答案
GraphRAG：问题 → 实体抽取 → 向量检索定位实体 → 图谱遍历扩展（关系/邻域）
              → 组装"带关系的上下文" → LLM → 答案（可回溯到具体关系）
```

⭐ **官方生态**：**`neo4j-graphrag-python`** 是官方提供的 GraphRAG 库，
把"实体抽取 → 图谱检索 → 上下文组装 → 生成"这套流程封装成可复用的组件。
典型流程（**⚠️ 本仓无 LLM 环境，以下为文档陈述，未实测**）：

1. **问题 → 实体抽取**：从用户提问里抽出实体（可用 LLM 或 NER）；
2. **实体定位**：用**向量索引**在图里找到最匹配的实体节点（= 本节第三节的向量召回）；
3. **图检索**：从这些实体**沿关系扩展**（1~2 跳），把关联实体与关系一并取出；
4. **上下文增强**：把"实体 + 关系 + 原文块"拼成给 LLM 的上下文；
5. **LLM 生成**：生成答案，并可**回溯到具体的关系路径**（这是可解释性的来源）。

⚠️ **必须说清的边界**：本仓**没有 LLM 环境**，GraphRAG 的**生成与效果对比未实测**；
能实测的是它的**检索底座**（向量索引 + 图遍历，即第三、三节）。⚠️ 另外
**"GraphRAG 一定更强"是错误的** —— 它换来可解释性与多跳能力，代价是**建图与维护成本**；
简单问答场景里传统 RAG 往往更划算。

---

## 五、Agentic AI 集成

**本节要点**：**GDS Agent（MCP Server）** 让 LLM / 智能体**直接调用图算法**，
把"图分析"变成智能体的一项工具；**Aura Graph Analytics** 提供按需的图计算环境。

| 能力 | 是什么 | 说明 |
|---|---|---|
| **GDS Agent（MCP Server）** | 把 GDS 算法暴露成 **MCP 工具** | 智能体可调用 PageRank / Louvain / 最短路等，自动完成"分析 → 解读" |
| **图算法自动执行** | 智能体按需选算法、传参、读结果 | 与 [GDS图算法实战.md](GDS图算法实战.md) 第七节同一主题 |
| **智能报告生成** | 算法结果 → LLM 解读 | 社区检测 / 中心性结果转成自然语言情报 |
| **Aura Graph Analytics** | 托管的**按需**图计算 | 免去自己管投影与内存 |

⚠️ **本节为 2026 新方向，基于官方文档与 Release Notes 整理，本仓未实测**（社区版无 MCP 服务、无 Aura）。
理解要点即可：**GraphRAG 是"检索增强"**（用图增强 LLM 的输入），
**GDS Agent 是"分析增强"**（用 LLM 调度图算法）—— 两者是 LLM 与图库结合的两条互补路径。

---

## 使用：搭一条"向量召回 → 混合排序 → 图扩展"的检索链

**本节要点**：把第三节的三个动作串成一条**可复现**的检索链，每步给本机真实读数。

```cypher
// ① 建向量索引（维度与相似度函数是建索引时定死的）
CREATE VECTOR INDEX doc_vec IF NOT EXISTS
FOR (d:Doc) ON (d.embedding)
OPTIONS {indexConfig: {
  `vector.dimensions`: 4,
  `vector.similarity_function`: 'COSINE'
}};

// ② 建全文索引（精确关键词的那一路）
CREATE FULLTEXT INDEX doc_ft IF NOT EXISTS FOR (d:Doc) ON EACH [d.text];

// ③ 等索引 ONLINE
SHOW INDEXES YIELD name, type, state
WHERE name IN ['doc_vec', 'doc_ft'] RETURN name, type, state;

// ④ 向量召回（语义路）
CALL db.index.vector.queryNodes('doc_vec', 5, [1.0, 0.0, 0.0, 0.0])
YIELD node, score AS vecScore
WITH node, vecScore,
     CASE WHEN node.text CONTAINS '存储' THEN 1.0 ELSE 0.0 END AS kw
RETURN node.id AS id, node.topic AS topic,
       round(vecScore, 4) AS vec, kw, round(vecScore + kw, 4) AS hybrid
ORDER BY hybrid DESC;

// ⑤ 图扩展（把召回节点的关系上下文一并取出来，供 LLM 组装）
CALL db.index.vector.queryNodes('doc_vec', 3, [1.0, 0.0, 0.0, 0.0])
YIELD node AS seed, score
MATCH (seed)-[:RELATED]->(n)
RETURN seed.id AS seed, collect(n.id) AS related;
```

实测读数（内核 5.26.31，`doc_vec` dim 4 / COSINE）：

```text
doc_vec / doc_ft : ONLINE
纯向量 top5      : 1 → 1.0 ; 5 → 0.9969 ; 6 → 0.556 ; 8 → 0.5009 ; 7 → 0.5009
纯全文「存储」    : 5 → 1.1645 ; 1 → 1.0564
混合（vec+kw）    : 1 → 2.0 ; 5 → 1.9969 ; 6 → 0.556 ; 8 → 0.5009 ; 7 → 0.5009
向量 PROFILE     : ProcedureCall → DB Hits 5
```

⭐ **判据表**：

| 观察 | 判 |
|---|---|
| 向量检索返回**分差明显** | embedding 有语义区分度（本仓 `:Doc` 是；`:User` 的合成向量无） |
| 向量与全文的**分数不可直接比** | 量纲不同（向量归一、全文无上界）→ **融合前必须归一化/加权** |
| 只取向量 top-K 就够 | 语义检索够用时别加复杂度；需要精确词约束时才上混合 |
| 需要"入口 + 周边实体" | 加**图遍历扩展**（GraphRAG 的关键动作） |
| 索引 `state` 不是 `ONLINE` | 索引还在建，检索会报错或退化 |

---

## 延伸追问

- **向量索引和全文索引怎么配合？**
  → 三种配合法：**①交集**（向量召回后再用全文过滤，召回更保守准确）；
  **②加权融合**（两路各给分，归一化后 `w1·vec + w2·full`，本仓演示就是这一类的简化版）；
  **③分工**（向量负责"语义入口"，全文负责"精确词约束"）。
  ⚠️ **融合的前提是归一化**：本仓实测向量分在 `[0,1]`、Lucene 全文分能到 `1.16+`（无上界），
  直接相加会被量纲大的那侧主导。另外**两者可以都不够** —— 需要"关系上下文"时就得上图遍历。
- **GraphRAG 比传统 RAG 强在哪？**
  → 强在**多跳推理与可解释性**：传统 RAG 的上下文是**互相独立的文本块**，"A 的上级的部门在哪"
  这类**多跳问题**只能靠"多捞几块"碰运气；GraphRAG 沿**关系**一跳跳走出来，能覆盖多跳，
  而且**答案能回溯到具体的关系路径**（"我是从 A-[:REPORTS_TO]->B-[:IN_DEPT]->C 推出来的"）。
  ⚠️ 代价是**建图与维护成本**（要抽实体、建关系、保证质量）—— 简单问答场景别硬上。
- **向量维度太高怎么办？**
  → 三条路：**①量化（quantization）**压缩向量省内存（本仓索引默认已开启
  `vector.quantization.enabled`）；**②降维**（用更小的 embedding 模型 / PCA 等，但会掉召回）；
  **③换索引/分片**（大库按业务分片检索）。⚠️ 但要先问"维度真的高吗" ——
  维度由 **embedding 模型**决定（常见 768 / 1024 / 1536），**不是随便调参能改的**；
  换维度意味着**重建索引 + 重算全部向量**，是迁移级动作，要提前规划。
- **`db.index.vector.queryNodes` 和 `SEARCH` 该用哪个？**
  → 现在（5.26 及之前）**只能用 `queryNodes`**；`SEARCH` 是 **2026.01+ 的新语法**（本仓未实测），
  本质是把过程调用内联成查询子句，并支持**索引内过滤**（2026.02 GA）。
  ⚠️ 所以"用哪个"是**版本问题**不是偏好问题：先看你的版本支持什么。
- **什么时候不该用向量索引？**
  → ①**精确匹配**能解决时（`u.id = 50000` 走范围索引，比向量快几个数量级）；
  ②**图结构本身就是答案**时（"谁关注我"是遍历，不是相似度）；
  ③**没有可靠的 embedding** 时（用随机/劣质向量做检索，结果没有意义）。
  向量索引的定位是"**语义召回**"，不是万能查询。

---

## 关联

- [README.md](README.md) — 本目录导读：版本坐标与阅读顺序
- [索引设计与查询优化.md](索引设计与查询优化.md) — 五类索引全景里向量索引的位置、与全文索引的分工
- [GDS图算法实战.md](GDS图算法实战.md) — FastRP 生成的节点嵌入可写入属性、再建向量索引检索
- [数据建模与导入.md](数据建模与导入.md) — 向量与文本怎么随数据导入、维度与类型的一致性
- [Cypher查询与执行计划.md](Cypher查询与执行计划.md) — `CALL` 过程输出在计划里长什么样（`ProcedureCall` 算子）
- [架构与存储引擎.md](架构与存储引擎.md) — 向量索引是独立于记录存储的索引文件
- [选型与落地案例.md](选型与落地案例.md) — 向量与 GraphRAG 能力如何影响图库选型
- [向量与AI搜索.md](../../搜索与分析/Elasticsearch/向量与AI搜索.md) — 另一种技术栈（ES）里的向量检索与混合检索
> 反向引用（本篇被下列文档引到）：[图数据库选型对比.md](../图数据库选型对比.md)
