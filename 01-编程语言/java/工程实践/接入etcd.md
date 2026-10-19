# 接入 etcd（Java）

> 覆盖：Java 侧用官方 jetcd 接 etcd 的完整手册——Spring 单例、初始化顺序、两种读法、Txn / CAS、Watch、选举、租约续约，以及与 ZooKeeper / Curator 的概念对照。
>
> 不覆盖：Raft 原理见 [Raft协议.md](../../../04-架构与系统/分布式/共识协议/Raft协议.md)；CAP 取舍见 [CAP与PACELC.md](../../../04-架构与系统/分布式/理论基础/CAP与PACELC.md)；服务发现机制见 [服务发现.md](../../../04-架构与系统/分布式/服务治理/服务发现.md)；Go 侧见 [接入etcd.md](../../go/工程实践/接入etcd.md)；Spring Cloud + Nacos 的注册发现见 [服务发现的Java实现.md](服务发现的Java实现.md)。
>
> 内容整理自个人学习笔记。本篇代码按 `jetcd 0.7.x / 0.8.x` 的公开 API 书写，**未在本机编译**（本机 Maven 仓库没有 jetcd 依赖，拉包需联网）；JDK 基线以 0.7.x（支持 JDK 8）为例，升到 0.8.x 前先核对 release notes。

---

## 一、客户端选型与依赖

**本节要点**：Java 侧优先官方 `jetcd-core`；不要拿 Curator 接 etcd（Curator 是 ZooKeeper 客户端），也不要在每个请求里新建 `Client`。

| 选型 | 说明 |
|---|---|
| **`io.etcd:jetcd-core`** | etcd 官方 Java 客户端，gRPC 实现，KV / Watch / Lease / Election 齐全 |
| `etcd-java`（第三方） | 社区封装，API 风格不同；新项目没有理由选它 |
| **Curator** | **ZooKeeper 专用**，不能用来连 etcd；只在 ZooKeeper 方案里出现 |

```xml
<dependency>
  <groupId>io.etcd</groupId>
  <artifactId>jetcd-core</artifactId>
  <version>${jetcd.version}</version>
</dependency>
```

**版本与 JDK**：

| jetcd | JDK 基线 | 备注 |
|---|---|---|
| 0.7.x | **JDK 8** | 存量 Spring Boot 2.x 项目常用 |
| 0.8.x | 以 release notes 为准 | 升级前确认 Spring Boot / Netty 版本兼容 |

> Spring Boot 没有官方 etcd starter；**自己定义 `@Bean` 就是标准做法**，不要为了「自动配置」引入来源不明的第三方 starter。

---

## 二、前提

**本节要点**：与 Go 侧完全一致——服务端先可用，认证与 TLS 先配好；客户端只负责连接。

| 要先准备 | 漏了的表现 |
|---|---|
| etcd 已启动并监听客户端端口 | `ConnectException` / `deadline exceeded` |
| `endpoints` 写全集群成员 | 单点故障后无处重连 |
| 开 RBAC 时先建用户并授权 | `etcdserver: permission denied` |
| 开 TLS 时配置 CA / 客户端证书 | `SSLHandshakeException` / `certificate_unknown` |
| JDK 与 jetcd 版本匹配 | `NoSuchMethodError` / `UnsupportedClassVersionError` |

```bash
export ETCDCTL_API=3
export ETCDCTL_ENDPOINTS=http://127.0.0.1:2379,http://127.0.0.1:2479,http://127.0.0.1:2579

etcdctl endpoint health -w table --cluster
etcdctl endpoint status -w table --cluster
```

---

## 三、初始化

**本节要点**：`Client` 是进程级单例，用 `@Bean(destroyMethod = "close")` 交给 Spring 管生命周期；`KV`、`Watch`、`Lease`、`Election` 都是它的共享视图，无需重复创建连接。

```java
package demo.etcd;

import io.etcd.jetcd.Client;
import io.etcd.jetcd.Election;
import io.etcd.jetcd.KV;
import io.etcd.jetcd.Lease;
import io.etcd.jetcd.Watch;
import org.springframework.context.annotation.Bean;
import org.springframework.context.annotation.Configuration;

@Configuration
public class EtcdConfig {

    // 单例：Client 内部持有 gRPC channel 与线程池。
    // 每请求新建会把建连/认证开销摊到每次调用上，还会耗尽 fd。
    @Bean(destroyMethod = "close")
    public Client etcdClient() {
        return Client.builder()
                .endpoints(
                        "http://127.0.0.1:2379",
                        "http://127.0.0.1:2479",
                        "http://127.0.0.1:2579")
                // 开了 RBAC 才需要：
                // .user(ByteSequence.from("root", UTF_8))
                // .password(ByteSequence.from("r00t", UTF_8))
                .build();
    }

    @Bean
    public KV etcdKV(Client client) {
        return client.getKVClient();       // 共享、线程安全
    }

    @Bean
    public Lease etcdLease(Client client) {
        return client.getLeaseClient();
    }

    @Bean
    public Watch etcdWatch(Client client) {
        return client.getWatchClient();
    }

    @Bean
    public Election etcdElection(Client client) {
        return client.getElectionClient();
    }
}
```

> `Client.builder().endpoints(...)` 接受多个地址；**只写一个就等于把单点写进配置**。
> 生产环境建议把 endpoints、认证、TLS 放到 `@ConfigurationProperties`，不要把地址硬编码在代码里。

---

## 四、单例边界

**本节要点**：判断标准同样是「内部有没有长活资源」；Spring 单例只应覆盖这些对象，业务对象仍然按请求 / 按用途创建。

| 对象 | 是否单例 | 原因 |
|---|---|---|
| `Client` | ✅ 进程级 | gRPC channel、线程池、重连 |
| `KV` / `Lease` / `Watch` / `Election` | ✅ 进程级 | 都是 Client 的共享视图 |
| `Watch.Watcher` | ✅ 每个前缀一个 | 底层是一条长活 watch 流 |
| `LeaseKeepAliveResponse` / 续约 observer | ✅ 每个租约一个 | 后台续约流 |
| `Txn` | ❌ 每次操作 | 一次事务，提交即结束 |
| `ByteSequence` | ❌ 值对象 | 轻量、不可变，可随用随建 |

> 常见错误：在 Controller 里 `Client.builder().build()`。压测时连接数与线程数一起涨，
> 最后报的往往是 `too many open files` 或线程池耗尽，而不是 etcd 报错。

---

## 五、初始化顺序

**本节要点**：Spring 的构造器注入能保证「先有 Client 再有 KV」，但**管不住 `@PostConstruct` 里提前调用**。

```text
读取 etcd 配置（endpoints / 认证 / TLS）
        ↓
创建 Client Bean
        ↓
创建 KV / Lease / Watch / Election Bean
        ↓
创建注册器、Resolver、业务 Store（构造器注入）
        ↓
Web 容器开始接流量
        ↓
关闭：先摘除注册 → 停流量 → 关闭 Watcher → 关闭 Client
```

**两个容易踩的时序坑**：

- 某个 Bean 在 `@PostConstruct` 里用 `KV` 做初始化，而它又依赖另一个尚未就绪的 Bean → 改成 `ApplicationRunner` 或 `SmartLifecycle`，在容器启动完成后再注册。
- `@PreDestroy` 里先 `client.close()`，再让注册器去 `revoke` 租约 → 客户端已关闭，租约只能等 TTL，滚动发布期间会残留死地址。**关闭顺序必须与初始化相反**。

---

## 六、单例与依赖注入的封装

**本节要点**：业务类只依赖 `KV` / `Lease` 接口，不自己创建 `Client`；这样单测可以注入 mock，也不会把连接生命周期散落到各处。

```java
package demo.etcd;

import io.etcd.jetcd.ByteSequence;
import io.etcd.jetcd.KV;
import io.etcd.jetcd.kv.GetResponse;
import java.nio.charset.StandardCharsets;
import java.util.Optional;
import java.util.concurrent.TimeUnit;
import org.springframework.stereotype.Component;

@Component
public class EtcdStore {

    private final KV kv;

    public EtcdStore(KV kv) {          // 构造器注入：由 Spring 保证单例
        this.kv = kv;
    }

    public Optional<String> get(String key) throws Exception {
        GetResponse resp = kv.get(bs(key)).get(3, TimeUnit.SECONDS);
        if (resp.getKvsCount() == 0) {
            return Optional.empty();
        }
        return Optional.of(resp.getKvs(0).getValue().toString(StandardCharsets.UTF_8));
    }

    private static ByteSequence bs(String s) {
        return ByteSequence.from(s, StandardCharsets.UTF_8);
    }
}
```

> `ByteSequence` 是 jetcd 的字节序列类型；**不要用 `String.getBytes()` 后直接当 key**，
> 不同默认字符集会让同一个中文 key 在客户端之间对不上。

---

## 七、读：强一致与弱一致

**本节要点**：jetcd 的默认 `get` 是 linearizable；弱一致读在各版本中的暴露方式不一致，**升级前必须看当前版本的 `GetOption` javadoc**。

```java
package demo.etcd;

import io.etcd.jetcd.ByteSequence;
import io.etcd.jetcd.KV;
import io.etcd.jetcd.kv.GetResponse;
import java.nio.charset.StandardCharsets;
import java.util.concurrent.TimeUnit;

public class EtcdReader {

    private final KV kv;

    public EtcdReader(KV kv) { this.kv = kv; }

    // 默认读 = linearizable = 强一致：要经过一轮多数派确认。
    public String getStrong(String key) throws Exception {
        GetResponse resp = kv.get(bs(key)).get(3, TimeUnit.SECONDS);
        return resp.getKvsCount() == 0 ? null
                : resp.getKvs(0).getValue().toString(StandardCharsets.UTF_8);
    }

    // 弱一致（serializable）读的写法随 jetcd 版本变化：
    // 有的版本是 GetOption.newBuilder().withSerializable().build()，
    // 有的版本没有暴露。用之前先查依赖的 javadoc，不要照抄博客。
    //
    // 很多团队的做法不是改读语义，而是「客户端本地缓存 + TTL」来换吞吐。
    private static ByteSequence bs(String s) {
        return ByteSequence.from(s, StandardCharsets.UTF_8);
    }
}
```

| 场景 | 读法 | 原因 |
|---|---|---|
| 抢锁、配置读取后立刻执行 | linearizable | 旧值会直接导致业务错误 |
| 列表 / 状态页、可容忍短暂陈旧 | serializable（若版本支持） | 省一轮 quorum，分摊 Follower 压力 |
| 缓存回填 | 默认读 + 本地 TTL | 语义简单，过期由 TTL 控制 |

> 如果当前 jetcd 版本没有暴露 serializable 选项，**不要为了「读写分离」去绕 REST 接口**；
> 优先用本地缓存降低读频率，或者接受强一致读的成本。

---

## 八、写与并发控制：Txn / CAS

**本节要点**：jetcd 的 Txn 与 Go 侧语义完全一致——`If` 的比较、`Then` / `Else` 的写入在服务端作为一条日志原子提交。

```java
package demo.etcd;

import io.etcd.jetcd.ByteSequence;
import io.etcd.jetcd.KV;
import io.etcd.jetcd.kv.TxnResponse;
import io.etcd.jetcd.op.Cmp;
import io.etcd.jetcd.op.CmpTarget;
import io.etcd.jetcd.op.Op;
import io.etcd.jetcd.options.PutOption;
import java.nio.charset.StandardCharsets;
import java.util.concurrent.TimeUnit;

public class EtcdTxn {

    private final KV kv;

    public EtcdTxn(KV kv) { this.kv = kv; }

    // 只创建一次：ModRevision == 0 表示 key 从未存在。
    // leaseId 传 0 表示不挂租约；服务实例注册必须挂租约。
    public boolean registerIfAbsent(String key, String value, long leaseId) throws Exception {
        PutOption put = leaseId == 0
                ? PutOption.DEFAULT
                : PutOption.builder().withLeaseId(leaseId).build();
        TxnResponse resp = kv.txn()
                .If(new Cmp(bs(key), Cmp.Op.EQUAL, CmpTarget.modRevision(0)))
                .Then(Op.put(bs(key), bs(value), put))
                .Else(Op.get(bs(key)))
                .commit()
                .get(3, TimeUnit.SECONDS);
        return resp.isSucceeded();
    }

    // CAS：值仍等于期望值时才更新。
    public boolean compareAndSwap(String key, String want, String next) throws Exception {
        TxnResponse resp = kv.txn()
                .If(new Cmp(bs(key), Cmp.Op.EQUAL, CmpTarget.value(bs(want))))
                .Then(Op.put(bs(key), bs(next), PutOption.DEFAULT))
                .commit()
                .get(3, TimeUnit.SECONDS);
        return resp.isSucceeded();
    }

    private static ByteSequence bs(String s) {
        return ByteSequence.from(s, StandardCharsets.UTF_8);
    }
}
```

| 模式 | Compare 目标 | 用途 |
|---|---|---|
| 只创建一次 | `CmpTarget.modRevision(0)` | 注册、初始化 |
| CAS 更新 | `CmpTarget.value(...)` | 状态机、配置发布 |
| 按版本更新 | `CmpTarget.modRevision(rev)` | 避免覆盖别人刚写的值 |
| 按租约校验 | `CmpTarget.lease(leaseId)` | 只让持锁者改 |

> ⚠️ `TxnResponse.isSucceeded()` 为 `false` **不是异常**，而是「条件不成立」；
> 业务必须显式处理这个分支，否则会把「没抢到」当成「写成功」。

---

## 九、Watch

**本节要点**：与 Go 侧相同的两条铁律——**先全量拿 revision，再从 `revision + 1` 订阅**；**删除事件必须读 `prevKV`**。

```java
package demo.etcd;

import io.etcd.jetcd.ByteSequence;
import io.etcd.jetcd.KV;
import io.etcd.jetcd.Watch;
import io.etcd.jetcd.kv.GetResponse;
import io.etcd.jetcd.options.WatchOption;
import io.etcd.jetcd.watch.WatchEvent;
import io.etcd.jetcd.watch.WatchResponse;
import java.nio.charset.StandardCharsets;

public class EtcdWatcher implements AutoCloseable {

    private final Watch watch;
    private final KV kv;
    private Watch.Watcher watcher;

    public EtcdWatcher(Watch watch, KV kv) {
        this.watch = watch;
        this.kv = kv;
    }

    public void start(String prefix, Runnable onCompacted) throws Exception {
        ByteSequence p = bs(prefix);

        // 1) 先全量拿基线，记住 revision
        GetResponse snap = kv.get(p,
                io.etcd.jetcd.options.GetOption.builder().withPrefix(p).build()).get();

        // 2) 从 revision + 1 起订，中间不留缝
        WatchOption opt = WatchOption.newBuilder()
                .withPrefix(p)
                .withPrevKV(true)
                .withProgressNotify(true)
                .withRevision(snap.getHeader().getRevision() + 1)
                .build();

        watcher = watch.watch(p, opt, new Watch.Listener() {
            @Override
            public void onNext(WatchResponse response) {
                // compact 后服务端会在响应里带 compactRevision
                if (response.getCompactRevision() > 0) {
                    onCompacted.run();   // 丢弃本地缓存，重新全量 + 重新订阅
                    return;
                }
                for (WatchEvent event : response.getEvents()) {
                    switch (event.getEventType()) {
                        case PUT:
                            handlePut(event);
                            break;
                        case DELETE:
                            // 删除时 getKeyValue() 的 value 为空，旧值在 getPrevKV()
                            handleDelete(event);
                            break;
                        default:
                            break;
                    }
                }
            }

            @Override
            public void onError(Throwable throwable) {
                // 必须重连；静默退出 = 本地视图永久不更新
            }

            @Override
            public void onCompleted() {
                // watcher 被关闭
            }
        });
    }

    private void handlePut(WatchEvent e) { /* 用 e.getKeyValue() */ }
    private void handleDelete(WatchEvent e) { /* 用 e.getPrevKV() 里的地址 */ }

    @Override
    public void close() {
        if (watcher != null) {
            watcher.close();
        }
    }

    private static ByteSequence bs(String s) {
        return ByteSequence.from(s, StandardCharsets.UTF_8);
    }
}
```

| 现象 | 根因 | 修法 |
|---|---|---|
| 实例下线了还在被调用 | DELETE 只读当前 KV | 读 `event.getPrevKV()` |
| 新实例上线没人知道 | 全量与订阅之间有缝 | `withRevision(snapRev + 1)` |
| 断网后永久失联 | 起点被 compact，未处理 | `getCompactRevision() > 0` → 全量重建 |
| 回调线程被阻塞 | 在 `onNext` 里做耗时业务 | 只更新内存快照，业务异步处理 |

> **一个前缀一个 Watcher**，不要每次请求都 `watch()`；`Watcher` 是长活流，忘记 `close()` 会泄漏。

---

## 十、选举

**本节要点**：etcd 选举 = 租约 + 顺序 key；jetcd 的 `campaign` 返回 `LeaderKey`，**释放时先 `resign` 再关租约**，否则下一个候选者要等 TTL。

```java
package demo.etcd;

import io.etcd.jetcd.ByteSequence;
import io.etcd.jetcd.Election;
import io.etcd.jetcd.Lease;
import io.etcd.jetcd.lease.LeaseGrantResponse;
import io.etcd.jetcd.lease.LeaseKeepAliveResponse;
import io.etcd.jetcd.election.CampaignResponse;
import io.etcd.jetcd.election.LeaderKey;
import io.etcd.jetcd.election.LeaderResponse;
import io.grpc.stub.StreamObserver;
import java.nio.charset.StandardCharsets;
import java.util.concurrent.TimeUnit;

public class EtcdLeader implements AutoCloseable {

    private final Election election;
    private final Lease lease;
    private long leaseId;
    private LeaderKey leaderKey;

    public EtcdLeader(Election election, Lease lease) {
        this.election = election;
        this.lease = lease;
    }

    public void campaign(String name, String nodeId) throws Exception {
        LeaseGrantResponse granted = lease.grant(15).get(3, TimeUnit.SECONDS);
        leaseId = granted.getID();

        // 后台续约：不消费这个流，租约到期后 leader key 会自动消失
        lease.keepAlive(leaseId, new StreamObserver<LeaseKeepAliveResponse>() {
            @Override public void onNext(LeaseKeepAliveResponse value) { }
            @Override public void onError(Throwable t) { /* 告警：续约已断 */ }
            @Override public void onCompleted() { }
        });

        CampaignResponse resp = election
                .campaign(bs(name), leaseId, bs(nodeId))
                .get(10, TimeUnit.SECONDS);   // 未当选会阻塞
        leaderKey = resp.getLeader();
    }

    public String currentLeader(String name) throws Exception {
        LeaderResponse resp = election.leader(bs(name)).get(3, TimeUnit.SECONDS);
        if (resp.getKvsCount() == 0) {
            return null;
        }
        return resp.getKvs(0).getValue().toString(StandardCharsets.UTF_8);
    }

    @Override
    public void close() throws Exception {
        if (leaderKey != null) {
            election.resign(leaderKey).get(3, TimeUnit.SECONDS); // 主动让位
            leaderKey = null;
        }
        if (leaseId != 0) {
            lease.revoke(leaseId).get(3, TimeUnit.SECONDS);      // 立刻删除挂载 key
            leaseId = 0;
        }
    }

    private static ByteSequence bs(String s) {
        return ByteSequence.from(s, StandardCharsets.UTF_8);
    }
}
```

| 动作 | 效果 | 切换耗时 |
|---|---|---|
| `resign` + `revoke` | leader key 立刻删除 | 亚秒级 |
| 只关闭 `Client` / 进程崩溃 | 租约到期后删除 | ≈ TTL（这里 15s） |
| 续约流断开但进程还在 | 租约到期，自动让位 | ≈ TTL |

> ⭐ **不要缓存「我是 Leader」这个布尔值**：拿到 `LeaderKey` 后，租约仍可能因网络分区而失效。
> 关键写操作前用 Txn 校验 leader key 的 `ModRevision` / lease，或者定期 `currentLeader` 对账——
> 这就是分布式锁里的 **fencing** 问题。

---

## 十一、与 ZooKeeper / Curator 的对照

**本节要点**：两套系统的概念一一对应；会 etcd 就能迁移到 ZK，差别主要在「谁提供 API」与「会话语义」。

| 概念 | etcd（jetcd） | ZooKeeper / Curator | 说明 |
|---|---|---|---|
| 会话与租约 | `Lease.grant` + `keepAlive` | `sessionTimeoutMs` | 都是「心跳断，临时数据消失」 |
| 临时节点 | 挂 lease 的 key | `CreateMode.EPHEMERAL` | 服务注册的地基 |
| 顺序 + 临时 = 可等待锁 | `/leader/<name>` + `campaign` | `EPHEMERAL_SEQUENTIAL` + `InterProcessMutex` | 公平性靠序号 |
| 版本号 / CAS | `ModRevision` + `Cmp` | `stat.version` + `setData(..., version)` | 语义一致 |
| 变更通知 | `WatchOption` + `Watch.Listener` | `CuratorCache` / `PathChildrenCache` | 都要处理丢事件 |
| 集群视图 | `endpoint status` | `zkServer.sh status` | etcd 的 term ≈ ZAB 的 epoch |
| 过半存活 | 3 容 1、5 容 2 | 完全相同 | 多数派是共识通性 |

```java
package demo.zk;

import org.apache.curator.framework.CuratorFramework;
import org.apache.curator.framework.CuratorFrameworkFactory;
import org.apache.curator.framework.recipes.leader.LeaderLatch;
import org.apache.curator.retry.RetryForever;
import org.springframework.context.annotation.Bean;
import org.springframework.context.annotation.Configuration;

@Configuration
public class ZkConfig {

    // 单例：内部是 ZK 连接 + 事件线程
    @Bean(destroyMethod = "close")
    public CuratorFramework zk() {
        return CuratorFrameworkFactory.builder()
                .connectString("127.0.0.1:2181,127.0.0.1:2182,127.0.0.1:2183")
                .sessionTimeoutMs(30_000)
                .connectionTimeoutMs(5_000)
                .retryPolicy(new RetryForever(1_000))
                .build();
    }

    @Bean(destroyMethod = "close")
    public LeaderLatch leaderLatch(CuratorFramework zk) throws Exception {
        LeaderLatch latch = new LeaderLatch(zk, "/leader/my-job", "127.0.0.1:8080");
        latch.start();
        return latch;
    }
}
```

> **选型一句话**：ZK 是「为协调而生的通用库」，etcd 是「用 Raft 重做了一遍、只给 KV + Watch 的极简库」。
> 新系统优先 etcd；存量 ZK 体系继续用 Curator，不要在同一个项目里混两套协调中心。

---

## 十二、验证与排查

**本节要点**：先确认「连接与租约真的生效」，再按症状查配置与版本。

| 症状 | 先查什么 |
|---|---|
| 启动就 `deadline exceeded` | endpoints 是否可达、端口是否映射、TLS 是否握手失败 |
| 注册后约 10~15 秒实例消失 | `keepAlive` 的 observer 是否被消费、线程池是否被占满 |
| `permission denied` | 是否先建用户再开 Auth、角色是否绑定到 key 前缀 |
| `NoSuchMethodError` / `UnsupportedClassVersionError` | jetcd 与 JDK / Netty 版本是否匹配 |
| Watch 不再收到事件 | 是否 compact、`onError` 是否被静默、Watcher 是否被关闭 |
| 滚动发布后仍有流量打到旧实例 | 是否先 `resign` / `revoke`，再停服务；关闭顺序是否反了 |

```bash
# 观察注册 key 与租约
etcdctl get /services/ --prefix -w table
etcdctl lease timetolive <leaseId> --keys

# 观察集群状态
etcdctl endpoint status -w table --cluster
```

> 以上排查命令是 etcd 通用命令；本篇的 Java 代码**未在本机运行**，
> 因此不提供客户端侧实测输出。可对照 Go 侧 [接入etcd.md](../../go/工程实践/接入etcd.md) 第十二节的真实输出理解同一套语义。

---

## 十三、延伸追问

- **为什么 `Client` 必须是 Spring 单例？** → 它内部持有 gRPC channel、线程池与重连逻辑；每请求新建会把建连/认证成本摊到每次调用，并耗尽 fd。见第三节。
- **`KV`、`Watch`、`Lease` 需要分别建吗？** → 不需要。它们都是 `Client` 的共享视图，注册成 Bean 只是为了注入方便，不产生新连接。
- **jetcd 的默认读是强一致吗？** → 是，默认 linearizable；弱一致读的 API 随版本变化，使用前查当前版本 javadoc，别照抄博客。见第七节。
- **为什么 Txn 返回 `false` 不抛异常？** → 条件不成立是正常业务分支；把它当异常会掩盖「没抢到锁 / 已被别人修改」的真实状态。见第八节。
- **Watch 为什么要 `prevKV`？** → DELETE 事件的当前 value 为空，只有 `prevKV` 里才有被删实例的地址；没有它就无法从本地列表里精确移除。见第九节。
- **`resign` 和关闭 Client 有什么区别？** → `resign` 立刻让位；关闭 Client 或崩溃只能等租约 TTL，切换会慢一个 TTL。见第十节。
- **为什么不能缓存「我是 Leader」？** → 网络分区或续约失败会让租约在后台失效，但进程内的布尔值不会自动变；关键写操作要用 Txn / lease 校验（fencing）。见第十节。
- **jetcd 和 Curator 能混用吗？** → 能连不同系统，但**不能拿 Curator 连 etcd**；同一个项目里也不建议同时维护两套协调中心。
- **Spring Boot 要引 starter 吗？** → 没有官方 starter；自己定义 `@Bean` 最可控，避免第三方 starter 的版本与生命周期黑盒。

## 十四、校验口径

> ✅ 本篇代码的校验方式与范围：

- **未编译**：第一至十二节的全部 Java 代码。原因：本机 Maven 仓库没有 `io.etcd:jetcd-core`，拉包需要联网；按 `jetcd 0.7.x / 0.8.x` 公开 API 书写，升级前请以实际依赖的 javadoc 为准。
- **未运行**：与 etcd 服务端的交互、Spring 生命周期、Watch 回调线程模型均未实测。
- **可直接复用的部分**：Maven 坐标、`@Bean` 单例形态、Txn 的 `Cmp` / `CmpTarget` 用法、`WatchOption` 的 `withPrefix / withPrevKV / withRevision`、选举的 `campaign / resign / revoke` 顺序。
- **版本敏感点**：弱一致读的 `GetOption`、`PutOption` 的租约构造方式、`WatchOption.withProgressNotify` 的签名，可能随版本变化。
- **对照实测**：同一套语义在 Go 侧已对 `etcd v3.5.21` 单节点容器跑通，见 [接入etcd.md](../../go/工程实践/接入etcd.md) 第十二节；Java 侧可据此对齐预期行为。

| 依赖 | 实际版本 |
| --- | --- |
| JDK | 未编译（按 0.7.x 的 JDK 8 基线书写） |
| `io.etcd:jetcd-core` | 未编译（`${jetcd.version}`，见第一节） |
| `org.apache.curator:curator-framework` / `curator-recipes` | 未编译（对照代码） |
| etcd 服务端 | 未运行（Go 侧实测为 v3.5.21） |

## 关联

- **上游/总纲**：[README.md](../../../04-架构与系统/分布式/服务治理/README.md) — 服务治理四件事与阅读顺序
- **原理**：[Raft协议.md](../../../04-架构与系统/分布式/共识协议/Raft协议.md) — 角色、日志复制与选主
- **原理**：[一致性模型.md](../../../04-架构与系统/分布式/理论基础/一致性模型.md)、[CAP与PACELC.md](../../../04-架构与系统/分布式/理论基础/CAP与PACELC.md) — 强 / 弱一致与 CAP 取舍
- **机制**：[服务发现.md](../../../04-架构与系统/分布式/服务治理/服务发现.md) — 租约、Get + Watch 与注册中心选型
- **对照/互补**：[接入etcd.md](../../go/工程实践/接入etcd.md) — Go 侧同一套语义与实测输出
- **下游**：[服务发现的Java实现.md](服务发现的Java实现.md) — Spring Cloud + Nacos 与 K8s 分层
- **产品选型**：[Nacos.md](../../../03-数据与中间件/中间件/注册与配置中心/Nacos.md) — 与 etcd 的定位差异
