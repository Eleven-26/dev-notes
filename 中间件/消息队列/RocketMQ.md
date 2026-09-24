# RocketMQ 消息中间件

> 覆盖 RocketMQ 的定位与架构、消息类型语义、Java/Go 双端使用示例、可靠性机制与部署运维要点。
>
> 内容整理自个人学习笔记；本轮补充（CommitLog/ConsumeQueue 存储、NameServer 路由、长轮询、重试与死信、事务半消息、DLedger、堆积 SOP）参考《RocketMQ 分布式消息中间件核心原理与最佳实践》（李伟）。消息队列的通用价值与选型对比见 [消息队列选型.md](消息队列选型.md)。

## 一、一句话定位与适用场景

RocketMQ 是阿里 2012 年开源、2016 年捐给 Apache（2017 年成为顶级项目）的分布式消息中间件，纯 Java 实现，单机十万级 TPS、支持万级队列；**原生支持延迟消息、事务消息、消息轨迹**，这是它与 Kafka / RabbitMQ 最直接的差异点。选型细节见 [消息队列选型.md](消息队列选型.md)。

| 场景 | 落点 |
|---|---|
| 削峰填谷 | 大促流量写 Topic，消费者按自身能力匀速拉取，堆积由 Broker 承担 |
| 系统解耦 | 上游只发消息，下游各自订阅同一 Topic，新增下游不改上游代码 |
| 异步处理 | 下单后发消息，积分/短信/风控异步消费，主链路 RT 大幅下降 |
| 最终一致性 | 事务消息把「本地事务」与「消息投递」绑定为最终一致 |
| 延迟消息 | 订单 30 分钟未支付自动关单、延迟重试、定时通知，不必写扫表定时任务 |

## 二、核心架构

### 四大角色

| 角色 | 职责 | 状态 | 关键点 |
|---|---|---|---|
| **NameServer** | 路由注册中心：Broker 上报 Topic/队列信息，客户端按 Topic 拉路由 | ❌ 无状态，数据在内存 | 节点之间**互不通信**，可任意扩容；默认端口 9876 |
| **Broker** | 消息存储与转发：CommitLog 落盘、ConsumeQueue 索引、长轮询投递 | ✅ 落盘 | 分 Master/Slave（`brokerId=0` 为 Master，>0 为 Slave）；默认端口 10911 |
| **Producer** | 取路由、按策略选队列发送、失败换 Broker 重试 | — | 与业务同进程；ProducerGroup 事务消息必需 |
| **Consumer** | 拉取消息并消费、提交位点 | 位点存 Broker | PushConsumer 本质是「长轮询 + 回调」，并非真正推送 |

消息流转：`Producer → NameServer 取路由 → Broker 写 CommitLog → ConsumeQueue 建索引 → Consumer 长轮询拉取 → 提交位点`。发送策略默认**轮询**，开启 `sendLatencyFaultEnable` 后变为「延迟故障规避 + 随机」，对慢 Broker 自动降权。

### 核心概念关系

| 概念 | 说明 | 类比 Kafka |
|---|---|---|
| **Topic** | 消息的逻辑分类，由多个队列组成 | Topic |
| **MessageQueue（Queue）** | Topic 的最小并行单元，`queueId` 从 0 开始；**一个队列同一时刻只能被同消费组内一个消费者消费** | Partition |
| **Tag / Key** | Tag 是二级分类（Broker 端过滤，只有一个）；Key 是业务唯一标识（建索引供 `mqadmin queryMsgByKey` 排障） | ❌ 无 |
| **Offset** | 队列内消费位点；集群模式存 Broker，广播模式存消费者本地 | Offset |
| **ConsumerGroup** | 集群模式下组内平分队列，一条消息只被组内一个实例消费 | Group |

### 为什么 NameServer 比 ZooKeeper 轻

| 维度 | NameServer | ZooKeeper（Kafka 旧版用法） |
|---|---|---|
| 一致性模型 | AP，最终一致（客户端本地缓存 + 30s 定期拉取） | CP，ZAB 协议强一致 |
| 节点关系 | 各节点独立无通信、无主无选举，信息纯内存不落盘 | 需选主，半数以上存活才可写，ZNode + 事务日志 |
| 故障影响 | 单节点挂掉不影响其余节点，客户端用本地缓存路由继续收发 | 半数以下存活则整体不可用 |
| 运维成本 | 无状态，部署即用，可任意加节点 | 需维护集群、磁盘、会话、GC |

⭐ 设计取舍：路由数据**允许短暂不一致**（Broker 上下线延迟几十秒可接受），所以不需要一致性协议。Broker 每 30s 向**所有** NameServer 注册心跳，NameServer 每 10s 扫描 `brokerLiveTable`，超过 120s 未心跳则摘除。Kafka 也正因 ZK 在分区数增大时成为瓶颈，才在 3.0 后用 KRaft 取代 ZK。

### NameServer 与客户端路由的实现细节 ⭐

承接上表的「薄」，落到代码结构上讲薄在哪：

- **NameServer 内存在几张表**：`topicQueueTable`（Topic → 队列分布）、`brokerAddrTable`（BrokerName → 主从地址）、`brokerLiveTable`（存活检测）、`filterServerTable`（Tag/SQL 过滤地址路由）。全部纯内存、无落盘、无持久化，重启即空表——等 Broker 30s 心跳重新注册即可恢复，这就是"无状态"的底气。
- **路由单元**：客户端按 Topic 拉一份完整 `TopicRouteData`（队列列表 + 主从拓扑 + 读写权限位），NameServer 不做增量协议。Topic 路由数据量极小（一条 Topic 几百字节），**全量下发比增量协议简单得多**，代价只是缓存过期窗口（客户端周期性更新，周期见上节）。
- **客户端本地缓存 + 重试时"投票"**：Producer 默认轮询队列；失败重试时按 `lastBrokerName` 避开刚挂的那台 Broker 重新选（同组内换队列而不是换 Broker），配合 `sendLatencyFaultEnable` 时还会把慢 Broker 记入故障掩码、一段时间内降权。路由指向已下线 Broker 时发送报错、下一次更新路由自愈——**错误容忍窗口换来了协议极简**。
- **自动建 Topic 的钩子也藏在路由里**：`autoCreateTopicEnable=true` 时新 Topic 借用默认 Topic（`TBW102`）的路由模板下发，这解释了为什么没建过的 Topic 也能发出去，也是生产必须关掉它的原因。
- ⭐ 回到本质：NameServer 能薄，是因为路由错误的代价**可以被重试消化**（选错队列就换一个）。但"谁是 Master"这类元数据错误会导致双写，不能重试消化——所以 DLedger 模式才把主选举交给 Raft（见第六节），两套机制回答的是同一个问题：**这份数据要不要强一致**。对照见 [Raft协议.md](../../分布式/Raft协议.md)。

### 存储设计：CommitLog / ConsumeQueue / IndexFile ⭐

#### CommitLog：所有 Topic 混在一条日志里顺序写

- Broker 只有**一条** CommitLog：所有 Topic、所有队列的消息按**到达物理顺序**追加写进当前的 mmap 文件（单文件 1 GiB，见第七节 PageCache 小节）。Topic/queueId 只是记录里的字段，不影响写的位置。
- 为什么混写：若像 Kafka 那样"每分区一条日志"，万级队列就意味着上万个并发追加目标，磁盘队列深度被摊薄、磁头（或 SSD 通道）在文件间跳来跳去，"顺序写"退化成**文件间随机写**。混写让全机只有一个追加点，写吞吐与队列数解耦——这是"单机万级队列"的存储根基（另一根基是下面的轻量索引，见第八节第 7 问）。
- 代价：读某一条队列时数据**散落在整条日志里**，必须有二级结构把"逻辑顺序"重建出来，否则消费就变成全文件扫描。

#### ConsumeQueue：定长"逻辑消费队列"，消费只读索引不读正文

- 后台分发线程从 CommitLog 异步"透析"出 ConsumeQueue：每条消息一条**定长 20 字节**记录 = 8B CommitLog 物理偏移 + 4B 消息长度 + 8B Tag 哈希；单文件固定 30 万条（约 6 MB），于是队列内第 N 条消息的索引位置 = `queueOffset × 20`，**寻址是纯算术，不需要任何树**。
- 消费路径：按位点读 ConsumeQueue 顺序拿"位置+长度"→ 再去 CommitLog 定长读。**顺序读被摊在两个文件上**：索引侧永远顺序，数据侧按索引顺序取，绝大多数情况仍接近顺序读。
- Tag 过滤在索引层就完成（比对 8 字节哈希），不匹配的消息不必读 CommitLog 正文——这是"Tag 过滤便宜、SQL92 过滤贵"（第三节）的物理原因：SQL 表达式要拿到消息体才能求值，读放大回不来。
- 同一条消息被多个消费组订阅，物理只存一份，各组推各组的 ConsumeQueue 位点：**订阅者数量不放大存储**。
- 注意 ConsumeQueue 是**可重建的派生数据**：只存索引不存正文，损坏或缺失时可按 CommitLog 重放重建，这让"索引丢一点"不构成数据事故。

#### IndexFile：按 Key 查询的哈希索引（服务于排障，不在消费路径）

- 结构：定长文件（约 200 MB）= 50 万个 4 字节哈希槽（约 2 MB）+ 1000 万条 20 字节条目；条目 = 4B keyHash + 8B CommitLog 偏移 + 4B 时间差 + 4B **同槽上一条目的偏移**——槽指向最新条目，向前回溯成一条链，是"哈希桶 + 链式地址"的定长文件版。
- 发送时把消息 Key（业务单号）哈希写进索引，`mqadmin queryMsgByKey` / Dashboard 按 Key 查消息走的就是它。
- ⚠️ 两个边界：① 槽位固定、条目写满后**轮回头覆盖最旧的**，所以只查得到近期消息，别把它当审计存储；② 只哈希 Key 不存 Key 原文，比对的是哈希值，理论上存在极小概率误报，查到的结果要以消息内容确认。

#### 消息在 CommitLog 里的物理布局

```
+------------------- 定长部分（示意，非全字段）-------------------+------ 变长部分（长度前缀+内容）------+
| totalLen 4B | magic 4B | bodyCRC 4B | queueId 4B | flag 4B      |
| queueOffset 8B | physOffset 8B | sysFlag 4B                      | bornHost | storeHost
| bornTimestamp 8B | storeTimestamp 8B | reconsumeTimes 4B         | topic
| preparedTxOffset 8B                                             | properties | body(长度4B声明在前)
+-----------------------------------------------------------------+--------------------------------+
```

- 记录自带总长，**向前"读长度→读内容"即可解析，永远不需要回溯定位**；这也是"改一条消息 = 在日志尾部追加新版本"的 append-only 模型（事务的操作状态、重试的 reconsumeTimes 都靠追加实现）。
- 消息装不下当前文件剩余空间时，用**全零的 BLANK magic 填到文件末尾**再换新文件——启动恢复时按 magic 判断边界，天然识别"半条消息"并丢弃尾部。
- `bornTimestamp`（生产端时间）与 `storeTimestamp`（Broker 落盘时间）分开存：两者差值直接暴露"客户端→Broker"的网络/排队耗时，是排障 RT 的第一现场。

#### mmap、读写路径与"写放大为什么低"

- 写：`memcpy` 进 mmap 映射的 PageCache 即算完成，刷盘线程再批量落盘（异步刷盘时 `send` 成功 ≠ 已落盘，见第七节）；顺序写 + 大块提交，让磁盘侧几乎跑满顺序带宽。
- 读：优先命中 PageCache（刚写入就被消费的"热消息"零磁盘 IO）；读旧消息才下盘。与 Kafka 不同，RocketMQ 读路径经 mmap 回到用户态（要做过滤、协议组装），**用不了 sendfile 式的纯零拷贝**——换来的是过滤与查询灵活性，代价是高吞吐消费时的用户态拷贝 CPU。

| 维度 | RocketMQ（混写 CommitLog + 索引） | Kafka（分区级日志） |
|---|---|---|
| 写 | 单追加流，真顺序；队列数不影响写 | 分区多时写头分散，页缓存/磁盘队列被摊薄 |
| 读 | 两跳（索引→数据），有随机化风险，靠顺序分发摊平 | 分区内纯顺序，读路径更直 |
| 队列/分区扩展成本 | 加队列 ≈ 加索引文件，极轻（万级队列可行） | 加分区 = 加一套日志段与句柄，重（数百为警戒线） |
| 数据与配置耦合 | 保留/清理是**全局**粒度（文件混写，无法按 Topic 分别过期，见第七节） | 每个 Topic 可独立设 retention |

对照阅读：[Kafka.md](Kafka.md)。

三层结构的写读全景（写一条路、读两跳）：

```
写入（全 Topic 混成一条流）：
  Producer TopicA/q0 ─┐
  Producer TopicA/q1 ─┼─► CommitLog 唯一追加点 ─► 1GiB 文件写满换下一个 ─► mmap/PageCache ─► 刷盘
  Producer TopicB/q0 ─┘
分发（后台异步，可丢可重建）：
  CommitLog 扫描 ─► 按 (topic, queueId) 追加 20B 条目 ─► ConsumeQueue（每队列一套）
             └─► 按 Key 哈希插入 IndexFile（排障查询用）
消费（读 = 索引两跳）：
  消费者组X 在 TopicA/q3 位点=1024 ─► ConsumeQueue/q3 第 1024 条 ─► (物理偏移,长度,tagHash)
                                    └─► 回 CommitLog 定长读正文 ─► 按 tagHash 过滤后返回
```

### 拉取与长轮询：Push 本质是「挂起的 Pull」⭐

- **没有真的推**：PushConsumer 内部是 `PullMessageService` **单线程**不停从拉取任务队列取请求发 RPC（拉本身是异步网络调用，一个线程够了），消费则在消费线程池执行——"拉"和"用"解耦，才是 Push 语义的全部真相。
- **broker 端挂起实现准实时**：拉取请求带 `suspendTimeoutMillis`，无消息时 Broker 不立即返回空，而是把请求挂起；新消息落入 CommitLog 时触发到达通知，按队列 + Tag 哈希匹配唤醒挂起的请求立刻返回。效果：**没消息不占一次往返，有消息毫秒级响应**，空轮询的网络/CPU 开销归零。Broker 对挂起请求的数量与时长都有上限保护（参数以官方文档为准），挂起满了会退化为短轮询。
- **三种消费形态对照**：

| 形态 | 节奏控制 | 实时性 | 适用 |
|---|---|---|---|
| 原生 Pull（`DefaultMQPullConsumer`，5.x 已基本被 SimpleConsumer 取代） | 完全自管位点与轮询 | 取决于轮询间隔 | 批处理、精确位点控制 |
| 长轮询 Pull | 客户端自管 | 毫秒级（挂起唤醒） | 常规服务的默认选择 |
| Push（`DefaultMQPushConsumer`） | 框架自动拉+回调 | 毫秒级 | 绝大多数在线消费 |
| 5.x SimpleConsumer | **Broker 侧分配与不可见时间**（`changeInvisibleTime`），客户端无 rebalance | 拉模式 | 函数计算、需要"消费失败短隐藏"的场景 |

- **批量拉取与位点**：`pullBatchSize` 一次拉多条，但位点是**队列级一个整数**——提交的是"下一条待拉位置"，批内第 3 条失败、整批不提交，下批从第 1 条重来：这是批消费重复放大的根源，批量消费更要绑紧幂等。
- **流控在客户端做**：每个队列的本地缓存（ProcessQueue）有条数/体积阈值，消费慢 → 阈值超 → 暂停该队列拉取并延后重试。表现为"**消费 TPS 掉但客户端没报错**"——排查堆积时先想到它（第七节 SOP）。
- **消费端线程模型与并发度**：

```
rebalance(周期+成员变更) ─► 分到的队列 → 每队列一个 ProcessQueue(本地缓存)
        │ 入队 PullRequest                   │
        ▼                                   ▼
PullMessageService(单线程发起拉取,长轮询RPC) ─► 响应写入 ProcessQueue
                                                │ 流控(条数/体积阈值)超限→延迟重投 PullRequest
                                                ▼
                                    ConsumeMessageService 线程池(并发: consumeThreadMin/Max;
                                                                顺序: 每队列持锁单线程)
                                                │
                                    位点持久化线程：周期性把"下一条待拉位置"提交到 Broker
```

  并发度由**三层**共同封顶：队列数（>队列数的线程无效）→ 线程池大小 → ProcessQueue 流控阈值；调参时从这三层依次核对，只调线程池往往无效。

- **失败退避**：拉取异常或位点非法（如被过期清理）→ 延后重发拉取请求，位点非法时按 `consumeFromWhere` 语义重置；找不到队列 → 触发路由重取。

## 三、消息类型全景 ⭐

| 类型 | 核心语义 | 消费方式 | 关键 API |
|---|---|---|---|
| **普通消息** | 无顺序保证，吞吐最高 | `MessageListenerConcurrently` | `producer.send(msg)` |
| **顺序消息** | 同 key 严格 FIFO，分区顺序 / 全局顺序 | `MessageListenerOrderly` | `send(msg, selector, arg)` |
| **延迟消息** | 4.x 18 个固定级别；5.x 任意时间 | 同普通消息 | `setDelayTimeLevel(n)` / `setDelayTimeMs()` |
| **事务消息** | 本地事务与消息投递最终一致 | 同普通消息 | `TransactionMQProducer` |
| **批量消息** | 多条小消息合并发送，减少 RPC 次数 | 同普通消息 | `send(Collection<Message>)` |
| **消息轨迹** | 生产/存储/消费全链路轨迹，写系统 Topic | — | `setEnableMsgTrace(true)` |

### 顺序消息

- **全局顺序**：Topic 只建 1 个队列，Producer 单线程发、Consumer 单线程收（吞吐低，仅强合规场景用）。
- **分区顺序（生产常用）**：队列数 > 1，用 `MessageQueueSelector` 把**同一业务 key**（如 orderId）路由到**同一队列**，消费者用 `MessageListenerOrderly` 按队列加锁消费。
- ⚠️ 限制：① 队列数确定后不能再改（扩容会让同一 key 落不同队列）；② 顺序消息**不支持广播模式**；③ 必须用 `send(msg, selector, arg)`，普通 `send` 会破坏顺序；④ `MessageListenerOrderly` 消费失败默认**无限重试并阻塞该队列**（`SUSPEND_CURRENT_QUEUE_A_MOMENT`）以保证不跳号，脏数据会卡死整条队列。

### 延迟消息：4.x 与 5.x 的分水岭

**4.x：18 个固定延迟级别**（level 从 1 开始，无 level 0，可通过 Broker 配置 `messageDelayLevel` 自定义）。实现上消息先写 CommitLog，再改投到系统 Topic `SCHEDULE_TOPIC_XXXX`（每个级别一个队列），`ScheduleMessageService` 按级别定时扫描，到期后写回真实 Topic。

| Level | 1 | 2 | 3 | 4 | 5 | 6 | 7 | 8 | 9 | 10 | 11 | 12 | 13 | 14 | 15 | 16 | 17 | 18 |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| 延迟 | 1s | 5s | 10s | 30s | 1m | 2m | 3m | 4m | 5m | 6m | 7m | 8m | 9m | 10m | 20m | 30m | 1h | 2h |

**5.x：任意时间定时消息**（RIP-43），系统 Topic 为 `rmq_sys_wheel_timer`，基于 TimerLog + 多级时间轮：

```java

Message msg = new Message("TopicDelay", body);
msg.setDelayTimeSec(10);                                     // 10s 后投递
msg.setDelayTimeMs(10_000L);                                 // 毫秒版，效果同上
msg.setDeliverTimeMs(System.currentTimeMillis() + 10_000L);  // 定时到某个时间点投递
```

| 维度 | 4.x 固定级别 | 5.x 任意时间 |
|---|---|---|
| 灵活性 / 精度 | 只能选 18 个档位，秒级 | 毫秒级任意时间，默认精度 `timerPrecisionMs=1000` |
| 上限 | 2h（改 `messageDelayLevel`） | `timerMaxDelaySec`，源码默认 3 天，超出报 `timer message illegal` |
| 实现 / 客户端 | `SCHEDULE_TOPIC_XXXX` + 定时扫描；`setDelayTimeLevel` | `rmq_sys_wheel_timer` + 时间轮；`setDelayTimeSec/Ms`、`setDeliverTimeMs`（需 5.0.0+ 客户端 + 5.x Broker） |

⚠️ 4.x 的 `setDelayTimeLevel(0)` 会抛异常；5.x 用 `setDelayTimeMs` 时 Broker 需开启定时消息能力，否则消息立即投递。

#### 延迟消息的实现代价与重启行为

- **4.x 为什么"只能 18 档"反而便宜**：每个级别独占 `SCHEDULE_TOPIC_XXXX` 的一条队列，队列内消息天然按到期时间有序（同级别延迟相同 → 写入顺序就是到期顺序），扫描只需看队头，**没有任何排序/优先队列成本**。任意时刻数就要求队列按"到期时间"排序，这是 18 档与任意时间之间真正的工程分水岭。
- **5.x 时间轮的代价换灵活性**：任意定时消息先顺序追加进 TimerLog，再由多级时间轮（按到期时间分格）驱动投递。延迟档位从"零成本"变成"要维护轮 + 重写投递"，精度由 `timerPrecisionMs` 控制（同表），精度调得越细、轮推进越频繁。
- **重启行为（定性）**：两代的延迟消息**都是先落 CommitLog 再延迟投递**，到期时间随消息持久化，Broker 重启后从持久化的进度（4.x 各级别队列消费位点 / 5.x 定时器的 checkpoint 从 TimerLog 重放）继续投递——**不会丢，也不会提前**；但停机期间到期的消息会在恢复后集中补投，下游要按"延迟可能批量迟到"设计。细节实现以官方文档为准。
- 无论哪代，"延迟"改变的只是**投递时机**，不影响存储顺序与消费语义；消费失败的 `%RETRY%` 重投本身就是对延迟级别机制的一次复用（见第六节重试细节）。

### 事务消息（两阶段 + 回查）

1. Producer 发送**半消息（Half Message）**：对消费者不可见，写入系统 Topic `RMQ_SYS_TRANS_HALF_TOPIC`；
2. Broker ACK 后 Producer 执行**本地事务**，再按结果发送 **Commit / Rollback**：Commit 把消息转入真实 Topic 并可见，Rollback 删除半消息；
3. 若 Broker 长时间收不到结果（Producer 回 `UNKNOW`、宕机或断网），`TransactionalMessageCheckService` **每 60s 扫描**超时半消息，回查 Producer 的 `checkLocalTransaction`，默认最多 **15 次**，仍未决则丢弃并打日志。

⭐ 三个本地事务状态：`COMMIT_MESSAGE`（提交，消息可见）、`ROLLBACK_MESSAGE`（回滚，半消息删除）、`UNKNOW`（等 Broker 回查）。
⚠️ 事务消息是**最终一致**而非强一致：回查耗尽仍可能丢消息，关键业务要对账补偿；也不支持延迟与批量。

#### 半消息为什么"不可见"，回查为什么"必须写" ⭐

- **不可见靠的是"位点模型"而非"隐藏标记"**：消费者拉消息的路径是 真实 Topic → ConsumeQueue → CommitLog（见第二节存储设计）。半消息虽然躺在 CommitLog 里，但**真实 Topic 的 ConsumeQueue 里没有它的索引条目**，消费者按位点根本走不到这条物理记录——不需要任何"不可见标志位"，索引缺席即不可见。
- **Commit = 追加一条新消息，Rollback = 追加一条操作记录**：提交时 Broker 把半消息内容**重新投递**进真实 Topic（生成新的 CommitLog 记录 + 真实队列的 ConsumeQueue 索引，所以半消息与其副本的 offsetMsgId 不同，幂等键要用业务 Key）；回滚与清理都不原地改日志，而是往 `RMQ_SYS_TRANS_OP_HALF_TOPIC` 追加"已回滚/已提交"标记，后台线程按操作日志回收——标准的 **append-only + 补偿日志**做法，与 CommitLog 布局的约束一脉相承。状态全景：

```
sendMessageInTransaction
   │ ①发半消息
   ▼
HALF topic 有索引、真实 topic 无索引（消费者不可达=不可见）
   │ ②本地事务 → Commit / Rollback / (无响应)
   ├─ Commit   ─► 追加副本进真实 topic(OP 记 REP)      ─► 消费者可见
   ├─ Rollback ─► OP 记 ROLLED_BACK                    ─► 清理线程回收半消息
   └─ 无响应   ─► 60s 周期回查循环(≤15次) ─► 超时耗尽 ─► 丢弃+日志(业务需对账)
```
- **回查是唯一的修复通道，不是可选优化**：半消息发出后、Commit 前 Producer 宕机，本地事务的结果**只有那个进程知道**——不实现回查，这条消息要么永远不可见、要么被丢。两个实战细节：① 回查请求可能落到**同组另一台**没发过这条消息的实例上，所以 `checkLocalTransaction` 必须靠消息属性反查事务表/订单状态，不能依赖内存 Map；② 回查逻辑与本地事务写入必须在同一事务边界内可见（先落库再返回 COMMIT 的可能性都要能查到），这正是 [分布式事务.md](../../分布式/分布式事务.md) 里"可靠消息最终一致"的原型。
- **它不承诺 RT**：半消息 + 二阶段提交至少两次 RPC 交互，本地事务结果未知时投递时机还要等回查窗口（分钟级）。所以"不影响主链路 RT"只在你**从不返回 UNKNOW** 时成立——返回 UNKNOW 的那部分消息，下游感知延迟由回查节奏决定，对时间敏感的链路（如库存释放）要重新评估。

### 批量消息、过滤与消息轨迹

- **批量**：`producer.send(Collection<Message>)`，同批次 Topic 必须一致，**不支持延迟与事务**；受 Broker `maxMessageSize`（默认 4 MiB）约束，官方建议单批不超过 1 MiB。
- **过滤**：Tag 过滤在 Broker 端完成（ConsumeQueue 存 tagCode，性能好）；SQL92 过滤需 Broker 开 `enablePropertyFilter=true`，用法 `MessageSelector.bySql("age > 18")`，⚠️ 代价是拉取放大。
- **消息轨迹**：Broker 开 `traceTopicEnable=true`，生产/消费端 `setEnableMsgTrace(true)`，轨迹写 `RMQ_SYS_TRACE_TOPIC`，可在 Dashboard 看全链路耗时。

## 四、使用一：Java ⭐

### 依赖坐标

```xml

<dependency>
  <groupId>org.apache.rocketmq</groupId>
  <artifactId>rocketmq-client</artifactId>
  <version>5.3.1</version>   <!-- 仍在 4.x Broker 上可退用 4.9.7 -->
</dependency>
<!-- Spring 集成：org.apache.rocketmq:rocketmq-spring-boot-starter:2.3.1（内部依赖 client 5.x） -->
<!-- 命令行工具：org.apache.rocketmq:rocketmq-tools:5.3.1（提供 mqadmin） -->
```

⭐ **5.x 客户端可连 4.9.4+ Broker**（兼容协议），但任意时间延迟等新特性必须「5.x 客户端 + 5.x Broker」同时满足。

### 普通消息：生产（同步 / 异步 / 单向）

```java

public class NormalProducerDemo {
    public static void main(String[] args) throws Exception {
        DefaultMQProducer producer = new DefaultMQProducer("producer_group_demo");
        producer.setNamesrvAddr("127.0.0.1:9876");
        producer.setRetryTimesWhenSendFailed(2);  // 失败重试 2 次（共 3 次尝试）
        producer.start();

        Message msg = new Message("TopicTest", "TagA", "key-1001", "hello rocketmq".getBytes());
        // 1) 同步发送：有返回值，可靠性最高
        SendResult r = producer.send(msg);
        // 2) 异步发送：不阻塞主线程，回调处理结果，适合高吞吐
        producer.send(msg, new SendCallback() {
            @Override public void onSuccess(SendResult result) { }
            @Override public void onException(Throwable e) { }
        });
        // 3) 单向发送：只写 socket 不等响应，最快但可能丢，适合日志采集
        producer.sendOneWay(msg);
        producer.shutdown();
    }
}
```

异常处理：`MQClientException`（未 start / 路由为空 / 消息体超限 / Topic 未创建）、`RemotingException`（网络断连）、`MQBrokerException`（Broker 错误码，如 `SERVICE_NOT_AVAILABLE`）、`InterruptedException`（线程中断）。
⚠️ 重试只发生在**发送阶段**且是「换队列/换 Broker 重发」；Broker 已成功但客户端超时同样会重发，因此**消费端幂等必须有**。

### 普通消息：消费（并发监听）

```java

// 片段：方法体内
DefaultMQPushConsumer consumer = new DefaultMQPushConsumer("consumer_group_demo");
consumer.setNamesrvAddr("127.0.0.1:9876");
consumer.setConsumeThreadMax(32);   // ⚠️ 超过队列总数即无意义
consumer.setMaxReconsumeTimes(3);   // 重试 3 次后进死信队列
consumer.subscribe("TopicTest", MessageSelector.byTag("TagA || TagB"));

consumer.registerMessageListener((MessageListenerConcurrently) (msgs, context) -> {
    for (MessageExt msg : msgs) {
        try {
            System.out.printf("msgId=%s body=%s%n", msg.getMsgId(), new String(msg.getBody()));
        } catch (Exception e) {
            return ConsumeConcurrentlyStatus.RECONSUME_LATER;   // 稍后重投 %RETRY%group
        }
    }
    return ConsumeConcurrentlyStatus.CONSUME_SUCCESS;
});
consumer.start();
```

| 配置项 | 默认值 | 说明 |
|---|---|---|
| `consumeFromWhere` | `CONSUME_FROM_LAST_OFFSET` | 消费组首次启动位点；已有位点时该配置无效 |
| `messageModel` | `CLUSTERING` | 集群模式；`BROADCASTING` 每个实例消费全量，位点存本地文件 |
| `consumeThreadMin/Max` | 20 / 64 | 消费线程池，受队列数上限约束（一队列只分配一个线程） |
| `maxReconsumeTimes` | 16（并发） | 超限后进 `%DLQ%{group}` 死信队列 |

### 顺序消息完整示例

```java

// 片段：生产者，同一 orderId 固定落同一队列
DefaultMQProducer producer = new DefaultMQProducer("order_producer_group");
producer.setNamesrvAddr("127.0.0.1:9876");
producer.start();
String orderId = "ORDER_20260924_001";
Message msg = new Message("TopicOrder", "CREATE", orderId, ("下单:" + orderId).getBytes());
// 业务 key 取模选队列：队列数不变时同一 orderId 永远落同一队列
MessageQueueSelector selector = (mqs, m, arg) -> mqs.get(Math.abs(arg.hashCode()) % mqs.size());
producer.send(msg, selector, orderId);

// 片段：消费者，顺序消费
DefaultMQPushConsumer consumer = new DefaultMQPushConsumer("order_consumer_group");
consumer.setNamesrvAddr("127.0.0.1:9876");
consumer.subscribe("TopicOrder", "*");
consumer.registerMessageListener((MessageListenerOrderly) (msgs, context) -> {
    for (MessageExt msg : msgs) {
        try {
            System.out.printf("queueId=%d body=%s%n", msg.getQueueId(), new String(msg.getBody()));
        } catch (Exception e) {
            return ConsumeOrderlyStatus.SUSPEND_CURRENT_QUEUE_A_MOMENT;   // 挂起队列重试，不跳号
        }
    }
    return ConsumeOrderlyStatus.SUCCESS;
});
consumer.start();
```

⚠️ 顺序消费失败无限重试，必须在**有限次后落库告警**，否则脏数据卡死队列。

### 事务消息完整示例

```java

public class TxProducerDemo {
    public static void main(String[] args) throws Exception {
        TransactionMQProducer producer = new TransactionMQProducer("tx_producer_group");
        producer.setNamesrvAddr("127.0.0.1:9876");
        producer.setExecutorService(Executors.newFixedThreadPool(4));  // 回查线程池，勿与发送共用
        producer.setTransactionListener(new OrderTxListener());
        producer.start();

        Message msg = new Message("TopicTx", "PAY", "ORDER_1001", "支付成功通知".getBytes());
        msg.putUserProperty("orderId", "ORDER_1001");   // 回查时靠它反查本地事务
        TransactionSendResult result = producer.sendMessageInTransaction(msg, "ORDER_1001");
        System.out.println("tx state=" + result.getLocalTransactionState());
        producer.shutdown();
    }
}

class OrderTxListener implements TransactionListener {
    // 第二阶段：Broker 收到半消息后回调，执行本地事务并返回提交/回滚
    @Override
    public LocalTransactionState executeLocalTransaction(Message msg, Object arg) {
        try {
            return payOrder(String.valueOf(arg)) ? LocalTransactionState.COMMIT_MESSAGE
                                                 : LocalTransactionState.ROLLBACK_MESSAGE;
        } catch (Exception e) {
            return LocalTransactionState.UNKNOW;   // 交给 Broker 60s 后回查
        }
    }

    // 回查阶段：Producer 宕机重启后 Broker 会反复回调，必须幂等反查
    @Override
    public LocalTransactionState checkLocalTransaction(MessageExt msg) {
        String orderId = msg.getUserProperty("orderId");
        return isPaid(orderId) ? LocalTransactionState.COMMIT_MESSAGE
                               : LocalTransactionState.ROLLBACK_MESSAGE;
    }

    private boolean payOrder(String orderId) { return true; }   // 本地数据库事务
    private boolean isPaid(String orderId) { return true; }
}
```

⭐ 三个约束：① `checkLocalTransaction` 必须能**幂等反查**（查本地事务表/订单状态）；② 回查有次数上限（默认 15 次），最坏情况丢消息，关键链路加对账；③ ProducerGroup 全局唯一，事务消息**不能与延迟/批量混用**。

### Spring Boot 集成

依赖坐标：`org.apache.rocketmq:rocketmq-spring-boot-starter:2.3.1`（内部已依赖 rocketmq-client 5.x）。

```yaml

rocketmq:
  name-server: 127.0.0.1:9876
  producer:
    group: my-producer-group
    retry-times-when-send-failed: 2
```

```java

// 片段：发送，destination 语法为 "topic:tag"
@Service
public class OrderProducerService {
    @Resource private RocketMQTemplate rocketMQTemplate;

    public void sendCreate(OrderDTO order) {
        rocketMQTemplate.syncSend("order-topic:CREATE", order);              // 同步
        rocketMQTemplate.sendOneWay("order-topic:LOG", order);               // 单向
        // 异步：rocketMQTemplate.asyncSend("order-topic:CREATE", order, callback);
        rocketMQTemplate.syncSend("order-topic:DELAY",                       // 延迟级别（4.x 语义）
                MessageBuilder.withPayload(order).build(), 3000, 5);
        // 事务：rocketMQTemplate.sendMessageInTransaction("order-topic:CREATE", msg, arg);
    }
}

// 片段：消费，抛异常即重投（方法体内用 RocketMQTemplate 时无需该注解）
@Service
@RocketMQMessageListener(topic = "order-topic", selectorExpression = "CREATE",
        consumerGroup = "order-consumer-group",
        consumeMode = ConsumeMode.CONCURRENTLY,   // ORDERLY 为顺序消费
        messageModel = MessageModel.CLUSTERING)
public class OrderConsumer implements RocketMQListener<OrderDTO> {
    @Override public void onMessage(OrderDTO order) { /* 业务处理 */ }
}
```

⭐ 事务用法：`@RocketMQTransactionListener` 实现 `executeLocalTransaction` / `checkLocalTransaction`，配合 `rocketMQTemplate.sendMessageInTransaction("topic:tag", msg, arg)`。
⚠️ `RocketMQTemplate` 发送的是序列化对象，消费端泛型必须与生产端一致，否则反序列化失败会一直重试到进死信队列。

## 五、使用二：Go

### 依赖与版本选择

```bash

# 老版 remoting 协议客户端（对应 4.x Broker，社区最常用）
go get github.com/apache/rocketmq-client-go/v2@v2.1.0
# 5.x 官方新客户端（gRPC 协议，需 Broker/Proxy 开启 gRPC 端口，默认 8081）
go get github.com/apache/rocketmq-clients/golang/v5
```

| 客户端 | 协议 | 适用 Broker | 能力边界 |
|---|---|---|---|
| `rocketmq-client-go/v2` | remoting（私有 TCP） | 4.x 完全匹配；5.x 兼容可用 | 普通/顺序/延迟级别消息可用；**无任意延迟**；v2.1.0 后基本停更 |
| `rocketmq-clients/golang/v5` | gRPC | 5.x（需 proxy） | 支持任意延迟等 5.x 新特性 |

⚠️ 用 Go 老客户端连 5.x 集群时，`WithDelayTimeLevel` 在 5.x Broker 上仍按 18 个固定档位生效，**不会**升级为任意延迟。

### 生产者

```go

package main

import "context"
import "fmt"
import "github.com/apache/rocketmq-client-go/v2"
import "github.com/apache/rocketmq-client-go/v2/primitive"
import "github.com/apache/rocketmq-client-go/v2/producer"

func main() {
	p, err := rocketmq.NewProducer(
		producer.WithNameServer([]string{"127.0.0.1:9876"}),
		producer.WithGroupName("producer_group_demo"),
		producer.WithRetry(2), // 发送失败重试次数
	)
	if err != nil {
		panic(err)
	}
	if err = p.Start(); err != nil {
		panic(err)
	}
	defer p.Shutdown()

	msg := primitive.NewMessage("TopicTest", []byte("hello rocketmq"))
	msg.WithTag("TagA")
	msg.WithKeys([]string{"key-1001"})
	msg.WithDelayTimeLevel(3) // 4.x 语义：level 3 = 10s 后投递

	res, err := p.SendSync(context.Background(), msg)
	if err != nil {
		fmt.Println("send error:", err)
		return
	}
	fmt.Printf("send ok: msgId=%s queueId=%d\n", res.MsgID, res.MessageQueue.QueueId)
}
```

### 消费者

```go

package main

import "context"
import "fmt"
import "github.com/apache/rocketmq-client-go/v2"
import "github.com/apache/rocketmq-client-go/v2/consumer"
import "github.com/apache/rocketmq-client-go/v2/primitive"

func main() {
	c, err := rocketmq.NewPushConsumer(
		consumer.WithNameServer([]string{"127.0.0.1:9876"}),
		consumer.WithGroupName("consumer_group_demo"),
		consumer.WithConsumerModel(consumer.Clustering),
		consumer.WithConsumerOrder(true), // 顺序消费：组内按队列加锁
	)
	if err != nil {
		panic(err)
	}
	// 必须先 Subscribe 再 Start；一次回调可能拿到多条消息
	err = c.Subscribe("TopicTest",
		consumer.MessageSelector{Type: consumer.Tag, Expression: "TagA || TagB"},
		func(ctx context.Context, msgs ...*primitive.MessageExt) (consumer.ConsumeResult, error) {
			for i := range msgs {
				fmt.Printf("msgId=%s tag=%s body=%s\n", msgs[i].MsgId, msgs[i].GetTags(), string(msgs[i].Body))
				if len(msgs[i].Body) == 0 {
					return consumer.ConsumeRetryLater, fmt.Errorf("empty body")
				}
			}
			return consumer.ConsumeSuccess, nil
		})
	if err != nil {
		panic(err)
	}
	if err = c.Start(); err != nil { // Subscribe 之后才能 Start
		panic(err)
	}
	select {} // 阻塞；生产环境应监听信号后 c.Shutdown() 优雅退出
}
```

⭐ Go 客户端回调签名为 `func(context.Context, ...*primitive.MessageExt)`；`consumer.ConsumeRetryLater` 等价 Java 的 `RECONSUME_LATER`，同样受 16 次重试上限约束，最终进 `%DLQ%{group}`。

## 六、可靠性机制

### 刷盘策略 × 主从复制

| 组合 | 配置 | 可靠性 | 性能 | 适用 |
|---|---|---|---|---|
| 异步刷盘 + 异步复制 | `ASYNC_FLUSH` + `ASYNC_MASTER`（**默认**） | 最低：OS 宕机丢 PageCache，Master 挂丢未同步数据 | 最高 | 日志、埋点等可容忍少量丢失 |
| 同步刷盘 + 异步复制 | `SYNC_FLUSH` + `ASYNC_MASTER` | 单机不丢；Master 挂可能丢未同步副本 | 中 | 消息不可丢、可接受 RT 上升 |
| 异步刷盘 + 同步复制 | `ASYNC_FLUSH` + `SYNC_MASTER` | Master 挂可切换，OS 宕机仍可能丢 | 中 | 高可用优先 |
| 同步刷盘 + 同步复制 | `SYNC_FLUSH` + `SYNC_MASTER` | 最高：落盘 + 从节点确认后才 ACK | 最低 | 支付、订单等金融场景 |

#### 复制方式对「不丢」与 RT 的影响，以及 DLedger 补的那块 ⭐

- **异步复制的丢失窗口**：Master 写完即 ACK，Slave 追赶是后台流式复制——Master 磁盘报废这类"不可恢复故障"时，**已 ACK 但未同步**的尾部消息即丢失。同步复制（`SYNC_MASTER`）把 ACK 推迟到组内多数/Slave 落组成功，窗口归零，代价是 RT 受组内**最慢副本**牵制、Slave 落后时 Master 会限流拒绝写入（明确失败，不静默丢）。
- **原生主从缺的不是复制，是"切换"**：4.x 原生主从 Master 挂掉后 Slave **只读、不会自动升主**，该组写能力直接中断（客户端的延迟故障规避只是绕开发送，不能恢复写）。要自动切换才上 DLedger 模式：把一组 Broker 组成 Raft 组，Master 故障自动选主。
- **DLedger 的三笔代价**：① 每组必须 ≥3 节点（多数派）；② 提交需多数写成功，RT 与可用性绑定网络分区；③ 选主窗口内该组不可写。5.x 起也可用独立 NameServer 集群（`enableControllerInNamesrv`）承载主切换、保留原生复制模型——"自动切主"和"多数派写"其实是两件事，选型时分开评估。Raft 细节见 [Raft协议.md](../../分布式/Raft协议.md)。
- 顺带回答"为什么半同步思路不常见"：RocketMQ 的 `SYNC_MASTER` 是"写组成功才 ACK"的整体语义，不像 MySQL 有"收到 ACK 即返回"的 after-sync 中间档——要"RT 低一点"只能靠减副本数或换异步，没有半档可调（以官方文档语义为准）。

### 消息重试与死信

| 阶段 | 机制 | 关键参数 |
|---|---|---|
| 生产者重试 | 同步发送失败换队列/Broker 重发 | `retryTimesWhenSendFailed=2`（共 3 次） |
| 消费者重试（并发） | 返回 `RECONSUME_LATER` → 进 `%RETRY%{group}`，延迟递增重投 | `maxReconsumeTimes=16` |
| 消费者重试（顺序） | `SUSPEND_CURRENT_QUEUE_A_MOMENT` 挂起队列，**默认无限重试** | 顺序消费默认 `Integer.MAX_VALUE` |
| 死信 | 重试超限进 `%DLQ%{group}`，只能人工通过 Dashboard / `mqadmin` 重投 | 需监控 DLQ 条数 |

#### 重试队列与死信的实现细节 ⭐

- **命名规则**：消费组首次订阅时 Broker 自动创建 `%RETRY%{consumerGroup}`（较新版本按 Topic 细分为 `%RETRY%{group}_{topic}`，以自己集群版本为准）与 `%DLQ%{consumerGroup}` 两个系统 Topic——它们**与普通 Topic 走同一套存储与消费路径**，重试队列本质上只是"名字里带组的普通 Topic"，消费者会额外订阅它，因此重试消息天然回到**同一个组**而不是原 Topic 的其他组。
- **延迟递增 = 复用延迟消息机制**：并发消费失败时，客户端把消息"发回"重试 Topic，并携带 `reconsumeTimes` 映射出的延迟级别——首次重试是十秒级，随重试次数递增到分钟、小时级（具体映射表以官方文档为准）。⚠️ 这意味着**重试退避依赖延迟消息基础设施**：4.x 上第 16 次重试早已超过小时档，尾部会落在最高档反复等——退避不是无限的指数，是封顶的。
- **为什么顺序消费不能走 %RETRY%**：重试 Topic 的队列与原 Topic 队列没有顺序映射关系，消息绕道回来时**原队列里的后继消息会被先消费**，顺序直接破坏。所以顺序消费失败只能"挂起原队列、原地重试"，代价就是第三节说的毒消息卡死整条队列——两条路是同一枚硬币的两面：**并发要吞吐所以敢换队重投，顺序要语义所以只能卡住**。
- **次数上限与判定的归属**：`reconsumeTimes` 存在消息系统属性里、跟着消息走（不是存在消费端），所以换实例、重启都不重置；超限（默认 16）后 Broker 侧改投 DLQ。
- **死信的"兜底"才是问题**：DLQ 队列容量有上限、且和消息保留期一样会被过期清理——**进 DLQ ≠ 进保险箱，没人管就是最终丢失**。可操作的分层：① 监控 `%DLQ%` 堆积数告警（Dashboard/`stats.log`）；② 上线一个专职"死信消费者"把消息落库归档；③ 业务侧按 [限流降级熔断.md](../../分布式/限流降级熔断.md) 的**写降级补偿**思路处理：失败消息落本地补偿表，人工/定时重放，与"本地消息表"同构。

### rebalance：队列分配与顺序性的冲突 ⭐

- **分配模型**：消费组内成员对 topic 队列做**客户端各自计算**的分配（默认平均分配：队列数 ÷ 实例数），无中心协调者；触发时机 = 组内成员变化（Broker 主动通知）+ 周期性兜底。这与 NameServer 的哲学一致：**分摊错误、事后自愈，不做强一致协调**。
- **再平衡瞬间为什么会重复**：切换不是原子交接——旧主还握着 ProcessQueue 里**未提交位点**的已拉消息，新主从 Broker 上"最后提交位点"重拉，交集即重复。所以重复消费的高峰不在日常，而在**发布/缩扩容/实例假死被判离线**这三类时刻（呼应幂等小节：幂等不是防御异常，是防御常态）。并发消费在 rebalance 前会先把已缓存消息消费完并提交位点、且**位点提交与队列移交之间仍有窗口**，这个窗口无法靠客户端消除——at-least-once 的下界就在这。
- **顺序消息的"三件事"缺一不可**：

| 环节 | 机制 | 单独失效的后果 |
|---|---|---|
| ① 生产端选同一队列 | `MessageQueueSelector` 按业务 Key 固定映射 | 同 Key 两条消息落不同队列，被不同线程并发消费——顺序在**入口**就没了，后面做得再好也没用 |
| ② 消费端单队列单线程 | 进程内每个队列一个 ProcessQueue + 本地锁 | 同一次拉取的多条消息被线程池并发回调，完成顺序 ≠ 提交顺序，**进程内**就乱了 |
| ③ 队列分布式锁 | 顺序消费前向 Broker 申请队列锁并周期续期，同组内谁持锁谁才能消费该队列 | rebalance 时新旧实例**同时**消费一个队列；旧实例宕机不释放则其他实例**等锁过期才能接管**——表现为扩缩容瞬间该队列停滞几十秒再突然追平 |

- 三件事的共同点：①②防"乱序"，③防"双主"；而 ③ 的续锁/过期机制又说明 RocketMQ 的顺序是**尽力而为的顺序**——锁过期窗口、Broker 主从切换瞬间仍可能双持锁，严格串行要业务层的序列号/版本号校验兜底（把"顺序"变成"可检测的矛盾"，再用幂等消化）。

### 消息幂等（必须消费端做）

RocketMQ 只保证 **at least once**：网络抖动、超时重发、位点提交失败都会导致重复消费，**Broker 不做去重**，去重只能落在消费端：

| 方案 | 实现 | 优点 | 缺点 |
|---|---|---|---|
| 唯一索引去重表 | 消费前插入去重表（`msg_key` 唯一索引），冲突即已消费 | 强一致、实现简单 | 每次消费一次 DB 写，需定期清理 |
| Redis 去重 | `SET key:msgKey 1 NX EX 86400`，返回 0 则跳过 | 性能高、天然过期 | 需容忍 Redis 故障（可降级放行） |
| 业务状态机 | `UPDATE order SET status='PAID' WHERE id=? AND status='UNPAID'`，影响行数 0 即重复 | 无额外存储、最贴近业务 | 仅适用有状态流转的场景 |

⚠️ 去重 key 优先用**业务唯一键**（`msg.getKeys()`、订单号），不要用 `msgId`：消息进重试队列后 msgId 与 offsetMsgId 都会重新生成。

### 消息堆积与消费位点

| 事项 | 说明 |
|---|---|
| 堆积根因 | 消费能力 < 生产速度：消费逻辑慢、线程不足、队列数不足、下游阻塞 |
| 扩容误区 | ⚠️ 消费者实例数 > 队列数时，多出的实例**完全分不到队列**，必须先扩队列再加机器 |
| 应对手段 | 提高 `consumeThreadMax`、批量消费、下游异步化、加队列后扩实例、必要时转存离线 |
| 位点管理 | 查看 `mqadmin consumerProgress -g {group}`（`Diff Total` 即堆积量）；重置 `mqadmin resetOffsetByTime`，⚠️ 会引发重复消费；集群模式位点存 Broker 的 `consumerOffset.json`，广播模式存本地 `~/.rocketmq_offsets` |

#### 端到端「消息不丢」三段责任表 ⭐

把前面所有机制按"失败发生在哪一段"重排——每段只对段内失败负责，跨段的窟窿必须由下一段的对策补：

| 段 | 典型失败点 | 段内对策 | 段间残留风险（归下一段兜） |
|---|---|---|---|
| **生产端 → Broker** | 网络超时、Broker 拒写（磁盘满/禁写）、客户端误以为已发 | 同步发送 + 校验 `SEND_OK` + 换 Broker 重试；关键消息用事务消息把"本地落库"与"发送"绑成最终一致；杜绝 oneway | 超时重发造成**重复**；"Broker 成功但客户端超时"造成状态未知 → 靠事务消息/对账收敛 |
| **Broker 存储** | OS 宕机丢 PageCache、Master 故障丢未复制尾部、过期删除/磁盘强删 | 同步刷盘、同步复制或 DLedger；保留期覆盖最慢消费者 | ACK 之后**消费前**消息被过期物理删除（保留期 < 堆积时长）→ 只能靠容量与告警防住，没有"未消费不删"承诺 |
| **Broker → 消费端** | 拉到了没处理完进程就挂、处理失败、rebalance 交接、提交位点丢失 | **先处理后提交**（框架默认即如此）；失败交给 %RETRY% 退避重试；毒消息有上限后进 DLQ 并有人兜底 | 重试/再平衡必带**重复** → 消费端幂等是整条链的最后一道闸（去重表/状态机） |

⭐ 一句话版本：RocketMQ 的投递语义是 **at-least-once**——"不丢"由三段各自对策连乘而成（任何一段失守全链即失守），"不重" Broker 不管，只有消费端一道防线。

## 七、部署与运维要点

### 端口与集群形态

NameServer 用 **9876**；Broker 主通道 **10911**（`listenPort`）、HA 端口 **10912**（`listenPort + 1`）、`fastRemotingPort` **10909**（VIP 通道，5.x 已废弃）；rocketmq-dashboard 默认 **8080**。

```bash

# 单机
nohup sh bin/mqnamesrv &
nohup sh bin/mqbroker -n 127.0.0.1:9876 -c conf/broker.conf &
# 生产集群：2 台 NameServer + 2m-2s（或 DLedger 自动主从切换）
sh bin/mqadmin clusterList -n 127.0.0.1:9876
```

```properties

# conf/broker.conf
brokerClusterName=DefaultCluster
brokerName=broker-a
brokerId=0                      # 0=Master，>0=Slave
namesrvAddr=127.0.0.1:9876
listenPort=10911
brokerRole=ASYNC_MASTER         # SYNC_MASTER / SLAVE
flushDiskType=ASYNC_FLUSH       # SYNC_FLUSH
autoCreateTopicEnable=false     # ⚠️ 生产必须关，防止乱建 Topic
fileReservedTime=72             # 消息保留 72 小时
diskMaxUsedSpaceRatio=75        # 磁盘使用率超 75% 拒绝写入
```

### 控制台与 PageCache

- **rocketmq-dashboard**（原名 rocketmq-console-ng）：`java -jar rocketmq-dashboard-2.0.0.jar --server.port=8080 --rocketmq.config.namesrvAddr=127.0.0.1:9876`，可看队列分布、消费堆积与 TPS、主从状态、消息轨迹、按 Key/MsgId 查消息、DLQ 重投。
- **PageCache**：CommitLog 用 **mmap 映射（单文件固定 1 GiB，`mappedFileSizeCommitLog`）**，写入先落 PageCache 再由刷盘线程落盘，因此异步刷盘下 `send` 成功 ≠ 已落盘；PageCache 不足会触发 `osPageCacheBusyTimeOutMills`（默认 1000ms）超时，本质是磁盘写入跟不上。要求磁盘预留 ≥30%、生产用 SSD，并把 `fileReservedTime` 与磁盘容量匹配。
- ⚠️ 优雅下线顺序：先停 Producer → 再停 Consumer → 最后 `sh bin/mqshutdown broker`，避免进程退出丢 PageCache 数据。

### 常见故障

| 故障 | 影响 | 处理 |
|---|---|---|
| NameServer 挂（含全挂） | 已运行的客户端用**本地缓存路由**继续收发；新客户端启动、Topic 变更、Broker 上下线失效 | 无状态，直接重启任一节点；生产部署 2~3 台 |
| Broker 从节点只读 | Slave 默认不提供写；能否读 Slave 由 `slaveReadEnable` 决定（默认 false，开启后仅 Master 内存高水位时才从 Slave 拉取） | 4.x 主从为**异步**复制，Master 挂后 Slave 只读且可能丢最后一段数据；要自动切换需 DLedger |
| Broker 磁盘满 | `diskMaxUsedSpaceRatio` 触发后拒绝写入，生产者报 `SERVICE_NOT_AVAILABLE` | 清理过期 CommitLog 或扩盘；调小 `fileReservedTime` |
| 位点丢失 | 集群模式位点在 Broker，数据目录被清空则位点归零，引发大量重复消费 | 备份 `config/consumerOffset.json`，用 `resetOffsetByTime` 校正 |

### 运维实操 SOP ⭐

#### 消息堆积的处理顺序（顺序本身比手段重要）

1. **先判方向，再动手**：对比生产 TPS 曲线与消费端指标（消费 RT、失败率、GC）。生产突增 → 是流量问题，走限流/扩容预案；消费变慢 → 是自身或下游问题（DB 慢、下游超时），**先修消费能力，别急着动 Topic**。
2. **看分布定性质**：`mqadmin consumerProgress` 里堆积**均匀分布在所有队列** → 组整体能力不足；**集中在个别队列** → 停滞类故障，走下一小节，扩消费者无用。
3. **扩容量**：提 `consumeThreadMax`（≤ 队列总数才有意义）、开批量消费、下游调用异步化——**进程内手段先行，因为零风险**。
4. **扩并行**：先加队列数、再加消费者实例（顺序反了多出的实例分不到队列，见第六节扩容误区）。⚠️ 顺序消息 Topic **不能加队列**（同 Key 会落不同队列），只能整机加线程/换更强实例。
5. **极端预案——转投临时 Topic**：写一个只做搬运的轻量消费者把消息原样灌进队列数更多的临时 Topic，再挂大批实例消费临时 Topic。用"一次额外堆积"换并行度，事后核对两边位点与总数再下线。
6. **追位点是手术不是吃药**：`resetOffsetByTime` 向前跳 = **永久跳过**未消费消息（无归档即真丢）。跳之前确认区间内有无交易/资金类消息、能否从 DB/轨迹反查补录；这是"弃车保帅"，须留对账记录。

#### 排查「某队列消费停滞」

- 第一步 Dashboard/consumerProgress 定位：哪个队列、分配给了哪个客户端实例（Diff 只涨不动的队列 → 客户端地址）。
- 三个高频原因按序核对：① **毒消息**：并发消费下该消息在 %RETRY% 里按递增退避反复重投（看重试队列是否有同一业务 Key），顺序消费下一条失败卡整队（SUSPEND 原地重试）；② **拉取流控**：位点不动但客户端无异常 → 该实例 ProcessQueue 缓存超阈值暂停拉取（消费线程池被慢任务占满的间接症状，配合 GC 日志看）；③ **队列锁滞留**：顺序消费实例宕机后锁未释放，其他实例等锁过期，表现为停滞数分钟后突然追平。
- 处置梯度：能自动退避自愈的不干预；毒消息从重试队列摘除转 DLQ/补偿表人工处理；顺序队列卡死超阈值要告警介入（第六节"有限次后落库告警"的落地）。

#### 磁盘与过期删除：与 Kafka retention 的本质差异

- 删除粒度是**整个 1 GiB CommitLog 文件**：文件全部消息超过保留期（`fileReservedTime`）才可删，配合定时清理窗口（`deleteWhen` 默认凌晨执行）错峰；磁盘超 `diskMaxUsedSpaceRatio` 水位则提前强制清理、再恶化直接拒写（第七节故障表）。ConsumeQueue/IndexFile 按 CommitLog 已删除的最小物理偏移联动回收。
- ⚠️ 因为是混写文件，**保留策略是集群全局的**：做不到 Kafka 那样"Topic A 留 3 天、Topic B 留 30 天"——不同保留 SLA 的业务要分集群部署（对照 [Kafka.md](Kafka.md) 的 segment 级 retention）。
- "未消费也照删"是设计立场：RocketMQ 的堆积容忍上限 = 保留期 × 磁盘，不是无限日志；重要数据在业务库落库，MQ 只做通道。

## 八、面试官会追问什么

1. **RocketMQ 为什么不支持任意延迟（4.x）？** 4.x 用固定 `delayLevel`，每个级别对应 `SCHEDULE_TOPIC_XXXX` 下一条队列 + 定时任务扫描，实现简单、无排序成本；任意延迟需要海量定时任务或时间轮支撑，5.x 才用 TimerLog + 多级时间轮（`rmq_sys_wheel_timer`）做到毫秒级任意延迟。
2. **事务消息的回查是怎么触发的？** 半消息写入 `RMQ_SYS_TRANS_HALF_TOPIC`；Producer 未回 Commit/Rollback（返回 UNKNOW、宕机、断网）时，Broker 的 `TransactionalMessageCheckService` 每 60s 扫描超时半消息，反查 `checkLocalTransaction`，默认最多 15 次，因此回查逻辑必须幂等且能反查本地事务。
3. **顺序消息如何保证不丢、不跳？** 生产端同 key 固定队列；消费端 `MessageListenerOrderly` 按队列加锁单线程消费，失败返回 `SUSPEND_CURRENT_QUEUE_A_MOMENT` 无限重试并阻塞队列，所以不会跳过；代价是队列卡死风险，需告警兜底。
4. **消息堆积怎么办？** 先定位是消费慢还是生产突增；手段为提消费线程（≤ 队列数）、批量消费、下游异步化、先扩队列再扩实例；⚠️ 队列数不变时加消费者实例无效。
5. **与 Kafka 的核心差异？** ① 元数据：NameServer（无状态 AP）vs ZooKeeper/KRaft；② 存储：统一 CommitLog + ConsumeQueue（单机可挂万级队列）vs 每分区独立目录（分区多则文件数爆炸）；③ 功能：RocketMQ 原生延迟消息、事务消息、Tag/SQL 过滤、消息轨迹与消息查询，Kafka 靠分区吞吐与流式生态取胜。
6. **如何保证消息不丢、不重复？** 不丢：生产端同步发送 + 重试 + 事务消息，Broker 端 `SYNC_FLUSH` + `SYNC_MASTER`，消费端处理成功后再提交位点；不重复：RocketMQ 只保证 at-least-once，只能消费端幂等（去重表 / Redis SETNX / 业务状态机），去重 key 用业务唯一键而非 msgId。
7. **为什么 RocketMQ 单机能支持数万队列，而 Kafka 分区多了会变差？** CommitLog 顺序写 + ConsumeQueue 轻量索引（每条约 20 字节，30 万条仅约 6MB）让存储与队列数解耦；Kafka 每个分区一套日志段与索引文件，分区数增大带来随机 IO 与文件句柄压力。
8. **CommitLog 写放大为什么低？换来了什么代价？** 全部 Topic 混写 → 全机单追加点 + mmap 写 PageCache + 批量刷盘，磁盘接近顺序带宽；ConsumeQueue 是定长追加。代价在读：单队列数据散落全日志，必须两跳（索引→正文）；且"写一次"后每个消费组各读一遍，热 Topic 高并发消费时 PageCache 会被读挤占（写路径变慢的隐性来源）。机制见第二节存储设计。
9. **长轮询和真正的 push 差在哪？为什么不做成 broker 主动推？** 差在连接方向：长轮询是"客户端挂起请求等结果"，broker 不需要维护到客户端的反向连接。不做真推的理由：客户端在 NAT/负载均衡后面不可达、broker 要保持每消费者的连接与状态（有状态化）、消费端扩缩容时 broker 得感知连接变化——把状态全留在位点与队列锁上，broker 才能对消费者近乎无感。见第二节拉取与长轮询。
10. **IndexFile 怎么按 Key 找到消息？** 哈希定位到 200MB 定长文件的槽，槽内存"最新条目偏移"，条目里带"同槽前一条偏移"形成链，沿链比对 keyHash + 时间差。写满覆盖最旧条目——所以只保证查到近期数据；且只比哈希不存原文，结果需二次确认。（第二节）
11. **rebalance 时一定会重复消费吗？怎么缓解？** 并发消费的交接窗口（旧主未提交位点的缓存消息 + 新主从已提交位点重拉）无法在协议层消除——at-least-once 的下界；缓解手段是减少 rebalance 频率（滚动发布批大小、会话参数）+ 消费端幂等。顺序消费靠 Broker 端队列分布式锁把"双主"变成"接管等待"，牺牲切换速度保正确性。（第六节）
12. **顺序消费的三件锁事各挡什么，少一件会怎样？** 生产端 Key→固定队列挡"入口分叉"；消费端单队列单线程挡"进程内并发乱序"；Broker 队列分布式锁挡"rebalance/宕机时的双实例同队消费"。少任何一件都有对应的乱序路径，且第三件的锁过期窗口说明它是"尽力而为"，严格场景要业务序列号校验。（第六节 rebalance 小节）
13. **什么时候必须上 DLedger？** 需求是"Master 故障自动恢复**写**能力"时——原生主从的 Slave 不会自动升主，客户端故障规避只救发送不救该组写。代价：≥3 副本、多数派写抬高 RT、选主窗口不可写。本质是把 NameServer 不要的强一致，恰好补给"谁是 Master"这份不能靠重试消化的元数据。（第六节；Raft 原理见 distributed/一致性与Raft.md）
14. **死信队列里消息放着就安全吗？** 不：DLQ 队列容量有上限会被覆盖，消息同受全局保留期约束，到期物理删除——没人消费的死信等于丢了。标准动作是监控 DLQ 增量告警 + 专职消费者落库归档 + 按写降级补偿思路重放。（第六节重试细节）

## 关联

- [消息队列选型.md](消息队列选型.md) — 什么时候用它而不是 Kafka
- [Kafka.md](Kafka.md) — 分区模型与存储结构的对照
- [../../分布式/Raft协议.md](../../分布式/Raft协议.md) — DLedger 模式的主选举
- [../../分布式/分布式事务.md](../../分布式/分布式事务.md) — 事务消息的完整语义
