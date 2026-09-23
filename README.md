# dev-notes

开发知识笔记。按技术方向分目录，每个文件聚焦**一个知识点**。

---

## 目录

### Go

| 文件 | 知识点 |
|---|---|
| [go/切片.md](go/切片.md) | 底层结构、SliceHeader 三要素、append 扩容规则、预分配优化 |
| [go/map.md](go/map.md) | 底层结构、哈希冲突、扩容两条件两方式、创建/访问/更新/疏散源码流程、key 类型限制 |
| [go/channel.md](go/channel.md) | 底层结构、收发流程、并发安全来源、缓冲区填补、通信六案例、4 个使用注意点、select 底层机制、项目 4 种用法 |
| [go/defer.md](go/defer.md) | 三个特性、三个应用场景、LIFO 执行顺序及源码原理 |
| [go/for-range.md](go/for-range.md) | 值变量是临时变量、哪些类型能通过指针改原始数据 |
| [go/内存逃逸.md](go/内存逃逸.md) | 定义、原因、分析工具、五类场景、三条判定规则、优点与代价 |
| [go/内存分配器.md](go/内存分配器.md) | mspan 字段、size class 67 等级、堆内存块元数据、mcache/mcentral/mheap 三级分配、两级索引映射、32 位地址优化 |
| [go/程序启动流程.md](go/程序启动流程.md) | _rt0 汇编入口、栈/分配器/调度器初始化、运行时检查、OS 信息初始化、启动全景图 |
| [go/函数调用与栈.md](go/函数调用与栈.md) | 函数调用过程、栈里包含哪些信息 |
| [go/线程安全.md](go/线程安全.md) | 并发读写 map 的后果、线程安全定义、三类线程安全类型 |
| [go/并发同步原语.md](go/并发同步原语.md) | Mutex 语义与饥饿模式、排队上限、手动加锁 vs sync.Map、协程间通信五法 |
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
| [go/code/](go/code/) | 并发控制示例代码（可直接 `go run`） |

### 数据库

| 文件 | 知识点 |
|---|---|
| [mysql/查询与架构.md](mysql/查询与架构.md) | 查询一条数据的完整链路、软件架构分层、JOIN 的用法与取舍、timestamp 与 datetime 选择 |
| [mysql/索引与优化.md](mysql/索引与优化.md) | 索引分类与回表、索引失效、慢查询优化与排查、分页优化 |
| [mysql/事务与日志.md](mysql/事务与日志.md) | ACID 与隔离级别、读未提交场景、落盘流程、redo/binlog、主从延迟 |
| [mysql/数据迁移与分表.md](mysql/数据迁移与分表.md) | 迁移三类手段、binlog+GTID 不停服切换、分表后非分片键的路由方案 |
| [redis/缓存与分布式锁.md](redis/缓存与分布式锁.md) | 缓存雪崩、淘汰策略、分布式锁、热 key、发布订阅、选型、本地缓存 vs Redis、内存不足、缓存污染 |

### 网络

| 文件 | 知识点 |
|---|---|
| [network/TCP.md](network/TCP.md) | 报文结构、三次握手、滑动窗口、Nagle 算法与延迟确认 |
| [network/应用层协议.md](network/应用层协议.md) | DHCP 流程与续租、HTTP 与 gRPC 的区别 |
| [network/数据序列化.md](network/数据序列化.md) | JSON 与 Protobuf 对比、Protobuf 使用注意事项 |

### 系统与运维

| 文件 | 知识点 |
|---|---|
| [linux/进程与线程.md](linux/进程与线程.md) | 进程概念与状态、线程三种实现方式、进程与线程的区别 |
| [linux/常用命令.md](linux/常用命令.md) | 查端口占用、看网络连接、递归建目录、日志关键词统计 |
| [docker/Docker.md](docker/Docker.md) | 进入运行中的容器、构建镜像、多阶段构建、容器生成原理与 namespace/cgroup、与 VM 对比 |
| [docker/CI-CD.md](docker/CI-CD.md) | GitLab Runner、Docker-outside-of-Docker、构建与部署两阶段 |
| [docker/K8s与镜像优化.md](docker/K8s与镜像优化.md) | Docker 开启 IPv6、镜像源加速、Helm 核心概念与常用命令 |

### 分布式与系统设计

| 文件 | 知识点 |
|---|---|
| [distributed/一致性与Raft.md](distributed/一致性与Raft.md) | 强/弱/最终一致性、CAP、Raft 角色转换与选举流程 |
| [distributed/服务发现与负载均衡.md](distributed/服务发现与负载均衡.md) | etcd 租约注册、健康检查、客户端/服务端负载均衡、为什么用注册中心、注册中心选型 |
| [distributed/系统设计.md](distributed/系统设计.md) | 直播弹幕、文件服务器选型、朋友圈设计、视频上传直传 vs 中转、微信海量存储、统计页加速 |

### 面试与索引

| 文件 | 知识点 |
|---|---|
| [interview/面试策略.md](interview/面试策略.md) | 数据结构备考、评价自身优势、如何介绍项目 |
| [interview/素材清单.md](interview/素材清单.md) | 全部素材来源与逐集清单、处理状态 |

### 其他

| 文件 | 知识点 |
|---|---|
| [java/java语法.md](java/java语法.md) | Java 语法 |
| [数据结构.md](数据结构.md) | 数据结构 |
| [数据结构和算法比较.md](数据结构和算法比较.md) | 数据结构与算法比较 |
| [常见加密算法及应用.md](常见加密算法及应用.md) | 常见加密算法及应用 |

---

## 说明

- 上述 Go / 数据库 / 网络 / 系统方向的内容，整理自大厂 Go 后端面试真题视频，
  逐题包含**题目来源、考察意图、参考答案、面试官追问方向**。
- 涉及代码的题目均已**实际编译运行验证**（见 `go/code/`）。
- 素材来源与逐集清单见 [interview/素材清单.md](interview/素材清单.md)。
