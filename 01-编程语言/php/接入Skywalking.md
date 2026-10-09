# PHP-FPM 接入 SkyWalking

> 内容整理自个人学习笔记 —— 按 apache/skywalking-php（PHP agent，v1.1.0）的**官方文档与源码口径**，从一台只有裸 `php-fpm` 镜像的机器开始，走一遍标准接入流程：**链路原理 → 装扩展 → 配 php.ini → 选 reporter → 容器化 → 日志与指标 → 验证排查**。
>
> 本篇只讲 PHP 侧。SkyWalking 本体原理、OAP/UI 见 [Skywalking.md](../../03-数据与中间件/中间件/可观测性/Skywalking.md)；Go 与 Java 的接入分册见 [接入Skywalking.md](../go/可观测性/接入Skywalking.md)、[接入Skywalking.md](../java/接入Skywalking.md)。

---

## 一、数据是怎么从 PHP 进程跑出去的？⭐

**本节要点**：这一节是全文的推导起点。记住「**PHP 进程内 hook 采集 → Unix socket → fork 出来的 Rust worker → gRPC 到 OAP**」这条链，后面每个配置项（前台启动、`runtime_dir`、reporter 选型、优雅停机）都不用背，全能从链路推出来。

```text
php-fpm master 启动，加载 skywalking_agent.so
   ↓ module init：fork 一个 Rust worker 子进程（Linux 上挂 PR_SET_PDEATHSIG，master 死它跟着死）
   ↓ worker 在 runtime_dir 下监听一个 unix socket（{微秒时间戳}.sock）
每个 PHP 请求：扩展 hook 住 curl / PDO / mysqli / redis 等调用，生成 span 与日志
   ↓ 经 unix socket 写 bincode 序列化的 CollectItem
worker 攒批后经 gRPC（默认 11800）推给 OAP
   ↓
SkyWalking UI（服务名 = skywalking_agent.service_name）
```

四个隐含结论，后文全部由它们推出：

1. **worker 是 master 在 module init 时 fork 的**。php-fpm 以 daemon 方式启动时，master fork 到后台后原进程退出，worker 被 `PR_SET_PDEATHSIG` 带走——所以 **grpc/kafka reporter 要求 php-fpm 前台运行（`-F`）**，daemon 模式只能换 standalone reporter（第四节）。
2. **PHP 进程与 worker 之间走 unix socket，文件落在 `runtime_dir`**（默认 `/tmp/skywalking-agent`）。目录不可写时 agent 初始化直接放弃，表现是「扩展在、配置对、零数据」（第五节）。
3. **agent 只在 `fpm-fcgi` SAPI（或 cli + swoole）下激活**。`php -m` 能看到扩展不代表它在干活：CLI 脚本、cron 任务默认不上报（第八节）。
4. **数据是异步攒批发送的**，进程被强杀就来不及发完，所以优雅停机直接影响可观测性（第五节）。

---

## 二、扩展怎么装进镜像？

**本节要点**：扩展名是 `skywalking_agent.so`（pecl 包名同为 `skywalking_agent`），不是早年间的 `skywalking`；它是 Rust + C 混合扩展，**编译需要 Rust 工具链**，所以生产镜像一律走多阶段构建，别在运行镜像里留 cargo。

三小节的关系：**2.1 是编译依赖（前置条件），2.2 与 2.3 是两条互斥的落地路径**。已有非容器环境（虚拟机、自建机）走 2.2，其中 pecl 与源码再二选一；容器场景**只看 2.3**——它的 builder 阶段已经把 2.1 的依赖与 2.2 的 pecl 装法包进去了，宿主机上不需要先执行 2.1、2.2。

版本要求：PHP 7.2 – 8.x，SkyWalking OAP 8.4+（`skywalking_agent.skywalking_version` 只认 8 / 9，小于 8 时 agent 直接不激活）。

### 2.1 编译依赖

以下依赖只在**真正执行编译的那一层**需要：走 2.2 时装在宿主机上，走 2.3 时写在 Dockerfile 的 builder 阶段里，运行镜像一律不带。

| 系统 | 安装命令 |
| --- | --- |
| Debian 系 | `apt install gcc make llvm-13-dev libclang-13-dev protobuf-c-compiler protobuf-compiler` |
| Alpine | `apk add gcc make musl-dev llvm15-dev clang15-dev protobuf-c-compiler` |

Rust 要求 1.85+。仓库里有 `rust-toolchain.toml` 会覆盖 rustup 的默认工具链，所以官方建议要么用系统包管理器装 Rust，要么 rustup 装时 `--default-toolchain none`：

```bash
curl --proto '=https' --tlsv1.2 -sSf https://sh.rustup.rs | sh -s -- --default-toolchain none
```

Alpine 上还要关掉静态链接，否则报 `libclang.so … Dynamic loading not supported`：

```bash
export RUSTFLAGS="-C target-feature=-crt-static"
```

### 2.2 两种安装方式（裸机 / 已有镜像，二选一）

```bash
# 方式一：pecl（最省事，编译过程同上，依赖缺一不可）
pecl install skywalking_agent
docker-php-ext-enable skywalking_agent
```

```bash
# 方式二：源码
git clone --recursive https://github.com/apache/skywalking-php.git
cd skywalking-php
phpize
./configure
make
make install
```

### 2.3 生产镜像：多阶段构建（容器场景的唯一入口）

官方仓库的 `docker/Dockerfile` 就是这个套路：builder 阶段装 Rust 与编译依赖、`pecl install`，运行阶段只拷两样东西——扩展 `.so` 和 `docker-php-ext-enable` 生成的 conf.d ini。照它简化：

```dockerfile
FROM php:8.2-fpm-bookworm AS builder
RUN apt-get update \
    && apt-get install -y gcc make llvm-13-dev libclang-13-dev \
       protobuf-c-compiler protobuf-compiler wget \
    && curl --proto '=https' --tlsv1.2 -sSf https://sh.rustup.rs \
       | sh -s -- -y --no-modify-path --profile minimal --default-toolchain none \
    && . "$HOME/.cargo/env" \
    && pecl install skywalking_agent \
    && rm -rf /var/lib/apt/lists/*

FROM php:8.2-fpm-bookworm
COPY --from=builder /usr/local/lib/php/extensions/ /usr/local/lib/php/extensions/
COPY --from=builder /usr/local/etc/php/conf.d/docker-php-ext-skywalking_agent.ini \
     /usr/local/etc/php/conf.d/
COPY skywalking.ini.tpl /tmp/skywalking.ini.tpl
COPY run.sh /usr/local/bin/run.sh
RUN chmod +x /usr/local/bin/run.sh
ENTRYPOINT ["/usr/local/bin/run.sh"]
```

- `COPY /usr/local/lib/php/extensions/` 整目录拷，是为了不硬编码 `no-debug-non-zts-XXXXXXXX/` 这段随 PHP 版本变化的 API 目录名。
- `skywalking.ini.tpl` 与 `run.sh` 见第三、五节；conf.d 里的 ini 只负责 `extension=` 加载，业务配置单独一份，避免和镜像升级互相覆盖。
- 该 Dockerfile **未在本机构建验证**（无对应构建环境），依赖清单与路径核对自官方仓库的 `docker/Dockerfile` 与 setup 文档。

---

## 三、php.ini 怎么配？

**本节要点**：所有 `skywalking_agent.*` 配置在源码里都注册为 **System 级**——`ini_set()`、`.user.ini`、`.htaccess` 一律改不动，只能写 php.ini / conf.d、fpm 池的 `php_admin_value`、或 CLI 的 `-d`。默认 `enable = Off` 是刻意的：官方不建议全局打开，否则每个 CLI 脚本、cron 任务都要白付 hook 与 fork 的开销。

### 3.1 最小可用配置

```ini
[skywalking_agent]
extension = skywalking_agent.so

; 总开关，默认 Off
skywalking_agent.enable = On

; 上报方式：grpc / kafka / standalone，默认 grpc
skywalking_agent.reporter_type = grpc

; OAP 的 gRPC 端口，默认 127.0.0.1:11800（不是 UI 的 8080）
skywalking_agent.server_addr = oap.skywalking.svc:11800

; UI 上的服务名与实例名；instance 留空则是「随机串@第一个非 lo/docker 网卡的 IPv4」
skywalking_agent.service_name = order-service
skywalking_agent.instance_name = ${HOSTNAME}

; 排障期打开，稳定后调回 OFF
skywalking_agent.log_level = INFO
skywalking_agent.log_file = /tmp/skywalking-agent.log

; PHP 进程与 worker 之间 unix socket 的存放目录，必须可写
skywalking_agent.runtime_dir = /tmp/skywalking-agent
```

`${HOSTNAME}` 是 php-fpm 对 ini 值的环境变量替换（官方文档对 `instance_name` 就是这么举例的），前提是池配置里 `clear_env = no`，否则 worker 环境被清空、替换拿不到值。不想依赖这个特性的，用第五节的 envsubst 模板。

### 3.2 全量配置速查

| 配置项 | 默认值 | 说明 |
| --- | --- | --- |
| `skywalking_agent.enable` | Off | 总开关 |
| `skywalking_agent.service_name` | hello-skywalking | UI 服务名 |
| `skywalking_agent.instance_name` | 空（随机@IP） | 实例名，可用 `${HOSTNAME}` |
| `skywalking_agent.server_addr` | 127.0.0.1:11800 | OAP gRPC 地址，仅 grpc |
| `skywalking_agent.reporter_type` | grpc | grpc / kafka / standalone |
| `skywalking_agent.runtime_dir` | /tmp/skywalking-agent | unix socket 目录 |
| `skywalking_agent.log_file` / `log_level` | /tmp/skywalking-agent.log / INFO | agent 自身日志；级别 OFF/TRACE/DEBUG/INFO/WARN/ERROR |
| `skywalking_agent.skywalking_version` | 8 | 只认 8 / 9 |
| `skywalking_agent.worker_threads` | 0 | worker 的 tokio 线程数，0 = CPU 核数 |
| `skywalking_agent.heartbeat_period` | 30 | 心跳间隔（秒） |
| `skywalking_agent.properties_report_period_factor` | 10 | 实例属性每 `heartbeat_period × factor` 秒报一次 |
| `skywalking_agent.enable_zend_observer` | On | PHP8+ 用 observer API 做 hook；PHP7 只能走 `zend_execute_ex` |
| `skywalking_agent.inject_context` | Off | 把 `SW_TRACE_ID` 等注入 `$_SERVER`（第六节） |
| `skywalking_agent.psr_logging_level` | Off | PSR-3 日志上报阈值（第六节） |
| `skywalking_agent.authentication` | 空 | OAP 开了 token 认证才填，仅 grpc |
| `skywalking_agent.enable_tls` / `ssl_trusted_ca_path` / `ssl_key_path` / `ssl_cert_chain_path` | Off / 空 | gRPC TLS 与 mTLS，仅 grpc |
| `skywalking_agent.kafka_bootstrap_servers` / `kafka_producer_config` | 空 / {} | 仅 kafka reporter |
| `skywalking_agent.standalone_socket_path` | 空 | 仅 standalone reporter |
| `skywalking_agent.metrics_enable` / `metrics_report_period` | Linux On / 30 | PHM 进程指标，仅 Linux（读 `/proc`），第七节 |

---

## 四、reporter 三种模式怎么选？

**本节要点**：区别只有一件事——**worker 进程谁来起、活在哪**。grpc / kafka 由扩展在 module init 时 fork；standalone 不 fork，由运维单独起一个 `skywalking-php-worker` 二进制，PHP 进程只往固定 socket 写。

| 模式 | worker 来源 | 适用场景 | 坑 |
| --- | --- | --- | --- |
| `grpc` | 扩展 fork | php-fpm 前台（`-F`）、Swoole 单进程 | daemon 模式下 worker 随原 master 退出，**零数据** |
| `kafka` | 扩展 fork | OAP 侧走 Kafka 收数的架构 | 需要带 kafka feature 的构建；pecl 包是否启用以 `php --ri skywalking_agent` 为准，拿不准就源码构建 |
| `standalone` | 人工启动二进制 | php-fpm daemon 模式、一台机器多 fpm 实例（避免每进程一个 worker） | 多一个要守护的进程；socket 路径要双方一致 |

standalone 的起法（官方文档原样）：

```bash
# 1. 编译 worker 二进制
cargo build -p skywalking-php-worker --bin skywalking-php-worker --all-features --release

# 2. 起 worker：监听 unix socket，后端走 grpc
./target/release/skywalking-php-worker -s /tmp/skywalking-php-worker.sock grpc --server-addr 127.0.0.1:11800
```

```ini
[skywalking_agent]
extension = skywalking_agent.so
skywalking_agent.reporter_type = standalone
skywalking_agent.standalone_socket_path = /tmp/skywalking-php-worker.sock
```

容器里用 grpc + `-F` 是最省心的组合：worker 的生命周期天然跟 master 绑定，不需要额外的进程管理。**下文默认这个组合**。

---

## 五、容器化接入的四个坑

**本节要点**：镜像装好、ini 配好之后，容器化还剩四件事——前台启动、PID 1 信号、`runtime_dir` 可写、宽限期够长。四个坑的表象全是「没数据」或「容器重启」，没有一个会报业务错误。

### 5.1 php-fpm 必须前台运行

php-fpm 默认 daemonize：master fork 到后台后父进程立即退出。在容器里这意味着 PID 1 结束 → 容器被判退出 → CrashLoopBackOff，日志里看不到任何业务报错。`-F`（`--nodaemonize`）让 master 常驻前台。

顺带纠正一个常见误传：**`-R` 不是前台启动的一部分**。`-R` 是「允许 worker 以 root 运行」，官方 php 镜像 master 以 root 启动、worker 按池配置降权到 www-data，不需要 `-R`；只有把池的 `user` 也写成 root 时才需要它，而那本身就不该做。

### 5.2 PID 1 与信号转发

Dockerfile 的 `ENTRYPOINT` 是 shell 脚本时，脚本最后一行必须 `exec`，否则 SIGTERM 只打到 shell 上，php-fpm 收不到，只能等宽限期到点被 SIGKILL——在途请求与未发完的链路数据一起丢。不想改脚本就引入 `tini` / `dumb-init` 当 init。

### 5.3 启动脚本模板（envsubst 渲染配置）

用模板渲染代替 ini 里的 `${VAR}`，好处是不依赖 `clear_env`、渲染结果可以直接 `cat` 出来核对：

```bash
#!/bin/sh
set -e

# 把环境变量渲染进 skywalking 配置；gettext-base 提供 envsubst
envsubst '${SW_SERVICE_NAME} ${SW_SERVER_ADDR} ${SW_ENABLE}' \
  < /tmp/skywalking.ini.tpl \
  > /usr/local/etc/php/conf.d/zz-skywalking.ini

/usr/sbin/nginx
exec php-fpm -F
```

对应的模板（占位符只列需要按服务变化的项）：

```ini
[skywalking_agent]
skywalking_agent.enable = ${SW_ENABLE}
skywalking_agent.service_name = ${SW_SERVICE_NAME}
skywalking_agent.server_addr = ${SW_SERVER_ADDR}
skywalking_agent.instance_name = ${HOSTNAME}
skywalking_agent.log_level = INFO
skywalking_agent.log_file = /tmp/skywalking-agent.log
skywalking_agent.runtime_dir = /tmp/skywalking-agent
```

- `envsubst` 只替换白名单里的变量，模板中的 `${HOSTNAME}` 留给 php-fpm 自己解析，不会被提前吃掉。
- 镜像里要有 `gettext-base`（Debian slim 基础镜像默认没有）：`apt-get install -y gettext-base`。
- `exec php-fpm -F` 一行同时解决 5.1 与 5.2。

### 5.4 `runtime_dir` 与只读根文件系统

`runtime_dir` 默认在 `/tmp`，普通容器直接可用。一旦给容器开了 `readOnlyRootFilesystem: true`，`/tmp` 也变成只读，agent 初始化创建目录失败后**静默不工作**。此时把它和 agent 日志挂成 `emptyDir`：

```yaml
volumes:
  - name: sw-runtime
    emptyDir: {}
volumeMounts:
  - name: sw-runtime
    mountPath: /tmp/skywalking-agent
```

与早年共享内存方案不同，现在的通道是 unix socket + 进程内队列，**不再需要为 `/dev/shm` 单独扩容**；`emptyDir` 不指定 `medium: Memory` 时走节点磁盘，不占内存 limit。

### 5.5 宽限期要大于在途请求时间

K8s 删 Pod 的时序是「SIGTERM → 等 `terminationGracePeriodSeconds`（默认 30s）→ SIGKILL」。php-fpm 收到 SIGTERM 走 graceful stop：master 停接新连接，等 worker 把手上请求处理完，单请求上限受池配置 `request_terminate_timeout` 约束（默认 0 表示不限，生产一般会设一个值）。这段时间也是链路数据上报的最后窗口，所以：

**`terminationGracePeriodSeconds` > `request_terminate_timeout` >  P99 请求耗时**。

worker 靠 `PR_SET_PDEATHSIG` 跟着 master 走：master 还在 graceful stop，worker 就还活着、还能发数据；master 一退，worker 立刻收到 SIGTERM，队列里剩下的就没了。任何 `kill -9` 都等于放弃最后一批数据，这与 Java agent 靠 ShutdownHook flush 是同一个道理（见 [Skywalking.md](../../03-数据与中间件/中间件/可观测性/Skywalking.md) 第五节）。

---

## 六、业务日志怎么和 trace 关联？

**本节要点**：日志要能和链路关联才有排障价值。两条路按项目日志框架选：用 PSR-3 日志库的零代码改动，自研日志框架的把 trace id 打进日志行。

**方式一：PSR-3 自动上报（零代码）**。设 `skywalking_agent.psr_logging_level = Warning` 后，agent 会 hook 任何实现 `Psr\Log\LoggerInterface` 的日志库（Monolog、Yii2 logger、Symfony logger 都算），把**级别不低于阈值**的日志随当前 trace 一起上报：

```php
// Monolog / 任意 PSR-3 实现，无需改代码；psr_logging_level = Warning 时这条会进 SkyWalking 日志面板
$logger->warning('order pay timeout', ['order_id' => $orderId]);
```

阈值语义是「大于等于才报」：设成 `Warning` 则 `Warning/Error/Critical/Alert/Emergency` 上报，`Info/Debug` 不上报。默认 `Off` 一条都不报。

**方式二：自研日志框架，把 trace id 写进日志行**。开 `skywalking_agent.inject_context = On`，agent 会把上下文注入 `$_SERVER`（Swoole 下是 `$request->server`），可用键有 `SW_TRACE_ID`、`SW_SERVICE_NAME`、`SW_INSTANCE_NAME`：

```php
<?php
final class LogHelper
{
    public static function write(string $message, string $level = 'INFO'): void
    {
        $traceId = $_SERVER['SW_TRACE_ID'] ?? '';
        $line = sprintf(
            '[%s] [%s] [trace=%s] %s',
            date('Y-m-d H:i:s'),
            $level,
            $traceId,
            $message
        );
        file_put_contents('/var/log/app/app.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
```

日志行里带上 trace id 后，在日志平台按它反查 SkyWalking 的 trace 即可串起来。

> ⚠️ 早年版本提供的 `skywalking_logging_report()` 全局函数在 v1.x 里**已经不存在**，日志上报统一走 PSR-3 hook；从旧版升级时，业务代码里对该函数的 `function_exists` 调用会静默失效，需要改成上面两种方式之一。

---

## 七、怎么逐层确认接入生效？

**本节要点**：按「扩展 → 配置 → 通道 → 平台」四层依次确认，能直接定位到具体哪一环断掉，而不是对着 UI 猜。

```bash
# 1. 扩展是否装进镜像（容器内执行）
php -m | grep -i skywalking_agent

# 2. 配置是否真的生效（System 级配置只能从这里看，ini_set 改的不算）
php -i | grep -i skywalking_agent

# 3. 通道是否建立：runtime_dir 下应有 .sock，且 worker 在监听
ls -l /tmp/skywalking-agent/

# 4. agent 自身日志：把 log_level 调到 DEBUG 看连接 OAP 是否报错
tail -f /tmp/skywalking-agent.log

# 5. php-fpm 是否为 PID 1（决定 SIGTERM 能否直达）
ps -o pid,comm -p 1
```

注意第 1、2 步在 CLI 下也能跑通——**它们只证明扩展加载与配置解析，不证明在上报**（agent 在 cli SAPI 下不激活）。真正的证据是第 3 步有 socket、第 4 步有发送日志、以及 UI 的 General Service 列表里出现 `service_name`、其 Instance 列表里出现 `instance_name`。

附带收获：Linux 上 agent 默认开启 **PHM（PHP Health Metrics）**，worker 通过 `/proc` 采样上报 6 个进程级指标（CPU 利用率、内存 used/peak、虚拟内存、线程数、打开 FD 数）。OAP 侧把 `php-runtime` 加进 `agent-analyzer.default.meterAnalyzerActiveFiles` 后，Horizon UI 的 General Service → Instance 面板即可看到；不需要就 `skywalking_agent.metrics_enable = Off`。

---

## 八、常见故障速查

| 症状 | 大概率原因 | 处理 |
| --- | --- | --- |
| 部署后容器秒退 / CrashLoopBackOff | php-fpm 没加 `-F`，daemonize 后 PID 1 退出 | 启动命令改 `php-fpm -F`，脚本里加 `exec`（5.1、5.2） |
| `php -m` 有扩展，UI 永远没这个服务 | daemon 模式 + grpc reporter，worker 随 master 退出 | 改前台 `-F`，或换 standalone reporter（第四节） |
| cron / CLI 脚本没有链路 | agent 只在 fpm-fcgi（或 cli+swoole）下激活 | 属预期行为；CLI 任务要链路需自行处理或走 Swoole |
| 扩展在、配置对、零数据，日志里有 `Create runtime directory failed` | `runtime_dir` 不可写（常见于只读根文件系统） | 挂 `emptyDir` 到该目录（5.4） |
| UI 服务名显示成 `${SW_SERVICE_NAME}` 字面量 | envsubst 没跑、或模板变量不在白名单里 | 进容器 `cat` 渲染后的 ini 核对（5.3） |
| 有链路、没有日志 | `psr_logging_level` 还是 Off，或日志库没实现 `LoggerInterface` | 设阈值；自研框架改用 `inject_context` 打 trace id（第六节） |
| 升级后日志上报突然没了 | 代码还在调已移除的 `skywalking_logging_report()` | 改 PSR-3 hook 或 inject_context（第六节） |
| PHP 7 + 开启 JIT 后异常 | observer API 仅 PHP8+，PHP7 的 hook 与 JIT 不兼容 | PHP7 关 JIT，或升 PHP8 |
| 每次发布前最后几秒的请求查不到 | PID 1 是 shell 且未 `exec`，或宽限期小于 `request_terminate_timeout` | 5.2、5.5 |
| 端口怎么调都不通 | 把 UI 的 8080 当成了上报地址 | `server_addr` 填 OAP 的 gRPC 端口，默认 11800 |

---

## 九、和 Java / Go 接入相比，PHP 侧难在哪？

| 维度 | PHP（本文） | Java |
| --- | --- | --- |
| 探针形态 | C/Rust 扩展（.so），**编译进镜像**，跟 PHP 版本与 ZTS/NTS 绑定 | javaagent jar，启动参数挂上即可 |
| 埋点方式 | PHP8 走 zend observer、PHP7 改 `zend_execute_ex`；覆盖 curl/PDO/mysqli/redis 等扩展与 predis 等库 | 字节码增强，覆盖面最广 |
| 上报通道 | 每进程 fork 一个 worker + unix socket | JVM 内线程 + 堆内队列 |
| 进程模型约束 | 多 worker 共享一份配置；daemon/前台决定 reporter 选型 | 单进程，无此约束 |
| 必配项 | 镜像构建 + ini + 前台启动 + runtime_dir 可写 | `-javaagent` 一行 |
| 丢数据风险 | shell 不转发 SIGTERM、宽限期不够 | `kill -9` 跳过 ShutdownHook |

---

## 使用：从零接入速查（照抄版）

1. **装扩展**：多阶段 Dockerfile（2.3），builder 装 Rust 与编译依赖后 `pecl install skywalking_agent`，运行镜像只拷 `.so` 与 conf.d ini。
2. **配 php.ini**：最小集见 3.1；按服务变化的项走 envsubst 模板（5.3），别用 `ini_set`（System 级，改不动）。
3. **选 reporter**：容器里前台跑就用默认 `grpc`；php-fpm 必须 daemon 的老架构用 `standalone`（第四节）。
4. **改启动脚本**：最后一行 `exec php-fpm -F`；K8s 的 `terminationGracePeriodSeconds` 大于 `request_terminate_timeout`（5.5）。
5. **日志关联**：PSR-3 日志库设 `psr_logging_level`；自研框架开 `inject_context` 打 `SW_TRACE_ID`（第六节）。
6. **验证**：容器内依次 `php -m` → `php -i` → `ls /tmp/skywalking-agent/` → `tail` agent 日志 → UI 找服务与实例（第七节）。

> ✅ **校验口径**：配置项名称与默认值核对自 apache/skywalking-php v1.1.0 的源码（`src/lib.rs`、`src/module.rs`、`src/worker.rs`、`src/channel.rs`）与官方文档；shell 片段经 `bash -n` 语法校验、PHP 片段经 `php -l`（本机 PHP 8.0.2）校验、yaml 为 K8s `emptyDir/volumeMounts` 标准结构。Dockerfile 与接入流程**未在本机实机运行验证**（无 PHP-FPM + 扩展的运行环境），文中不含伪造的输出。

---

## 延伸追问

- **为什么 php-fpm 必须前台运行？** → grpc/kafka reporter 的 worker 是 master 在 module init 时 fork 的，且挂了 `PR_SET_PDEATHSIG`；daemon 模式下原 master 退出会把 worker 一起带走，于是「扩展在、配置对、零数据」。前台 `-F` 或 standalone reporter 二选一。
- **PHP 进程和上报进程之间怎么通信？** → unix domain socket（`runtime_dir` 下按启动时间戳命名的 `.sock`），消息是 bincode 序列化的采集项；不是共享内存，所以没有 shm 容量这类问题，但有目录可写性问题。
- **为什么 CLI 脚本看不到链路？** → agent 按 SAPI 判断是否激活，只认 `fpm-fcgi` 与 cli+swoole；这是设计而非故障，避免 cron/脚本白付 hook 与 fork 成本。
- **怎么把业务日志挂到 trace 上？** → 两条路：PSR-3 hook（设 `psr_logging_level`，任何 `LoggerInterface` 实现自动上报）或 `inject_context` 把 `SW_TRACE_ID` 注入 `$_SERVER` 自己打进日志行。
- **停机时怎么不丢最后一批数据？** → 整条链路：`exec` 让 php-fpm 当 PID 1 收到 SIGTERM → graceful stop 排空在途请求（worker 此时仍活着、仍在发送）→ master 退出后 worker 随 `PDEATHSIG` 退出；宽限期小于排空时间就等于 `kill -9`。
- **PHP7 和 PHP8 的 hook 有什么区别？** → PHP8 用 zend observer API（不影响 JIT、无栈深度问题，8.2 起内部函数也覆盖）；PHP7 只能替换 `zend_execute_ex` / `zend_execute_internal`，受 `ulimit -s` 栈限制且与 JIT 冲突。

---

## 关联

- [Skywalking.md](../../03-数据与中间件/中间件/可观测性/Skywalking.md) — 探针与上报链路的原理、OAP/UI
- [接入Skywalking.md](../go/可观测性/接入Skywalking.md) — Go 侧的编译期注入路线
- [接入Skywalking.md](../java/接入Skywalking.md) — Java 侧的 `-javaagent` 无侵入路线
- [Jaeger.md](../../03-数据与中间件/中间件/可观测性/Jaeger.md) — 另一套 tracing 体系的对照
- [镜像构建与缓存.md](../../06-工程实践/部署/docker/镜像构建与缓存.md) — 多阶段构建与镜像层缓存
- [K8s部署与生命周期面试题.md](../../06-工程实践/部署/k8s/K8s部署与生命周期面试题.md) — 优雅停机与 SIGTERM 排空

> 反向引用（本篇被下列文档引到）：[可观测性选型对比.md](../../03-数据与中间件/中间件/可观测性/可观测性选型对比.md)
