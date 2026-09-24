# dev-notes

开发知识笔记。按技术方向分目录，每个文件聚焦**一个知识点**。

---

## 目录

### Go

| 文件 | 知识点 |
|---|---|
| [go/切片.md](go/切片.md) | 底层结构、SliceHeader 三要素、append 扩容规则、预分配优化、母子切片共享与断链、子切片内存泄漏与 copy、切片传参陷阱 |
| [go/map.md](go/map.md) | 底层结构、哈希冲突、扩容两条件两方式、创建/访问/更新/疏散源码流程、key 类型限制 |
| [go/channel.md](go/channel.md) | 底层结构、收发流程、并发安全来源、缓冲区填补、通信六案例、4 个使用注意点、select 底层机制、项目 4 种用法、死锁四条判据、多生产者单消费者同步点、共享内存 vs CSP |
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
| [go/编译与调试.md](go/编译与调试.md) | gcflags 速查、如何查更多 flags、VSCode 断点调试配置 |
| [go/国际化.md](go/国际化.md) | gotext 的 extract→翻译→generate 工作流与常见坑 |
| [go/依赖注入.md](go/依赖注入.md) | 手动构造注入、wire 代码生成、dig/fx 反射方案对比、Kratos 实战 |
| [go/Kratos框架.md](go/Kratos框架.md) | 集成 ent/validate、注册发现与容器化、服务间鉴权与元数据传递、json→protobuf |
| [go/Eino框架.md](go/Eino框架.md) | 字节 Eino 大模型应用框架 |
| [go/语言优势与生态.md](go/语言优势与生态.md) | 设计哲学、与 Java/C++/Python 对比、云原生生态、就业前景客观判断 |

### 数据结构与算法

| 文件 | 知识点 |
|---|---|
| [algorithms/数据结构.md](algorithms/数据结构.md) | 逻辑结构 vs 物理结构、八大基础结构的特性/优缺点/应用场景、选型对照表 |
| [algorithms/复杂度与算法对比.md](algorithms/复杂度与算法对比.md) | 时间复杂度与大 O 表示法、查找算法与结构对照表、10 种排序算法对比与选型 |
| [algorithms/压缩与淘汰算法.md](algorithms/压缩与淘汰算法.md) | Snappy / LZW 原理与选型、LRU 实现与缓存污染、LRU-K/2Q/LFU 变体、Redis 近似 LRU |

### Java

| 文件 | 知识点 |
|---|---|
| [java/类加载机制.md](java/类加载机制.md) | 四层类加载器、双亲委派「先委托后自己加载」流程与优点、打破双亲委派的场景 |
| [java/JVM与垃圾回收.md](java/JVM与垃圾回收.md) | 对象生命周期、可达性分析与 GC Roots、四种 GC 算法、三色标记与漏标修复、GC 分类与调优命令、栈帧结构与动态链接 |
| [java/Stream流实战.md](java/Stream流实战.md) | 取列/flatMap、List 转 Map、groupingBy 分组、BigDecimal 累加、多字段排序、按字段去重、7 个常见坑 |

### GC

| 文件 | 知识点 |
|---|---|
| [gc/GC基础算法.md](gc/GC基础算法.md) | 性能评价四标准、标记-清除/引用计数/标记-压缩/复制/保守式/分代/增量式/RC Immix 的过程与优缺点、算法选型总表、与 Java GC 的映射（含 46 张本地化配图，见 `gc/images/`） |

### 数据库

| 文件 | 知识点 |
|---|---|
| [mysql/查询与架构.md](datastore/mysql/查询与架构.md) | 查询一条数据的完整链路、软件架构分层、JOIN 的用法与取舍、timestamp 与 datetime 选择；Go 与 Java 两套落地（预编译、拆 JOIN、读写分离） |
| [mysql/索引与优化.md](datastore/mysql/索引与优化.md) | 索引分类与回表、索引失效、慢查询优化与排查、分页优化 |
| [mysql/事务与日志.md](datastore/mysql/事务与日志.md) | ACID 与隔离级别、读未提交场景、落盘流程、redo/binlog、主从延迟；Go 事务（小事务/保存点/重试/读己之写）与 Spring 事务落地 |
| [mysql/并发控制与MVCC.md](datastore/mysql/并发控制与MVCC.md) | 乐观锁 vs 悲观锁的原理/实现/选型、MVCC 特点、InnoDB 的隐藏列+undo 版本链+Read View、RC 与 RR 差异、主节点选举归属；Go 三种写冲突控制写法 + JPA 乐观/悲观锁与幂等 |
| [mysql/数据迁移与分表.md](datastore/mysql/数据迁移与分表.md) | 迁移三类手段、binlog+GTID 不停服切换、分表后非分片键的路由方案；Go 路由/基因法/双写校验 + Java ShardingSphere-JDBC |
| [redis/命令与使用.md](middleware/redis/命令与使用.md) | 五大数据结构与底层实现、RDB/AOF、主从/哨兵/Cluster、过期与淘汰、Go/Java 客户端与分布式锁实现 |
| [redis/缓存与分布式锁.md](middleware/redis/缓存与分布式锁.md) | 缓存雪崩、淘汰策略、分布式锁、热 key、发布订阅、选型、本地缓存 vs Redis、内存不足、缓存污染 |

### 数据存储

| 文件 | 知识点 |
|---|---|
| [datastore/ElasticSearch.md](datastore/ElasticSearch.md) | 倒排索引与字段类型全表、分片分配与恢复、写入 4 步与搜索流程、文本分析三件套、DSL 查询与深分页、Go/Java 客户端 |
| [datastore/MongoDB.md](datastore/MongoDB.md) | 文档模型与 MySQL 对照、内嵌 vs 引用建模、索引体系（复合/数组/TTL/地理）、explain、副本集与类 Raft 选举、Go/Java 客户端 |

### 中间件

| 文件 | 知识点 |
|---|---|
| [middleware/Nacos.md](middleware/Nacos.md) | 注册中心 + 配置中心双角色、命名空间/分组/实例、Distro 与 JRaft、部署与鉴权、Go/Java 配置与注册发现示例 |
| [middleware/xxl-job.md](middleware/xxl-job.md) | 调度中心与执行器解耦、路由与阻塞策略、分片广播、部署、Go 客户端为主 + Java 官方实现对照 |
| [middleware/消息队列选型.md](middleware/消息队列选型.md) | MQ 的五大使用场景、四款 MQ 横向对比与选型决策、分册导航、事务消息三种方案、延迟队列四种实现 |
| [middleware/Kafka.md](middleware/Kafka.md) | 分区与 ISR、acks/幂等/事务、消费者组重平衡与两个超时参数、KRaft 去 ZK、Go 三客户端取舍 + Java 原生/Spring Kafka |
| [middleware/RabbitMQ.md](middleware/RabbitMQ.md) | 四种交换机路由模型、Publisher Confirm 与手动 ack、DLX 与两种延迟队列、Quorum 队列、Go/Java 客户端 |
| [middleware/RocketMQ.md](middleware/RocketMQ.md) | 四角色架构、顺序/延迟/事务消息原理、可靠性（刷盘×复制四组合、重试与死信）、Java 客户端为主 + Go 客户端 |
| [middleware/Nats.md](middleware/Nats.md) | Core NATS 与 JetStream 语义对比、Queue Group、Request-Reply、集群与 Leaf Node、Go/Java 客户端（结合项目实际用法） |

### 可观测性

| 文件 | 知识点 |
|---|---|
| [observability/Skywalking.md](middleware/observability/Skywalking.md) | 业务痛点与 UI 六大面板、Agent/OAP/Storage/UI 架构、javaAgent 与轻量级队列内核原理、多语言探针、Go/Java 接入两条路线对比 |
| [observability/Jaeger.md](middleware/observability/Jaeger.md) | Trace/Span 概念、与 OpenTelemetry 的协作、部署与采样策略、Go/Java 接入、与 SkyWalking 的分工（结合 photography-server 实际架构） |

### 网络

| 文件 | 知识点 |
|---|---|
| [network/TCP.md](network/TCP.md) | 报文结构、三次握手、滑动窗口、Nagle 算法与延迟确认；Go/Java 把窗口、Nagle、保活落到 socket 选项上（Go 编译校验，Java 侧未编译校验） |
| [network/DNS解析.md](network/DNS解析.md) | 域名层级与四类服务器、递归/迭代查询全流程、TTL 与缓存故障、记录类型与 CNAME 三个坑、DNS 轮询与 GSLB、劫持/HTTPDNS/DoH、Go net.Resolver 与 Java 用法 |
| [network/HTTPS与TLS.md](network/HTTPS与TLS.md) | TLS 与 SSL 关系、TLS 1.2 两次往返握手、RSA vs ECDHE、证书验证与主密钥推导、1.3 的 1-RTT/0-RTT 与重放攻击、会话复用、SSLKEYLOGFILE 解密抓包 |
| [network/IO多路复用.md](network/IO多路复用.md) | 五种 I/O 模型、同步/异步与阻塞/非阻塞之辨、select/poll/epoll 演进、LT vs ET、惊群与 Reactor、Go netpoller 与 Java NIO、fd 上限 |
| [network/应用层协议.md](network/应用层协议.md) | DHCP 流程与续租、HTTP 与 gRPC 的区别；Go HTTP 客户端与租约、Java HttpClient/OkHttp 连接池，含一条/多条连接实测 |
| [network/数据序列化.md](network/数据序列化.md) | JSON 与 Protobuf 对比与性能实测、Protobuf 使用注意事项；Go 逐字节拆解 varint/tag、Java protobuf-java 与 Jackson 对照组 |

### 系统与运维

| 文件 | 知识点 |
|---|---|
| [linux/进程与线程.md](linux/进程与线程.md) | 进程概念与状态、线程三种实现方式、进程与线程的区别；Go/Java 双册实测：进程/线程/协程创建成本、超订与上下文切换、协程栈内存（含与常见说法不符的量化结论） |
| [linux/内存与文件系统.md](linux/内存与文件系统.md) | 虚拟内存与地址空间布局、分页/页表/TLB、缺页中断、伙伴系统与 Slab、Page Cache 与 free 的正确解读、kswapd 与 LRU 回收、ext4/XFS/Btrfs、inode 与软硬链接、write ≠ 落盘、Go MemStats vs RSS |
| [linux/性能排查.md](linux/性能排查.md) | USE/RED 方法论与分层排查顺序、负载高但 CPU 低的成因、vmstat 速读、CPU 飙高套路与上下文切换、free 解读与 OOM Killer、内存泄漏判断、iostat 与磁盘/inode 满、连接状态分布、Go 服务排查 |
| [linux/常用命令.md](linux/常用命令.md) | 查端口占用、看网络连接、递归建目录、日志关键词统计；Go/Java 双册：命令注入、超时杀进程树、退出码语义、纯 Go 复刻管道并与 shell 对拍（均本机实测） |
| [docker/Docker.md](docker/Docker.md) | 进入运行中的容器、构建镜像、多阶段构建、容器生成原理与 namespace/cgroup、与 VM 对比 |
| [docker/命令速查.md](docker/命令速查.md) | 镜像/容器/网络/清理/Compose 命令与 `docker run` 参数速查、exec vs attach、高频组合场景 |
| [docker/CI-CD.md](docker/CI-CD.md) | GitLab Runner、Docker-outside-of-Docker、构建与部署两阶段 |
| [docker/K8s与镜像优化.md](docker/K8s与镜像优化.md) | Docker 开启 IPv6、镜像源加速、Helm 核心概念与常用命令 |

### 版本控制

| 文件 | 知识点 |
|---|---|
| [git/命令与场景.md](git/命令与场景.md) | 提交/拉取/合并/暂存命令、远程仓库操作、回滚三种 reset 模式对照、reset vs revert、reflog 救回、pull vs fetch、merge vs rebase |

### PHP

| 文件 | 知识点 |
|---|---|
| [php/PHP-FPM对接步骤.md](php/PHP-FPM对接步骤.md) | 数据上报链路原理、接入前检查清单、Dockerfile 与 php.ini 占位符、run.sh 前台启动、星洲环境变量、业务日志两种上报方式、shm 容量计算、优雅停机、验证与常见问题速查 |

### 安全

| 文件 | 知识点 |
|---|---|
| [security/加密算法与应用.md](security/加密算法与应用.md) | 对称/非对称/散列算法、摘要与签名、数字证书与 PKI、SSL/HTTPS、现代工程实践 |

### 分布式与系统设计

| 文件 | 知识点 |
|---|---|
| [distributed/一致性与Raft.md](distributed/一致性与Raft.md) | 强/弱/最终一致性、CAP、Raft 角色转换与选举流程；Go（etcd client v3 的两种读/Txn/Election/Watch）与 Java（jetcd + Curator 对照）落地，另有三节点实验（命令未在本机实测） |
| [distributed/分布式事务.md](distributed/分布式事务.md) | XA/DTP 与 BASE、七种方案（2PC/3PC/TCC/Saga/本地消息表/事务消息/最大努力通知）对比与选型顺序、Seata AT 与 TCC 接入、Go dtm 四种模式 |
| [distributed/分布式ID.md](distributed/分布式ID.md) | 三条硬要求、方案全景对比、雪花位结构与 workerId 分配、时钟回拨、前端 JS 精度丢失、Go(bwmarrin/sonyflake) 与 Java(Hutool/MyBatis-Plus) 落地 |
| [distributed/限流降级熔断.md](distributed/限流降级熔断.md) | 四种限流算法与关键差异、Redis+Lua 分布式限流、熔断三态与 Half-Open、熔断与重试的相互作用、三类降级、sentinel-golang/gobreaker 与 Resilience4j |
| [distributed/服务发现与负载均衡.md](distributed/服务发现与负载均衡.md) | etcd 租约注册、健康检查、客户端/服务端负载均衡、为什么用注册中心、注册中心选型；Go 注册/发现/LB 四段可运行代码（哈希环比环平滑度为本机实测）+ Java（Spring Cloud/Nacos，未编译校验） |
| [distributed/系统设计.md](distributed/系统设计.md) | 直播弹幕、文件服务器选型、朋友圈设计、视频上传直传 vs 中转、微信海量存储、统计页加速；四道场景题各配「落地实现：最小可运行骨架」（Go 已编译校验并跑通），Q1/Q3 另附逐类型移植的 Java 版骨架（JBR 编译并实测，含 Go↔Java 数字对照） |

### 面试与索引

| 文件 | 知识点 |
|---|---|
| [interview/面试策略.md](interview/面试策略.md) | 数据结构备考、评价自身优势、如何介绍项目 |
| [interview/素材清单.md](interview/素材清单.md) | 视频素材来源与链接清单 |

---

## 说明

- 命名约定：**英文技术目录 + 中文文件名**，每个文件聚焦一个知识点；根目录只保留本索引。
- 内容来源分三类：
  1. **面试真题整理**（Go / 数据库 / 网络 / 系统 / 分布式方向）：逐题包含**题目来源、考察意图、参考答案、面试官追问方向**；素材来源见 [interview/素材清单.md](interview/素材清单.md)。
  2. **个人学习笔记**（算法 / Java / GC / 安全 / Git / PHP 等）：以知识体系为主线组织，侧重对比表与选型结论。
  3. **中间件与组件手册**（数据存储 / 中间件 / 可观测性）：采用「介绍 + 使用方法」结构，**使用示例同时给出 Go 与 Java 两个版本**。
- 上述「介绍 + 使用方法」结构现已覆盖**面试真题类**文档（mysql / network / distributed / linux）：
  每题正文之后统一补「使用一：Go」「使用二：Java」，能本地跑的都已跑出**实测数据**并把偏差写进正文。
- 文中配图已**全部本地化**到各自目录的 `images/` 下（用相对路径引用，不依赖外部图床）。
- 代码块格式约定：**开栏围栏（```bash / ```go 等）后空一行**再写内容，否则渲染时语言标记的三角形会与首行文字重叠。
- 「使用方法」章节标准：每篇都要给 **Go + Java 双版本**的客户端/实操示例（纯设计题写「落地实现：最小可运行骨架」）；**中间件实例一律单例**（Go 用 `sync.OnceValue`，Java 用 Spring 单例 Bean），不在请求路径里新建连接池。
- Go 示例均经 `go build` / `go vet` / `gofmt` 校验并在文中标注依赖版本；无对应运行环境时会显式标注「未做运行时验证」或「未编译校验」。
- Java 示例的校验口径：本机有 Maven 3.8.1 + IDEA/GoLand 自带 JBR（`java` 不在 PATH，需 `JAVA_HOME` 指过去），**Spring 系代码块也能真编译**（`mvn -B clean compile`）；依赖已在 `~/.m2`，抽块脚本与各篇口径见 [索引与优化.md](datastore/mysql/索引与优化.md)「使用二」。仍有一批文档的 Java 段标着「未编译校验」，属历史遗留，按篇逐个补编译。
- 涉及代码的题目均在本地**实际编译运行验证**过。
