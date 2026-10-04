# JOIN 与反范式

> JOIN 的用法、互联网为什么倾向少用 JOIN，以及「拆成多次单表查询 + 应用层归并」的落地写法与实测。
>
> 内容整理自个人学习笔记，素材来源：《高性能 MySQL》（第 4 版）第 6 章「太多的联接」小节、
> 第 8 章「查询性能优化」的读后整理，**按主题归纳而非逐字摘录**；
> 参考资料与原始素材见 [素材清单](../../../../素材清单.md)。

>
> ✅ **实测口径**：本篇的耗时与计划类结论来自本机 Docker Desktop 的隔离实例（`mysql:8.0` = **8.0.46**，
> 独立容器名 + 独立端口 13306 + 独立 volume）：10 万用户 / 100 万订单的 JOIN 夹具、
> `EXPLAIN` 与 `EXPLAIN ANALYZE`、Hash Join 与 `BNL` hint 的对照、`SHOW PROFILE` 的分阶段耗时，
> 脚本与原始输出在 `.workbuddy/tmp/mysqlverify/` 的 `AL_join` ~ `AL10_join` 几组文件里，可整批重跑。
> ⚠️ **仍未实测**：跨实例 / 跨库的联接（本机只有一台实例）、真实业务的数据分布与冷热缓存 ——
> 文中耗时都是 5000×5000 与 10 万×100 万这两份小夹具上的读数，**不能外推到生产数据量**；
> `pt-online-schema-change` 一类工具本机未装，未验证。

---

## 一、MySQL 的 JOIN 怎么用？在什么场景下才该用？

**本节要点**：**结论偏向"少用 JOIN"**，
能讲出"互联网为什么不推荐 JOIN"才是关键。

### 1.1 JOIN 是什么

最常用的是**内连接（`INNER JOIN`）**：

```sql
SELECT o.id, o.amount, u.name
FROM orders o
INNER JOIN users u ON o.user_id = u.id;
```

> **两张表里都有、且能相互匹配的数据才会被查出来**——
> 相当于**把两张表的数据拼到一起，再查出所需字段**。

### 1.2 ⚠️ 关键认知：JOIN 不一定更快

> **`INNER JOIN` 只是"能一次性把数据查出来"，它并不是"性能最优"的选择。**

**传统企业 vs 互联网企业的思路差异**：

| | 思路 |
|---|---|
| **传统企业** | **想尽一切办法一次性把所需数据都查出来**（更看重"一次拿到完整结果"） |
| **互联网企业** | **想尽办法加快单次请求的过程**（更看重延迟） |

**核心判断**：

> **数据量大之后，一次 JOIN 的查询效率往往比不过两次单表查询。**

### 1.3 互联网的常见替代做法：拆成单表查询 + 应用层拼接

```text
① 先查 table1
② 再查 table2
③ 在应用程序的内存里把两次结果拼接起来，得到最终数据
```

**为什么这样更快**：

| 原因 | 说明 |
|---|---|
| **避免大表 JOIN 的中间结果集** | JOIN 会产生笛卡尔积式的中间结果，数据量大时内存/临时表压力剧增 |
| **两次查询都能走索引** | 单表按主键/索引精确查询，**每次都是 O(log n) 级别的定位** |
| **可并行** | 两次查询甚至可以用 `errgroup` 并发执行，**总耗时取较慢的那个** |
| **易缓存** | 两张表的结果可以分别进 Redis，**命中率比 JOIN 结果高得多** |
| **便于分库分表** | 拆表后天然支持分片；JOIN 在分片后几乎无法使用 |

上表这五条"为什么拆成单表更快"，在本机 10 万用户 / 100 万订单的夹具上量过（细节见「使用三」）：
**小结果集时两种写法打平（各 12~14 ms），拆单表在"快"这一项上换不到东西**。
真正能落地的是"避免中间结果集"那一条 —— 连接列没索引时，优化器对联接输出行数的估算
会**放大约 250 倍**（估 250 万行、实际 1 万行，实测见第 2.1 节）。
这就是"中间结果集"在执行计划里的真实样子：它不一定让这条 SQL 当场更慢，
但会让**优化器失去判断力**，后面的联接顺序、索引选择、半联接改写全跟着翻车。

**Go 中的做法示例**：

```go
// 用 errgroup 并发两次单表查询，再在内存拼接
g, ctx := errgroup.WithContext(ctx)
var users map[int64]*User
var orders []*Order

g.Go(func() error { users, err = userRepo.BatchGet(ctx, ids); return err })
g.Go(func() error { orders, err = orderRepo.ListByUserIDs(ctx, ids); return err })
if err := g.Wait(); err != nil { return err }
// 内存里按 user_id 归并
```

### 1.4 什么场景下才适合用 JOIN

**两类典型场景**：

| 场景 | 说明 |
|---|---|
| **企业管理内部系统** | 数据量不大、开发效率优先，JOIN 很方便 |
| **报表统计 / 跑定时任务** | 例如**凌晨跑任务统计、抓取数据**——不在业务高峰，性能压力小 |

**⚠️ 一个重要的风险提示**：

> 当表里的数据特别大时，如果**同一个数据库既给前端业务用、又给企业后端用**，
> 那么**用 JOIN 可能会影响前端系统**（一次重查询会占满 IO/CPU）。
>
> 所以内部/报表类查询**最好走只读从库**，与线上业务隔离。

### 1.5 结论

> **能用单表查询就用单表查询，尽可能避免连接查询。**
>
> **需要 JOIN 的场景，基本是企业管理系统和离线报表/定时任务**；
> 而**面向 C 端的高并发链路**，应当**拆成多次单表查询 + 应用层拼接 + 缓存**。

## 二、MySQL 到底怎么执行一个 JOIN？

**来源**：《高性能 MySQL》（第 4 版）第 6 章「太多的联接」、第 8 章「执行计划」相关的联接执行方式。

**本节要点**：1.2 已经给出「JOIN 不一定更快」的结论，这一节补它的原因 ——
MySQL 的联接算法本质都是**嵌套循环**，内层能不能走索引决定了代价是 `M×N` 还是 `M×logN`。

### 2.1 三种执行方式：NLJ、Index NLJ、Hash Join

```text
① Simple Nested Loop（无索引）
   for 外层每一行:  for 内层全表扫描:  比较
   代价 ≈ M × N            ← 这就是"join 列没索引"的真实成本

② Index Nested Loop（内层有可用索引，EXPLAIN 里内层 type=ref）
   for 外层每一行:  走内层索引点查
   代价 ≈ M × logN         ← 唯一真正有效的优化方向

③ 内层没索引时怎么少扫几遍
   8.0.18 之前： Block Nested Loop —— 把外层若干行攒进 join_buffer，再扫一遍内层批量比较
   8.0.18 起：  无索引的等值联接改用 Hash Join（小的一侧建哈希，另一侧探测）
   8.0.20 起：  原先走 BNL 的场景**全部**改走 Hash Join，BNL 不再被使用
```

读执行计划时的三条判据：

- 内层 `type=ALL` 且 `Extra` 里出现 `Using join buffer` → **缺索引**，
  先给联接列建索引，再谈其他（`EXPLAIN` 各列含义见 [查询链路.md](查询链路.md)）；
- 8.0.18+ 看到 `Inner hash join` / `Left hash join` 说明优化器已经在用哈希兜底 ——
  它比 BNL 快，但**两侧都要扫一遍**，仍然不如索引点查；
- `EXPLAIN ANALYZE`（8.0.18 起）会**真的执行**语句，并给出每个迭代器的
  `actual time` / `rows` / `loops`，用来核对"优化器估的行数"和"实际行数"差多少 ——
  估偏了才轮到统计信息与写法，估准了才是纯粹的索引问题。

本机 8.0.46 上这三条判据都能直接量出来（两张 5000 行、连接列都没有索引的表）：

```text
# 判据 ①②：FORMAT=TREE 里就是迭代器名
-> Aggregate: count(0)  (cost=2.75e+6 rows=1)
    -> Inner hash join (b.k = a.k)  (cost=2.5e+6 rows=2.5e+6)
        -> Table scan on b  (cost=0.0101 rows=5000)
        -> Hash
            -> Table scan on a  (cost=500 rows=4993)

# 判据 ③：EXPLAIN ANALYZE 会真的执行，估算与实际的差距一眼可见
    -> Inner hash join (b.k = a.k)  (cost=2.5e+6 rows=2.5e+6)
                                  (actual time=3.51..5.41 rows=10000 loops=1)
```

- **估算 vs 实际**：无索引时优化器估 `rows=2.5e+6`，实际只有 **10000 行**，偏了约 **250 倍**；
  给 `b.k` 补上索引之后估 `rows=9986`、实际 `rows=10000` —— 几乎正中。
  "估偏了才轮到统计信息与写法背锅"这条判据在这台实例上能复现。
- ⚠️ **"内层走索引一定更快"在小数据量上不成立**。同一份夹具的两种计划实测：
  Hash Join 是 `actual time=3.51..5.41 rows=10000 loops=1`；
  索引版 `Nested loop inner join` 是 `actual time=0.18..13 rows=10000 loops=1`，
  里面那行 `Covering index lookup on b using idx_k` 标着 `loops=5000` —— **慢了一倍多**，
  因为它把内层点查重复做了 5000 次。索引换来的是**结果集变大时的可扩展性**，不是无条件的快。
- 顺手记录一个估算细节：同一份数据在 `ANALYZE TABLE` 之前估 `rows=4993`、之后估 `rows=5000`
  （实际 5000）—— 统计信息对这种小表的影响只有 0.1%，**别指望 ANALYZE 能救回翻车的计划**。

### 2.2 `join_buffer_size` 治的是哪种慢

`join_buffer_size` 决定 BNL 一轮能攒多少组外层行，攒得越多、内层被重复扫描的次数越少；
反过来它**按连接分配**，调大等于给每个连接多要一块内存（每连接内存的算法见
[参数调优.md](参数调优.md) 第 4.2 节）。

结论要说得准确一点：8.0 之后 **BNL 这个算法确实退场了，但它的名字、开关、hint 全都还在**，
而且 hint 换了职责。实测（两张 5000 行、连接列无索引的表，耗时取 `SHOW PROFILE` 的服务端时长）：

| 设置 | FORMAT=TREE 里的迭代器 | 服务端耗时（两轮） |
|---|---|---|
| 默认 | `Inner hash join` | 3.63 / 3.48 ms |
| `/*+ BNL(b) */` | `Inner hash join` | 4.16 / 4.10 ms |
| `/*+ NO_BNL(b) */` | `Nested loop inner join`（不挂缓冲） | 5077 / 5638 ms |
| `optimizer_switch='block_nested_loop=off'` | `Nested loop inner join`（不挂缓冲） | 5377 ms |

- 那个 **≈1400 倍**的差距才是"攒批"真正的价值：`BNL` / `NO_BNL` 现在管的是
  **要不要挂 join buffer**，底下的算法早就是哈希了，hint 名只是历史遗留；
- ⚠️ `optimizer_switch` 里的 `hash_join=on` 是**摆设**：实测单独设 `hash_join=off` 之后
  `SELECT @@optimizer_switch LIKE '%hash_join=off%'` 返回 1（开关确实改了），
  但 `EXPLAIN` 的 Extra 仍是 `Using where; Using join buffer (hash join)`；
  要两个开关一起关（`hash_join=off,block_nested_loop=off`）Extra 才变成光秃秃的 `Using where`；
- `join_buffer_size` 实测默认 **262144**（256 KB），调到 8 MB 在这份夹具上量不出差异
  （墙钟 19 / 21 ms，里面还含客户端启动的固定开销）—— 所以"调大它治慢 JOIN"在 8.0 上换不来什么，
  慢 JOIN 的正解仍然是**给联接列建索引**或**减少联接次数**。

### 2.3 「太多的联接」是 schema 问题，不是 SQL 问题

每多一张表参与联接，就多一次索引查找、多一处优化器可能估错的地方，
执行计划翻车的概率也乘一次。本书把它归到 schema 设计层面，判据有三条：

- **关联列的类型、字符集、排序规则必须一致**，否则隐式转换让内层索引用不上，
  直接退化回 2.1 的 ①（约束写法见 [数据类型选型.md](数据类型选型.md) 第 4.3 节）；
- **读多写少**的宽联接，用冗余列 / 组装好的宽表把 join 换成一次单表读，
  代价是更新要同步多份 —— 与缓存的一致性问题是同一类（见
  [缓存问题与方案.md](../../缓存/缓存问题与方案.md)）；
- **跨服务、跨库的联接不该写在 SQL 里**：这正是 1.3 的"多次单表查询 + 应用层拼接"，
  分库分表之后只有这一条路（见 [分表与路由.md](分表与路由.md)）。

---

## 延伸追问

- **MySQL 的 JOIN 到底有几种算法？** → 本质都是嵌套循环：无索引的 simple nested loop、内层走索引的 index nested loop、内层没索引时的攒批（8.0.18 之前是 BNL，之后是 hash join，8.0.20 起 BNL 完全不再使用）。所以「小表驱动大表」只在**大表侧没有可用索引**时才真正影响代价。
- **`EXPLAIN` 和 `EXPLAIN ANALYZE` 有什么区别？** → 后者会**真的执行**语句，按迭代器给出 `actual time` / `rows` / `loops`，用来核对估算与实际差距；8.0.18 起可用，只有 TREE 格式。
- **`join_buffer_size` 还值得调吗？** → 它是 join buffer 的大小（BNL 时代攒外层行，8.0 里同一块缓冲给 hash join 用）。实测 8.0.46 默认 262144，从 256 KB 调到 8 MB 在 5000×5000 的无索引联接上量不出差异；而且这内存**按连接分配**，调大等于给每个连接多要一块。真正决定代价的是 `block_nested_loop` 这个开关（关掉就退回不挂缓冲的 nested loop，实测慢约 1400 倍）和联接列有没有索引。
- **`INNER JOIN` / `LEFT JOIN` / `RIGHT JOIN` 的区别？** →
  内连接取交集；左连接以左表为准（右表无匹配补 NULL）；右连接反之。
- **`ON` 和 `WHERE` 的区别？** → **`ON` 是连接条件**（在生成连接结果时生效，
  外连接中放在 `ON` 里不会过滤掉左表的行）；**`WHERE` 是结果过滤**（在连接之后过滤，
  外连接中写在这里会把补 NULL 的行也过滤掉，**等价于退化成内连接**）。
- **JOIN 的驱动表怎么选？** → 一般**小表驱动大表**；
  优化器会自行判断，必要时可用 `STRAIGHT_JOIN` 强制顺序。
- **分库分表后怎么做关联查询？** → 通过**冗余字段、宽表、异步同步（binlog→ES/数仓）**，
  或**在同一分片键下保证数据落在同一库**，避免跨库 JOIN。

---

## 使用一：Go（拆 JOIN：并发单表查询 + 内存归并）⭐

### 1. 拆 JOIN：并发单表查询 + 内存归并

第一节的结论是「面向 C 端的高并发链路，拆成多次单表查询 + 应用层拼接」。落到代码要注意三件事：
**用批查而不是逐条查（N+1）**、**并发写变量不要用共享 `err`**、**归并用 map 而不是嵌套循环**。

```go
package joinfree

import (
	"context"
	"database/sql"
	"strings"

	"golang.org/x/sync/errgroup"
)

type User struct {
	ID   int64
	Name string
}

type Order struct {
	ID     int64
	UserID int64
	Amount int64
}

// OrderView 给前端的扁平结构——JOIN 想要的效果，用内存拼出来。
type OrderView struct {
	OrderID     int64  `json:"order_id"`
	Amount      int64  `json:"amount"`
	UserID      int64  `json:"user_id"`
	UserName    string `json:"user_name"`
	UserMissing bool   `json:"user_missing"` // 单表拼接必须显式表达"左连接补 NULL"的语义
}

// ✗ N+1：先查订单，再在循环里逐个查用户。100 条订单 = 101 次往返。
func listWithUserNPlusOne(ctx context.Context, db *sql.DB, uid int64) ([]OrderView, error) {
	orders, err := ordersByUsers(ctx, db, []int64{uid})
	if err != nil {
		return nil, err
	}
	out := make([]OrderView, 0, len(orders))
	for _, o := range orders {
		var u User
		if err := db.QueryRowContext(ctx, `SELECT id, name FROM users WHERE id = ?`, o.UserID).Scan(&u.ID, &u.Name); err != nil {
			return nil, err
		}
		out = append(out, OrderView{OrderID: o.ID, Amount: o.Amount, UserID: u.ID, UserName: u.Name})
	}
	return out, nil
}
```

上面是反例；下面是正确写法：两次单表查询并发跑，再在内存里按 map 归并出 JOIN 的效果。

```go
// ✓ 两次单表查询 + 并发 + 内存归并：总耗时 ≈ 较慢的那一次。
func ListWithUser(ctx context.Context, db *sql.DB, userIDs []int64) ([]OrderView, error) {
	if len(userIDs) == 0 {
		return nil, nil
	}

	var (
		orders []Order
		users  map[int64]User
	)

	// ⚠️ 两个 goroutine 分别持有自己的返回值，不要再共享外层 err 变量——
	// 共享 err 是数据竞争（go test -race 会报，go vet 不一定报）。errgroup 本身就负责传错误。
	g, gctx := errgroup.WithContext(ctx)
	g.Go(func() error {
		v, err := ordersByUsers(gctx, db, userIDs)
		orders = v
		return err
	})
	g.Go(func() error {
		v, err := usersByIDs(gctx, db, userIDs)
		users = v
		return err
	})
	if err := g.Wait(); err != nil {
		return nil, err
	}

	out := make([]OrderView, 0, len(orders))
	for _, o := range orders {
		v := OrderView{OrderID: o.ID, Amount: o.Amount, UserID: o.UserID}
		if u, ok := users[o.UserID]; ok { // map 查找 O(1)，别写成 for + for
			v.UserName = u.Name
		} else {
			v.UserMissing = true // 对应用户不存在/已删除——这是 LEFT JOIN 补 NULL 的等价物
		}
		out = append(out, v)
	}
	return out, nil
}
```

归并所依赖的两个批查函数放在最后，它们各自只做一条 `IN (...)` 的单表查询。

```go
func ordersByUsers(ctx context.Context, db *sql.DB, ids []int64) ([]Order, error) {
	args := make([]any, len(ids))
	for i, id := range ids {
		args[i] = id
	}
	q := `SELECT id, user_id, amount FROM orders WHERE user_id IN (` + strings.TrimSuffix(strings.Repeat("?, ", len(ids)), ", ") + `)`
	rows, err := db.QueryContext(ctx, q, args...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var out []Order
	for rows.Next() {
		var o Order
		if err := rows.Scan(&o.ID, &o.UserID, &o.Amount); err != nil {
			return nil, err
		}
		out = append(out, o)
	}
	return out, rows.Err()
}

func usersByIDs(ctx context.Context, db *sql.DB, ids []int64) (map[int64]User, error) {
	args := make([]any, len(ids))
	for i, id := range ids {
		args[i] = id
	}
	q := `SELECT id, name FROM users WHERE id IN (` + strings.TrimSuffix(strings.Repeat("?, ", len(ids)), ", ") + `)`
	rows, err := db.QueryContext(ctx, q, args...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	out := make(map[int64]User, len(ids))
	for rows.Next() {
		var u User
		if err := rows.Scan(&u.ID, &u.Name); err != nil {
			return nil, err
		}
		out[u.ID] = u
	}
	return out, rows.Err()
}
```

> 上文第一节的示意代码里两个 `g.Go` 共用了外层 `err`，那是**数据竞争**写法；以本节 `ListWithUser` 为准。

## 使用二：Java（`groupingBy` + 并发批查）

### 1. 拆 JOIN：`groupingBy` + 并发批查

第一节的「应用层拼接」在 Java 里就是 `Stream` 分组归并（更多写法见 [Stream流实战.md](../../../../01-编程语言/java/Stream流实战.md)）：

```java
package notes.mysql.joinfree;

import java.util.List;
import java.util.Map;
import java.util.concurrent.CompletableFuture;
import java.util.function.Function;
import java.util.stream.Collectors;
import org.springframework.jdbc.core.JdbcTemplate;

public class OrderAssembler {

    private final JdbcTemplate jdbc;

    public OrderAssembler(JdbcTemplate jdbc) {
        this.jdbc = jdbc;
    }

    public List<OrderView> list(List<Long> userIds) {
        if (userIds.isEmpty()) {
            return List.of();
        }

        // 两次单表批查，并发发起：总耗时取较慢的那个
        CompletableFuture<List<Order>> ordersF = CompletableFuture.supplyAsync(
                () -> jdbc.query("SELECT id, user_id, amount FROM orders WHERE user_id IN (?)",
                        (rs, i) -> new Order(rs.getLong("id"), rs.getLong("user_id"), rs.getLong("amount")),
                        join(userIds)));
        CompletableFuture<Map<Long, User>> usersF = CompletableFuture.supplyAsync(
                () -> jdbc.query("SELECT id, name FROM users WHERE id IN (?)",
                                (rs, i) -> new User(rs.getLong("id"), rs.getString("name")),
                                join(userIds))
                        .stream().collect(Collectors.toMap(User::id, Function.identity())));

        Map<Long, User> users = usersF.join();
        return ordersF.join().stream()
                .map(o -> {
                    User u = users.get(o.userId()); // null 就是 LEFT JOIN 补 NULL 的情况
                    return new OrderView(o.id(), o.amount(), o.userId(), u == null ? null : u.name(), u == null);
                })
                .toList();
    }

    // ⚠️ 这里为了省篇幅直接把 id 列表拼进 SQL：生产必须改用 ?占位符 或 NamedParameterJdbcTemplate。
    // 因为 Long 列表不存在引号问题，但拼 SQL 这个动作一旦被复制到字符串场景就是注入。
    private static String join(List<Long> ids) {
        return ids.stream().map(String::valueOf).collect(Collectors.joining(","));
    }

    record Order(long id, long userId, long amount) {}

    record User(long id, String name) {}

    record OrderView(long orderId, long amount, long userId, String userName, boolean userMissing) {}
}
```

> ⚠️ `CompletableFuture.supplyAsync` 默认用公共 `ForkJoinPool`，**会把数据库并发放大到 CPU 核数倍**；
> 生产要传专用线程池，且线程池大小要和 HikariCP 的 `maximumPoolSize` 对齐，否则只是在排队抢连接。

## 使用三：本地验证与 JOIN / 两次单表的实测

前面所有结论都可以在一台本地 MySQL 上跑出来，值得亲手验一次——尤其是「JOIN 不一定更快」。
本节下面的数字就是本机 8.0.46 按这段命令跑出来的（只有造数那一步要先抬递归深度，见实测补充）。

### 1. 起库与造数

```bash
docker run -d --name mysql8 -p 3306:3306 \
  -e MYSQL_ROOT_PASSWORD=root123456 -e MYSQL_DATABASE=test \
  mysql:8.0 --innodb-buffer-pool-size=256M

# 等就绪（健康检查比 sleep 可靠）
until docker exec mysql8 mysqladmin ping -uroot -proot123456 --silent; do sleep 1; done

docker exec -i mysql8 mysql -uroot -proot123456 test <<'SQL'
CREATE TABLE users (
  id BIGINT PRIMARY KEY,
  name VARCHAR(32) NOT NULL,
  KEY idx_name (name)
) ENGINE = InnoDB;

CREATE TABLE orders (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  user_id BIGINT NOT NULL,
  amount BIGINT NOT NULL,
  KEY idx_user (user_id)            -- 被驱动表的连接列要有索引；缺了不一定当场更慢（实测见「使用三」），但优化器的行数估算会失控
) ENGINE = InnoDB;

-- 造 10 万用户
INSERT INTO users (id, name)
SELECT t.N, CONCAT('u', t.N) FROM (
  WITH RECURSIVE s(N) AS (SELECT 1 UNION ALL SELECT N + 1 FROM s WHERE N < 100000)
  SELECT N FROM s
) t;

-- 造 100 万订单（10 用户 × 10 万，故意做成倾斜分布，便于观察"数据量决定 JOIN 代价"）
INSERT INTO orders (user_id, amount)
SELECT u.id, 100 FROM users u JOIN users s ON s.id <= 10;
SQL
```

本机照抄这段跑了一遍，**第一处就撞墙**：`WITH RECURSIVE` 的默认递归深度只有 1000。

```text
ERROR 3636 (HY000): Recursive query aborted after 1001 iterations.
Try increasing @@cte_max_recursion_depth to a larger value.
```

造 10 万用户必须先抬这个会话变量：

```sql
SET SESSION cte_max_recursion_depth = 110000;   -- 默认 1000，抬到比目标行数大即可
```

抬完之后这份夹具是能跑出来的，实测（同一台实例，`innodb_buffer_pool_size` 用默认值）：

| 步骤 | 实测 |
|---|---|
| 10 万用户 | 371 ms |
| 100 万订单（文档这条自联接写法） | 35,139 ms |
| `orders` 体积 | 数据 17 MB + 索引 20 MB（`information_schema.tables`） |
| 倾斜分布确实生效 | 头部 `user_id` 各 10 万行 |

- ⚠️ `docker run -p 3306:3306` 在本机会撞已有端口，实测用 **13306**；
  容器名要自己新建，别复用别人的实例（本机用户已有 `seckill-mysql` 等）。
- 顺手量到的一个 DDL 数字：100 万行的表上 `DROP INDEX idx_user` 再 `ADD INDEX idx_user`，
  重建耗时 **1526 ms** —— 在线 DDL 的代价感可以拿这个数打底。

### 2. 同一需求的两种写法对比

```sql
-- 写法 A：INNER JOIN
SELECT o.id, o.amount, u.name
FROM orders o INNER JOIN users u ON o.user_id = u.id
WHERE u.name = 'u1000' LIMIT 50;

-- 写法 B：两次单表（应用层拼接）—— 等价于下面两条
SELECT id, name FROM users WHERE name = 'u1000';           -- 走 idx_name，1 行
SELECT id, user_id, amount FROM orders WHERE user_id = ? LIMIT 50;  -- 走 idx_user
```

**怎么判断谁快**（对 [查询链路.md](查询链路.md) 链路里「优化器选路径」做一次实测）：

```bash
# 分别看执行计划：重点看 type / key / rows / Extra
docker exec -i mysql8 mysql -uroot -proot123456 test -e "EXPLAIN FORMAT=JSON SELECT o.id,o.amount,u.name FROM orders o INNER JOIN users u ON o.user_id=u.id WHERE u.name='u1000' LIMIT 50\G"

# 看真实耗时（关闭查询缓存类干扰，8.0 本来也没有查询缓存）
docker exec -i mysql8 mysql -uroot -proot123456 test -e "SET profiling=1; SELECT COUNT(*) FROM orders o INNER JOIN users u ON o.user_id=u.id; SHOW profiles;"
```

`SHOW PROFILE` 在 8.0.46 上**仍然可用**（实测三次都出数），但它带着废弃告警：

```text
mysql> SET profiling=1; SELECT COUNT(*) FROM users; SHOW PROFILE;
Warning 1287: '@@profiling' is deprecated and will be removed in a future release.
| starting                       | 0.000033 |
| Opening tables                 | 0.000046 |
| System lock                    | 0.000008 |
| executing                      | 0.000029 |
| query end                      | 0.000004 |
| waiting for handler commit     | 0.000031 |
| closing tables                 | 0.000009 |
| freeing items                  | 0.000032 |
| cleaning up                    | 0.000008 |
```

- `SHOW PROFILES` 给整条语句的总时长（实测 `SELECT COUNT(*) FROM users` = `0.00032550` 秒）；
  只留最近 **15** 条（`@@profiling_history_size` 实测 15），长跑批会被挤掉；
- 长期方案是 `EXPLAIN ANALYZE` 的 `actual time`，或
  `performance_schema.events_statements_summary_by_digest` —— 实测同一张表的
  `SELECT COUNT(*) FROM users` 在 digest 里记的是 `avg_ms = 0.4741`（用法见
  [观测与诊断.md](观测与诊断.md)）；
- ⚠️ `SET profiling` 是**会话级**的：像上面这样另起一次 `mysql -e` 去查
  `SHOW VARIABLES LIKE 'profiling'` 会看到 `OFF`，前一条命令的设置已经随连接销毁了。
  要把设置和被测语句写进**同一次** `mysql -e`。

实测（同一份夹具；墙钟含 `mysql` 客户端启动 + 建连接的固定开销，所以只断言趋势）：

| 写法 | 三轮墙钟 |
|---|---|
| A：带 `WHERE u.name='u1000' LIMIT 50` 的 JOIN | 12 / 12 / 13 ms |
| B：两次单表（先 `users` 取 id，再按 `user_id` 取 `orders`） | 14 / 13 / 12 ms |
| A：去掉 `WHERE` 的全量 JOIN | 首轮 **2584 ms**（冷缓存），之后 601 / 612 ms |

结论按数字收得更紧：**驱动表选对 + 被驱动表有索引时，小结果集的 JOIN 和两次单表打平**，
"拆单表更快"在这个量级上量不出来。真正拉开差距的是**结果集规模**（全量 JOIN 到几百毫秒～秒级），
而这份代价拆成两次单表**同样在**（把 100 万行搬回应用内存里拼只会更慢）。
所以第 1.3 节那条"避免中间结果集"的价值不在快，而在**可控**：单表查询能 `LIMIT`、能分页、能缓存、能并行。

还有一个反直觉的实测结果值得记住：**把 `idx_user` 抽掉，这条全量 JOIN 并没有变慢**
（443~475 ms，和带索引时的 468~660 ms 落在同一个波动区间）。原因是 `users.id` 是主键，
内层仍然有索引可走，优化器只是把另一侧换成 hash join。
**要量出"连接列缺索引"的代价，得让两侧都没有索引**（第 2.1 节那两份 5000 行的小表就是为此准备的）。

### 3. 观察「连接池 / 预编译」这两环

```bash
# 实例当前缓存的预编译语句总数（⚠️ 不是"每个连接一份"，见下方实测）
docker exec -i mysql8 mysql -uroot -proot123456 -e "SHOW GLOBAL STATUS LIKE 'Prepared_stmt_count';"

# 高频 SQL 有没有走预编译：看 Com_stmt_prepare / Com_stmt_execute（名字实测过，见下）
docker exec -i mysql8 mysql -uroot -proot123456 -e "SHOW GLOBAL STATUS LIKE 'Com_pre%';"

# 当前谁在占用连接：连接池是否配小了、有没有慢 SQL 占着不放
docker exec -i mysql8 mysql -uroot -proot123456 -e "SHOW FULL PROCESSLIST;"
```

实测这台 8.0.46 上的变量名（两条 `SHOW GLOBAL STATUS LIKE`）：

```text
# SHOW GLOBAL STATUS LIKE 'Com_pre%'  —— 实测只有这两行
Com_preload_keys             0
Com_prepare_sql              1     <- SQL 层 PREPARE 语句的计数，确实还在
# SHOW GLOBAL STATUS LIKE 'Com_stmt%' —— 协议层那三个在这里
Com_stmt_prepare           140
Com_stmt_execute           420
Com_stmt_close             420
# SHOW GLOBAL STATUS LIKE 'Prepared_stmt_count'
Prepared_stmt_count          0
```

两处口径要修正：

- ⚠️ **`Com_pre%` 匹配不到 `Com_stmt_*`** —— 上面那三行来自 `LIKE 'Com_stmt%'`。
  巡检脚本按 `Com_pre%` 抓"高频 SQL 有没有走预编译"会**一条都抓不到**，只剩一个恒为 0 的
  `Com_preload_keys`；驱动 + 连接池那条路（Go 的 `db.Prepare`、MySQL Connector/J 默认行为）
  计入的是 `Com_stmt_prepare` / `Com_stmt_execute` / `Com_stmt_close`。
- `Prepared_stmt_count` 是**整个实例**当前缓存的预编译语句条数，不是"每个连接上缓存了多少" ——
  每连接一份是**客户端**（Go 的 `*sql.Stmt`、HikariCP 的 `prepareThreshold`）自己的行为，
  服务端这个数只是所有连接叠起来的总量，用它反推连接池配置会推错。
  顺带：它是 status 变量，`SELECT @@prepared_stmt_count` 报
  `ERROR 1193 (HY000): Unknown system variable 'prepared_stmt_count'`，别按系统变量去查。

> 与 Redis 那份笔记的呼应：MySQL 8.0 移除查询缓存 ≠ 不需要缓存，
> 而是把缓存职责交回应用层——具体实现见 [缓存问题与方案.md](../../缓存/缓存问题与方案.md)。

---

## 关联

- [查询链路.md](查询链路.md) — 优化器如何选驱动表与关联顺序
- [索引与优化.md](索引与优化.md) — 关联字段上的索引设计
- [软件架构.md](软件架构.md) — 读写分离与报表查询分流
- [查询优化.md](查询优化.md) — JOIN 算法、驱动表选择与 8.0 的 Hash Join
- [参数调优.md](参数调优.md) — `join_buffer_size` 这类连接级参数的内存账
- [观测与诊断.md](观测与诊断.md) — 用 digest 表定位「哪一类 JOIN 在消耗总时间」
- [Stream流实战.md](../../../../01-编程语言/java/Stream流实战.md) — 应用层归并的 Java 写法
