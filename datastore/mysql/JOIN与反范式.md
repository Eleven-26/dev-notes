# JOIN 与反范式

> JOIN 的用法、互联网为什么倾向少用 JOIN，以及「拆成多次单表查询 + 应用层归并」的落地写法与实测。
>
> 内容整理自大厂 Go 后端面试真题视频，参考资料与原始素材见 [素材清单](../../interview/素材清单.md)。

---

## Q1. MySQL 的 JOIN 怎么用？在什么场景下才该用？

**来源**：`p=53` 小鹏后台开发二面（日常实习）· 时长 8分07秒
**考察意图**：这题的**标准答案偏向"少用 JOIN"**，
能讲出"互联网为什么不推荐 JOIN"就是答到了点上。

### 一、JOIN 是什么

最常用的是**内连接（`INNER JOIN`）**：

```sql

SELECT o.id, o.amount, u.name
FROM orders o
INNER JOIN users u ON o.user_id = u.id;
```

> **两张表里都有、且能相互匹配的数据才会被查出来**——
> 相当于**把两张表的数据拼到一起，再查出所需字段**。

### 二、⚠️ 关键认知：JOIN 不一定更快

> **`INNER JOIN` 只是"能一次性把数据查出来"，它并不是"性能最优"的选择。**

**传统企业 vs 互联网企业的思路差异**：

| | 思路 |
|---|---|
| **传统企业** | **想尽一切办法一次性把所需数据都查出来**（更看重"一次拿到完整结果"） |
| **互联网企业** | **想尽办法加快单次请求的过程**（更看重延迟） |

**核心判断**：

> **数据量大之后，一次 JOIN 的查询效率往往比不过两次单表查询。**

### 三、互联网的常见替代做法：拆成单表查询 + 应用层拼接

```
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

### 四、什么场景下才适合用 JOIN

**原视频给的两类场景**：

| 场景 | 说明 |
|---|---|
| **企业管理内部系统** | 数据量不大、开发效率优先，JOIN 很方便 |
| **报表统计 / 跑定时任务** | 例如**凌晨跑任务统计、抓取数据**——不在业务高峰，性能压力小 |

**⚠️ 一个重要的风险提示**：

> 当表里的数据特别大时，如果**同一个数据库既给前端业务用、又给企业后端用**，
> 那么**用 JOIN 可能会影响前端系统**（一次重查询会占满 IO/CPU）。
>
> 所以内部/报表类查询**最好走只读从库**，与线上业务隔离。

### 五、结论（可以直接背）

> **能用单表查询就用单表查询，尽可能避免连接查询。**
>
> **需要 JOIN 的场景，基本是企业管理系统和离线报表/定时任务**；
> 而**面向 C 端的高并发链路**，应当**拆成多次单表查询 + 应用层拼接 + 缓存**。

### 面试官会追问什么

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

Q1 的结论是「面向 C 端的高并发链路，拆成多次单表查询 + 应用层拼接」。落到代码要注意三件事：
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

> 上文 Q1 的示意代码里两个 `g.Go` 共用了外层 `err`，那是**数据竞争**写法；以本节 `ListWithUser` 为准。

## 使用二：Java（`groupingBy` + 并发批查）
### 1. 拆 JOIN：`groupingBy` + 并发批查

Q1 的「应用层拼接」在 Java 里就是 `Stream` 分组归并（更多写法见 [../java/Stream流实战.md](../../java/Stream流实战.md)）：

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
  KEY idx_user (user_id)            -- 被驱动表的连接列必须有索引，否则 JOIN 一定慢
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

预期与结论一致：**驱动表选对 + 被驱动表有索引时，小结果集的 JOIN 并不慢**；
一旦连接结果集变大（去掉 `WHERE`）、或连接列缺索引，JOIN 的中间结果集就会让
它明显输给「两次单表 + 内存拼接」——这正是 Q1 那条结论的来源。

### 3. 观察「连接池 / 预编译」这两环

```bash

# 每个连接上缓存了多少 prepared statement（对应 Go 侧"每连接一份语句缓存"）
docker exec -i mysql8 mysql -uroot -proot123456 -e "SHOW GLOBAL STATUS LIKE 'Prepared_stmt_count';"

# 高频 SQL 是否命中计划：看 Com_prepare / Com_execute 与 QPS 的比例
docker exec -i mysql8 mysql -uroot -proot123456 -e "SHOW GLOBAL STATUS LIKE 'Com_pre%';"

# 当前谁在占用连接：连接池是否配小了、有没有慢 SQL 占着不放
docker exec -i mysql8 mysql -uroot -proot123456 -e "SHOW FULL PROCESSLIST;"
```

> 与 Redis 那份笔记的呼应：MySQL 8.0 移除查询缓存 ≠ 不需要缓存，
> 而是把缓存职责交回应用层——具体实现见 [../../middleware/redis/缓存问题与方案.md](../../middleware/redis/缓存问题与方案.md)。

---

## 关联

- [查询链路.md](查询链路.md) — 优化器如何选驱动表与关联顺序
- [索引与优化.md](索引与优化.md) — 关联字段上的索引设计
- [软件架构.md](软件架构.md) — 读写分离与报表查询分流
- [../../java/Stream流实战.md](../../java/Stream流实战.md) — 应用层归并的 Java 写法
