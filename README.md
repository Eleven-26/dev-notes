# dev-notes

开发知识笔记。按技术方向分目录，**每个文件聚焦一个知识点**。

---

## 目录

### Go

| 文件 | 知识点 |
|---|---|
| [go/切片.md](go/切片.md) | 底层结构、SliceHeader 三要素、append 扩容规则、预分配优化、母子切片共享与断链、子切片内存泄漏与 copy、切片传参陷阱 |
| [go/map.md](go/map.md) | 底层结构、哈希冲突、扩容两条件两方式、创建/访问/更新/疏散源码流程、key 类型限制 |
| [go/channel原理与底层实现.md](go/channel原理与底层实现.md) | hchan 字段、收发流程、并发安全来源、缓冲区「总是新鲜的」、select 的三轮检查与轮询顺序 |
| [go/channel使用陷阱.md](go/channel使用陷阱.md) | 能不能先判断阻塞再写入、最容易踩的 4 个点、死锁什么时候报（四条判据） |
| [go/channel实战模式.md](go/channel实战模式.md) | 通信 6 案例、项目里 4 种典型用法、多生产者 + 单消费者的两个同步点 |
| [go/共享内存与CSP.md](go/共享内存与CSP.md) | 「不要通过共享内存来通信」到底在说什么、两条路线的适用边界 |
| [go/defer.md](go/defer.md) | 三个特性、三个应用场景、LIFO 执行顺序及源码原理 |
| [go/for-range.md](go/for-range.md) | 值变量是临时变量、哪些类型能通过指针改原始数据 |
| [go/内存逃逸.md](go/内存逃逸.md) | 定义、原因、分析工具、五类场景、三条判定规则、优点与代价 |
| [go/内存分配器.md](go/内存分配器.md) | mspan 字段、size class 67 等级、堆内存块元数据、mcache/mcentral/mheap 三级分配、两级索引映射、32 位地址优化 |
| [go/程序启动流程.md](go/程序启动流程.md) | _rt0 汇编入口、栈/分配器/调度器初始化、运行时检查、OS 信息初始化、启动全景图 |
| [go/函数调用与栈.md](go/函数调用与栈.md) | 函数调用过程、栈里包含哪些信息 |
| [go/线程安全.md](go/线程安全.md) | 并发读写 map 的后果、线程安全定义、三类线程安全类型 |
| [go/并发同步原语.md](go/并发同步原语.md) | Mutex 语义与饥饿模式、排队上限、手动加锁 vs sync.Map、协程间通信五法、RWMutex 相容矩阵与写饥饿、sync.Once 与单例、分段锁 map |
| [go/并发控制实战.md](go/并发控制实战.md) | 打印升序数字、交替打印奇偶数、获取协程返回值（含可运行代码） |
| [go/协程泄漏与死锁.md](go/协程泄漏与死锁.md) | 泄漏四类成因与最小复现代码、pprof 排查、死锁四条件与检测手段 |
| [go/GMP调度.md](go/GMP调度.md) | GMP 三元组、work stealing、协程上下文切换时机 |
| [go/context.md](go/context.md) | 四个派生函数、链路超时预算与级联取消、落地四个反模式 |
| [go/限流器.md](go/限流器.md) | 令牌桶模型、Limit/Burst 参数、平滑生成与突发处理 |
| [go/零拷贝.md](go/零拷贝.md) | 四次拷贝两次系统调用、mmap/sendfile/splice/MSG_ZEROCOPY、Go 里的自动优化 |
| [go/算法题.md](go/算法题.md) | 环形缓冲、斐波那契改迭代、堆构建与弹堆顶、TopK、TTL 缓存（代码实测可跑） |
| [go/编译与gcflags.md](go/编译与gcflags.md) | gcflags 的作用与使用时机、`-m`/`-N`/`-l`/`-S` 速查、如何查更多 flag |
| [go/调试与IDE配置.md](go/调试与IDE配置.md) | VSCode（gopls + dlv）开发运行环境配置、常用调试技巧 |
| [go/国际化.md](go/国际化.md) | gotext 的 extract→翻译→generate 工作流与常见坑 |
| [go/依赖注入.md](go/依赖注入.md) | 手动构造注入、wire 代码生成、dig/fx 反射方案对比、Kratos 实战 |
| [go/Kratos框架.md](go/Kratos框架.md) | 集成 ent/validate、注册发现与容器化、服务间鉴权与元数据传递、json→protobuf |
| [go/Eino框架.md](go/Eino框架.md) | 字节 Eino 大模型应用框架 |
| [go/语言设计与对比.md](go/语言设计与对比.md) | 设计哲学与核心特性、与 Java/C++/Python 对比、业务适配与局限、云原生生态被包场的原因 |
| [go/就业面与技能要求.md](go/就业面与技能要求.md) | 「Go 好就业」的客观拆解、从招聘 JD 反推要补的技能 |

### 数据结构与算法

| 文件 | 知识点 |
|---|---|
| [algorithms/数据结构.md](algorithms/数据结构.md) | 八大基础结构的特性与场景、跳表/Trie/布隆/并查集/一致性哈希、B+ 树与跳表的选型推导、结构×复杂度×隐藏代价总表 |
| [algorithms/复杂度分析.md](algorithms/复杂度分析.md) | 大 O 与常见复杂度、摊还分析、递归树与主定理直觉、空间复杂度与稳定性、大 O 之外的三个陷阱 |
| [algorithms/查找与排序对比.md](algorithms/查找与排序对比.md) | 查找结构与排序算法对照表、工程选型、标准库为何各选不同、Top-K 的堆怎么选 |
| [algorithms/压缩算法.md](algorithms/压缩算法.md) | Snappy/LZW 与 zstd/lz4 定位、块压缩 vs 流压缩、「开了压缩反而更慢」的判据 |
| [algorithms/缓存淘汰算法.md](algorithms/缓存淘汰算法.md) | LRU 实现与两大缺陷、LFU/Clock/W-TinyLFU、WiredTiger 的压缩与淘汰联动 |

### Java

| 文件 | 知识点 |
|---|---|
| [java/类加载机制.md](java/类加载机制.md) | 四层类加载器、双亲委派「先委托后自己加载」流程与优点、打破双亲委派的场景 |
| [java/JVM与垃圾回收.md](java/JVM与垃圾回收.md) | 对象生命周期、可达性分析与 GC Roots、四种 GC 算法、三色标记与漏标修复、收集器分类与调优命令 |
| [java/运行时数据区与栈帧.md](java/运行时数据区与栈帧.md) | 栈帧五个组成部分、动态链接为什么是多态的实现基础、线程私有的运行时数据区 |
| [java/Stream流实战.md](java/Stream流实战.md) | 取列/flatMap、List 转 Map、groupingBy 分组、BigDecimal 累加、多字段排序、按字段去重、7 个常见坑 |

### GC

| 文件 | 知识点 |
|---|---|
| [gc/GC基础算法.md](gc/GC基础算法.md) | 性能评价四标准、标记-清除/引用计数/标记-压缩/复制/保守式/分代/增量式/RC Immix 的过程与优缺点、算法选型总表、与 Java GC 的映射（含 46 张本地化配图，见 `gc/images/`） |

### 数据库与存储

| 文件 | 知识点 |
|---|---|
| [datastore/mysql/查询链路.md](datastore/mysql/查询链路.md) | 查询一条数据的完整链路、预编译在接口层的真实收益与坑（Go / Java 双版本） |
| [datastore/mysql/软件架构.md](datastore/mysql/软件架构.md) | MySQL 分层架构（连接层 / SQL 层 / 存储引擎层）、读写分离的架构位置与实现 |
| [datastore/mysql/JOIN与反范式.md](datastore/mysql/JOIN与反范式.md) | JOIN 用法与取舍、互联网为什么少用 JOIN、拆成多次单表查询 + 应用层归并的实测 |
| [datastore/mysql/索引与优化.md](datastore/mysql/索引与优化.md) | 索引分类与回表、索引失效、慢查询优化与排查、分页优化 |
| [datastore/mysql/事务与隔离级别.md](datastore/mysql/事务与隔离级别.md) | ACID 与四种隔离级别、「读未提交」的适用场景、小事务/保存点/死锁重试落地 |
| [datastore/mysql/日志与落盘.md](datastore/mysql/日志与落盘.md) | 落盘流程、redo/undo/binlog 分工、两阶段提交、主从延迟与读己之写 |
| [datastore/mysql/并发控制与MVCC.md](datastore/mysql/并发控制与MVCC.md) | 乐观锁 vs 悲观锁的原理/实现/选型、MVCC 特点、隐藏列 + undo 版本链 + Read View、RC 与 RR 差异 |
| [datastore/mysql/数据迁移.md](datastore/mysql/数据迁移.md) | 大表搬迁三类做法、binlog + position 不停服切 GTID、迁移期双写与校验、切换与回滚命令 |
| [datastore/mysql/分表与路由.md](datastore/mysql/分表与路由.md) | 非分片键定位数据在哪张表的四类方案、基因法、表数扩容为什么「成倍扩」 |
| [datastore/mysql/数据类型选型.md](datastore/mysql/数据类型选型.md) | timestamp 与 datetime 的差异、时区与范围取舍、到底该怎么选 |
| [middleware/redis/命令与使用.md](middleware/redis/命令与使用.md) | 五大数据结构与底层实现、RDB/AOF、主从/哨兵/Cluster、过期与淘汰、Go/Java 客户端 |
| [middleware/redis/缓存问题与方案.md](middleware/redis/缓存问题与方案.md) | 缓存雪崩 / 击穿 / 穿透、热 key 与大 key、缓存污染，含 Go / Java 落地代码 |
| [middleware/redis/缓存选型与容量.md](middleware/redis/缓存选型与容量.md) | Redis 与 MySQL 的分工与量级差、本地缓存 vs 中心化缓存、内存放不下的扩容与分片 |
| [middleware/redis/淘汰策略.md](middleware/redis/淘汰策略.md) | maxmemory 触发时机、三大类淘汰策略的取舍 |
| [middleware/redis/分布式锁.md](middleware/redis/分布式锁.md) | SetNX + 过期 + Lua 释放、必须考虑的 6 个故障点、Go 手写与 Java Redisson |
| [middleware/redis/发布订阅.md](middleware/redis/发布订阅.md) | Pub/Sub 与 Stream 的实现差异、语义对比与选型 |
| [datastore/ElasticSearch.md](datastore/ElasticSearch.md) | 倒排索引与字段类型、text vs keyword 与 mapping 代价、写入全链路与段生命周期、BM25 打分、聚合误差与深翻页四方案、分片规划与 ILM、事故清单、Go/Java 客户端 |
| [datastore/MongoDB.md](datastore/MongoDB.md) | 文档建模模式与迁移、索引体系与 ESR 规则、explain 读法、聚合管道代价、分片与架构管理、副本集读写关注与因果一致性、事务边界、运维坑、Go/Java 客户端 |

### 中间件

| 文件 | 知识点 |
|---|---|
| [middleware/Nacos.md](middleware/Nacos.md) | 注册中心 + 配置中心双角色、命名空间/分组/实例、Distro 与 JRaft、部署与鉴权、Go/Java 示例 |
| [middleware/xxl-job.md](middleware/xxl-job.md) | 调度中心与执行器解耦、路由与阻塞策略、分片广播、部署、Go 客户端为主 + Java 官方实现对照 |
| [middleware/消息队列选型.md](middleware/消息队列选型.md) | MQ 的五大使用场景、四款 MQ 横向对比与选型决策、分册导航、事务消息三种方案、延迟队列四种实现 |
| [middleware/Kafka.md](middleware/Kafka.md) | 分区与 ISR、acks/幂等/事务、消费者组重平衡与两个超时参数、KRaft 去 ZK、Go 三客户端取舍 + Java 原生/Spring Kafka |
| [middleware/RabbitMQ.md](middleware/RabbitMQ.md) | 四种交换机路由模型、Publisher Confirm 与手动 ack、DLX 与两种延迟队列、Quorum 队列、Go/Java 客户端 |
| [middleware/RocketMQ.md](middleware/RocketMQ.md) | 四角色与 NameServer 路由、CommitLog/ConsumeQueue 存储、长轮询与 rebalance、顺序/延迟/事务消息、可靠性（刷盘×复制、重试与死信）、DLedger、堆积 SOP、Java/Go 客户端 |
| [middleware/Nats.md](middleware/Nats.md) | Core NATS 与 JetStream 语义对比、Queue Group、Request-Reply、集群与 Leaf Node、Go/Java 客户端 |

### 可观测性

| 文件 | 知识点 |
|---|---|
| [middleware/observability/Skywalking.md](middleware/observability/Skywalking.md) | 业务痛点与 UI 六大面板、Agent/OAP/Storage/UI 架构、javaAgent 与轻量级队列内核原理、多语言探针、Go/Java 接入两条路线对比 |
| [middleware/observability/Jaeger.md](middleware/observability/Jaeger.md) | Trace/Span 概念、与 OpenTelemetry 的协作、部署与采样策略、Go/Java 接入、与 SkyWalking 的分工（结合 photography-server 实际架构） |

### 网络

| 文件 | 知识点 |
|---|---|
| [network/TCP滑动窗口.md](network/TCP滑动窗口.md) | 窗口怎么滑、窗口的本质与吞吐上限、把窗口/保活落到 socket 选项上的 Go / Java 写法与对照实验 |
| [network/TCP的Nagle与延迟确认.md](network/TCP的Nagle与延迟确认.md) | Nagle 合并小包、延迟确认合并 ACK、两者同时开启的「互相伤害」与破解办法 |
| [network/TCP报文结构.md](network/TCP报文结构.md) | 连接的本质、首部字段、序列号与确认号演算、字节流没有边界的两种解法（Go 长度前缀 / Java Netty） |
| [network/TCP三次握手.md](network/TCP三次握手.md) | 三次握手逐步拆解、为什么必须三次、初始序号为什么必须随机 |
| [network/DNS解析.md](network/DNS解析.md) | 域名层级与四类服务器、递归/迭代查询全流程、TTL 与缓存故障、记录类型与 CNAME 三个坑、DNS 轮询与 GSLB、劫持/HTTPDNS/DoH、Go net.Resolver 与 Java 用法 |
| [network/HTTPS与TLS.md](network/HTTPS与TLS.md) | TLS 与 SSL 关系、TLS 1.2 两次往返握手、RSA vs ECDHE、证书验证与主密钥推导、1.3 的 1-RTT/0-RTT 与重放攻击、会话复用、SSLKEYLOGFILE 解密抓包 |
| [network/HTTP与gRPC.md](network/HTTP与gRPC.md) | HTTP/2 的五点改进、gRPC 与 HTTP 的本质差别、连接池与 `httptrace` 复用观测、多路复用实测 |
| [network/DHCP.md](network/DHCP.md) | DORA 四阶段、T1/T2 续租时间点、租约状态机在 Go / Java 里的复用 |
| [network/IO多路复用.md](network/IO多路复用.md) | 五种 I/O 模型、同步/异步与阻塞/非阻塞之辨、select/poll/epoll 演进、LT vs ET、惊群与 Reactor、Go netpoller 与 Java NIO、fd 上限 |
| [network/数据序列化.md](network/数据序列化.md) | JSON 与 Protobuf 对比与性能实测、Protobuf 使用注意事项；Go 逐字节拆解 varint/tag、Java protobuf-java 与 Jackson 对照组 |

### 系统与运维

| 文件 | 知识点 |
|---|---|
| [linux/进程与线程.md](linux/进程与线程.md) | 进程概念与状态、线程三种实现方式、进程与线程的区别；Go/Java 双册实测创建成本、超订与上下文切换、协程栈内存 |
| [linux/内存管理.md](linux/内存管理.md) | 虚拟内存与地址空间布局、分页/页表/TLB、缺页中断、伙伴系统与 Slab、Page Cache 与 free 的正确解读、kswapd 与 LRU 回收、容器内 OOM |
| [linux/文件系统与IO.md](linux/文件系统与IO.md) | ext4/XFS/Btrfs 差异、inode 与软硬链接、从 write 到落盘的完整 I/O 栈、inode 耗尽与空间未释放的排查 |
| [linux/性能排查.md](linux/性能排查.md) | USE/RED 方法论与分层排查顺序、负载高但 CPU 低的成因、vmstat 速读、CPU 飙高套路、free 解读与 OOM Killer、iostat 与磁盘/inode 满、连接状态分布、Go/Java 服务排查 |
| [linux/常用命令.md](linux/常用命令.md) | 查端口占用、看网络连接、递归建目录、日志关键词统计；Go/Java 双册：命令注入、超时杀进程树、退出码语义、纯 Go 复刻管道并与 shell 对拍 |

### 容器与 K8s

| 文件 | 知识点 |
|---|---|
| [docker/容器原理.md](docker/容器原理.md) | 容器 = Namespace + cgroup + rootfs + 一个进程、与 VM 的本质差异、rootfs 为什么用 `pivot_root`、PID 1 的信号语义 |
| [docker/进入运行中的容器.md](docker/进入运行中的容器.md) | docker exec / attach / nsenter 的差别与生产用法、进不去容器时的排查顺序 |
| [docker/镜像构建与缓存.md](docker/镜像构建与缓存.md) | 多阶段构建解决什么、构建缓存何时失效、层顺序、ENTRYPOINT vs CMD、HEALTHCHECK ≠ K8s 探针 |
| [docker/网络与存储.md](docker/网络与存储.md) | 五类网络驱动与 `-p` 的 DNAT 本质、overlay2 合并视图与 copy-up、volume/bind/tmpfs 选型、日志驱动不轮转的坑 |
| [docker/Docker网络与镜像源.md](docker/Docker网络与镜像源.md) | 容器怎么拿到 IPv6 地址、Dockerfile 里换国内镜像源/软件源的完整改法 |
| [docker/资源限制与运维.md](docker/资源限制与运维.md) | 内存/CPU 限额怎么落地、137 的两种含义、runtime 感知 cgroup 的版本要求、常见故障定位表 |
| [docker/镜像瘦身与构建缓存.md](docker/镜像瘦身与构建缓存.md) | 镜像瘦身的有效手段（Go / Java）、CI 上为什么每次从零构建、缓存复用策略 |
| [docker/K8s部署与生命周期.md](docker/K8s部署与生命周期.md) | Helm 与 values 分层、imagePullPolicy 与 QoS、三探针与优雅关闭、滚动升级与回滚、PDB 与有状态负载 |
| [docker/命令速查.md](docker/命令速查.md) | 镜像/容器/网络/清理/Compose 速查、inspect 取字段与退出码速判、prune 作用范围对照、exec vs run、镜像膨胀排查、context、buildx |
| [docker/CI-CD.md](docker/CI-CD.md) | GitLab Runner 与 executor 选型、DooD 风险、镜像 tag 与缓存策略、声明式部署与回滚、流水线常见事故 |

### 版本控制

| 文件 | 知识点 |
|---|---|
| [git/命令与场景.md](git/命令与场景.md) | 提交/拉取/合并/暂存命令、远程仓库操作、回滚三种 reset 模式对照、reset vs revert、reflog 救回、pull vs fetch、merge vs rebase |

### PHP

| 文件 | 知识点 |
|---|---|
| [php/PHP-FPM对接步骤.md](php/PHP-FPM对接步骤.md) | 数据上报链路原理、接入前检查清单、Dockerfile 与 php.ini 占位符、run.sh 前台启动、环境变量注入、业务日志两种上报方式、shm 容量计算、优雅停机、验证与常见问题速查 |

### 安全

| 文件 | 知识点 |
|---|---|
| [security/加密算法.md](security/加密算法.md) | 对称/非对称/散列三类算法清单与优缺点对比、密钥长度怎么选、DES/3DES 为什么弃用 |
| [security/摘要与数字签名.md](security/摘要与数字签名.md) | 数字摘要与数字签名的定义与实现步骤、与「数据加密」的区别、bcrypt 与盐 |
| [security/数字证书与PKI.md](security/数字证书与PKI.md) | 数字证书与信任链、SSL 与 HTTPS、现代工程实践、mTLS 与自签证书的坑 |

### 分布式与系统设计

| 文件 | 知识点 |
|---|---|
| [distributed/一致性与CAP.md](distributed/一致性与CAP.md) | 强/弱/最终一致的区别、CAP 取舍、etcd 把「一致性等级」做成一行代码 |
| [distributed/Raft协议.md](distributed/Raft协议.md) | Raft 角色与日志复制、选主流程拆解；etcd 客户端 Txn/Election/Watch 落地与三节点实验 |
| [distributed/分布式事务.md](distributed/分布式事务.md) | XA/DTP 与 BASE、七种方案对比与选型顺序、Seata AT 与 TCC 接入、Go dtm 四种模式 |
| [distributed/分布式ID.md](distributed/分布式ID.md) | 三条硬要求、方案全景对比、雪花位结构与 workerId 分配、时钟回拨、前端 JS 精度丢失、Go/Java 落地 |
| [distributed/限流降级熔断.md](distributed/限流降级熔断.md) | 四种限流算法与关键差异、Redis+Lua 分布式限流、熔断三态与 Half-Open、熔断与重试的相互作用、三类降级 |
| [distributed/服务发现与负载均衡.md](distributed/服务发现与负载均衡.md) | etcd 租约注册、健康检查、客户端/服务端负载均衡、为什么用注册中心、注册中心选型；Go 四段可运行代码 + Java Spring Cloud/Nacos |
| [distributed/直播弹幕系统.md](distributed/直播弹幕系统.md) | 场景题：推拉模型、削峰限流、广播扇出、海量弹幕存储（`barrage` 包 Go 骨架已编译实测 + Java 移植版） |
| [distributed/文件存储与上传架构.md](distributed/文件存储与上传架构.md) | 文件服务器选型（Ceph vs 对象存储）与视频直传 vs 中转，同一原则的两个侧面 |
| [distributed/朋友圈设计.md](distributed/朋友圈设计.md) | 场景题：要素分析、表设计与数据分层、feed 推拉方式（`moments` 包 Go 骨架 + Java 移植版） |
| [distributed/海量数据存储设计.md](distributed/海量数据存储设计.md) | 开放题：按数据类型选存储 + 分布式分层两条突破路线 |
| [distributed/统计页提速.md](distributed/统计页提速.md) | 场景题：少查询、把统计前移，预聚合结果表的最小实现 |

### 面试与索引

| 文件 | 知识点 |
|---|---|
| [interview/数据结构备考.md](interview/数据结构备考.md) | 常见数据结构有哪些、该重点掌握哪些（本质是备考策略题） |
| [interview/自我表达与项目介绍.md](interview/自我表达与项目介绍.md) | 「评价一下你相对其他同学的优势」与「如何介绍自己的项目」的回答结构与完整案例 |
| [interview/素材清单.md](interview/素材清单.md) | 视频与书籍素材来源与链接清单 |

---

## 说明

- 命名约定：**英文技术目录 + 中文文件名**，每个文件聚焦一个知识点；根目录只保留本索引。
- 内容来源分三类，各自的组织方式不同：
  1. **面试真题整理**（Go / 数据库 / 网络 / 系统 / 分布式方向）：**一个文件一道题**（或一组强关联的题），逐题包含**题目来源、考察意图、参考答案、面试官追问方向**；素材来源见 [interview/素材清单.md](interview/素材清单.md)。
  2. **个人学习笔记**（算法 / Java / GC / 安全 / Git / PHP / Linux 等）：以知识体系为主线组织，章节统一用 `Qn.` 疑问句编号，侧重对比表与选型结论，结尾都有「面试官会追问什么」。
  3. **中间件与组件手册**（数据存储 / 中间件 / 可观测性）：采用「介绍 + 使用方法」结构，章节按 `一、二、三` 体系组织，**使用示例同时给出 Go 与 Java 两个版本**。
- **按知识点重组过的文件结尾都有「关联」小节**，用于拆分后保持知识点之间的双向可达；相同考点只在一处讲透（单一来源），其余位置改为交叉引用。早期未拆分的文件其相关链接写在正文里，`关联` 小节按篇逐个补齐。
- 每题正文之后统一补「使用一：Go」「使用二：Java」，能本地跑的都已跑出**实测数据**并把偏差写进正文；纯设计题写「落地实现：最小可运行骨架」。
- 中间件实例一律**单例**（Go 用 `sync.OnceValue`，Java 用 Spring 单例 Bean），不在请求路径里新建连接池。
- 文中配图已**全部本地化**到各自目录的 `images/` 下（用相对路径引用，不依赖外部图床）。
- 代码块格式约定：**开栏围栏（```bash / ```go 等）后空一行**再写内容，否则渲染时语言标记的三角形会与首行文字重叠。
- Go 示例均经 `go build` / `go vet` / `gofmt` 校验并在文中标注依赖版本；Java 示例的校验口径见 [datastore/mysql/索引与优化.md](datastore/mysql/索引与优化.md)「使用二」；无对应运行环境时会显式标注「未做运行时验证」或「未编译校验」。
