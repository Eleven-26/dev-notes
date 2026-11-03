# PRAGMA 与调优

> 覆盖：PRAGMA 的四类划分（持久 / 连接级 / 一次性 / 危险）、`synchronous` 的真实代价由什么决定、
> `cache_size` 与 `mmap_size` 该不该调（含一次负结果）、`page_size` 的改法、
> 必须设的几条、`PRAGMA optimize` 的位置、以及调优的正确顺序。
>
> 不覆盖：`journal_mode` 与 WAL 的机制见 [WAL模式与检查点.md](WAL模式与检查点.md)；
> `busy_timeout` 与 BUSY 处理见 [事务与并发控制.md](事务与并发控制.md)；
> 外键与 `STRICT` 见 [类型系统与数据建模.md](类型系统与数据建模.md)。
>
> 内容整理自个人学习笔记。**实测口径**：本机 Go 1.26.5 + `modernc.org/sqlite`（SQLite 3.54.1），
> Windows 本地盘。`synchronous` 三档各跑两组（每行一事务 500 行 / 一事务 500 行），
> 读数为单次总耗时（毫秒级，远超本机计时粒度）。`cache_size` 那组用 2000 次累计平均。

---

## 一、PRAGMA 分四类，为什么这个分类最重要？

**本节要点**：PRAGMA 不是「参数」，是**能力与开关的混合体**。四类的生效范围完全不同：
一类写进库文件跟一辈子，一类只活在当前连接，一类执行一次就完，一类会直接毁数据。
把连接级当成持久级，是最常见的调优事故。

### 1.1 四类

![PRAGMA分四类](images/PRAGMA分四类.svg)

四行是四类，看第三列「作用范围」——这一列决定你该在哪一层设置它。第一行跟文件走、第二行跟连接走，这两行混起来就会出现「我明明设了但没生效」。

| 类别 | 代表 | 作用范围 | 怎么设才对 |
|---|---|---|---|
| 持久（写进库头） | `journal_mode` / `page_size` / `auto_vacuum` / `user_version` / `application_id` | 跟文件走 | 设一次，之后所有连接都受影响 |
| 连接级 | `cache_size` / `mmap_size` / `busy_timeout` / `foreign_keys` / `temp_store` | 只影响当前连接 | 每条新连接都要设（写进 DSN） |
| 一次性 | `optimize` / `wal_checkpoint` / `integrity_check` / `foreign_key_check` | 执行一次 | 放在合适的生命周期点 |
| 危险 | `synchronous=OFF` / `writable_schema` / `ignore_check_constraints` / `trusted_schema=OFF` | 当前连接 | 除非明确知道后果，否则不碰 |

### 1.2 三个混用会出事的组合

| 误用 | 后果 |
|---|---|
| 以为 `busy_timeout` 设一次全局生效 | 连接池里新连接回到默认 0，随机 BUSY |
| 以为 `cache_size` 是全局缓存大小 | 每个连接各有一份，连接池 10 条就是 10 份 |
| 以为 `foreign_keys` 开了就一直开 | 同上，新连接默认关，外键悄悄失效 |

⭐ 判别口诀：**问「这个设置会不会改变文件内容」**。会 → 持久；不会 → 连接级（或一次性）。

### 1.3 本机读到的默认值

| PRAGMA | 默认值 | 含义 |
|---|---|---|
| `page_size` | 4096 | 页大小，建库后基本改不动 |
| `cache_size` | −2000 | 负数表示 KiB，即约 2 MB |
| `mmap_size` | 0 | 关闭 mmap |
| `journal_mode` | `delete` | rollback journal |
| `max_page_count` | 4294967294 | 页数上限（3.45+ 的默认值） |
| `synchronous` | 2（`FULL`） | 每次提交都 fsync |
| `wal_autocheckpoint` | 1000 | 1000 页触发一次 PASSIVE |

---

## 二、`synchronous` 的代价到底由什么决定？

**本节要点**：`synchronous` 控制「什么时候 fsync」，但**真正决定耗时的是一次会话里有多少次提交**。
一次事务写 500 行只有 1~2 次 fsync；每行一个事务就有 500 次。所以「关掉 synchronous 变快 3 倍」
这个结论只在「每行一提交」的写法下成立。

### 2.1 本机实测

![synchronous四档与fsync次数](images/synchronous四档与fsync次数.svg)

三行是三档，中间两列是同一种写法的两种批次，右列是两者的倍数。读法要横着看：同一档位内 278~484 倍的差是「事务批次」造成的，而同一写法下 OFF→FULL 只差 2.4 倍——后者才是 synchronous 的真实代价。

| 档位 | 每行一事务（500 行） | 一事务 500 行 | 倍数 |
|---|---|---|---|
| `OFF` | 1 359.0 ms | 4.88 ms | 278.6× |
| `NORMAL`（默认） | 2 922.2 ms | 6.04 ms | 483.9× |
| `FULL` | 3 270.7 ms | 7.53 ms | 434.6× |

⭐ 两个可用的结论：

1. **批量化的收益是数量级的**（278~484 倍），远大于调 `synchronous`（2.4 倍）
2. `OFF → FULL` 在同一写法下是 1359 → 3271 ms（2.4 倍），在批量写法下是 4.88 → 7.53 ms（1.54 倍）——
   因为批量写法本来就只有一次 fsync，档位差异被摊薄

### 2.2 三档的语义与代价

| 值 | 名字 | 何时 fsync | 掉电会丢什么 |
|---|---|---|---|
| 0 | `OFF` | 交给操作系统 | 可能丢最近若干事务，甚至库损坏（极端情况） |
| 1 | `NORMAL` | WAL 模式下 checkpoint 时；journal 模式下每次提交 | WAL 下可能丢最近的事务；库不会坏 |
| 2 | `FULL`（默认） | 每次提交 | 已提交的不丢 |
| 3 | `EXTRA` | `FULL` + 目录项同步 | 同上，更严（3.8.6+） |

⚠️ `NORMAL` 的行为**在 WAL 与 journal 模式下不一样**，这是最容易记混的一处：
WAL 模式下 `NORMAL` 是「提交不 fsync、checkpoint 时 fsync」，所以更快但可能丢最近事务；
journal 模式下 `NORMAL` 与 `FULL` 差别较小。

### 2.3 怎么选

| 数据性质 | 选 |
|---|---|
| 不可重建（账、订单、用户数据） | `FULL`（默认，不要动） |
| 可重建（缓存、索引、会话） | `NORMAL` |
| 纯导入中间态、失败可重跑 | `OFF`（导完改回） |
| 移动端（随时被杀） | `FULL` 或 `NORMAL`，别用 `OFF` |

---

## 三、`cache_size` 与 `mmap_size` 该不该调？

**本节要点**：它们是**连接级**缓存，只对「同一批热页被反复访问」的场景有效。
本机实测里没有测出差别，这个负结果本身就是结论：**固定开销会掩盖页缓存差异**。

### 3.1 本机实测：三档 `cache_size` 的随机点查

2000 次主键随机点查（每档先跑 200 次预热）：

| `cache_size` | 平均每次 |
|---|---|
| −2000（默认，约 2 MB） | 101.9 µs |
| −200（约 200 KB） | 117.9 µs |
| −16000（约 16 MB） | 103.2 µs |

三档差别在 ±15% 以内，且**不是单调的**（16 MB 并不比 2 MB 快）。
原因：每次 `QueryRow` 的固定开销（SQL 解析、驱动层、结果封装）约 100 µs，
把页缓存命中的差异盖住了。

⭐ 所以「调大 `cache_size` 就快」在这台机器上**不成立**。
它成立的前提是：单次查询处理的数据量足够大，且热集能被缓存装下——
比如反复扫同一张大表，或做多轮聚合。

### 3.2 什么情况下 `cache_size` 真的有用

| 场景 | 有用吗 |
|---|---|
| 反复扫同一张大表（多轮聚合） | ✅ 热页命中率提升明显 |
| 大量随机点查、每次一条 | ❌ 固定开销占主导（本机实测） |
| 一次性顺序扫描 | ❌ 顺序读不重用页 |
| 内存受限的嵌入式设备 | ⚠️ 要**调小**，不是调大 |

### 3.3 `mmap_size`

`mmap_size` 让 SQLite 用内存映射读文件，省掉一次 `read` 拷贝。默认 0（关闭）。

| 考虑 | 说明 |
|---|---|
| 能省什么 | 读路径的数据拷贝；页缓存与 OS 页缓存合一 |
| 风险 | 映射区大小受地址空间限制；32 位进程要小心 |
| 建议 | 64 位、库不大（几 GB 内）可以设几十 MB~几百 MB；嵌入式别设 |

```sql
PRAGMA mmap_size = 268435456;   -- 256 MiB，0 表示关闭
PRAGMA mmap_size;               -- 读回实际生效值（可能被限制）
```

⚠️ 读回的值可能小于你设的值（受编译选项与平台限制），**以读回值为准**。

---

## 四、`page_size` 什么时候该改？

**设置要点**：页大小建库后基本改不动，要改只能重建。所以这个问题只在两个时点有意义：
**建库前**、**做一次 `VACUUM INTO` 时**。

| 行宽 | 建议页大小 | 理由 |
|---|---|---|
| 小（< 200 字节） | 4096（默认） | 一页装得多，树矮 |
| 中（几百字节到 1 KB） | 4096~8192 | 减少溢出页 |
| 大（> 2 KB，含长 TEXT/BLOB） | 8192~16384 | 溢出阈值翻倍，跨页少 |
| 随机点查为主、写并发高 | 小页（4096 或更小） | 每次读写的数据少 |

```sql
-- 新库：先设页大小再建表
PRAGMA page_size = 8192;
CREATE TABLE ...;

-- 已有库：换页大小要重建
PRAGMA page_size = 8192;
VACUUM;                          -- 原地重建（需要等大临时空间）
-- 或更安全：VACUUM INTO 'new.db'，然后替换文件
```

⚠️ WAL 模式下改 `page_size` 会报错，要先 `PRAGMA journal_mode = DELETE`。

---

## 五、必须设的几条

**设置要点**：下面五条不属于「调优」，属于「不设就会出问题」。建议直接写进 DSN，
而不是散落在代码里。

| PRAGMA | 建议值 | 不设的后果 |
|---|---|---|
| `foreign_keys` | `1` | 外键定义失效（默认 0），级联删除不生效 |
| `busy_timeout` | `2000`~`5000` | 默认 0，冲突立刻失败 |
| `journal_mode` | `WAL`（单机场景） | 读写互斥，并发差 |
| `synchronous` | `NORMAL` 或 `FULL` | 默认 `FULL` 是安全的，别为速度改成 `OFF` |
| `wal_autocheckpoint` | `1000`（默认） | 设 0 会让 `-wal` 无限涨 |

```go
// 一次写全，避免「某个连接忘了设」
dsn := "file:app.db?" +
    "_pragma=journal_mode(WAL)" +
    "&_pragma=busy_timeout(2000)" +
    "&_pragma=synchronous(NORMAL)" +
    "&_pragma=foreign_keys(1)" +
    "&_pragma=wal_autocheckpoint(1000)" +
    "&_pragma=cache_size(-8000)" +
    "&_txlock=immediate"
```

⚠️ `_pragma` 参数是驱动扩展，不是 SQLite 标准。`modernc.org/sqlite` 与
`mattn/go-sqlite3` 都支持；Java 的 `sqlite-jdbc` 用 `SQLiteConfig` 设。
驱动差异见 [架构与执行链路.md](架构与执行链路.md) 第六节。

---

## 六、`PRAGMA optimize` 与统计信息

**设置要点**：优化器的统计信息存在 `sqlite_stat1`，`ANALYZE` 生成它。
`PRAGMA optimize` 是「按需分析」，成本低，适合放在连接关闭前。

| 命令 | 成本 | 什么时候跑 |
|---|---|---|
| `ANALYZE` | 全库扫，可能很慢 | 批量导入后、数据分布大变后 |
| `ANALYZE 表名` | 单表扫 | 单表大改后 |
| `PRAGMA optimize` | 检查是否需要，通常很轻 | **连接关闭前**（官方推荐） |
| `PRAGMA optimize(0x10002)` | 带掩码的版本，控制分析范围 | 需要精细控制时 |

```sql
-- 应用退出路径
PRAGMA optimize;
```

本机实测 `ANALYZE` 后的 `sqlite_stat1` 内容（20 万行、5000 种取值）：

```text
t | idx_a | 200000 40
```

读法见 [索引与查询计划.md](索引与查询计划.md) 第六节。**统计信息的作用在 SQLite 里比 MySQL 小**
（它没有基于代价的多算法选择），但在「多个索引都能用」时会影响取舍。

---

## 七、一次调优的正确顺序

**设置要点**：顺序错了会做很多无用功。先改写法（数量级），再改结构（数量级），
再调缓存（百分比），最后才碰 `synchronous`（常数，且有数据风险）。

![调优的先后顺序](images/调优的先后顺序.svg)

五步从上到下是按收益排的，右侧标签是量级。前两步是数量级收益，第三步只有百分比，第四步是常数且有掉电风险——所以「先关 synchronous」这种直觉上的快做法，恰恰排最后。

| 顺序 | 动作 | 本机量级 | 风险 |
|---|---|---|---|
| 1 | 批量化（一次事务写多行） | 278~484 倍 | 无 |
| 2 | 建对索引（`SCAN` → `SEARCH`） | 约 611 倍 | 写放大 |
| 3 | 调 `cache_size` / `mmap_size` | 本机未测出（±15%） | 内存占用 |
| 4 | 改 `synchronous` | 2.4 倍（每行一事务） | **掉电丢数据** |
| 5 | 定期 `PRAGMA optimize` | 影响计划选择 | 无 |

### 7.1 一个反例

```text
「写入太慢」→ 直接把 synchronous 设成 OFF
→ 快了一倍，但每次掉电都可能丢最近几笔
→ 真正的问题（一次事务只写一行）没被看见
```

正确的诊断顺序：先看「一秒几次提交」，再看「一次提交写几行」，
最后才看「每次提交要不要 fsync」。

### 7.2 怎么量「提交次数」

```sql
-- 没有直接的计数器，用总耗时 / 事务数自己算
-- 或者在应用层埋点：每次 Commit 记一次，按分钟聚合
```

本机可直接对照的数：500 行分成 500 个事务是 3.27 s，分成 1 个事务是 7.53 ms
（`synchronous=FULL`）。**差 434 倍**，全部来自提交次数。

---

## 使用：一份可抄的配置与五项验证

**本节要点**：配置写全只是第一步，验证「真的生效」是第二步。下面五项能确认
每个 PRAGMA 都落到了实处。

### 使用一：配置（Go DSN）

```go
const dsn = "file:app.db?" +
    "_pragma=journal_mode(WAL)" +
    "&_pragma=synchronous(NORMAL)" +
    "&_pragma=busy_timeout(3000)" +
    "&_pragma=foreign_keys(1)" +
    "&_pragma=cache_size(-8000)" +
    "&_pragma=wal_autocheckpoint(1000)" +
    "&_txlock=immediate"
```

### 使用二：五项验证

```sql
-- 1. 持久级：确认写进了库
PRAGMA journal_mode;        -- 期望 wal
PRAGMA page_size;           -- 期望 4096 或你设的值

-- 2. 连接级：每条连接都要查（这是关键）
PRAGMA foreign_keys;        -- 期望 1，不是 0
PRAGMA busy_timeout;        -- 期望 3000，不是 0
PRAGMA cache_size;          -- 期望 -8000

-- 3. 生效值可能被限制：以读回为准
PRAGMA mmap_size;

-- 4. WAL 是否真的在跑（主库不涨、-wal 在涨就是对的）
PRAGMA wal_checkpoint(PASSIVE);   -- 返回 busy / log / checkpointed

-- 5. 顺手查完整性（比 integrity_check 快得多）
PRAGMA quick_check;
```

### 使用三：Go 里做一次「配置断言」

```go
// 启动时断言：防止某次重构把 DSN 改了却没发现
func assertPragmas(db *sql.DB) error {
    checks := map[string]string{
        "journal_mode": "wal",
        "foreign_keys": "1",
        "busy_timeout": "3000",
    }
    for name, want := range checks {
        var got string
        if err := db.QueryRow("PRAGMA " + name).Scan(&got); err != nil {
            return fmt.Errorf("read pragma %s: %w", name, err)
        }
        if got != want {
            return fmt.Errorf("pragma %s = %q, want %q", name, got, want)
        }
    }
    return nil
}
```

⚠️ 这个断言在连接池上只验证「当前那条连接」。要验证每条连接，用
`db.SetConnMaxLifetime` 配合连接创建回调，或在 DSN 里统一设（这也是推荐 DSN 的原因）。

### 判读表

| 观察 | 结论 |
|---|---|
| `foreign_keys` 读到 0 | DSN 没带上，或设置语句在事务内执行（无效） |
| `journal_mode` 读到 `delete` | 没切成功（只读介质、有别的连接、或有事务未结束） |
| `cache_size` 与设的不一致 | 设成了正数（表示页数而不是 KiB），或没生效 |
| `mmap_size` 比设的小 | 受平台/编译限制，正常，以读回为准 |
| 改了 `synchronous` 但耗时没变 | 你本来就是批量提交，fsync 次数没变 |

---

## 延伸追问

**Q：PRAGMA 能不能在事务里改？**
一部分可以，但改了不生效或报错。`foreign_keys` 在事务内改是**静默无效**的，
这是最坑的一条。`journal_mode` 不能在事务内改。稳妥做法：所有 PRAGMA 都在连接建立时设。

**Q：`cache_size` 设成正数和负数有什么区别？**
正数表示**页数**，负数表示 **KiB**。−2000 = 约 2 MB（默认），2000 = 2000 页 = 8 MB（页 4 KiB 时）。
两种写法差 4 倍，很容易设错。

**Q：为什么 `synchronous=NORMAL` 在 WAL 下能快那么多？**
WAL 模式下 `NORMAL` 只在 checkpoint 时 fsync，提交时不 fsync。所以提交变成纯内存操作，
但掉电会丢最近若干已提交事务。这个取舍在「可重建的数据」上没问题，在账上不行。

**Q：调优需要 benchmark 工具吗？**
不需要专门工具，但需要**正确的计时方式**。本机在 Windows 上 `time.Now()` 有毫秒级量化，
单次微秒读数不可信（见 [观测与诊断.md](观测与诊断.md) 第三节）。宁可少测几次、
每次量大，也不要测很多次单次读数。

## 关联

- [README.md](README.md) — 本册导读与四条读序
- [WAL模式与检查点.md](WAL模式与检查点.md) — `journal_mode` / `wal_autocheckpoint` 的机制
- [事务与并发控制.md](事务与并发控制.md) — `busy_timeout` 与 `BEGIN IMMEDIATE` 的配合
- [类型系统与数据建模.md](类型系统与数据建模.md) — `foreign_keys` 为什么必须显式开
- [备份恢复与迁移.md](备份恢复与迁移.md) — `page_size` 变更与 `VACUUM INTO` 的关系
- [MySQL/参数调优.md](../MySQL/参数调优.md) — 对照读：服务端数据库的内存账与 I/O 账
