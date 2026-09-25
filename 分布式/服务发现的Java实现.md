# 服务发现的 Java 实现（Spring Cloud + Nacos）

> 面试问服务发现时，**Java 背景的面试官默认你说的是 Spring Cloud**，Go 背景的默认是 etcd / K8s。
> 这篇的重点是**把两套词汇对上**：Nacos 的 `lease` ≈ etcd 的租约，`@LoadBalanced` ≈ Go 侧的 `DiscoveryTransport`；
> 并说清 **K8s 那一层到底谁在做负载均衡**。
>
> 内容整理自大厂 Go 后端面试真题视频，并参考《大型网站技术架构：核心原理与案例分析》（李智慧）；参考资料与原始素材见 [素材清单](../素材清单.md)。
>
> ⚠️ **Java 代码未在本机编译校验**（依赖 Spring Cloud Alibaba，需要私服 / 联网拉包）；
> API 名称按 `spring-cloud-starter-alibaba-nacos-discovery` + `spring-cloud-starter-loadbalancer` 的公开接口书写，落地前请以你所用版本的源码为准。

> 面试问服务发现时，**Java 背景的面试官默认你说的是 Spring Cloud**，
> Go 背景的默认是 etcd/K8s。所以这节的重点是**把两套词汇对上**：
> Nacos 的 `lease` ≈ etcd 的租约，`@LoadBalanced` ≈ 使用一的 `DiscoveryTransport`。
>
> ⚠️ **Java 代码未在本机编译校验**（依赖 Spring Cloud Alibaba，需要私服/联网拉包）。
> API 名称按 `spring-cloud-starter-alibaba-nacos-discovery` + `spring-cloud-starter-loadbalancer`
> 的公开接口书写，落地前请以你所用版本的源码为准。

## 一、注册参数：`lease` 就是 etcd 的"租约 + KeepAlive"

```yaml
spring:
  application:
    name: order-service          # ← 正文说的"服务名"，客户端按它发现，永远不用改 IP
  cloud:
    nacos:
      discovery:
        server-addr: nacos-headless.nacos.svc:8848
        enabled: true
        # ↓↓ 这三行就是"租约 + 续约 + TTL"，语义与使用一完全一致
        lease-renewal-interval-in-seconds: 5    # 每 5s 发一次心跳（默认值就是 5）
        lease-expiration-duration-in-seconds: 15 # 15s 没收到心跳就摘掉（默认值就是 15）
        weight: 100                              # 权重 → 对应使用一的加权轮询
        metadata:
          zone: sh-a
          version: v2.3.1
        # 本地调试时不把自己注册上去，否则流量会打到你笔记本
        # register-enabled: false
```

**⚠️ `15 = 3 × 5` 不是巧合，这是正文那句"TTL 是故障发现延迟与续约开销的折中"的具体形态**：
容忍连续丢 2 次心跳，第 3 次还没来才摘——
把 `lease-expiration` 调到 5s 只是"心跳周期"，**一次网络抖动就会误摘实例**。

**和 etcd 的两点差异（面试可用来加分）**：

| | etcd | Nacos |
|---|---|---|
| 续约通道 | gRPC 流 `KeepAlive`（**一个租约一条流**） | v1/v2 都是客户端心跳；**v2 换成 gRPC 长连接**，变更走**推送**，秒级→毫秒级 |
| 一致性 | **CP**（Raft，全部 key 一致） | **临时实例走 AP（Distro 协议）**、持久实例走 CP（JRaft）——**注册中心挂时优先保证"还能读到旧列表"** |
| 健康检查 | **纯被动**（心跳停 → key 过期） | **双模**：临时实例被动心跳；持久实例服务端**主动探测** |

> 这也回答了正文第三节追问里"AP 还是 CP"的取舍：
> **注册中心通常选 AP**——列表旧一点只是有几个请求打到死地址（客户端能重试），
> 而选 CP 时**注册中心一旦不可写，整个服务的实例信息就冻结甚至不可用**。

## 二、客户端：`@LoadBalanced` 的模板必须**是单例**

```java
@Configuration
public class DiscoveryClientConfig {

    /**
     * ★ 一个进程一个。@Bean 默认 singleton，这正好符合"中间件客户端要单例"。
     *
     * 反面写法（生产事故级）：在业务方法里 new RestTemplate() ——
     * 它内部的 ClientHttpRequestFactory 有自己的连接池，
     * 每次 new 等于每次请求重建 TCP+TLS 连接，高并发下直接 TIME_WAIT 打满。
     * 同理 FeignClient / WebClient.Builder 也只该在 @Bean 里建一次。
     */
    @Bean
    @LoadBalanced                       // ← 加上它，URL 里就能写服务名而不是 IP
    public RestTemplate orderRestTemplate() {
        var factory = new SimpleClientHttpRequestFactory();
        factory.setConnectTimeout(Duration.ofMillis(200));   // 建连超时
        factory.setReadTimeout(Duration.ofSeconds(2));       // 等待响应超时
        // 注意：@LoadBalanced 会替换 factory，需要用
        //   LoadBalancerRestTemplateBuilder / 自定义 RequestFactory
        // 才能把超时真正落到"每次挑中的实例"上；这里给出意图，版本差异请以源码为准。
        return new RestTemplate(factory);
    }
}
```

调用方就写成**服务名**，这正是正文方案 B"地址完全不用考虑"的 Java 形态：

```java
// URL 里的 order-service 是 spring.application.name，不是主机名
OrderVO vo = restTemplate.getForObject(
        "http://order-service/api/order/{id}", OrderVO.class, orderId);
```

**`@LoadBalanced` 背后发生了什么**（这段说清楚，面试官就知道你不是只会用注解）：

```text
RestTemplate 拦截 URL 的 host = "order-service"
        ↓
LoadBalancerInterceptor → LoadBalancerClientFactory 拿到该服务的一个子容器
        ↓
ServiceInstanceListSupplier.get() → Nacos 的本地缓存实例列表（★ 本地缓存，和正文一样）
        ↓
LoadBalancer（默认 RoundRobin，可换 ZonePreference / 加权 / 最少连接）挑一个 ServiceInstance
        ↓
把 host 改写成真实 IP:端口，再发出去
```

> **所以 Java 侧同样是"客户端负载均衡"**——`@LoadBalanced` 只是把使用一那 200 行做成了框架能力。

## 三、换策略：自定义 `ServiceInstanceListSupplier`（预热摘除的例子）

Spring Cloud LoadBalancer 的**扩展点是"实例列表供给器"**，而不是"挑选算法"——
绝大多数需求（过滤不健康实例、按 zone 优先、**新实例预热期不打满流量**）都应该在这里做：

```java
/**
 * 新实例刚起来时 JVM 未预热、缓存未加载，直接按全权重接流量会超时。
 * 做法：实例第一次被看到时记时间戳，预热窗口内把它**排到列表最后**（而不是删掉，
 * 删掉会导致实例数骤减、压力转移到其他机器）。
 *
 * 这个 bean 会被 Spring Cloud 放在"每个服务名一个"的子容器里，
 * 所以内部的 ConcurrentHashMap 只保护该服务的实例，天然不需要跨服务共享。
 */
public class WarmupSupplier extends DelegatingServiceInstanceListSupplier {

    private static final Logger log = LoggerFactory.getLogger(WarmupSupplier.class);
    private final Duration warmup;
    private final ConcurrentHashMap<String, Instant> firstSeen = new ConcurrentHashMap<>();

    public WarmupSupplier(ServiceInstanceListSupplier delegate, Duration warmup) {
        super(delegate);
        this.warmup = warmup;
    }

    @Override
    public Flux<List<ServiceInstance>> get() {
        return delegate.get().map(this::orderByWarmup);
    }

    private List<ServiceInstance> orderByWarmup(List<ServiceInstance> instances) {
        Instant now = Instant.now();
        // ★ 排序前先复制：delegate 返回的 List 可能是不可变的，也可能被别的线程共享
        List<ServiceInstance> copy = new ArrayList<>(instances);
        copy.sort(Comparator.comparingLong(si -> {
            Instant seen = firstSeen.computeIfAbsent(si.getInstanceId(), k -> {
                log.info("discover: new instance {} at {}", si.getHost(), now);
                return now;
            });
            // 预热中的实例给一个大数，被排到末尾
            return Duration.between(seen, now).compareTo(warmup) < 0 ? 1L : 0L;
        }));
        return copy;
    }
}
```

注册到子容器（**`@LoadBalancerClient` 只作用于指定服务名，全局生效用 `@LoadBalancerClients`**）：

```java
@Configuration
@LoadBalancerClient(name = "order-service", configuration = OrderLbConfig.class)
public class LbRegistration { }

public class OrderLbConfig {
    @Bean
    public ServiceInstanceListSupplier serviceInstanceListSupplier(
            ConfigurableApplicationContext context) {
        return new WarmupSupplier(
                ServiceInstanceListSupplier.builder()
                        .withDiscoveryClient()
                        .withCaching()          // ← 本地缓存 + 定时刷新，对应正文"不必每请求拉全量"
                        .build(context),
                Duration.ofSeconds(30));
    }
}
```

> **`withCaching()` 就是正文那句关键设计的 Java 版**：
> **拉取 + 缓存 + 变更同步**，而不是每次请求查注册中心。
> 默认刷新间隔由 `spring.cloud.loadbalancer.cache.ttl` 控制（默认 35 秒）——
> **这个默认值对"秒级摘除"来说太慢了**，Nacos 推送场景应改小或直接用带 watch 的 supplier。

## 四、手写版（等价于使用一的 `Resolver` + `Balancer`）

如果面试官要求"不用框架，你会不会写"，Java 版长这样——

**结构和 Go 那节一一对应**：不可变快照 + `AtomicReference` 整体替换 + 无锁读路径。

```java
/**
 * 未编译校验。依赖：nacos-client（NamingService 是**进程级单例**，一个 serverAddr 一个，
 * 用完才 shutdown；把它当普通对象到处 new 会泄漏 gRPC 连接和后台线程）。
 */
public final class NacosResolver {

    private final NamingService naming;          // ← 单例注入，不要在这里 new
    private final String service;
    private final AtomicReference<List<String>> snapshot =
            new AtomicReference<>(List.of());     // ★ 不可变列表，读侧无锁

    public NacosResolver(NamingService naming, String service) {
        this.naming = naming;
        this.service = service;
    }

    /** 读路径：无锁、常数时间。 */
    public List<String> instances() { return snapshot.get(); }

    /** 全量替换。订阅 + 定时兜底各调一次，两者都能覆盖到。 */
    private void refresh() {
        try {
            List<String> list = naming.selectInstances(service, true).stream()
                    .map(i -> "http://" + i.getIp() + ":" + i.getPort())
                    .toList();
            if (list.isEmpty()) {
                // ★ 正文/使用一铁律 2：**空列表要告警，不能当正常状态换上去**
                log.warn("discover: {} resolved to EMPTY, keep old {}", service, snapshot.get());
                return;
            }
            snapshot.set(list);
        } catch (NacosException e) {
            // ★ 拉取失败：**保留旧列表**（注册中心抖动 ≠ 全部实例下线）
            log.warn("discover: refresh {} failed, keep stale list", service, e);
        }
    }

    /** 变更推送：Nacos v2 是 gRPC 推，v1 是 UDP；两者都只需实现这个回调。 */
    public void start() throws NacosException {
        refresh();
        naming.subscribe(service, clusters -> refresh());
        // 再挂一个 5s 的定时兜底：订阅回调可能丢事件（这是所有 watch 机制的通病）
        scheduler.scheduleWithFixedDelay(this::refresh, 5, 5, TimeUnit.SECONDS);
    }

    /** 挑一个：轮询。注意 AtomicInteger 的取模而不是 synchronized。 */
    private final AtomicInteger cursor = new AtomicInteger();
    public String pick() {
        List<String> list = snapshot.get();
        if (list.isEmpty()) throw new NoInstanceException(service);
        int i = Math.floorMod(cursor.getAndIncrement(), list.size());
        return list.get(i);
    }
}
```

> **`Math.floorMod` 而不是 `%`**：`AtomicInteger` 溢出成负数后，
> `-2147483648 % 3` 在 Java 里是 `-2` → `IndexOutOfBounds`。这个坑每年都有人踩。

## 五、K8s 里到底谁在做负载均衡（正文方案 B 的完整分层）

| 层 | 组件 | 层级 | 粒度 | 典型坑 |
|---|---|---|---|---|
| 服务名解析 | **CoreDNS** | — | 域名 → **ClusterIP** | `ndots:5` 导致每个域名多次查询；**DNS 结果有缓存，扩缩容后不是立刻生效** |
| 集群内转发 | **kube-proxy**（Service） | **L4** | **连接级**（iptables/IPVS） | ⚠️ **长连接（gRPC/HTTP2/连接池）会一直打到同一台 Pod** → 新 Pod 分不到流量；解法：客户端轮询重建连接、或用 **Headless Service + 客户端 LB** |
| 七层入口 | **Ingress**（Nginx/Traefik）/ Gateway API | **L7** | **请求级**，可按 path/host 分流 | 只对**入口流量**生效，**service→service 内部调用不经过它** |
| 无平台 LB | **Headless Service**（`clusterIP: None`） | — | DNS 直接返回**所有 Pod IP** | 把 LB 交回给客户端（gRPC 官方推荐这条，配合 `round_robin` LB policy） |
| 摘除时机 | **ReadinessProbe** | — | 控制 Pod 是否进 Endpoints | ⚠️ **Readiness 没配 = 容器一起来就接满流量**（JVM 预热期超时），这是 K8s 里最常见的"上线抖一下"原因 |

> **正文第一节那句"用 Swarm/K8s 就直接用它的传输层负载均衡"要补一句限制**：
> **K8s Service 是连接级 LB**，对短连接 HTTP 完全够用；
> **对 gRPC / 数据库长连接这类"一条连接跑到底"的协议，它等效于没做负载均衡**。
> 这句话说出来，基本就能把这一题从"背过"变成"做过"。

**用了 K8s 还要不要 Nacos？**（高频追问，给结论）

| 问题 | 谁来做 |
|---|---|
| 实例地址同步、扩缩容 | **K8s Service + Endpoints 足够**，不用再注册一遍 |
| 配置中心 | K8s 有 ConfigMap，但**热更新要 Reloader/重新挂载**，做灰度和监听推送还是 Nacos/Apollo 顺手 |
| 跨集群 / 混合云（服务在 K8s 外） | K8s Service **出不了集群**，这时需要 Nacos/Consul 做统一注册 |
| 权重灰度、按 zone 路由 | K8s 原生没有，需要 **Istio VirtualService** 或 Nacos 权重 |

> **一句话**：**同集群内的东西交给 K8s；跨环境、需要流量治理时才引入注册中心**——
> 两套并存时，**必须以一边为唯一事实来源**，否则两边的实例列表会互相打架。

## 六、四种注册中心的"健康检查语义"对照（正文第二节/第三节的收口）

| 注册中心 | 怎么发现实例 | 判定"不健康"的方式 | 摘除延迟 |
|---|---|---|---|
| **etcd** | 客户端写 KV + 租约 | **纯被动**：KeepAlive 停 → TTL 到期 → key 消失 | ≈ TTL（10s） |
| **ZooKeeper** | 客户端建**临时节点**（EPHEMERAL） | **session 超时**（会话断，节点自动删）——续约与摘除是**同一个机制** | ≈ session timeout（常配 10~30s） |
| **Consul** | Agent 注册 service | **主动探测**（HTTP/TCP/gRPC check 周期性访问）+ 可选 TTL；**失败会立刻摘出 DNS/接口** | check interval（秒级，可配） |
| **Nacos** | 客户端注册（临时实例） | **被动心跳**为主，持久实例**主动探测**；不健康实例**默认只停转发不删除** | 15s 标不健康 / 30s+ 摘除 |

> **面试模板**：**先说"谁主动"（服务端心跳 vs 注册中心探测），再说"摘除延迟"，最后说"摘除是否可逆"**。
> 比如 Consul/Nacos 会把不健康实例**标记**而不是删除，恢复后立刻回流；
> etcd/ZK 是**删除**，恢复要靠服务重新注册。

---

---

---

## 面试官会追问什么

### 一、Nacos 的 `lease` 和 etcd 的租约是同一回事吗？
**机制同源，细节不同**：两者都靠"客户端定期续期 + 服务端到期剔除"来实现故障剔除。
差别在**谁负责续期**：etcd 需要应用自己调 `KeepAlive`（原文 Go 侧就是这么写的），而 Nacos 的客户端 SDK 默认在后台**自动心跳**，
所以 Java 侧看到的配置项少一些、但"到底多久没心跳会被摘掉"这件事也更不透明。
⚠️ 更要紧的是**健康检查语义**：Nacos 有"临时实例（心跳）"与"持久化实例（主动探测）"两种，行为完全不同（见本篇第六节）。

### 二、`@LoadBalanced` 的模板为什么必须是单例？
因为负载均衡器的**状态就在这个对象里**：它持有实例列表的订阅、本地缓存、以及轮询/哈希的游标与权重状态。
每次请求 `new RestTemplate` 就等于**每次重置状态** —— 轮询永远从第一个实例开始、缓存永远为空、订阅被反复创建。
所以它必须是一个 Spring 单例 Bean，把"实例列表 + 选择策略 + 状态"在进程内共享。
（这和 Go 侧"etcd 客户端一个进程一个"是同一条原则。）

### 三、K8s 里 Service 和 Ingress 分别做了什么？
**Service 做 L4**：给一组 Pod 一个稳定虚拟 IP / DNS 名，并用 kube-proxy（iptables / IPVS）在**节点内核层**做转发与负载均衡，
配合 readiness 探针把没就绪的 Pod 从 Endpoints 里摘掉。

**Ingress 做 L7**：按域名 / 路径路由到不同的 Service，做 TLS 终止 —— 它**不是** Service 的替代品，而是它前面的一层。
所以"K8s 里谁在做 LB"要分两层答：**Service（L4，内核转发）** 与 **Ingress（L7，七层路由）**；
而**客户端负载均衡**（本篇与 Go 篇讲的那套）在 K8s 里通常被 Service 取代 —— 这是"两套词汇"最容易混的地方。

## 关联

- [服务发现与负载均衡.md](服务发现与负载均衡.md) — 机制与面试三道题
- [服务注册与发现的Go实现.md](服务注册与发现的Go实现.md) — etcd 侧的对应实现
- [客户端负载均衡的Go实现.md](客户端负载均衡的Go实现.md) — LB 策略在 Go 侧怎么写
- [../容器/k8s/K8s部署与生命周期.md](../容器/k8s/K8s部署与生命周期.md) — K8s 那一层的服务发现与 LB
- [../中间件/Nacos.md](../中间件/Nacos.md) — Nacos 作为注册中心的配置与实操
