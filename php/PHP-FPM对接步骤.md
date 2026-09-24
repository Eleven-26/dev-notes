# PHP-FPM 接入 Skywalking 对接步骤

> 用的是**已内置 skywalking PHP 扩展的自研基础镜像**，所以接入本身不写业务代码，只有三步：换基础镜像 → 启动脚本改前台 → 平台注入环境变量。日志上报与 `/dev/shm` 容量属于按需调优。
>
> Skywalking 本体原理、UI 面板、Java/Go 接入见 [Skywalking.md](../可观测性/Skywalking.md)。

## 〇、先看数据是怎么跑出去的

理解了这条链路，后面每一步的配置项都能推出来：

```text

PHP 扩展采集 span / 日志
   ↓ 写入
shm 共享内存消息队列（/dev/shm，php-fpm 的 master + N 个 worker 共用）
   ↓ 由上报逻辑消费
gRPC 推给 OAP（默认 11800 端口）
   ↓
Skywalking UI（服务名 = GROUP_NAME::SERVICE_NAME）
```

三个隐含结论：

1. **php-fpm 是多进程模型**，跨进程只能靠共享内存，所以 `/dev/shm` 是链路与日志的公共缓冲区——它满了就丢数据（见第六节）。
2. **链路和日志共用一条上报通道**（同一个 gRPC 地址），所以「链路有、日志没有」通常是日志侧开关或消息体大小的问题，而不是网络问题。
3. **数据是异步攒批发送的**，进程被强杀就来不及 flush，所以优雅停机直接影响可观测性（见第七节）。

## 一、接入前检查清单

| 检查项 | 要求 | 不满足的后果 |
| --- | --- | --- |
| 基础镜像 | 换成带 skywalking 扩展的镜像（第二节） | 扩展不存在，环境变量全部无效 |
| 启动方式 | php-fpm 必须前台运行（`-F`） | 容器秒退，K8s 进入 CrashLoopBackOff |
| php.ini | 自定义过 php.ini 时补回 `[skywalking]` 段 | 扩展加载但配置丢失，链路不上报 |
| 平台变量 | 至少 `GROUP_NAME`/`SERVICE_NAME`/`SKYWALKING_SERVER_ADDR` | UI 上服务名为空或显示未展开的占位符 |
| 消息体大小 | 单条日志裁剪到 32KB 以内 | 超限的日志被静默丢弃 |
| `/dev/shm` | 调大消息体后同步扩容，并计入内存 limit | 队列写失败，链路/日志随机丢失 |

## 二、第一步：换基础镜像

### 2.1 镜像地址

```bash

registry.cn-shenzhen.aliyuncs.com/ykj-baseimages/nginx-php7.0.33-skywalking:1.0.0
```

### 2.2 标准 Dockerfile

```dockerfile

FROM registry.cn-shenzhen.aliyuncs.com/ykj-baseimages/nginx-php7.0.33-skywalking:1.0.0
WORKDIR /webser/www
RUN mkdir -p /webser/www/rental-backend/rental/protected/runtime/tmp
# 拷贝源项目代码到容器中
COPY ./../rental-backend ${WORKDIR}/rental-backend
# 加载配置文件
COPY rental/docker/ /tmp/rental-backend.myfuwu.com.cn/
# 加载启动脚本
COPY rental/docker/run.sh /tmp/run.sh
RUN chmod +x /tmp/run.sh
EXPOSE 80
ENTRYPOINT ["/tmp/run.sh"]
```

注意两点：

- 历史模板里有一行孤立的 `env` 和重复的 `WORKDIR`，是误留内容，可直接删。
- `ENTRYPOINT` 指向 `/tmp/run.sh`，容器真正的 PID 1 是这个 shell 脚本——这正是第三节必须改启动脚本、且要关心信号转发的原因。

### 2.3 基础镜像里的关键路径

| 用途 | 路径 |
| --- | --- |
| Nginx 配置 | `/etc/nginx/conf.d/` |
| php.ini | `/usr/local/etc/php/conf.d/php.ini` |
| fpm 配置 | `/usr/local/etc/php-fpm.d/www.conf` |
| 启动脚本 | `/tmp/run.sh` |
| skywalking 扩展日志 | `/data/log/skywalking.log` |

### 2.4 自定义 php.ini 时必须补回 `[skywalking]` 段

镜像自带的 php.ini 里有 skywalking 配置；一旦业务镜像用自己的 php.ini 覆盖，这段没了就等于扩展装了但不开工。需要完整复制：

```ini

[skywalking]
skywalking.app_code = ${GROUP_NAME}::${SERVICE_NAME}
skywalking.enable = ${SKYWALKING_ENABLE}
skywalking.version = 8
skywalking.log_enable = ${SKYWALKING_DEBUG_LOG_ENABLE}
skywalking.grpc = ${SKYWALKING_SERVER_ADDR}
skywalking.error_handler_enable = 0
skywalking.log_path = /data/log/skywalking.log
skywalking.mq_max_message_length = ${SKYWALKING_MQ_MSG_LEN}
skywalking.logging_enable = ${SKYWALKING_LOGGING_ENABLE}
skywalking.logging_mq_max_message_length = ${SKYWALKING_LOGGING_MQ_MSG_LEN}
skywalking.logging_yii_target_name = ${SKYWALKING_LOGGING_YII_TARGET_NAME}
skywalking.logging_mq_length = ${SKYWALKING_LOGGING_MQ_LEN}
```

- `${...}` 是**环境变量占位符**，由容器启动时展开，因此基础镜像和各业务镜像可以共用同一份模板，无需为每个服务改 ini——这也是第三节只配变量就能切换服务名的原因。
- `skywalking.app_code` 就是 UI 上显示的服务标识，由 `GROUP_NAME::SERVICE_NAME` 拼成。
- 参数分三类：开关（`enable`）、上报地址与服务名（`grpc`/`app_code`）、队列容量（`mq_max_message_length` 是链路单条大小，`logging_mq_*` 是日志侧的单条大小与队列长度）。队列容量类改动要连带考虑 `/dev/shm`，见第六节。

## 三、第二步：run.sh 改前台启动

### 3.1 为什么必须 `-F`

```bash

/usr/local/sbin/php-fpm -R -F
```

- **PID 1 退出 = 容器退出**。php-fpm 默认以 daemon 方式启动：master fork 到后台后，父进程立即退出，`run.sh` 随之执行完毕，容器被判为已结束，K8s 不断重启（CrashLoopBackOff）。`-F`（`--nodaemonize`）让 master 常驻前台充当 PID 1。
- `-R`（`--allow-to-run-as-root`）允许以 root 运行——php-fpm 出于安全默认拒绝 root 启动，容器内必须显式放开；代价是接受了 root 运行的风险，建议配合非特权模式、只读根文件系统加固。
- 脚本里 Nginx 是 daemon 方式启动的（`/usr/sbin/nginx` 默认后台），真正把脚本「顶住不退出」的是最后这行 `php-fpm -R -F`；删掉它容器同样秒退。

### 3.2 标准模板

```bash

#!/bin/sh
if [ $runtimemode = "prod" ];then
  env="prod"
  cp -r /tmp/rental-backend.myfuwu.com.cn/conf.d/* /etc/nginx/conf.d
  mv  /etc/nginx/conf.d/oss_upload.conf /etc/nginx/
elif  [ $runtimemode = "grey" ];then
  env="prod"
  cp -r /tmp/rental-backend.myfuwu.com.cn/conf.d/* /etc/nginx/conf.d
  mv  /etc/nginx/conf.d/oss_upload.conf /etc/nginx/
elif  [ $runtimemode = "private" ];then
  env="prod"
  cp -r /tmp/rental-backend.myfuwu.com.cn/conf.d/* /etc/nginx/conf.d
  mv  /etc/nginx/conf.d/oss_upload.conf /etc/nginx/
elif  [ $runtimemode = "test" ];then
  env="test"
  cp -r /tmp/rental-backend.myfuwu.com.cn/conf.d/* /etc/nginx/conf.d
  mv  /etc/nginx/conf.d/oss_upload.conf /etc/nginx/
elif  [ $runtimemode = "pressure-test" ];then
  env="pressure-test"
  cp -r /tmp/rental-backend.myfuwu.com.cn/conf.d/* /etc/nginx/conf.d
  mv  /etc/nginx/conf.d/oss_upload.conf /etc/nginx/
fi
echo $runtimemode > /webser/www/$runtimemode
echo $env > /webser/www/env
sed -i s'/request_terminate_timeout = 20s/request_terminate_timeout = 60s/g' /usr/local/etc/php-fpm.d/www.conf
sed -i "s#ENV_OSS_UPLOAD_URL#$ENV_OSS_UPLOAD_URL#g" /etc/nginx/oss_upload.conf
sed -i "s#ENV_FLOW_URL#$ENV_FLOW_URL#g" /etc/nginx/conf.d/rental.conf
sed -i "s#ENV_DATASET_URL#$ENV_DATASET_URL#g" /etc/nginx/conf.d/rental.conf
sed -i "s#ENV_PASS#$ENV_PASS#g" /etc/nginx/conf.d/rental.conf
/usr/sbin/nginx
exec /usr/local/sbin/php-fpm -R -F
```

脚本本身是纯启动胶水，与 skywalking 无关的部分（多环境复制 nginx 配置、`sed` 注入业务占位符）照旧。接入相关的只有两处：最后一行的前台启动，以及把 `request_terminate_timeout` 从 20s 调到 60s——它同时决定了优雅停机时 worker 最多还能跑多久、还有多少时间 flush 链路数据（见第七节）。

### 3.3 最后一行建议加 `exec`

模板里刻意写成 `exec /usr/local/sbin/php-fpm -R -F`，原因见第七节。旧版脚本这里是不带 `exec` 的裸命令，属于已知缺陷。

## 四、第三步：平台注入环境变量

在星洲上至少增加 **GROUP_NAME**、**SERVICE_NAME**、**SKYWALKING_SERVER_ADDR**：

```bash

GROUP_NAME=租赁组
SERVICE_NAME=债权中心
SKYWALKING_SERVER_ADDR=172.16.68.108:11800
```

全量变量：

| 变量名称 | 变量说明 | 默认值 |
| --- | --- | --- |
| GROUP_NAME | 业务组 | |
| SERVICE_NAME | 服务名称 | |
| SKYWALKING_SERVER_ADDR | skywalking OAP 服务地址 | |
| SKYWALKING_ENABLE | 是否开启 skywalking，0 关闭 1 开启 | 1 |
| SKYWALKING_DEBUG_LOG_ENABLE | 是否开启 skywalking 调试日志，日志路径 /data/log/skywalking | 0 |
| SKYWALKING_MQ_MSG_LEN | 链路上报消息体大小，超过大小后会丢弃 | 20480 |
| SKYWALKING_LOGGING_ENABLE | 是否开启 skywalking 日志上报 | 1 |
| SKYWALKING_LOGGING_MQ_MSG_LEN | skywalking 日志上报消息体大小，超过大小后会丢弃，最大只能设置为 32KB，详情看[常见问题](https://doc.in.myspacex.cn/pages/viewpage.action?pageId=34064866) | 20480 |
| SKYWALKING_LOGGING_YII_TARGET_NAME | yii 日志目标类，该类输出日志时会上报到 skywalking | yii\log\FileTarget |

- `SKYWALKING_SERVER_ADDR` 填的是 **OAP 的 gRPC 端口（默认 11800）**，不是 UI 的 HTTP 端口（8080）；链路与日志都走它。
- 变量的生效路径是：平台 → 容器环境变量 → php.ini 的 `${...}` 占位符 → 扩展配置。所以只改变量、不改 ini 就能按服务区分，但也意味着**ini 里没写占位符的项，配环境变量无效**。

## 五、业务日志上报

两种方式，按项目用的日志框架选：

**方式一：Yii 框架的 Target（零代码改动）**。日志输出走 `yii\log\Target` 子类（`FileTarget` 或自定义子类）时，只需把子类配到 `SKYWALKING_LOGGING_YII_TARGET_NAME`，扩展会拦截该子类的 `export` 方法，把同一批日志同时上报到 skywalking。

**方式二：非框架日志，调全局函数**。自己实现的日志输出，用 `skywalking_logging_report(message, level)` 主动上报：

```php

// 加 function_exists 判断，兼容没装 skywalking 扩展的环境（如本地、单测）
if (function_exists('skywalking_logging_report')) {
    skywalking_logging_report($string, "INFO");
}
```

资管的日志输出类实现如下：

![fig-01.png](images/fig-01.png)

> 日志与链路共用 shm 队列，单条超过 `SKYWALKING_LOGGING_MQ_MSG_LEN`（上限 32KB）会被**静默丢弃**——打印大对象（完整请求体、序列化模型）前先裁剪，否则排障时最需要的恰好是那条被丢掉的大日志。

## 六、按需调整 `/dev/shm` 共享内存

### 6.1 什么情况要动

满足以下**任一**条件才需要调整，否则可跳过本节：

- 改过 `SKYWALKING_MQ_MSG_LEN` 或 `SKYWALKING_LOGGING_MQ_MSG_LEN`；
- 调大后两项之和超过 64M（默认 `/dev/shm` 就是 64MB，常规配置 `20480KB + 20480KB + 100KB ≈ 41MB`，够用）。

### 6.2 容量计算

SDK 的队列容量公式：

```text

shm_size = (SKYWALKING_MQ_MSG_LEN * 1024) + (SKYWALKING_LOGGING_MQ_MSG_LEN * 1024) + 100KB
```

### 6.3 为什么要单独挂 `/dev/shm`

- 容器里的 `/dev/shm` 默认只有 64MB（Docker 的 `--shm-size=64m`，K8s 沿用运行时默认值）。它是 tmpfs，**占用计入容器内存**，不是磁盘。
- php-fpm 是 1 master + N worker 的多进程模型，worker 写队列、上报逻辑消费，跨进程只能靠 POSIX 共享内存 + 信号量，容量必须同时覆盖「链路 + 日志」两块缓冲区。
- 超出后表现为队列写失败、链路或日志随机丢失，且**不会让请求报错**，容易被误判成「探针不稳定」。
- 单独挂成 `emptyDir: medium: Memory` 并设 `sizeLimit` 后，容量由 `sizeLimit` 决定；由于它计入内存用量，**必须同步抬高 Pod 的 memory limit**，否则从「丢日志」变成 OOMKill。

### 6.4 配置方法

先在平台按下面的层次加卷。

![fig-02.png](images/fig-02.png)

层次为 `spec.spec.volumes`：

```yaml

- name: cache-volume
  emptyDir:
    medium: Memory
    sizeLimit: 128Mi
```

![fig-03.png](images/fig-03.png)

层次为 `spec.spec.containers.volumeMounts`：

```yaml

- name: cache-volume
  mountPath: /dev/shm
```

## 七、优雅停机：别让最后一批数据丢

K8s 删除 Pod 时的时序：先给容器 PID 1 发 `STOPSIGNAL`（Dockerfile 未指定则默认 `SIGTERM`），等 `terminationGracePeriodSeconds`（默认 30s），超时未退就 `SIGKILL`。

问题出在中间那一环：

1. **shell 不转发信号**。若 PID 1 是 `run.sh` 且最后一行没写 `exec`，SIGTERM 只会打到 shell 上，php-fpm 收不到，只能干等到 grace period 结束被强杀。此时正在处理的 PHP 请求被中断，shm 队列里未 flush 的链路和日志一并丢失。
2. **写 `exec` 就好**。`exec /usr/local/sbin/php-fpm -R -F` 让 php-fpm 直接接管 PID 1，信号直达；不想改脚本也可以引入 `tini`/`dumb-init` 作为 init，顺带负责僵尸进程回收。
3. **grace period 要大于 in-flight 时间**。php-fpm 收到 SIGTERM 后走 graceful stop：master 停止接新连接，等 worker 处理完当前请求再退出，上限受 `www.conf` 的 `request_terminate_timeout` 约束（本模板已把 20s 调成 60s）。这段时间也是链路数据上报的最后窗口，因此 **`terminationGracePeriodSeconds` 必须大于 `request_terminate_timeout`**，否则 worker 还在跑就被 SIGKILL。

这与 Java agent 靠 ShutdownHook flush 队列是同一个道理（见 [Skywalking.md](../可观测性/Skywalking.md) 第四节）：任何 `kill -9` 都等于放弃最后一批数据。

## 八、验证接入是否生效

按「扩展 → 配置 → 进程 → 平台」四层依次确认，能定位到具体哪一环断掉：

```bash

# 1. 扩展是否装进镜像（在容器内执行）
php -m | grep -i skywalking

# 2. 配置是否真的展开（占位符没被替换时会显示 ${GROUP_NAME}）
php -i | grep -i skywalking

# 3. php-fpm 是否为 PID 1（决定信号能否直达，输出应能看到 php-fpm 本身）
ps -o pid,comm -p 1

# 4. 共享内存实际大小与占用
df -h /dev/shm
```

5. 打开 `SKYWALKING_DEBUG_LOG_ENABLE=1`，看 `/data/log/skywalking.log` 里有没有连接 OAP 失败或队列写入失败的记录。
6. 最后到 UI 的 Generals 列表找 `GROUP_NAME::SERVICE_NAME`，有服务、有 trace、日志能关联到 trace，即为接入完成。

## 九、常见问题速查

| 症状 | 大概率原因 | 处理 |
| --- | --- | --- |
| 部署后容器秒退 / CrashLoopBackOff | php-fpm 没加 `-F`，或删掉了那行前台启动命令 | 恢复 `php-fpm -R -F`（第三节） |
| UI 里根本没有这个服务 | 扩展缺失、`SKYWALKING_ENABLE=0`、地址端口填成 8080 | 按第八节四层排查，端口确认 11800 |
| 服务名显示成 `${GROUP_NAME}::${SERVICE_NAME}` | 自定义 php.ini 覆盖了镜像配置，占位符没环境来源 | 补回 `[skywalking]` 段并配齐变量（2.4、第四节） |
| 有链路、没有日志 | `SKYWALKING_LOGGING_ENABLE=0`，或日志类未配到 `LOGGING_YII_TARGET_NAME` | 检查开关与 Target 子类名（第五节） |
| 日志时有时无、大对象那条总缺 | 单条超过 `LOGGING_MQ_MSG_LEN`（上限 32KB）被丢弃 | 业务侧打印前裁剪；需要更大值就连带扩 shm |
| 调大消息体后丢失更严重 | `/dev/shm` 仍是默认 64MB，队列写失败 | 按第六节挂载 `emptyDir: Memory` 并抬高内存 limit |
| 每次发布前最后几秒的请求在 UI 里查不到 | PID 1 是 shell 且未 `exec`，或 grace period 小于 `request_terminate_timeout` | 第七节：加 `exec`、调大 `terminationGracePeriodSeconds` |

## 十、与 Java/Go 接入的差异

| 维度 | PHP（本文） | Java |
| --- | --- | --- |
| 探针形态 | PHP 扩展（.so），随镜像走 | javaagent，启动参数挂 |
| 埋点范围 | 扩展能 hook 到的内置函数与 curl/PDO/redis 等，加框架层适配 | 字节码增强，覆盖面最广 |
| 进程模型 | 多 worker 共享 shm 队列 | 单 JVM 内线程，队列在堆里 |
| 必配项 | 基础镜像 + 前台启动 + 环境变量 + shm 容量 | `-javaagent` 一行 |
| 丢数据风险 | shell 不转发 SIGTERM | `kill -9` 跳过 ShutdownHook |

## 关联

- [../可观测性/Skywalking.md](../可观测性/Skywalking.md) — 探针与上报链路的原理
- [../容器/docker/镜像构建与缓存.md](../容器/docker/镜像构建与缓存.md) — 换基础镜像与自定义 `php.ini`
- [../容器/k8s/K8s部署与生命周期.md](../容器/k8s/K8s部署与生命周期.md) — 优雅停机与 SIGTERM 排空
