# go-job 实现

> 用 [`github.com/cybergarage/go-job`](https://github.com/cybergarage/go-job)（**v1.2.3**）实现定时任务的实测记录。
> 它的定位是**任务平台**（调度 + 队列 + 观测），所以重点是两个**反直觉的默认行为**与它的内建可观测性。
>
> ⭐ 边界：语义问题本身（丢拍 / 漂移 / 漏跑 / 重入 / 取消 / 多副本重复）见 [标准库实现.md](标准库实现.md)；
> 同目录的另一个库见 [go-cron实现.md](go-cron实现.md)；四者怎么选见 [README.md](README.md)。
>
> 内容整理自个人学习笔记；素材见 [素材清单](../../../../素材清单.md)。
>
> 📎 **实测口径**：宿主 **Go 1.26.5（windows/amd64）**，版本由 `go mod` 实读（**v1.2.3**）；
> ⚠️ 未跑容器版。**只打印计数与判定的项：连跑两遍逐行相同**；
> 含时序类判定的项（`Wait` 返回时任务是否跑完）报**分布**而不是单值。

---

## 一、go-job 实测

**本节要点**：它把任务放进了**队列**：优先级、重试、状态历史都内建，但有两个**反直觉的默认行为**必须先知道。

### 1.1 优先级队列：数值越小越优先

worker 数设 1（串行），一次性入队 **low(10) → high(0) → medium(5)**：

| 提交顺序 | 实际执行顺序（5 轮一致） |
|---|---|
| low → high → medium | **high → medium → low** |

⭐ 与 Unix `nice` 同向：`HighPriority = 0`、`MediumPriority = 5`、`LowPriority = 10`，**默认是 Medium**。

### 1.2 重试的触发条件（这里有两个坑，都是实测踩出来的）

| 实验 | 配置 | 结果 |
|---|---|---|
| **B1** executor 返回 `("", error)` | `WithMaxRetries(3)` | 调用 **1** 次，最终状态 **Completed** |
| **B2** executor 第 1、2 次超时（任务体 400ms > 预算 200ms） | `WithMaxRetries(5)` | 调用 **3** 次（`Attempts()` = 1→2→3），最终 **Completed** |

⭐ B1 是反直觉的那个：**executor 返回的 `error` 不会让任务失败** —— 源码里 `Execute()` 把函数的**全部返回值（含 error）一起塞进结果集**，自己只在不满足反射调用时才报错。所以「业务失败」在这个库里**不能用返回 error 表达**；能触发重试的是**执行层失败**（超时、取消）。

⭐ 另一个坑更硬：**不给 `WithCompleteProcessor` 会 panic** —— 实测任务第一次成功完成时就崩在库内部（`worker.go` 调用了一个 nil 函数），而 `WithKind` / `WithExecutor` 都不报错。**注册任务时把完成处理器一并写上**。

### 1.3 内建观测：状态与日志是现成的

| 读法 | 实测结果（5 轮一致） |
|---|---|
| `LookupInstanceHistory(query)` | **4** 条：`Created → Scheduled → Processing → Completed` |
| `LookupInstanceLogs(query)` | **6** 条（executor 与状态回调里 `ji.Infof` 的记录） |

![go-job 的任务实例状态流转：Created → Scheduled → Processing → Completed，以及两条失败出口](images/go-job任务状态流转.svg)

⚠️ 但它的 `Wait(ctx)` **不等于「等任务跑完」**：源码里 `Wait` 只在 worker「**正在处理**」时循环等待。实测调度后立刻 `Wait`，20 次里有 **3~7 次**返回时任务仍是排队/处理中（5 轮：7、4、4、3、3 次）。**要判定「跑完了」得自己轮询状态**，别把 `Wait` 当同步屏障用。

### 1.4 可直接抄的骨架

```go
mgr, err := job.NewManager(job.WithNumWorkers(2))
if err != nil {
	return err
}
j, err := job.NewJob(
	job.WithKind("daily-report"),
	job.WithExecutor(func(ji job.Instance) string {
		ji.Infof("attempt=%d", ji.Attempts()) // 进实例日志历史
		return "ok"
	}),
	// ⚠️ 必须给：不设它，任务完成时库内部会调用 nil 函数 panic（实测）
	job.WithCompleteProcessor(func(ji job.Instance, res []any) {}),
	job.WithTimeout(30*time.Second), // 超时是「执行层失败」，会触发重试
	job.WithMaxRetries(2),
)
if err != nil {
	return err
}
if err := mgr.RegisterJob(j); err != nil {
	return err
}
if err := mgr.Start(); err != nil {
	return err
}

inst, err := mgr.ScheduleRegisteredJob("daily-report",
	job.WithCrontabSpec("0 2 * * *"),
	job.WithPriority(job.HighPriority), // 0 = 最高；数值越小越优先
)
if err != nil {
	return err
}

// 观测：按实例查状态历史与日志
hist, _ := mgr.LookupInstanceHistory(job.NewQuery(job.WithQueryInstance(inst)))
logs, _ := mgr.LookupInstanceLogs(job.NewQuery(job.WithQueryInstance(inst)))
_ = hist
_ = logs
defer mgr.Stop()
```

---

## 延伸追问

- **库把任务标成「成功」就真的成功了吗？** → ⚠️ 不一定。实测 executor 返回 `error` 时最终状态仍是 `Completed` —— 源码把函数的**全部返回值（含 error）**一起塞进结果集。**别拿调度库的状态当业务成功的判据**，要另设业务指标（见 1.2）。
- **`Wait(ctx)` 能当「等任务跑完」用吗？** → 不能。源码里它只在 worker「**正在处理**」时循环等待；实测调度后立刻 `Wait`，20 次里有 **3~7 次**返回时任务仍是排队/处理中。要判定「跑完了」得自己轮询状态（见 1.3）。
- **为什么注册任务时必须给 `WithCompleteProcessor`？** → 不给会在任务第一次完成时崩在库内部（调用 nil 函数），而 `WithKind` / `WithExecutor` 都不报错 —— 这类「少一个选项就 panic」的 API 要在骨架里写死（见 1.4）。

---

## 关联

- [标准库实现.md](标准库实现.md) — 六个语义问题的原理与本机实测（本篇是它的「换库版」）
- [go-cron实现.md](go-cron实现.md) — 同目录的另一个真实库，定位是「调度器」而非「任务平台」
- [xxl-job接入.md](xxl-job接入.md) — 再往上一步：把调度交给中心化平台
- [README.md](README.md) — 四个方案的能力覆盖矩阵与选型判据
- [cybergarage/go-job](https://github.com/cybergarage/go-job) — 官方仓库（注意与同名项目 `goliatone/go-job` 区分）
