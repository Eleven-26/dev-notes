# XXL-JOB 分布式任务调度

> 一句话说明本文件覆盖什么：XXL-JOB 的定位与核心概念、调度中心 / 执行器的通信与调度架构、Docker 部署，以及 Go（主，篇幅给足）与 Java（官方，作对照）两套执行器的可落地接入方式与生产避坑。
>
> 内容整理自个人学习笔记。通用分布式协调原理见 [distributed](../分布式/)，容器编排见 [docker](../容器/)。

---

## 1. 一句话定位：它解决什么问题

**XXL-JOB = 分布式任务调度平台**（作者许雪里，国产开源），核心理念是「**调度与任务解耦**」：**调度中心（Admin）** 只决定「何时、按什么策略、调度到哪台机器」，**执行器（Executor）** 只负责「把业务逻辑跑起来」。两者用 **HTTP 反向触发** 通信，业务代码几乎零侵入（不用继承框架 Job 基类）。

> ⚠️ 协议澄清：主仓库 `xuxueli/xxl-job` 是 **GPL-3.0**（不是网上不少资料写的 Apache-2.0）；跨语言客户端 `xxl-job-executor-go` 是 **MIT**。商用二次分发前先确认合规。

| 排期方式 | 典型痛点 | XXL-JOB 的解法 |
| --- | --- | --- |
| OS `crontab` | 散落各机器、无集中视图、改一次要登机器、无重试与告警 | Web 控制台统一管理；Cron / 固定频率 / 固定延时 / API 触发 |
| 单机 `@Scheduled` | 多副本部署时**每个副本都跑一遍** | 统一调度 + 路由策略，一次调度只落一台 |
| Quartz 集群 | 要自建 `JobStore` 集群表与触发恢复，无执行日志、无分片、无跨语言 | 自带注册表 / 日志 / 重试 / 分片广播 / 多语言 OpenAPI |
| MQ 延时消息 | 只能「延时一次」，做不了 Cron 与依赖编排 | 父子任务、六种触发类型 |

---

## 2. 核心概念

| 概念 | 含义 | 关键点 |
| --- | --- | --- |
| **调度中心 Admin** | 管理端 Web + 调度引擎：注册发现、路由、触发、日志、告警 | 集群部署，靠 **DB 悲观锁**选主调度（见 §3） |
| **执行器 Executor** | 内嵌业务进程的 SDK，监听固定端口接收触发 | 注册单位是 **AppName**（执行器名），不是机器 IP |
| **任务 JobInfo** | 一条调度配置：Cron、Handler 名、参数、路由 / 阻塞策略、超时、重试 | 任务与 Handler **按名字绑定**，不一致直接报「没有注册」 |
| **JobHandler** | 执行器里注册的业务函数 / 方法，被名字（如 `demoJobHandler`）引用 | Go 用 `RegTask`，Java 用 `@XxlJob` |
| **执行日志** | 调度日志（`xxl_job_log`，中心侧）+ 执行日志（执行器本地文件，中心**回拉**） | 默认保留 30 天，`logretentiondays` 可配 |
| **任务超时** | `executor_timeout`（秒），0 表示不限；超时后中心标记失败并 kill | Go 侧是 `context.WithTimeout`，函数内必须 `select ctx.Done()` |
| **失败重试** | `executor_fail_retry_count`，失败后重试次数 | ⚠️ 重试**会重复执行业务**，业务必须幂等 |
| **子任务** | `child_jobid`，当前任务成功后触发下一批任务 | 做简单 DAG 编排；失败则子任务不触发 |

**路由策略**（执行器集群时选哪台执行，`SHARDING_BROADCAST` 除外）：

| 策略 | 含义 | 适用 |
| --- | --- | --- |
| `FIRST` / `LAST` | 固定选注册表的第一 / 最后一台 | 调试、指定单机执行 |
| `ROUND` | 轮询 | 无状态、负载均摊（常用默认） |
| `RANDOM` | 随机 | 同上，更简单 |
| `CONSISTENT_HASH` | 一致性 HASH，同参数固定落同机 | 依赖本地缓存 / 需要同 key 同机 |
| `LEAST_FREQUENTLY_USED` / `LEAST_RECENTLY_USED` | 最不经常使用 / 最近最久未使用 | 按实际负载挑机 |
| `FAILOVER` | 故障转移：依次心跳探测取第一台可用 | 要求高可用，可接受探测开销 |
| `BUSYOVER` | 忙碌转移：取第一台空闲（`/idleBeat` 未在跑该任务）的 | 任务重、要求不排队 |
| `SHARDING_BROADCAST` | **分片广播**：广播到**所有**执行器并下发分片序号 / 总数 | 大数据量并行 ⭐ |

**阻塞处理策略**（同一执行器上，上一次没跑完又来一次）：

| 策略 | 常量 | 行为 |
| --- | --- | --- |
| 单机串行（默认） | `SERIAL_EXECUTION` | 排队等待，前一次完成后依次执行 |
| 丢弃后续调度 | `DISCARD_LATER` | 直接返回失败「There are tasks running」，本次不执行 |
| 覆盖之前调度 | `COVER_EARLY` | cancel 掉正在跑的那次，只跑最新一次 |

其他属性：**调度过期策略** `DO_NOTHING` / `FIRE_ONCE_NOW`；**调度类型** `NONE` / `CRON` / `FIX_RATE` / `FIX_DELAY`；**运行模式** `BEAN` / `GLUE(Java)` / Shell / Python / NodeJS / PHP / PowerShell。

---

## 3. 整体架构

```

┌────────────── 调度中心集群（xxl-job-admin ×N，:8080） ──────────────┐
│ Web 控制台 │ 调度引擎 JobScheduleHelper │ 注册发现 JobRegistryHelper │
│     ▼ 集群一致性唯一凭据：MySQL xxl_job_lock（SELECT ... FOR UPDATE）│
└───┬───────────────────────────────────────────────────────────────┘
    │ ① 执行器主动注册 POST /api/registry（Go 20s / Java 30s，90s 未续约剔除）
    │ ② 触发任务 POST http://executor:9999/run（Header 带 XXL-JOB-ACCESS-TOKEN）
    │ ③ 结果回调 POST /api/callback   ④ 回拉日志 POST http://executor:9999/log
    ▼
┌─ 执行器集群（AppName=xxl-job-executor-sample，:9999，可多实例） ─┐
│ HTTP Server(/run /kill /log /beat /idleBeat) + 线程池/Goroutine + 本地日志 │
└───────────────────────────────────────────────────────────────┘
```

| 设计点 | 实现方式 | 说明 |
| --- | --- | --- |
| **通信方式** | **HTTP 反向触发**，中心 → 执行器 | 不是消息队列。执行器是 HTTP Server、中心是 Client，所以 **执行器 `:9999` 必须对中心可达** |
| **注册方式** | 默认 **DB 注册表** `xxl_job_registry`；Java 版注册中心**可插拔**（ZK / Nacos / Consul / etcd） | Go 客户端只实现了 DB 注册，不支持可插拔注册中心 |
| **中心集群一致性** | 调度前 `SELECT * FROM xxl_job_lock WHERE lock_name='schedule_lock' FOR UPDATE`，**抢到锁的实例才扫描待调度任务** | 悲观锁天然保证「同一时刻只有一台在调度」，无需 ZK 选主 |
| **执行器自动发现** | 执行器周期上报 → 中心写注册表 → 中心刷新 `xxl_job_group.address_list` | 注册表按 AppName 聚合出地址列表，路由策略在此列表上挑 |
| **心跳保活** | `BEAT_TIMEOUT = 30s`（监控扫描周期），`DEAD_TIMEOUT = BEAT_TIMEOUT × 3 = 90s` | 90s 内没续约的地址被 `removeDead` 物理删除 |
| **触发时效** | 每轮预读 `PRE_READ_MS = 5000ms`，把任务塞进 **60 槽时间轮**，ring 线程按秒推进 | 调度精度是**秒级**，不是毫秒级 |

**一次调度的完整时序（文字版）**：

1. `T0` 执行器启动 → 调 `POST /xxl-job-admin/api/registry` 上报 `{registryGroup:"EXECUTOR", registryKey:"AppName", registryValue:"http://ip:9999"}`，之后每 20s（Go）/ 30s（Java）续约；中心 `JobRegistryHelper` 每 30s 扫描，删掉 90s 未续约的地址，把存活地址刷回 `xxl_job_group.address_list`。
2. 中心 `JobScheduleHelper` 每轮抢 `xxl_job_lock`，查出 `trigger_next_time` 落在一分钟窗口内的任务，塞进时间轮。
3. 到点触发：按**路由策略**从地址列表挑目标（分片广播则挑全部，并带上分片序号 / 总数）。
4. 中心 `POST http://ip:9999/run`，Body 为 `RunReq` JSON，Header 带 `XXL-JOB-ACCESS-TOKEN`；执行器校验 Handler 已注册 + 应用阻塞策略，异步执行后立即返回。
5. 业务函数执行完 → 执行器 `POST /api/callback` 上报 `handleCode`（200 成功 / 500 失败）与 `handleMsg`。
6. 中心写 `xxl_job_log`；失败按 `executor_fail_retry_count` 重试，成功触发 `child_jobid` 子任务，失败告警；控制台点「执行日志」时，中心 `POST http://ip:9999/log` 反向拉取执行器本地日志内容。

---

## 4. 部署

| 项 | 值 |
| --- | --- |
| 官方镜像 | `xuxueli/xxl-job-admin:2.4.1`（Docker Hub 实时跟进官方 Release） |
| 控制台地址 | `http://127.0.0.1:8080/xxl-job-admin`（**context-path 固定为 `/xxl-job-admin`**） |
| 默认账号 | `admin / 123456`（SQL 里是 `sha256("123456")`，上线第一件事就是改） |
| 数据库 / 端口 | MySQL 5.7 / 8.0（库名 `xxl_job`，脚本取官方 `doc/db/tables_xxl_job.sql`）；admin `8080`、执行器 `9999`、MySQL `3306` |
| 必须改的配置 | `spring.datasource.url/username/password`、`xxl.job.accessToken` |

`docker-compose.yml`（MySQL 首启自动导入建表数据，可直接用）：

```yaml

services:
  mysql:
    image: mysql:8.0
    restart: always
    environment: { MYSQL_ROOT_PASSWORD: root123456, MYSQL_DATABASE: xxl_job }
    command: --character-set-server=utf8mb4 --collation-server=utf8mb4_unicode_ci
    volumes:
      - ./mysql-data:/var/lib/mysql
      # 取自官方仓库 doc/db/tables_xxl_job.sql（含建库、建表、admin 账号、schedule_lock 初始行）
      - ./tables_xxl_job.sql:/docker-entrypoint-initdb.d/tables_xxl_job.sql:ro
    ports: ["3306:3306"]

  xxl-job-admin:
    image: xuxueli/xxl-job-admin:2.4.1
    restart: always
    depends_on: [mysql]
    environment:
      # Spring 配置通过 PARAMS 透传（必须是一整串）
      PARAMS: >-
        --spring.datasource.url=jdbc:mysql://mysql:3306/xxl_job?useUnicode=true&characterEncoding=UTF-8&autoReconnect=true&serverTimezone=Asia/Shanghai
        --spring.datasource.username=root
        --spring.datasource.password=root123456
        --xxl.job.accessToken=change_me_to_a_random_token
      JAVA_OPTS: "-Xmx512m -Xms512m"
    volumes: ["./admin-logs:/data/applogs"]
    ports: ["8080:8080"]
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost:8080/xxl-job-admin/actuator/health"]
      interval: 30s
      retries: 3
```

> ⚠️ 建表脚本里 `INSERT INTO xxl_job_lock VALUES ('schedule_lock')` **必须存在**，否则调度线程拿不到锁、任务永远不触发。
> ⚠️ `xxl.job.accessToken` 是**中心与执行器的共享密钥**，两边必须完全一致，否则报 `The access token is wrong`；此外 `xxl_job_group.access_token` 还有**按组**的独立 token，多团队共用时可据此隔离。
> ⚠️ 中心集群必须连**同一个 MySQL**（锁才有意义），前面挂 Nginx 即可，无需额外选主组件。

---

## 5. 使用一：Go ⭐

```bash

go get github.com/xxl-job/xxl-job-executor-go
```

### 5.1 执行器配置与任务注册

| `xxl` 选项函数 | 作用 | 默认值 / 说明 |
| --- | --- | --- |
| `xxl.ServerAddr(addr)` | 调度中心地址 | 形如 `http://127.0.0.1:8080/xxl-job-admin`，**必须带 context-path** |
| `xxl.AccessToken(token)` | 通讯令牌 | 与中心 `xxl.job.accessToken` 一致 |
| `xxl.ExecutorIp(ip)` | 执行器注册 IP | 默认 `ipv4.LocalIP()`；多网卡 / 容器**必须显式指定** |
| `xxl.ExecutorPort(port)` | 执行器监听端口 | 默认 `9999` |
| `xxl.RegistryKey(name)` | **执行器 AppName** | 默认 `golang-jobs`；须与中心「执行器管理」的 AppName 完全一致 |
| `xxl.SetLogger(l)` | 自定义日志 | 实现 `Info(format string, a ...interface{})` / `Error(...)` 的 `xxl.Logger` |

> ⚠️ Go 客户端**没有** `SetAddresses` / `SetAccessToken` / `SetRegistry` 这类 setter（那是部分博客的臆造或他语言写法），只有上面这些 `Option` 函数；也**不支持可插拔注册中心**，固定走 DB 注册表。

```go

package main

import (
	"context"
	"log"
	"time"

	xxl "github.com/xxl-job/xxl-job-executor-go"
)

func main() {
	exec := xxl.NewExecutor(
		xxl.ServerAddr("http://127.0.0.1:8080/xxl-job-admin"), // 调度中心，必须含 context-path
		xxl.AccessToken("default_token"),                      // = 中心 xxl.job.accessToken
		xxl.ExecutorIp("192.168.1.10"),                        // 对中心可达的 IP，务必显式写
		xxl.ExecutorPort("9999"),                              // 默认 9999
		xxl.RegistryKey("xxl-job-executor-sample"),            // = 中心「执行器管理」的 AppName
	)
	exec.Init()                 // 必须在 RegTask / Run 之前，内部会拉起注册协程
	exec.LogHandler(logHandler) // 控制台看「执行日志」时回拉，见下

	exec.RegTask("demoJobHandler", demoJobHandler) // 名字须与中心 JobHandler 一致
	exec.RegTask("shardingJobHandler", shardingJobHandler)
	// Run() 阻塞至 SIGINT/SIGTERM，自动向中心摘除注册后返回（无需自己 signal.Notify）
	if err := exec.Run(); err != nil {
		log.Fatalf("executor exit: %v", err)
	}
}

// 任务签名固定：func(ctx context.Context, param *xxl.RunReq) string
func demoJobHandler(ctx context.Context, param *xxl.RunReq) string {
	log.Printf("handler=%s param=%q logId=%d timeout=%ds",
		param.ExecutorHandler, param.ExecutorParams, param.LogID, param.ExecutorTimeout)
	for i := 0; i < 5; i++ {
		select {
		case <-ctx.Done(): // 中心超时 / 人工 kill 会 cancel，必须处理
			return "demo canceled: " + ctx.Err().Error()
		default:
		}
		time.Sleep(time.Second)
	}
	return "demo done"
}

// 实现后控制台「执行日志」才能看到内容；不实现则用内置的本地日志文件读取
func logHandler(req *xxl.LogReq) *xxl.LogRes {
	return &xxl.LogRes{Code: xxl.SuccessCode, Content: xxl.LogResContent{
		FromLineNum: req.FromLineNum, LogContent: "在此接入日志文件或日志采集系统",
		IsEnd: true, // 必须为 true，否则控制台会不停分页拉取
	}}
}
```

**优雅停止**：`exec.Run()` 内部已注册信号监听，收到 `SIGINT/SIGTERM/SIGQUIT/SIGKILL` 后调 `registryRemove()` 再返回。若要把执行器嵌进已有 `http.Server` 或交给框架托管生命周期，可只用 `exec.Init()`，后续手动 `exec.Stop()`。

**中间件**（统一日志、耗时统计、panic 兜底、链路透传）：`exec.Use(mw1, mw2)`，签名 `func(xxl.TaskFunc) xxl.TaskFunc`。注意 `Use` 是**整体覆盖**而非追加，多个中间件要在一次调用里传完。

### 5.2 分片广播 ⭐

路由策略选 `SHARDING_BROADCAST` 时，中心会把任务**广播给该 AppName 下的每一台执行器**，并在 `RunReq` 里带上：

| 字段 | 含义 | 说明 |
| --- | --- | --- |
| `param.BroadcastIndex` | 当前分片序号 | **从 0 开始**，0 表示第一片 |
| `param.BroadcastTotal` | 执行器总实例数（总分片数） | 路由策略不是分片广播时为 **0** |

> ⚠️ 字段名就是 `BroadcastIndex` / `BroadcastTotal`（`int64`），语义等同 Java 的 `shardIndex` / `shardTotal`。大量博客写成 `param.ShardIndex`，那是**错的**，编译不过。

```go

// shardingJobHandler：每个执行器实例只处理自己那一份数据（需 import "context" / "fmt" / "log"）
// 典型场景：把千万级待处理行横向拆到 N 台机器并行跑
func shardingJobHandler(ctx context.Context, param *xxl.RunReq) string {
	idx, total := int(param.BroadcastIndex), int(param.BroadcastTotal)
	if total <= 1 { // total<=0 说明本次不是分片广播路由（如被改成 ROUND），退化为单机全量
		idx, total = 0, 1
	} else if idx < 0 || idx >= total {
		return fmt.Sprintf("invalid shard param: %d/%d", idx, total)
	}

	rows := loadIDs() // 业务侧自行分页拉取「待处理行」
	handled := 0
	for n, id := range rows {
		if n%total != idx { // 不属于本分片的直接跳过
			continue
		}
		select {
		case <-ctx.Done(): // 超时或人工 kill，立刻让出，避免脏写
			return fmt.Sprintf("shard %d/%d canceled, handled=%d", idx+1, total, handled)
		default:
		}
		if err := process(ctx, id); err != nil {
			// ⚠️ 单行失败不要直接 return，否则本分片剩余数据全部漏跑；记录明细后 continue
			log.Printf("shard %d/%d process id=%d err=%v", idx+1, total, id, err)
			continue
		}
		handled++
	}
	return fmt.Sprintf("shard %d/%d done, handled=%d", idx+1, total, handled)
}
func loadIDs() []int64                          { return []int64{1, 2, 3, 4, 5, 6, 7, 8, 9, 10} }
func process(_ context.Context, id int64) error { _ = id; return nil }
```

分片广播的**正确姿势**是「每片自己 `MOD` 取子集」，而不是「按数据库主键区间切」——区间方案在实例上下线时会漏数据或重复。中心不感知数据，只保证每个在线实例各收到一次、且带正确的 index / total。

### 5.3 失败返回与重试的处理约定 ⚠️

Go 客户端**最容易踩的坑**：`Task.Run` 的逻辑是——**函数正常返回 → 一律回调 `code=200`（成功）；只有 `panic` 才回调 `code=500`（失败）**。任务函数返回的字符串只是 `handleMsg`（日志内容），**不是状态码**。

| 业务结果 | Go 侧写法 | 中心表现 |
| --- | --- | --- |
| 成功 | `return "done"` | `handleCode=200`，绿色 |
| 业务失败，需重试 / 告警 | **必须 `panic(err)`**，或用中间件把错误约定转成 panic | `handleCode=500`，按 `executor_fail_retry_count` 重试 + 告警 |
| 超时 | 不 panic；中心按 `executor_timeout` 判失败并调 `/kill` | cancel 掉 `ctx`，函数应尽快返回 |

```go

// 用中间件把「返回以 ERROR: 开头的字符串」统一转成 panic，避免业务里到处 panic（需 import "strings"）
func failFastMiddleware(next xxl.TaskFunc) xxl.TaskFunc {
	return func(ctx context.Context, param *xxl.RunReq) string {
		msg := next(ctx, param)
		if strings.HasPrefix(msg, "ERROR:") { // 约定：以 ERROR: 开头即业务失败
			panic(msg)                          // 触发回调 code=500 → 中心重试 / 告警
		}
		return msg
	}
}

// exec.Use(failFastMiddleware)，任务里只需： return "ERROR: 库存扣减失败, orderId=123"
```

> ⚠️ 重试 = **业务会被重复执行**。所有任务按「至少一次」设计：业务幂等键（如 `bizKey + 业务日期`）、DB 唯一索引、状态机前置判断。
> ⚠️ `panic` 会被 `recover` 住并打印堆栈，不会让进程挂掉；但**别**用 panic 表达可恢复的单行错误（见 5.2 的 `continue`）。

---

## 6. 使用二：Java（官方，对照）

```xml

<!-- 调度中心与执行器共用核心包，版本须与 admin 镜像一致 -->
<dependency>
  <groupId>com.xuxueli</groupId>
  <artifactId>xxl-job-core</artifactId>
  <version>2.4.1</version>
</dependency>
```

```properties

# application.properties，key 名与官方 sample 一致
xxl.job.admin.addresses=http://127.0.0.1:8080/xxl-job-admin
xxl.job.accessToken=default_token
xxl.job.executor.appname=xxl-job-executor-sample
xxl.job.executor.port=9999
xxl.job.executor.logpath=/data/applogs/xxl-job/jobhandler
xxl.job.executor.logretentiondays=30
```

```java

@Configuration // 另需 import XxlJobSpringExecutor / @Value / @Bean / @Configuration
public class XxlJobConfig {
    @Value("${xxl.job.admin.addresses}")           private String adminAddresses;
    @Value("${xxl.job.accessToken}")               private String accessToken;
    @Value("${xxl.job.executor.appname}")          private String appname;
    @Value("${xxl.job.executor.port}")             private int port;
    @Value("${xxl.job.executor.logpath}")          private String logPath;
    @Value("${xxl.job.executor.logretentiondays}") private int logRetentionDays;

    @Bean
    public XxlJobSpringExecutor xxlJobExecutor() {
        XxlJobSpringExecutor executor = new XxlJobSpringExecutor();
        executor.setAdminAddresses(adminAddresses);
        executor.setAppname(appname);   // 注意：2.3+ 是 setAppname（早期写作 setAppName）
        executor.setPort(port);
        executor.setAccessToken(accessToken);
        executor.setLogPath(logPath);
        executor.setLogRetentionDays(logRetentionDays);
        return executor;
    }
}
```

```java

@Component // 另需 import XxlJobHelper / XxlJob / @Component / java.util.List
public class SampleXxlJob {

    /** Handler 名与中心任务配置的 JobHandler 对应 */
    @XxlJob("demoJobHandler")
    public void demoJobHandler() throws Exception {
        String param = XxlJobHelper.jobParam();          // 对应 Go 的 param.ExecutorParams
        XxlJobHelper.log("param={}", param);             // 写执行器本地日志，控制台自动回拉

        int shardIndex = XxlJobHelper.getShardIndex();   // 非分片路由时为 0
        int shardTotal = XxlJobHelper.getShardTotal();   // 非分片路由时为 1
        XxlJobHelper.log("shard {}/{}", shardIndex, shardTotal);

        List<Long> ids = loadIds();
        for (int i = 0; i < ids.size(); i++) {
            if (i % shardTotal != shardIndex) continue;  // 只管自己那一份
            process(ids.get(i));
        }
        // 默认成功；失败用： XxlJobHelper.handleFail("业务失败原因");  → code=500，触发重试 / 告警
    }

    private List<Long> loadIds() { return List.of(1L, 2L, 3L); }
    private void process(Long id) { /* 业务 */ }
}
```

> ⭐ 与 Go 的体感差异：Java 有 `XxlJobHelper.log()` 把运行日志写进**本地日志文件**并由控制台自动回拉；Go 必须自己实现 `LogHandler` 才有同样效果。另外 Java 用 `XxlJobHelper.handleFail/handleSuccess` **显式**表达成功失败，Go 只能靠「不 panic = 成功」。

### 6.1 Go ↔ Java 能力对照表

| 维度 | Go（`xxl-job-executor-go`） | Java（`xxl-job-core`） |
| --- | --- | --- |
| 依赖坐标 | `go get github.com/xxl-job/xxl-job-executor-go` | `com.xuxueli:xxl-job-core:2.4.1` |
| 执行器配置 | `xxl.NewExecutor(Option...)` 函数式选项 | `XxlJobSpringExecutor` Bean + `setXxx()` |
| 中心地址配置名 | `xxl.ServerAddr(...)` | `xxl.job.admin.addresses` |
| 令牌配置名 | `xxl.AccessToken(...)` | `xxl.job.executor.accessToken` |
| AppName 配置名 | `xxl.RegistryKey(...)`，默认 `golang-jobs` | `xxl.job.executor.appname` |
| 监听端口 | `xxl.ExecutorPort(...)`，默认 `9999` | `xxl.job.executor.port`，默认 `9999` |
| 任务定义 | `exec.RegTask("name", fn)` | `@XxlJob("name")` 注解在方法上 |
| 任务签名 | `func(ctx context.Context, p *xxl.RunReq) string` | 无参方法 + `XxlJobHelper` 取上下文 |
| 取任务参数 | `param.ExecutorParams` | `XxlJobHelper.jobParam()` |
| 分片索引 / 总数 | `param.BroadcastIndex` / `param.BroadcastTotal`（0 起） | `getShardIndex()` / `getShardTotal()`（0 起） |
| 非分片路由下的分片值 | `BroadcastTotal = 0`（需自行兜底为 1） | `shardTotal = 1` |
| 写执行日志 | 自定义 `LogHandler` + 自己落文件 | `XxlJobHelper.log(...)` 自动落 `logpath` |
| 成功 / 失败上报 | 正常返回 = 成功；**panic = 失败** | `handleSuccess()` / `handleFail(msg)` 显式调用 |
| 超时 / kill 感知 | `ctx.Done()`（`executor_timeout` 转成 `context.WithTimeout`） | 线程 interrupt，检查 `Thread.currentThread().isInterrupted()` |
| 并发模型 | goroutine（不设上限，靠阻塞策略兜底） | 线程池 `jobThreadRepository` + `FastThreadPool` |
| 注册中心 | 仅 DB 注册表（`/api/registry`），心跳 **20s** | 默认 DB 注册表，心跳 **30s**；可插拔 ZK / Nacos / Consul / etcd |
| 运行模式 | 仅 BEAN（业务函数） | BEAN + GLUE(Java) + Shell/Python/NodeJS/PHP/PowerShell |
| 路由 / 阻塞策略 | 均由**调度中心**配置下发，客户端只按 `ExecutorBlockStrategy` 执行 | 同左（策略实现全在 admin 侧） |
| 优雅停机 | `Run()` 捕获信号 → `registryRemove()` | 实现 `SmartLifecycle`，`destroy()` 时摘除注册 |

---

## 7. 生产实践与坑

| 主题 | 做法 / 坑点 |
| --- | --- |
| **网络可达性** ⚠️ | 触发方向是 **中心 → 执行器 `:9999`**，执行器端口必须对中心开放；「执行器能访问中心」≠「中心能访问执行器」。容器环境别只放通出方向，K8s 里确认 Service / NetworkPolicy 允许中心侧入站 |
| **AccessToken 一致** ⚠️ | 中心 `xxl.job.accessToken` = 执行器 `accessToken`，另加 `xxl_job_group.access_token` 的按组密钥；三者对不上报 `The access token is wrong`，且线索只体现在中心日志 |
| **任务幂等** ⚠️ | 重试、`COVER_EARLY` 覆盖、手动执行都会重复触发；必须用业务幂等键 + 唯一索引 + 状态机，禁止假设「只跑一次」 |
| **分片参数有效性** ⚠️ | `BroadcastIndex/BroadcastTotal` **只在 `SHARDING_BROADCAST` 下有效**；改成 `ROUND` 后 Go 拿到 `0/0`、Java 拿到 `0/1`，代码必须兜底，否则 `n % 0` 直接 panic |
| **AppName 与 Handler 名** | 两者都是**字符串强绑定**：AppName 决定注册归属，Handler 名对不上会报「Task not registered / 没有注册」 |
| **超时设置** | `executor_timeout` 默认 0（不限）是事故高发点，大任务务必显式设置；Go 侧只有主动检查 `ctx.Done()` 才真的「停得下来」 |
| **日志与磁盘** | 执行器 `logpath` 默认留 30 天，务必挂持久卷 + 配轮转；`xxl_job_log` 表增长最快（每次调度一行），按 `trigger_time` 归档清理，否则 MySQL 磁盘先满 |
| **中心高可用** | admin 至少 2 实例 + 同一 MySQL，Nginx 前置；DB 锁保证同时只有一台在调度，故障切换时任务最多延迟 5s（`PRE_READ_MS`）量级 |
| **执行器多实例** | 要「不重复执行」必须用 `ROUND/RANDOM/FIRST` 等单播策略；要「并行提速」才用 `SHARDING_BROADCAST`，两者语义互斥 |
| **注册表脏数据** | 执行器被 `kill -9` 后地址会滞留至多 90s，期间路由可能打到死节点（表现为触发失败但执行器无日志）；发版用 SIGTERM 优雅停机可避免 |
| **时间同步** | 中心按毫秒级 `trigger_next_time` 判断，机器时间漂移会导致漏调度或重复补调，所有节点必须 NTP 对时 |

---

## 8. 选型对比

| 维度 | **XXL-JOB** | Quartz | Spring `@Scheduled` | K8s CronJob | Airflow |
| --- | --- | --- | --- | --- | --- |
| 定位 | 分布式任务调度平台 | 单机调度框架（集群需自建） | 应用内定时器 | 容器内定时任务 | 数据流水线编排 |
| 集群 / 高可用 | **原生**（DB 锁选主） | 需配 `JobStore` + 集群表 | ❌ 多副本会重复执行 | 原生（控制面保证） | 原生（Scheduler HA） |
| 可视化 / 日志 | **完善**（Web + 执行日志回拉） | 无 | 无 | `kubectl logs` | **完善**（DAG + 任务实例） |
| 失败重试 / 告警 | 内置重试 + 邮件告警 | 有重试，无告警 | 无 | `backoffLimit`，告警另配 | **强**（重试 / SLA / 回调） |
| 分片 / 并行 | **分片广播**开箱可用 | 需自行实现 | 无 | `parallelism` + Indexed Job | Task 级并行，最灵活 |
| 依赖 / DAG | 仅父子任务（单层） | 无 | 无 | 无（需 Argo Workflows） | **原生 DAG，最强** |
| 动态改 Cron / 参数 | **控制台改，秒级生效** | 需重启或编程改 | 需重启 | 改 YAML 并 apply | 改 DAG 文件并部署 |
| 跨语言 | **OpenAPI / 多语言 SDK**（Go / Python / PHP / Node…） | Java 生态 | Java 生态 | 任意（只要有镜像） | Python 生态 |
| 最适合 | 业务定时任务：对账、报表、清理、补偿 | 存量 Java 单体项目 | 极其简单的单机小任务 | 纯 K8s 的运维 / 一次性任务 | 数据 / AI 编排，强依赖关系 |

**选型口诀**：Java / Go 微服务里跑业务定时任务 → **XXL-JOB**；已在 K8s 且任务是运维 / 一次性 Job → **K8s CronJob**；有复杂 ETL 依赖、要回填历史数据 → **Airflow**；单机 JVM 小工具 → `@Scheduled` 就够。

---

## 9. 面试官会追问什么

1. **调度中心如何保证同一任务不被重复调度？** 集群里每个实例都在跑调度线程，但调度前必须先执行 `SELECT * FROM xxl_job_lock WHERE lock_name='schedule_lock' FOR UPDATE`，**只有拿到 MySQL 行锁的实例**才能继续扫描 `xxl_job_info` 并推进时间轮；锁随事务提交释放，天然排他，因此任意时刻只有一个实例在调度——不需要 ZK 选主。
2. **执行器怎么被发现的？** 不是中心扫机器，而是**执行器主动注册**：启动后 POST `/api/registry` 上报 `AppName + http://ip:port`，之后周期续约（Go 20s / Java 30s）。中心每 30s 扫描注册表，删除 90s 未续约（`DEAD_TIMEOUT = BEAT_TIMEOUT × 3`）的地址，并把存活地址刷回 `xxl_job_group.address_list`。
3. **中心和执行器之间是长连接还是消息队列？** 都不是，是**中心主动 HTTP 调用执行器的 `/run` 接口**（反向触发）。硬前提是**执行器 `:9999` 必须对中心网络可达**，否则注册成功但触发失败——最常见的排查点是防火墙 / K8s NetworkPolicy 只放通了执行器出方向。
4. **分片广播的原理和用途？** `SHARDING_BROADCAST` 路由时中心把一次调度**广播给该 AppName 下所有在线执行器**，每条请求带 `broadcastIndex`（0 起）与 `broadcastTotal`。用途是把大数据量任务按 `index % total` 横向拆到多机并行，如千万级用户的批量对账。⚠️ 参数只在分片路由下有意义：其他路由下 Go 是 `0/0`、Java 是 `0/1`，代码必须兜底。
5. **失败重试会不会重复执行业务？** 会。重试是中心层面「重新触发一次任务」，不感知业务状态，所以任务必须**幂等**（业务唯一键 + DB 唯一索引 + 状态机）。另外 `COVER_EARLY` 会 kill 掉正在跑的任务再重跑，同样要求幂等。
6. **调度精度有多高？** **秒级**。调度线程每轮预读 `PRE_READ_MS = 5000ms` 的任务塞进 60 槽时间轮，ring 线程按秒推进；加上 DB 锁竞争与网络往返，实际触发有百毫秒到秒级抖动。要毫秒级请不要用它。
7. **Go 客户端为什么「业务失败不重试」？** 因为 `Task.Run` 只有 panic 才回调 `code=500`，函数正常返回一律 200。所以 Go 侧必须显式 `panic`（或用中间件把错误约定转成 panic）才能让中心触发重试与告警——这是 Go 与 Java（`XxlJobHelper.handleFail`）最本质的差异。
8. **和 K8s CronJob 怎么选？** 看任务归属与治理需求：**业务定时任务**（要动态改 Cron、看执行日志、失败告警、按业务参数分片）选 XXL-JOB；**运维 / 与业务解耦的一次性 Job、且团队已重度使用 K8s** 选 CronJob。两者可共存——XXL-JOB 执行器本身就能跑在 K8s Pod 里，只是不必再把调度逻辑塞进镜像。

## 关联

- [../数据存储/mysql/分表与路由.md](../数据存储/mysql/分表与路由.md) — 分片广播任务落在哪张表上
- [../分布式/服务发现与负载均衡.md](../分布式/服务发现与负载均衡.md) — 执行器地址的刷新与健康检查
- [../分布式/分布式ID.md](../分布式/分布式ID.md) — 分片键与 ID 生成的关系
- [Nacos.md](Nacos.md) — 同为「一个进程解决一类基础设施问题」
