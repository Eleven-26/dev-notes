# MetricsQL 查询

> MetricsQL 是 VictoriaMetrics 的查询语言，**向后兼容 PromQL**，但在一批具体行为上
> 故意做得「更符合直觉」：`rate()` 会算上窗口前的那个样本、不做外推、计数型的结果不再出现小数。
> 本篇逐条讲清这些差异与增强函数（`rollup*`、`keep_metric_names`、`alias`、`union`、
> `WITH`、`limit_offset`），并给出一批可直接抄的查询示例。
>
> 内容整理自个人学习笔记，并参考官方文档与 Release Notes；素材见 [素材清单](../../../../素材清单.md)。

---

## 一、MetricsQL 和 PromQL 到底是什么关系？

**本节要点**：不是「另起一门语言」，而是「PromQL 的超集 + 若干**有意的行为差异**」。
理解这点，才能解释「为什么换了后端、Grafana 面板照跑，但个别曲线对不上」。

### 1.1 「向后兼容」的确切含义

官方口径很明确：**MetricsQL 实现了 PromQL，并在此基础上追加功能**；
因此「用 Prometheus 数据源做的 Grafana 面板，切到 VM 后应当表现一致」。
官方还单独提供了 **standalone MetricsQL 解析库**，可以在外部应用里解析 MetricsQL。

但紧接着官方就列了一串**有意为之的差异**（见下节）。所以准确的表述是：
**语法与绝大多数语义兼容，少数函数的行为不同，且这些不同是「故意改好」而非「实现缺陷」**。

### 1.2 兼容性到底测出来多少？

官方用 Prometheus 的兼容性测试工具（`prometheus/compliance`）做过多轮对照，
在一次公开记录的测试中（**VictoriaMetrics v1.67.0 对照 Prometheus v2.30.0**）
结果是 **385 / 529 通过（约 72.78%）**，0 个 unsupported。

⚠️ 关键在于**失败的 149 条是怎么分布的**——官方自己把失败归成了几类：

| 失败类型 | 占比（官方统计） | 性质 |
| --- | --- | --- |
| 结果里**保留了 metric name** | 约 17%（92/529） | 有意增强（见 4.1） |
| `rate` / `increase` / `deriv` / `irate` 等**算法不同** | 约 7%（39/529） | 有意增强（见第二节） |
| NaN 处理不同 | 约 1%（6/529） | 有意增强 |
| 负 offset 行为不同 | 约 0.5%（3/529） | 历史实现差异 |
| 精度损失（压缩算法不同导致末位差） | 约 0.5%（3/529） | 压缩换空间的取舍 |

⭐ 结论：**「兼容性百分比」要连它的分母和失败构成一起看**。
这里绝大多数「不兼容」是**用户更想要的那个结果**，不是能力缺口。

---

## 二、`rate()` / `increase()` 为什么和 Prometheus 算得不一样？

**本节要点**：这是两门语言最实质、也最容易被误判为「bug」的差异。
症结在**窗口边界取哪个样本**，以及**要不要外推**。

### 2.1 取窗口之前的上一个样本

设某 counter 的样本序列是 `V1 V2 V3 V4 V5`，两个相邻窗口分别覆盖 `{V2,V3}` 与 `{V4,V5}`。

- **Prometheus**：用「本窗口最后一个样本 − 本窗口第一个样本」，
  即 `V3−V2` 与 `V5−V4`——**丢掉了跨窗口的那段增长**；
- **MetricsQL**：用「本窗口最后一个样本 − 窗口开始前的那个样本」，
  即 `V3−V1` 与 `V5−V3`——**把窗口之间的增量补上了**。

```text
样本:        V1        V2        V3        V4        V5
窗口:                  [--- win1 ---]      [--- win2 ---]
Prometheus:  rate ≈ V3-V2             rate ≈ V5-V4   ← 漏掉跨窗口增量
MetricsQL:   rate ≈ V3-V1             rate ≈ V5-V3   ← 包含跨窗口增量
```

⚠️ 另一个副作用：**Prometheus 在某些情形下会返回空**——
当窗口内只有一个样本时，`rate` / `increase` 无从计算；而 MetricsQL 因为会参考窗口前的样本，
**在 `step` 小于真实采集间隔时依然返回非空结果**（这正是 Grafana 上「曲线断断续续」的常见来源）。

### 2.2 不做外推（no extrapolation）

Prometheus 会把 `rate` / `increase` 的结果**外推到整个窗口**，于是对整数计数器
`increase()` 也会吐出小数；MetricsQL **不做外推**，所以
**对变化缓慢的整数计数器，`increase()` 会返回整数结果**，更贴近「这段时间确实涨了几个」。

⭐ 一句话记忆：**MetricsQL 的 `rate` / `increase` 更接近「真实增量」，Prometheus 的更接近「线性估计」**。

### 2.3 想要 Prometheus 的老口径：`*_prometheus` 系列

凡是首尾样本参与计算的 rollup 函数，两边都会有差异。VM 为此提供了**按 Prometheus 逻辑计算**的
同族函数，命名规则是加后缀 `_prometheus`：

```text
rate_prometheus(m[5m])
delta_prometheus(m[5m])
increase_prometheus(m[5m])
```

⭐ 用途：**跨后端对账 / 迁移期做结果比对时**，用 `_prometheus` 版本才能和原 Prometheus 对齐；
日常看板用默认版本即可。做 PromQL 兼容性验证时，也可以用这些函数把「算法差异」这一项排除掉。

---

## 三、rollup 系列函数解决什么问题？

**本节要点**：一个窗口里其实有「最小 / 最大 / 平均」等多个值值得同时看。
与其跑三条查询，rollup 系列**一次返回多组结果**，用额外标签区分。

### 3.1 `rollup` / `rollup_rate` / `rollup_increase` / `rollup_delta` / `rollup_deriv`

这些函数对窗口 `d` 内的原始样本做聚合，返回**min / max / avg 三组值**，
用 `rollup="min"|"max"|"avg"` 的额外标签区分：

| 函数 | 算的是什么 |
| --- | --- |
| `rollup(m[d])` | 窗口内样本值的 min / max / avg |
| `rollup_rate(m[d])` | 窗口内相邻样本**每秒变化率**的 min / max / avg |
| `rollup_increase(m[d])` | 窗口内相邻样本**增量**的 min / max / avg |
| `rollup_delta(m[d])` | 窗口内相邻样本**差值**的 min / max / avg |
| `rollup_deriv(m[d])` | 窗口内相邻样本**每秒导数**的 min / max / avg |

⚠️ 官方提示：这些函数**默认会剥掉 metric name**，需要保留就加 `keep_metric_names` 修饰符。

⭐ 实战价值举例：一条 `rollup_rate(http_requests_total[5m])` 能同时回答
「这段时间速率最低多少、最高多少、平均多少」——**波动范围一眼可见**，
比只看一条 `rate()` 曲线信息量大得多。

### 3.2 `rollup_candlestick`：一次拿到 OHLC

`rollup_candlestick(m[d])` 计算窗口内的 **开、高、低、收（OHLC）**，
分别放在 `rollup="open"|"high"|"low"|"close"` 标签上返回。官方明确说它**面向金融类场景**，
但用在「响应时间 / 资源占用」这类指标上，也能直观看出一个周期内的全貌。

### 3.3 `rollup_scrape_interval` 与 `scrape_interval`

这两个函数用来**反查采集间隔**——排查「曲线有空洞」时很有用：

| 函数 | 返回 |
| --- | --- |
| `scrape_interval(m[d])` | 窗口内**平均**采集间隔（秒） |
| `rollup_scrape_interval(m[d])` | 窗口内相邻样本间隔的 **min / max / avg** |

⭐ 用法：如果 `rollup_scrape_interval(m[5m])` 的 `max` 明显大于你配置的采集间隔，
说明**抓取有抖动或丢点**——问题在采集侧（网络延迟、target 不响应），不在存储/查询侧。

---

## 四、名字和标签怎么在计算后保住？

**本节要点**：PromQL 的约定是「函数改变语义后就丢掉 metric name」，这在多指标一起算时
会直接报 `duplicate time series`。MetricsQL 给了三个层面的解法。

### 4.1 `keep_metric_names`

默认情况下，函数算完后 metric name 会被去掉。当**一个函数作用在多个不同名的序列**上时，
去掉名字会导致多个结果挤成同一组标签、直接报错。`keep_metric_names` 修饰符能阻止这一去名：

```text
# 结果里保留 foo 与 bar 两个 metric name，不再报 duplicate
rate({__name__=~"foo|bar"}[5m]) keep_metric_names

# min_over_time / round 这类「不改变原始含义」的函数，VM 默认就保留 metric name
min_over_time(foo[1h])
```

⭐ 官方补充：对于 `*_over_time`、`ceil`、`floor`、`round`、`clamp_*`、`holt_winters`、
`predict_linear` 这类**不改变原始含义**的函数，VM **默认就保留** metric name——
这也是「约 17% 兼容性用例失败」的来源，但对用户是好事。

### 4.2 `alias`

`alias(q, name)` 把 `q` 返回的所有序列的 metric name **统一改成 `name`**：

```text
alias(rate(http_requests_total[5m]), "http_req_rate")
```

### 4.3 `label_*` 函数族

MetricsQL 提供一整套标签操作函数，比 PromQL 的 `label_replace` 更成套：

| 函数 | 作用 |
| --- | --- |
| `label_set(q, k1, v1, ...)` | 设置（新增/覆盖）标签值 |
| `label_del(q, k1, ...)` | 删除标签 |
| `label_keep(q, k1, ...)` | 只保留列出的标签 |
| `label_copy(q, src, dst, ...)` | 复制标签值 |
| `label_move(q, src, dst, ...)` | 移动（重命名）标签 |
| `label_map(q, label, srcV1, dstV1, ...)` | 按值映射改写标签值 |
| `label_transform(q, label, regexp, replacement)` | 按正则改写标签值 |
| `label_value(q, label)` | 把某标签的**数值**取出来当作样本值 |
| `label_match(q, label, regexp)` / `label_mismatch(...)` | 按标签正则过滤序列 |

---

## 五、`union`、`WITH` 模板与 `limit_offset` 怎么用？

**本节要点**：这三个是「查询组织层」的糖——`union` 合并多路结果，
`WITH` 抽公共过滤条件，`limit_offset` 做分页。

### 5.1 `union` 与省略函数名的写法

`union(q1, ..., qN)` 把多路子查询的结果**合并到同一个返回集**里。官方还给了一个语法糖：
**函数名可以省略**，`(q1, q2)` 与 `union(q1, q2)` 等价。

```text
union(
  {__name__="cpu_usage", host="web01"},
  {__name__="memory_usage", host="web01"},
)

# 等价写法（省略函数名）
({__name__="cpu_usage", host="web01"}, {__name__="memory_usage", host="web01"})
```

⭐ 用途：**面板上把几条「不同名但想同屏看」的曲线并成一条查询**，
少开几个 target，也少几轮网络往返。

### 5.2 `WITH` 模板表达式

`WITH` 用来抽取**公共过滤条件或公共子表达式的参数**，让复杂查询可读、可复用；
官方还支持**字符串字面量拼接**，因此可以拼出 metric name：

```text
WITH (
  commonFilter = {job="api", env="prod"},
  errorRate(m) = rate(m{status=~"5.."}[5m]) / rate(m[5m]),
)
errorRate(http_requests_total{commonFilter})

WITH (prefix="long_metric_prefix_")
  {__name__=prefix+"suffix1"} / {__name__=prefix+"suffix2"}
```

⚠️ 官方提示：**列表里最后一个逗号是可接受的**（label 过滤、函数参数、`WITH` 表达式都支持），
方便脚本自动生成查询。

### 5.3 `limit_offset` 与聚合的 `limit N`

两个「限制规模」的机制，用途不同：

| 语法 | 语义 |
| --- | --- |
| `limit_offset(limit, offset, q)` | 从 `q` 的结果里**跳过 offset 条、返回最多 limit 条**，可做简单分页 |
| `limitk(k, q)` | 限制 `q` 返回的序列数到 k 条 |
| `sum(x) by (y) limit 3` | **聚合函数后缀 `limit N`**，只保留聚合后的前 N 条序列 |

```text
limit_offset(10, 20, sum by (pod) (rate(http_requests_total[5m])))
sum(x) by (y) limit 3
```

---

## 六、`histogram_quantile` 在 VM 里要注意什么？

**本节要点**：分位数为什么必须「先聚合桶再算」属于指标侧的通用原理，**本仓未单独成篇**；
本节只讲 **VM 侧特有的两点**：桶的两套标签形态，以及桶数量失控。

### 6.1 两套 bucket 形态：`le` 与 `vmrange`

VM 既能吃 **Prometheus 形态**的桶（用 `le` 标签表示上界、累积计数），
也能吃 **VictoriaMetrics 自己的形态**（用 `vmrange` 标签表示区间、非累积计数）。
相关的转换与聚合函数：

| 函数 | 作用 |
| --- | --- |
| `histogram_over_time(m[d])` | 对窗口内原始样本生成 VM 形态的直方图 |
| `histogram(q)` | 对 `q` 的每条曲线在每个时间点算聚合直方图 |
| `prometheus_buckets(buckets)` | 把 VM 形态（`vmrange`）桶转成 Prometheus 形态（`le`）桶 |
| `buckets_limit(k, q)` | 把每条曲线的桶数限制到 k，并**顺带转成 `le` 形态** |

⭐ 典型组合（用 VM 自产直方图算中位数）：

```text
histogram_quantile(
  0.5,
  sum(histogram_over_time(temperature[24h])) by (vmbucket, country)
)
```

### 6.2 bucket 数量失控与 `buckets_limit`

⚠️ 直方图指标的另一类成本是**桶数量**：桶越多，每条序列的 `_bucket` 子序列越多，
基数越高。当上游给的桶太多，导致查询返回的序列炸开时，`buckets_limit` 可以把桶数压下来。
官方也提供了 `histogram_quantiles`（一次算多个分位数）等扩展函数，减少重复查询。

---

## 使用：可以直接抄的查询示例

下面每一段都可以直接丢进 vmui 或 Grafana（把指标名换成你自己的）：

```text
# ① 速率：显式窗口（最通用）
sum by (status) (rate(http_requests_total{job="api"}[5m]))

# ② 速率：省略窗口 —— VM 按 step / 真实采集间隔自动选窗口
rate(node_network_receive_bytes_total)

# ③ 聚合 + topk：先按 service 聚合错误率，再取 top5
topk(5, sum by (service) (rate(http_requests_total{status=~"5.."}[5m])))

# ④ 分位数：经典 Prometheus 直方图（注意 by 里必须带 le）
histogram_quantile(
  0.95,
  sum by (job, le) (rate(http_request_duration_seconds_bucket[5m]))
)

# ⑤ 预测：用最近 1 小时的线性趋势外推 4 小时，判断磁盘是否将耗尽
predict_linear(node_filesystem_avail_bytes[1h], 4 * 3600) < 0

# ⑥ 告警表达式：错误率 > 5%
sum(rate(http_requests_total{status=~"5.."}[5m]))
  / sum(rate(http_requests_total[5m])) > 0.05

# ⑦ rollup：一次拿到速率的最低/最高/平均
rollup_rate(http_requests_total[5m])

# ⑧ SLO：过去 24h 服务可用的时间占比
share_gt_over_time(up[24h], 0)

# ⑨ 合并多路结果，同屏显示
union(
  sum(rate(http_requests_total[5m])),
  sum(rate(grpc_requests_total[5m])),
)
```

几条写查询的通用纪律：

- **告警表达式尽量用 `rate` + 聚合**，别用瞬时值直接比阈值，否则容易抖动误报；
- 需要「持续 N 分钟才告警」时用 `for` 子句（vmalert / Prometheus 规则里），
  表达式本身只负责算出「是否越界」；
- 迁移期做结果对账，用 `*_prometheus` 函数排除算法差异；
- ⚠️ 别在表达式里放大范围无锚点正则，那是**索引侧**的成本（见
  [索引设计与查询优化.md](索引设计与查询优化.md) 第四节）。

---

## 延伸追问

- **MetricsQL 会不会哪天不再兼容 PromQL？** → 官方把它定位成「PromQL 的超集 + 有意改好的差异」，
  并用 Prometheus 官方兼容性工具持续对照。真正的风险不是语法，而是**少数函数结果值不同**
  （`rate` 系、NaN、负 offset）——迁移前应挑几条代表性查询做结果比对。
- **为什么 `rate()` 省略窗口是安全的？** → VM 会根据 `/api/v1/query_range` 的 `step` 与**真实样本间隔**
  自动选回溯窗口；对 `rate` / `default_rollup` 会取 `max(step, scrape_interval)`，避免 `step` 过小出现空洞。
- **`keep_metric_names` 什么时候必须加？** → 当你**用一个函数作用在多个不同名的序列上**、
  且结果希望能区分彼此的时候。不加就可能撞上 `duplicate time series`。
- **`union` 和「一个正则选中多个指标名」有什么区别？** → 正则写法会把不同名的序列挤成同一组标签，
  一旦外面套了会去名的函数就报错；`union` 是显式合并，**语义清晰、不会因此报错**。
- **VM 直方图的 `vmrange` 桶是怎么来的？** → 由 `histogram_over_time(m[d])` / `histogram(q)` 这类函数
  对原始样本现算出来，桶边界是 VM 自己的区间表示；需要给 Prometheus 形态时用
  `prometheus_buckets` / `buckets_limit` 转换。
- **`WITH` 模板会不会拖慢查询？** → 它只是**文本层的宏展开**，解析期就把公共部分替换掉了，
  运行时没有额外开销。

---

## 关联

- [索引设计与查询优化.md](索引设计与查询优化.md) — 本篇讲「怎么算」，索引篇讲「先能查到哪些序列」
- [与Prometheus生态的关系.md](与Prometheus生态的关系.md) — 兼容性的来龙去脉，以及 VM 在 Prometheus 生态里的位置
- [数据模型与写入路径.md](数据模型与写入路径.md) — 指标、标签、样本的基本概念与写入侧
- [可观测性选型.md](../../../../06-工程实践/可观测性/可观测性选型.md) — 可观测性栈层面的三方选型对比
- [选型与落地案例.md](选型与落地案例.md) — 从 Prometheus 迁到 VM 时的查询对账方法
> 反向引用（本篇被下列文档引到）：[PromQL兼容查询.md](../GreptimeDB/PromQL兼容查询.md)、[性能调优与基准测试.md](性能调优与基准测试.md)
