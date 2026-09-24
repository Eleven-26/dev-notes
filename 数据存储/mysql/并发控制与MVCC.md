# MySQL 并发控制与 MVCC

> 乐观锁与悲观锁的原理、实现与选型，MVCC 多版本并发控制的特点与 InnoDB 落地机制
>
> 内容整理自个人学习笔记。原文件名为「常见技术及应用」，含义过泛，已按知识点改名归位。
> 事务隔离级别与 undo/redo 日志见 [事务与隔离级别.md](事务与隔离级别.md)、[日志与落盘.md](日志与落盘.md)。

---

## 一、锁：乐观锁与悲观锁

> ⚠️ 先明确一个前提：**乐观锁与悲观锁是两种"并发控制思想"，不是数据库提供的具体锁**。
> 数据库真正提供的锁是行锁、表锁、间隙锁等；乐观锁通常由应用层实现。

### 1.1 乐观锁

乐观锁是一种并发控制机制，用于处理多用户同时操作同一数据时可能出现的冲突。它基于一种假设：**在大多数情况下，多个事务可以同时进行而不会相互干扰**——冲突是小概率事件。

因此它允许事务在**不加锁**的情况下进行，**只在提交时才检查是否发生了冲突**。

**实现方式**：通常依赖记录的**版本号**或**时间戳**。

1. 读取数据时，同时获取当前的版本号（或时间戳）
2. 事务完成、准备提交更新时，检查自读取以来数据的版本号是否发生变化
3. **没有变化** → 说明期间无其他事务修改过该数据，安全提交
4. **发生变化** → 说明已有其他事务修改，当前事务需要**回滚或重新执行**

```sql

-- 版本号机制的典型写法：把「比对版本」和「更新」放在一条 SQL 里，由数据库保证原子性
UPDATE item
SET stock = stock - 1, version = version + 1
WHERE id = #{id} AND version = #{oldVersion};
-- 影响行数为 0 → 说明版本已变，业务层重试或报错
```

**优点**：

1. **性能**：不需要加锁，减少了锁争用，提高了系统的并发性能
2. **简单性**：实现相对简单，特别是在**读多写少**的场景下

**缺点**：

1. **冲突检测滞后**：在提交时才检测冲突，如果冲突频繁，可能导致大量事务需要回滚或重试
2. **ABA 问题**：一个事务读取数据后，另一个事务把数据**改掉又改回原值**（或版本号回到原状），此时校验可以通过，但数据实际已被中间修改过、可能产生误判

> 💡 **常见误写**：有人把 `SELECT ... FOR UPDATE` 归为乐观锁的实现——**它恰恰是悲观锁**，
> 因为它一开始就锁住了行。乐观锁的特征是「读取时不加锁，写入时校验」。

### 1.2 悲观锁

悲观锁与乐观锁相对，基于一种较为保守的假设：**多个事务并发执行时冲突是常见的**，因此需要通过锁定机制来避免冲突。

核心思想是：在事务进行数据操作时**先对数据加锁**，确保在事务执行期间其他事务不能修改这些数据。

**关键特点**：

1. **锁定数据**：事务开始时通过数据库的锁机制锁定需要操作的数据，防止其他事务同时修改
2. **数据一致性**：只有持有锁的事务才能修改数据，因此能确保一致性
3. **死锁风险**：多个事务相互等待对方释放锁时可能导致死锁
4. **性能影响**：高并发系统中可能因频繁的锁争用而影响性能
5. **适用场景**：适用于**写操作频繁**的场景，锁定可减少冲突和数据不一致的风险
6. **事务隔离级别**：悲观锁的行为与事务隔离级别相关，不同级别对锁的持有与释放时机不同
7. **实现方式**：通过 SQL 实现，如 `SELECT ... FOR UPDATE` 锁定行，或使用数据库特定的锁机制
8. **回滚机制**：事务无法提交时，数据库系统负责释放锁并回滚事务，保证数据一致性

```sql

-- 悲观锁：一开始就锁住这行，直到事务结束（COMMIT/ROLLBACK）才释放
BEGIN;
SELECT stock FROM item WHERE id = 1 FOR UPDATE;   -- 排他锁
UPDATE item SET stock = stock - 1 WHERE id = 1;
COMMIT;
```

### 1.3 两者对比与选型

| 维度 | 乐观锁 | 悲观锁 |
|---|---|---|
| **核心假设** | 冲突是小概率事件 | 冲突是常态 |
| **加锁时机** | 不加锁，提交时校验 | 操作前先加锁 |
| **实现位置** | 应用层（版本号/CAS/时间戳） | 数据库锁（`FOR UPDATE`、行锁、表锁） |
| **冲突代价** | 提交时才发现，需回滚重试 | 其他事务**阻塞等待** |
| **适合场景** | **读多写少**、冲突少 | **写多**、冲突多、要求强一致 |
| **主要风险** | 大量重试、ABA | **死锁**、锁等待超时、吞吐下降 |
| **典型应用** | 商品详情、库存扣减（带重试） | 银行转账、账户扣款、秒杀预扣 |

**选择结论**：读操作远多于写操作的场景中乐观锁更合适（减少锁争用、提高并发）；
写操作频繁且对一致性要求极高的场景中悲观锁更好（用锁换取确定性）。

> ⭐ 补充：**Redis 分布式锁**、**MySQL 唯一索引**、**`INSERT IGNORE` / `ON DUPLICATE KEY UPDATE`**
> 也都是常见的"避免并发写冲突"手段，各有权衡，不要只盯乐观/悲观两种。

## 二、MVCC（多版本并发控制）

MVCC（Multi-Version Concurrency Control，多版本并发控制）是一种用于数据库系统中处理并发数据访问的技术：**它允许在不锁定资源的情况下执行读取和写入操作**，从而提高数据库的并发性能。

### 2.1 特点

| 特点 | 说明 |
|---|---|
| **无锁读取** | 读取操作不需要获取任何锁，因为每个事务看到的是一致性视图（Consistent View），即在某个时间点数据库的状态 |
| **快照读取** | 事务在开始时会创建一个快照，包含事务开始时数据库的状态，事务基于该快照读取一致性数据 |
| **版本控制** | 每个数据项可能存在多个版本，每个版本有一个时间戳表示其创建时间 |
| **写入时冲突检测** | 当事务试图修改数据时，系统检查是否有其他事务已读取该数据的旧版本；有冲突则处理（回滚或等待） |
| **支持多隔离级别** | 在不同隔离级别下工作，从而在一致性和并发性之间权衡 |

### 2.2 实现方式

MVCC 通常通过以下方式实现：

- **版本向量**：记录每个事务看到的每个数据项的版本号
- **时间戳**：每个事务都有一个时间戳，用于确定事务的先后顺序
- **Undo 日志**：用于回滚操作、以及**构造历史版本**以解决写入冲突

### 2.3 MySQL InnoDB 的具体落地 ⭐

InnoDB 的 MVCC 由三样东西协作实现：

| 组成 | 作用 |
|---|---|
| **隐藏列** | 每行有两个隐藏字段：`DB_TRX_ID`（最后修改该行的事务 ID）、`DB_ROLL_PTR`（回滚指针，指向 undo log 中的旧版本） |
| **Undo Log** | 保存数据的历史版本，通过 `DB_ROLL_PTR` 串成**版本链** |
| **Read View** | 事务快照读时生成，记录"当前活跃的事务 ID 列表"，用于判断某个版本对该事务是否可见 |

**可见性判断**：读取时沿版本链找到第一个"对当前 Read View 可见"的版本。

**RC 与 RR 的差异在 Read View 的生成时机**：

- **读已提交（RC）**：**每次** `SELECT` 都重新生成 Read View → 能读到别的事务已提交的新数据
- **可重复读（RR）**：**事务内第一次** `SELECT` 时生成一次，之后复用 → 整个事务看到同一个快照（这也是 MySQL 默认级别下能避免不可重复读的原因）

> ⚠️ 注意区分**快照读**与**当前读**：
> 普通 `SELECT` 走 MVCC 快照读（不加锁）；`SELECT ... FOR UPDATE`、`UPDATE`、`DELETE` 属于**当前读**，
> 读的是最新版本并加锁。

### 2.4 应用与优劣

**应用**：PostgreSQL、Oracle、MySQL（InnoDB 存储引擎）、MongoDB（WiredTiger 引擎）等现代数据库广泛采用。

| 方面 | 内容 |
|---|---|
| **优势** | ① 提高并发性：允许多个事务同时读取数据而不必等待；② 减少死锁：读操作不需要加锁，减少死锁可能 |
| **挑战** | ① **存储开销**：每个数据项可能存多个版本，增加存储需求；② **垃圾回收**：需要定期清理不再使用的旧版本以释放空间 |

> MVCC 是一种复杂但高效的技术，在提高数据库并发性能方面发挥了重要作用。

## 三、延伸：多机之间「谁来写」

前两部分讲的都是**单机内**的读写并发（锁与 MVCC）。一旦把视角抬到多机，
问题就从「怎么并发读」变成「由谁负责写」——即**主节点选举（Leader Election）**，
常见实现有 Raft、Paxos/Multi-Paxos，以及基于它们的 ZooKeeper / etcd。

> 📖 这一层不属于 MySQL 并发控制，已归到分布式篇：
> **选主流程见 [Raft协议.md](../../分布式/Raft协议.md)**，
> **一致性等级与 CAP 取舍见 [一致性与CAP.md](../../分布式/一致性与CAP.md)**。

---

## 使用一：Go（三种写冲突控制的落地写法）⭐

> 校验说明：本节 Go 代码在 `go-sql-driver/mysql v1.10` 下 `go build` + `go vet` + `gofmt` 通过；本机无 MySQL 实例，**未做运行时验证**。
> 各代码块同属包 `lockdemo`。建表：
> `CREATE TABLE item (id BIGINT PRIMARY KEY, stock BIGINT NOT NULL, version BIGINT NOT NULL DEFAULT 0) ENGINE=InnoDB;`
> `CREATE TABLE msg_done (msg_key VARCHAR(128) PRIMARY KEY, done_at DATETIME NOT NULL) ENGINE=InnoDB;`

### 1. 乐观锁：版本号 + 用 `RowsAffected` 判定冲突

正文那段 SQL 的关键在最后一行注释——**「影响行数为 0」才是冲突信号**。
Go 里对应的 API 是 `sql.Result.RowsAffected()`；不要先 `SELECT` 比对再无条件 `UPDATE`，那样等于把竞态窗口又打开。

```go

package lockdemo

import (
	"context"
	"database/sql"
	"errors"
	"fmt"
	"math/rand"
	"time"
)

var (
	ErrConflict       = errors.New("lockdemo: version conflict, retry")
	ErrStockNotEnough = errors.New("lockdemo: stock not enough")
)

type Item struct {
	ID      int64
	Stock   int64
	Version int64
}

func GetItem(ctx context.Context, db *sql.DB, id int64) (Item, error) {
	var it Item
	// 这条是快照读（普通 SELECT），不加锁——正是正文 2.3 末尾区分的那两类读
	err := db.QueryRowContext(ctx, `SELECT id, stock, version FROM item WHERE id = ?`, id).
		Scan(&it.ID, &it.Stock, &it.Version)
	return it, err
}

// DeductOptimistic 完整走一遍正文 1.1 的四步：读到版本 → 计算 → 带版本提交 → 影响行数 0 就重试。
func DeductOptimistic(ctx context.Context, db *sql.DB, id, qty int64, retries int) error {
	var lastErr error
	for i := range retries {
		lastErr = deductOnce(ctx, db, id, qty)
		if !errors.Is(lastErr, ErrConflict) {
			return lastErr // 成功、或"余额不足"这类业务错误都不用重试
		}
		select {
		case <-ctx.Done():
			return ctx.Err()
		case <-time.After(jitter(i)):
		}
	}
	return fmt.Errorf("lockdemo: %d retries exhausted: %w", retries, lastErr)
}

func deductOnce(ctx context.Context, db *sql.DB, id, qty int64) error {
	tx, err := db.BeginTx(ctx, nil)
	if err != nil {
		return err
	}
	defer func() { _ = tx.Rollback() }() // 提交成功后这行返回 ErrTxDone，忽略即可

	var cur Item
	if err := tx.QueryRowContext(ctx, `SELECT id, stock, version FROM item WHERE id = ?`, id).
		Scan(&cur.ID, &cur.Stock, &cur.Version); err != nil {
		return err
	}
	if cur.Stock < qty {
		return ErrStockNotEnough
	}

	res, err := tx.ExecContext(ctx,
		`UPDATE item SET stock = stock - ?, version = version + 1 WHERE id = ? AND version = ?`,
		qty, id, cur.Version)
	if err != nil {
		return err
	}
	n, err := res.RowsAffected()
	if err != nil {
		return err
	}
	if n == 0 {
		return ErrConflict // ← 版本已被别人改过
	}
	return tx.Commit()
}

// jitter：指数退避 + 随机抖动。
// ⚠️ 只退避不加抖动，会让同一批冲突者下一次仍然同时到达（惊群）。
func jitter(attempt int) time.Duration {
	base := time.Duration(5<<min(attempt, 6)) * time.Millisecond
	return base + time.Duration(rand.Int63n(int64(base)))
}
```

**两个必须能说清的边界**：

| 追问 | 回答 |
|---|---|
| **冲突率高时怎么办？** | 重试次数期望值 ≈ 并发竞争者数量，正文「冲突频繁 → 大量回滚」就是这里。读多写少才用乐观锁；秒杀这种写密集场景应换悲观锁 / 队列串行化 / 条件更新（见 2.） |
| **ABA 怎么防？** | 正文列的缺陷。**用单调递增的 `version` 而不是 `updated_at`** 就不会 ABA；用时间戳做版本时，同一毫秒内的改回原值会被误判通过 |

### 2. 不用版本号的乐观锁：条件更新（库存扣减首选）

版本号要加列、要维护、还要重试。**如果不变式可以写进 `WHERE`，一条 `UPDATE` 就是最省事的乐观锁**：
数据库在行锁内完成「判断 + 修改」，天然是原子的。

```go

package lockdemo

import (
	"context"
	"database/sql"
)

// DeductByCondition：把校验压进 WHERE。
// 返回剩余库存——利用 MySQL 的 LAST_INSERT_ID(expr) 技巧：
// 它把表达式结果记在**本连接**的 last_insert_id 上，于是省掉"扣完再读一次"的往返。
func DeductByCondition(ctx context.Context, db *sql.DB, id, qty int64) (int64, error) {
	res, err := db.ExecContext(ctx,
		`UPDATE item SET stock = LAST_INSERT_ID(stock - ?) WHERE id = ? AND stock >= ?`, qty, id, qty)
	if err != nil {
		return 0, err
	}
	n, err := res.RowsAffected()
	if err != nil {
		return 0, err
	}
	if n == 0 {
		return 0, ErrStockNotEnough // 行不存在 或 库存不足；要区分就再 SELECT 一次（低频路径）
	}
	return res.LastInsertId() // 拿到扣后的值
}
```

> ⚠️ `LAST_INSERT_ID(expr)` 是**连接级**状态：必须和那条 `UPDATE` 用**同一条连接**读回。
> 从连接池看，`res.LastInsertId()` 由驱动在同一物理连接的执行结果里带回，是安全的；
> 但如果换成"再 `db.QueryRow` 一次"，就可能落到另一条连接上读到别的请求的值——**这是真坑**。

### 3. 悲观锁：`FOR UPDATE` 的三条硬规矩

```go

package lockdemo

import (
	"context"
	"database/sql"
	"time"
)

// ReservePessimistic 正文 1.2 的代码化：先锁行，再算，再改。
//
// 三条硬规矩：
//  1. 必须在事务里——`FOR UPDATE` 在自动提交的单语句结束后就释放锁，等于白锁；
//  2. 条件必须走索引——`WHERE` 用了无索引列，InnoDB 会锁它扫过的**所有行**（RR 下还带间隙锁），
//     表现就是"更新一行、全表卡住"；没有合适索引时宁可退回条件更新；
//  3. 持锁区间要短——锁在 COMMIT/ROLLBACK 才释放，中间任何 RPC 都是延迟放大器
//     （见 [事务与隔离级别.md](事务与隔离级别.md) 的「大事务拆分」）。
func ReservePessimistic(ctx context.Context, db *sql.DB, id, qty int64) error {
	tx, err := db.BeginTx(ctx, nil)
	if err != nil {
		return err
	}
	defer func() { _ = tx.Rollback() }()

	var stock int64
	// 当前读：读的是**最新版本**并加排他锁，而不是 MVCC 快照
	if err := tx.QueryRowContext(ctx, `SELECT stock FROM item WHERE id = ? FOR UPDATE`, id).Scan(&stock); err != nil {
		return err
	}
	if stock < qty {
		return ErrStockNotEnough
	}
	if _, err := tx.ExecContext(ctx, `UPDATE item SET stock = stock - ? WHERE id = ?`, qty, id); err != nil {
		return err
	}
	return tx.Commit()
}

// ScanAndReserve 秒杀/任务分发式的"跳过已被别人锁住的行"：
// SKIP LOCKED 让并发请求各自取不同的行，互不排队——这是队列型用法，不是通用改法。
func ScanAndReserve(ctx context.Context, db *sql.DB, n int) ([]int64, error) {
	tx, err := db.BeginTx(ctx, nil)
	if err != nil {
		return nil, err
	}
	defer func() { _ = tx.Rollback() }()

	rows, err := tx.QueryContext(ctx,
		`SELECT id FROM item WHERE stock > 0 ORDER BY id LIMIT ? FOR UPDATE SKIP LOCKED`, n)
	if err != nil {
		return nil, err // 5.7 之前不支持 SKIP LOCKED / NOWAIT，会直接语法报错
	}
	defer rows.Close()

	var ids []int64
	for rows.Next() {
		var id int64
		if err := rows.Scan(&id); err != nil {
			return nil, err
		}
		ids = append(ids, id)
	}
	return ids, rows.Err()
}

// LockWaitBudget 把"最多等多久"变成一层可预测的预算，而不是等默认 50s 把请求堆死。
// ⚠️ 别混两个参数：innodb_lock_wait_timeout 管**行锁**等待，
//
//	lock_wait_timeout 管**元数据锁（MDL）**等待——DDL 卡住业务通常是后者。
func LockWaitBudget(ctx context.Context, db *sql.DB, budget time.Duration) error {
	tx, err := db.BeginTx(ctx, nil)
	if err != nil {
		return err
	}
	defer func() { _ = tx.Rollback() }()

	// SET 是会话级，且只对这条连接生效——事务期间连接被固定，所以够用；
	// 退出事务后连接回池会残留该设置，需要在归还时还原或统一走 DSN/服务端配置。
	secs := max(int(budget.Seconds()), 1)
	if _, err := tx.ExecContext(ctx, `SET SESSION innodb_lock_wait_timeout = ?`, secs); err != nil {
		return err
	}
	return tx.Commit()
}
```

> 与乐观锁的选型回到正文 1.3 的表：**读多写少 → 乐观；写密集且要强一致 → 悲观**，
> 而**能用条件更新解决的，就不要引入版本号列或显式行锁**。
> 跨服务/跨库的"锁"只能靠 Redis 分布式锁，实现与坑见 [分布式锁.md](../redis/分布式锁.md)。

### 4. 唯一索引兜底：正文那句「不要只盯乐观/悲观两种」

「先查后插」在并发下必然重复（查和插之间有时间窗）。**防重的正确工具是唯一索引**，
它把判断交给存储引擎的行锁与索引结构，一次往返搞定：

```go

package lockdemo

import (
	"context"
	"database/sql"
)

// ConsumeOnce 消息消费的幂等闸门。
// 返回 false 表示"这条已经处理过"，调用方直接 ack，不要重复执行业务。
func ConsumeOnce(ctx context.Context, db *sql.DB, msgKey string, handle func(*sql.Tx) error) (bool, error) {
	tx, err := db.BeginTx(ctx, nil)
	if err != nil {
		return false, err
	}
	defer func() { _ = tx.Rollback() }()

	// INSERT IGNORE 会把"主键冲突"从错误降级成 0 影响行数。
	// ⚠️ 它同样会忽略掉除零/截断之类的警告——除了幂等标记表，别用它掩盖脏数据。
	res, err := tx.ExecContext(ctx,
		`INSERT IGNORE INTO msg_done (msg_key, done_at) VALUES (?, NOW())`, msgKey)
	if err != nil {
		return false, err
	}
	n, err := res.RowsAffected()
	if err != nil {
		return false, err
	}
	if n == 0 {
		return false, nil // 已处理过
	}
	if err := handle(tx); err != nil {
		return false, err
	}
	return true, tx.Commit() // 标记与业务在同一事务里 → 要么都生效要么都没有
}

// Accumulate 计数类写入：累加而不是覆盖，避免"读-改-写"竞态。
func Accumulate(ctx context.Context, db *sql.DB, day string, delta int64) error {
	_, err := db.ExecContext(ctx,
		`INSERT INTO stat_day (day, cnt) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE cnt = cnt + VALUES(cnt)`, day, delta)
	return err
}
```

> **`ON DUPLICATE KEY UPDATE` 的两个坑**：① 它依赖**唯一键**才能判重，没有唯一索引就是纯 `INSERT`；
> ② 主从架构下它写的是 **statement 相关的 binlog**，用 `VALUES(col)` 老语法在 8.0.20+ 已废弃，
> 推荐别名写法 `INSERT INTO t (...) VALUES (...) AS new ON DUPLICATE KEY UPDATE cnt = cnt + new.cnt`。

### 5. 快照读 / 当前读的对照实验

正文 2.3 的「RC 每次 SELECT 都重建 Read View，RR 只在第一次生成」是可以**手工复现**的，
跑一遍比背十遍有用（`mysql -h... --comments` 开两个终端）：

```sql

-- 终端 A（事务，RR）
SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ;
BEGIN;
SELECT stock FROM item WHERE id = 1;        -- ① 看到 100，此刻生成 Read View

-- 终端 B（另一个连接）
UPDATE item SET stock = 99 WHERE id = 1;    -- ② 直接提交，没被阻塞（A 只是快照读，不加行锁）

-- 回到终端 A
SELECT stock FROM item WHERE id = 1;        -- ③ 仍是 100 ← RR 复用同一个 Read View
SELECT stock FROM item WHERE id = 1 FOR UPDATE;  -- ④ 变成 99！当前读绕开 MVCC 读最新版
COMMIT;
```

| 步骤 | RR 结果 | 换成 RC 的结果 | 说明 |
|---|---|---|---|
| ③ 快照读 | 100 | **99** | RC 每次读都重建 Read View，所以"读到了别人已提交的新值" |
| ④ 当前读 | 99 | 99 | `FOR UPDATE` / `UPDATE` / `DELETE` 都读最新版本，**不受隔离级别影响** |

> ④ 这一行是正文「RR 下幻读解决了吗」的答案来源：**快照读靠 MVCC 规避幻读，当前读必须靠间隙锁（Next-Key Lock）**。
> 也解释了为什么"事务里前面读到的值不能再拿去做后续判断"——一旦中途出现当前读，视图就切到最新了。

---

## 使用二：Java（JPA 乐观锁 / 悲观锁与幂等）

> ⚠️ 本节代码按 Spring Boot 3.x / Hibernate 6.x 书写，**未编译校验**。四条结论与 Go 侧一一对应。

### 1. 乐观锁：`@Version` 就是正文那套版本号机制

```java

package notes.lock;

import jakarta.persistence.Column;
import jakarta.persistence.Entity;
import jakarta.persistence.Id;
import jakarta.persistence.Table;
import jakarta.persistence.Version;

@Entity
@Table(name = "item")
public class Item {

    @Id
    private Long id;

    private long stock;

    // Hibernate 自动把 version 拼进 WHERE 并 +1：
    //   UPDATE item SET stock=?, version=? WHERE id=? AND version=?
    // 影响行数 0 → 抛 OptimisticLockException（Spring 转成 ObjectOptimisticLockingFailureException）
    @Version
    private long version;

    @Column(name = "updated_by")
    private String updatedBy;

    public Long getId() {
        return id;
    }

    public long getStock() {
        return stock;
    }

    public void setStock(long stock) {
        this.stock = stock;
    }
}
```

```java

package notes.lock;

import org.springframework.orm.ObjectOptimisticLockingFailureException;
import org.springframework.retry.annotation.Backoff;
import org.springframework.retry.annotation.Retryable;
import org.springframework.stereotype.Service;
import org.springframework.transaction.annotation.Transactional;

@Service
public class StockService {

    private final ItemRepository repo;

    public StockService(ItemRepository repo) {
        this.repo = repo;
    }

    // ⚠️ @Retryable 必须放在**事务方法的外层**：
    // 重试如果发生在事务内部，JPA 的 PersistenceContext 里还是那个旧实体，等于白重。
    @Retryable(for = ObjectOptimisticLockingFailureException.class, maxAttempts = 5,
            backoff = @Backoff(delay = 5, maxDelay = 100, random = true))
    public void deduct(Long id, long qty) {
        doDeduct(id, qty);
    }

    @Transactional
    void doDeduct(Long id, long qty) {
        Item item = repo.findById(id).orElseThrow();
        if (item.getStock() < qty) {
            throw new IllegalStateException("库存不足");
        }
        item.setStock(item.getStock() - qty); // 提交时带 version 校验
    }
}
```

**MyBatis 侧**没有自动版本管理，就是把正文那条 SQL 直接写出来并**判断返回的影响行数**：

```java

// interface ItemMapper { int deduct(@Param("id") Long id, @Param("qty") long qty, @Param("v") long version); }
// <update id="deduct">
//   UPDATE item SET stock = stock - #{qty}, version = version + 1
//   WHERE id = #{id} AND version = #{v} AND stock >= #{qty}   <!-- 条件更新也顺手带上 -->
// </update>
int affected = mapper.deduct(id, qty, loaded.getVersion());
if (affected == 0) {
    throw new OptimisticLockerException("版本已变");  // 交给上层重试，别在 mapper 层循环
}
```

### 2. 悲观锁与幂等

```java

// FOR UPDATE：JPA 用锁提示，Hibernate 6 起支持 NOWAIT / SKIP_LOCKED
@Transactional
@Query(value = "SELECT * FROM item WHERE id = ?1 FOR UPDATE", nativeQuery = true)
@Lock(LockModeType.PESSIMISTIC_WRITE)
Optional<Item> lockForUpdate(Long id);

// 队列型取任务（对应 Go 侧 ScanAndReserve）
@Lock(LockModeType.PESSIMISTIC_WRITE)
@QueryHints(@QueryHint(name = "jakarta.persistence.lock.timeout", value = "-2")) // -2 = SKIP_LOCKED
List<Item> findTop10ByStockGreaterThanOrderByIdAsc(long stock);
```

```java

// 幂等：直接依赖唯一索引 + ON DUPLICATE KEY，别写 findById 再 save
@Modifying
@Query(value = """
        INSERT INTO stat_day (day, cnt) VALUES (:day, :delta)
        ON DUPLICATE KEY UPDATE cnt = cnt + :delta
        """, nativeQuery = true)
int accumulate(@Param("day") String day, @Param("delta") long delta);

// 重复消费闸门：捕获 DuplicateKeyException 而不是"先查再插"
try {
    doneRepo.insert(new MsgDone(key, LocalDateTime.now()));
} catch (DuplicateKeyException e) {
    return Ack.DUPLICATE; // 已处理过
}
```

> ⚠️ `@Transactional` 里 catch 掉 `OptimisticLockException` 是无效的：事务已被标记 rollback-only，
> 提交时会抛 `UnexpectedRollbackException`。**乐观锁的重试必须在事务边界之外**，
> 这和 Go 侧"`RunInTx` 外层重试、而不是在 `fn` 里重试"是同一件事。

---

## 使用三：锁等待与死锁的线上排查

### 1. 谁在等谁（8.0）

```sql

-- 现成的视图：直接给出"被谁挡住 + 该 kill 谁 + 挡住了哪条 SQL"
SELECT * FROM sys.innodb_lock_waits\G

-- 8.0 的 information_schema.INNODB_LOCKS / INNODB_WAITS 已被移除，改用：
SELECT * FROM performance_schema.data_lock_waits\G
SELECT ENGINE_TRANSACTION_ID, THREAD_ID, OBJECT_NAME, LOCK_TYPE, LOCK_MODE, LOCK_STATUS, LOCK_DATA
  FROM performance_schema.data_locks;   -- RECORD / GAP / INSERT_INTENTION，能看出是不是间隙锁在挡

-- ⚠️ 查不到锁信息，多半是这两张表的 consumer 没开（默认部分关闭）
UPDATE performance_schema.setup_instruments SET ENABLED='YES', TIMED='YES' WHERE NAME LIKE 'wait/lock/table%';
```

### 2. 死锁：不要只靠 `LATEST DETECTED DEADLOCK`

```bash

# 只保留"最近一次"死锁，且需要 RECREATE 输出段才刷新；生产应把死锁写进错误日志
docker exec -i mysql8 mysql -uroot -p -e "SHOW ENGINE INNODB STATUS\G" | sed -n '/LATEST DETECTED DEADLOCK/,/TRANSACTIONS/p'
```

```sql

-- 让每次死锁都被完整记录（含两边 SQL），排期比看 STATUS 有用得多
SHOW VARIABLES LIKE 'innodb_print_all_deadlocks';   -- 建议 ON
SHOW VARIABLES LIKE 'innodb_deadlock_detect';       -- 关掉它 = 只能用等待超时兜底，热点行场景反而更糟
```

| 现象 | 判断 | 处置 |
|---|---|---|
| 死锁（1213）偶发、量小 | 加锁顺序不一致 | **统一顺序**（见 [事务与隔离级别.md](事务与隔离级别.md) 的 Q1 ⑤），Go 侧见 `Transfer` 的按 id 排序 |
| 大量 1205 锁等待超时，但没有死锁日志 | 有人在事务里做慢操作 / RPC，或热点行 | 拆事务、改条件更新、或把该行的写串行化（队列） |
| DDL 把业务全卡住，`State: Waiting for table metadata lock` | MDL，不是行锁 | 看 `lock_wait_timeout` 与**未提交的老事务**，先杀事务再重试 DDL（在线 DDL 步骤见 [索引与优化.md](索引与优化.md) 的「索引变更」） |

### 3. 找到"持锁的老事务"

```sql

-- 事务已经跑了很久还没结束 = 锁一直被持有；正文「大事务」的现场版
SELECT trx_id, trx_state, trx_started, TIMESTAMPDIFF(SECOND, trx_started, NOW()) AS run_s,
       trx_rows_locked, trx_rows_modified, trx_mysql_thread_id
  FROM information_schema.INNODB_TRX
 ORDER BY trx_started LIMIT 10;

-- 关联到连接与来源，才能定位是哪个服务
SELECT id, user, host, db, command, time, state, LEFT(info, 120) AS sql_head
  FROM information_schema.PROCESSLIST
 WHERE command <> 'Sleep'
 ORDER BY time DESC LIMIT 10;

-- 确认无误再杀（回滚本身可能比等它跑完更久，大事务慎杀）
-- KILL <processlist_id>;
```

> 与 MVCC 的关联：`trx_rows_modified` 很大的长事务会让 **undo 版本链变长**，
> 快照读要沿链回溯更远（读变慢），同时 purge 无法推进 → undo 表空间膨胀。
> 这就是正文 2.4「挑战：存储开销 / 垃圾回收」在监控上的具体体现。

---

## 关联

- [事务与隔离级别.md](事务与隔离级别.md) — 隔离级别是 MVCC 的语义层
- [日志与落盘.md](日志与落盘.md) — undo/redo 与版本链的存储
- [索引与优化.md](索引与优化.md) — 加锁范围与索引的关系
- [../../分布式/一致性与CAP.md](../../分布式/一致性与CAP.md) — 单机并发之外的一致性
