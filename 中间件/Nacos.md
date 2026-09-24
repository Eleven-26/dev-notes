# Nacos 注册中心与配置中心

> 一句话说明本文件覆盖什么：Nacos 的定位、核心概念与架构、部署，以及 Java / Go 两套「配置管理 + 服务注册发现」的可落地用法与生产避坑。
>
> 内容整理自个人学习笔记。服务发现与负载均衡的通用原理见 [服务发现与负载均衡.md](../分布式/服务发现与负载均衡.md)。

---

## 1. 一句话定位：它解决什么问题

**Nacos = 动态服务发现（Naming）+ 动态配置管理（Config）+ 服务管理（健康检查 / 权重 / 元数据）**，即「注册中心 + 配置中心」双角色，一个组件顶掉 Eureka + Spring Cloud Config 的组合。

| 端口 | 用途 |
| --- | --- |
| `8848` | HTTP 端口，控制台 `http://ip:8848/nacos` 与 OpenAPI |
| `9848` | 客户端 gRPC 端口（= 8848 + 1000），2.x SDK 长连接、配置监听全走这里 |
| `9849` | 服务端之间 gRPC 端口（= 8848 + 1001），集群数据同步；另有 `7848` 为 JRaft 集群通信端口（1.x 遗留） |

| 没有它的问题 | 典型表现 | Nacos 的解法 |
| --- | --- | --- |
| 配置写死、散落各处 | 改一个超时时间要改代码、打包、发版；配置跟代码进 Git，多环境冲突 | 配置外置，运行时推送，改完秒级生效 |
| 扩容 / 缩容要重启 | 上游把下游 IP 写进配置文件，下游加机器上游不知道 | 服务启动自动注册，消费方订阅列表变更 |
| 健康检查缺失 | 机器进程假死，流量还在往上打，报错率飙升 | 心跳 + 服务端探测，不健康实例自动摘除 |
| 多环境难维护 | dev / test / prod 三套配置互相污染 | Namespace + Group 逻辑隔离 |
| 配置无迹可循、缺动态治理 | 谁在什么时候改的配置无从查起；想临时给某台机器降权、灰度只能重启 | 内置历史版本 + 一键回滚；权重、元数据、上下线运行时调整 |

---

## 2. 核心概念

| 概念 | 说明 | 注意点 |
| --- | --- | --- |
| **Namespace（命名空间）** | 最外层隔离，用于**租户 / 环境**隔离；不同 Namespace 之间服务与配置完全不可见 | 建议**一环境一 Namespace**；默认 `public`，生产不要所有环境挤在 `public` |
| **Group（分组）** | Namespace 内的二级隔离，用于**业务 / 项目**隔离 | 默认 `DEFAULT_GROUP`；不同 Group 互不可见 |
| **Service（服务）** | 一个逻辑服务名，是注册与发现的单位 | 建议与 `spring.application.name` 一致，小写中划线 |
| **Instance（实例）** | 具体节点：`ip:port` + 权重 + 元数据 + healthy + ephemeral | 机房维度由 Cluster 字段表达，用于同机房优先路由 |
| **配置（Data ID）** | 一个配置文件，由 **Namespace + Group + Data ID** 三元组唯一定位 | 三者任一不同即为不同配置 |

Spring Cloud Alibaba 默认拼接 `DataId = ${spring.application.name}-${spring.profiles.active}.${file-extension}`，例如 `dev` + `DEFAULT_GROUP` + `order-service-dev.yaml`，**必须严格一致**，否则拉不到配置。

| 维度 | 临时实例 Ephemeral | 持久化实例 Persistent |
| --- | --- | --- |
| `ephemeral` | `true`（默认） | `false` |
| 健康检查 | **客户端主动上报心跳**（默认 5s 一次） | **服务端主动探测**（TCP / HTTP / MySQL） |
| 一致性协议 | Distro，**AP** | JRaft，**CP** |
| 宕机处理 | 超时（15s 不健康、30s 摘除）后**自动删除** | 数据保留，只标记不健康 |
| 适用场景 | 绝大多数微服务（可弹性伸缩） | 数据库、缓存、第三方不可控节点 |

> ⭐ Nacos 的「CP / AP 可切换」= 由 `ephemeral` 决定：要 AP 高可用用临时实例，要 CP 强一致用持久化实例。**同一服务下的实例不能混用两种模式**。

---

## 3. 整体架构

```
┌──────────────── Nacos Server 集群（节点对等，无主从） ─────────────────┐
│  Nacos A  ◀────────── JRaft / Distro 数据同步 ──────────▶ Nacos B / C │
│  :8848 HTTP+控制台   :9848 客户端 gRPC   :9849 服务端 gRPC   :7848 集群 │
└───────────────────────────────────┬───────────────────────────────────┘
    ▼ 存储：MySQL / 内嵌 Derby（单机演示）    ▲ 客户端 SDK：心跳 + 配置长轮询 + 本地快照
```

| 组件 | 职责 |
| --- | --- |
| **Nacos Server** | OpenAPI、控制台、配置存储、服务注册表、健康检查；集群节点对等，无主从之分 |
| **Nacos Client（SDK）** | 内嵌业务进程：注册心跳、配置长轮询、本地快照与故障降级 |
| **数据存储层** | 单机可用内嵌 Derby（**仅演示**）；集群**必须**外置 MySQL（5.7+ / 8.0） |
| **Distro 协议** | 自研分布式一致性协议，用于**临时实例**，最终一致 + 高可用（**AP**） |
| **JRaft** | 基于 Raft 的强一致协议，用于**持久化实例**与**配置数据**（**CP**） |

> ⚠️ 集群节点数必须**奇数**（3 / 5 / 7），Raft 需多数派存活；2 个节点挂 1 个即不可用，还不如单机。
> ⚠️ 集群内所有节点必须**统一配置**：同样的 MySQL、同样的 `NACOS_AUTH_TOKEN`、一致的 `cluster.conf` 列表（用固定 IP，不写域名）。

---

## 4. 部署

| 模式 | 数据存储 | 一致性 | 适用 |
| --- | --- | --- | --- |
| `standalone` 单机 | 默认内嵌 Derby，可切 MySQL | 单点，无集群一致性 | 本地开发、CI 演示 |
| `cluster` 集群 | **必须 MySQL** | JRaft + Distro | 所有生产环境 |

```bash
sh startup.sh -m standalone    # Linux/macOS；Windows 用 startup.cmd -m standalone
docker run -d --name nacos -p 8848:8848 -p 9848:9848 -e MODE=standalone nacos/nacos-server:v2.3.2
# 控制台 http://127.0.0.1:8848/nacos，默认 nacos / nacos
```

`docker-compose.yml`（MySQL 存储 + 开启鉴权，可直接用）：

```yaml
services:
  mysql:
    image: mysql:8.0
    restart: always
    environment:
      MYSQL_ROOT_PASSWORD: root123456
      MYSQL_DATABASE: nacos_config
    volumes:
      - ./mysql-data:/var/lib/mysql
      # 表结构取自 alibaba/nacos 的 distribution/conf/mysql-schema.sql（v2.3.2 分支）
      - ./mysql-schema.sql:/docker-entrypoint-initdb.d/nacos-schema.sql:ro
  nacos:
    image: nacos/nacos-server:v2.3.2
    restart: always
    depends_on: [mysql]
    environment:
      MODE: standalone                      # 集群改为 cluster 并挂载 cluster.conf
      SPRING_DATASOURCE_PLATFORM: mysql
      MYSQL_SERVICE_HOST: mysql
      MYSQL_SERVICE_PORT: "3306"
      MYSQL_SERVICE_DB_NAME: nacos_config
      MYSQL_SERVICE_USER: root
      MYSQL_SERVICE_PASSWORD: root123456
      MYSQL_SERVICE_DB_PARAM: "characterEncoding=utf8&connectTimeout=1000&socketTimeout=3000&autoReconnect=true&useSSL=false&allowPublicKeyRetrieval=true&serverTimezone=Asia/Shanghai"
      NACOS_AUTH_ENABLE: "true"             # 鉴权：生产必须开启
      NACOS_AUTH_TOKEN: "SecretKey0123456789012345678901234567890123456789012345678901"  # Base64，解码后 >= 32 字节，务必替换
      NACOS_AUTH_IDENTITY_KEY: "serverIdentity"
      NACOS_AUTH_IDENTITY_VALUE: "change-me-please"
      JVM_XMS: 512m
      JVM_XMX: 512m
    ports: ["8848:8848", "9848:9848", "9849:9849"]   # HTTP 控制台 / 客户端 gRPC / 服务端 gRPC
```

> ⚠️ **生产三件套**：① 外置 MySQL 并持久化；② `NACOS_AUTH_ENABLE=true`（默认**关闭**，裸奔在内网也会被扫）；③ 集群化（3 节点起）并放通 8848/9848/9849/7848。另外 Nacos 默认 JVM 堆 2G，容器里不限制 `JVM_XMX` 很容易 OOM 被 kill，建议 512m ~ 1g。

---

## 5. 使用一：配置中心 ⭐

### 5.1 Java：nacos-client 原生用法

```xml
<!-- com.alibaba.nacos:nacos-client:2.3.2 ，版本与服务端保持一致 -->
<dependency>
  <groupId>com.alibaba.nacos</groupId><artifactId>nacos-client</artifactId>
  <version>2.3.2</version>
</dependency>
```

```java
import com.alibaba.nacos.api.NacosFactory;
import com.alibaba.nacos.api.PropertyKeyConst;
import com.alibaba.nacos.api.config.ConfigService;
import com.alibaba.nacos.api.config.ConfigType;
import com.alibaba.nacos.api.config.listener.Listener;
import java.util.Properties;
import java.util.concurrent.Executor;

public class NacosConfigDemo {
    static final String DATA_ID = "order-service-dev.yaml";
    static final String GROUP = "DEFAULT_GROUP";

    public static void main(String[] args) throws Exception {
        Properties props = new Properties();
        props.put(PropertyKeyConst.SERVER_ADDR, "127.0.0.1:8848");
        props.put(PropertyKeyConst.NAMESPACE, "dev");     // 命名空间 ID，不是显示名称
        props.put(PropertyKeyConst.USERNAME, "nacos");
        props.put(PropertyKeyConst.PASSWORD, "nacos");
        ConfigService configService = NacosFactory.createConfigService(props);

        // 1) 发布配置（幂等：存在即覆盖）
        configService.publishConfig(DATA_ID, GROUP, "order:\n  timeout: 3000\n  retry: 3\n", ConfigType.YAML.getType());
        // 2) 获取配置（超时 5000ms）
        System.out.println("content = " + configService.getConfig(DATA_ID, GROUP, 5000L));

        // 3) 监听变更（长轮询 + 服务端推送）；取消监听用 removeListener(DATA_ID, GROUP, listener)
        configService.addListener(DATA_ID, GROUP, new Listener() {
            @Override public Executor getExecutor() { return null; }   // null = 使用客户端内置线程池
            @Override public void receiveConfigInfo(String configInfo) {
                System.out.println("[变更] 新配置 = " + configInfo);
            }
        });
        Thread.sleep(Long.MAX_VALUE);   // 仅演示：阻塞住进程，实际项目由容器管理生命周期
    }
}
```

### 5.2 Java：Spring Cloud Alibaba 用法

```xml
<!-- BOM：com.alibaba.cloud:spring-cloud-alibaba-dependencies:2023.0.1.0（import scope），对应 Spring Boot 3.2.4
     下面三个 starter 由 BOM 统一管版本，无需写 version -->
<dependency>
  <groupId>com.alibaba.cloud</groupId><artifactId>spring-cloud-starter-alibaba-nacos-config</artifactId>
</dependency>
<dependency>
  <groupId>com.alibaba.cloud</groupId><artifactId>spring-cloud-starter-alibaba-nacos-discovery</artifactId>
</dependency>
<!-- 2021.0.1.0 之后默认不再走 bootstrap，需显式引入；或改用 spring.config.import（二者不要混用） -->
<dependency>
  <groupId>org.springframework.cloud</groupId><artifactId>spring-cloud-starter-bootstrap</artifactId>
</dependency>
```

`bootstrap.yml`（**必须**在 bootstrap 阶段拉取，否则晚于容器初始化）：

```yaml
spring:
  application:
    name: order-service          # DataId 前缀
  profiles:
    active: dev                  # DataId 后缀 → order-service-dev.yaml
  cloud:
    nacos:
      server-addr: 127.0.0.1:8848
      username: nacos
      password: nacos
      config:
        namespace: dev                 # 命名空间 ID
        group: DEFAULT_GROUP
        file-extension: yaml
        # 优先级：主配置 > extension-configs[n] > shared-configs[n]
        shared-configs:
          - { data-id: common-redis.yaml, group: DEFAULT_GROUP, refresh: true }
      discovery:
        namespace: dev
        ephemeral: true                # 临时实例，走 Distro AP
```

```java
import org.springframework.beans.factory.annotation.Value;
import org.springframework.cloud.context.config.annotation.RefreshScope;
import org.springframework.web.bind.annotation.GetMapping;
import org.springframework.web.bind.annotation.RestController;

@RestController
@RefreshScope   // 关键：Nacos 推来新配置后 Bean 被销毁重建，@Value 才会刷新
public class ConfigController {
    @Value("${order.timeout:3000}")
    private long timeout;

    @GetMapping("/timeout")
    public long timeout() { return timeout; }
    // 等价写法：@NacosValue(value = "${order.timeout:3000}", autoRefreshed = true)（需 nacos-config-spring-boot-starter）
}
```

> ⚠️ `@Value` **不加** `@RefreshScope` 永远不会刷新；`@ConfigurationProperties` 默认支持刷新（重建绑定对象），推荐优先用。

### 5.3 Go：nacos-sdk-go/v2 配置读取与监听

```bash
go get github.com/nacos-group/nacos-sdk-go/v2@v2.3.1
```

```go
package main

import (
	"fmt"

	"github.com/nacos-group/nacos-sdk-go/v2/clients"
	"github.com/nacos-group/nacos-sdk-go/v2/common/constant"
	"github.com/nacos-group/nacos-sdk-go/v2/vo"
)

func main() {
	// 1) serverConfig：只填 HTTP 地址，2.x 的 gRPC 9848 由 SDK 自动推导
	serverConfigs := []constant.ServerConfig{*constant.NewServerConfig("127.0.0.1", 8848, constant.WithContextPath("/nacos"))}
	// 2) clientConfig
	clientConfig := *constant.NewClientConfig(
		constant.WithNamespaceId("dev"), constant.WithTimeoutMs(5000),
		constant.WithNotLoadCacheAtStart(true),        // 不读旧快照，调试方便；生产建议 false
		constant.WithUsername("nacos"), constant.WithPassword("nacos"),
		constant.WithLogDir("/tmp/nacos/log"), constant.WithLogLevel("info"),
		constant.WithCacheDir("/tmp/nacos/cache"),     // 本地快照目录，生产需持久化挂载
	)
	configClient, err := clients.NewConfigClient(vo.NacosClientParam{
		ClientConfig: &clientConfig, ServerConfigs: serverConfigs,
	})
	if err != nil { panic(err) }

	// 3) 主动拉取一次（同时写入本地快照）
	content, err := configClient.GetConfig(vo.ConfigParam{DataId: "order-service-dev.yaml", Group: "DEFAULT_GROUP"})
	if err != nil { panic(err) }
	fmt.Println("首次拉取配置:\n", content)

	// 4) 监听变更（长轮询 + 服务端推送）
	err = configClient.ListenConfig(vo.ConfigParam{
		DataId: "order-service-dev.yaml", Group: "DEFAULT_GROUP",
		OnChange: func(namespace, group, dataId, data string) {
			fmt.Printf("[变更] ns=%s group=%s dataId=%s\n%s\n", namespace, group, dataId, data)
		},
	})
	if err != nil { panic(err) }

	// 发布配置：configClient.PublishConfig(vo.ConfigParam{DataId, Group, Content, Type: "yaml"})
	// 取消监听：configClient.CancelListenConfig(vo.ConfigParam{DataId, Group})
	select {}
}
```

### 5.4 配置监听与热更新机制

| 机制 | 说明 |
| --- | --- |
| **长轮询 Long Polling** | 客户端发起 `listening` 请求，服务端 **hold 住 29.5s** 不返回；期间有变更立即返回，超时返回空并重新发起 |
| **2.x gRPC** | 客户端与 9848 建**双向长连接**，变更通过 Push 通道下发，比 1.x 长轮询更省连接 |
| **本地快照 Failover** | 配置写入 `cacheDir`；Nacos 不可用时用快照启动，避免「注册中心挂了业务也起不来」 |
| **MD5 校验** | 服务端返回 `md5`，客户端比对本地 MD5，一致则不触发回调，杜绝无效刷新与回调风暴 |

> ⚠️ 不要把高频变动的业务开关（如秒级灰度比例）塞进配置，几十个客户端同时刷新会打爆应用，这类场景用专门的开关平台或 MQ。单条配置建议 **< 100KB**，超长拆多个 Data ID；`OnChange` 回调里不要做重活（重建连接池等），会阻塞推送线程。

---

## 6. 使用二：服务注册与发现 ⭐

### 6.1 Java：Spring Cloud Alibaba 自动注册 + DiscoveryClient

`spring.cloud.nacos.discovery`（见 5.2）配好后启动即自动注册。

```java
import jakarta.annotation.Resource;
import org.springframework.cloud.client.discovery.DiscoveryClient;
import org.springframework.cloud.client.loadbalancer.LoadBalanced;
import org.springframework.context.annotation.Bean;
import org.springframework.web.bind.annotation.GetMapping;
import org.springframework.web.bind.annotation.RestController;
import org.springframework.web.client.RestClient;
import java.util.List;
import java.util.stream.Collectors;

@RestController
public class DiscoveryController {
    @Resource
    private DiscoveryClient discoveryClient;

    @GetMapping("/instances")
    public List<String> instances() {
        return discoveryClient.getInstances("order-service").stream()
                .map(i -> i.getHost() + ":" + i.getPort())
                .collect(Collectors.toList());
    }

    @Bean
    @LoadBalanced   // 服务名当域名用，由 LoadBalancer 从 Nacos 取实例
    public RestClient.Builder restClientBuilder() { return RestClient.builder(); }
}
```

原生 `NamingService` 手动注册（非 Spring 场景，import 已省略）：

```java
Properties props = new Properties();
props.put(PropertyKeyConst.SERVER_ADDR, "127.0.0.1:8848");
props.put(PropertyKeyConst.NAMESPACE, "dev");
NamingService naming = NacosFactory.createNamingService(props);   // com.alibaba.nacos.api.naming.NamingService

Instance instance = new Instance();          // com.alibaba.nacos.api.naming.pojo.Instance
instance.setIp("10.0.0.1");
instance.setPort(8080);
instance.setEphemeral(true);                 // true=临时实例（AP），false=持久化实例（CP）
instance.setMetadata(Map.of("version", "v1"));
naming.registerInstance("order-service", "DEFAULT_GROUP", instance);

List<Instance> instances = naming.selectInstances("order-service", "DEFAULT_GROUP", true);
naming.subscribe("order-service", "DEFAULT_GROUP",
        (EventListener) event -> System.out.println("实例变更: " + event.getServiceName()));
```

### 6.2 Go：RegisterInstance / SelectInstances / Subscribe

```go
package main

import (
	"fmt"

	"github.com/nacos-group/nacos-sdk-go/v2/clients"
	"github.com/nacos-group/nacos-sdk-go/v2/common/constant"
	"github.com/nacos-group/nacos-sdk-go/v2/model"
	"github.com/nacos-group/nacos-sdk-go/v2/vo"
)

const serviceName, groupName, clusterName = "order-service", "DEFAULT_GROUP", "DEFAULT"

func main() {
	serverConfigs := []constant.ServerConfig{*constant.NewServerConfig("127.0.0.1", 8848, constant.WithContextPath("/nacos"))}
	clientConfig := *constant.NewClientConfig(
		constant.WithNamespaceId("dev"), constant.WithTimeoutMs(5000),
		constant.WithNotLoadCacheAtStart(true), constant.WithLogLevel("info"),
		constant.WithUsername("nacos"), constant.WithPassword("nacos"),
		constant.WithLogDir("/tmp/nacos/log"), constant.WithCacheDir("/tmp/nacos/cache"),
	)
	namingClient, err := clients.NewNamingClient(vo.NacosClientParam{
		ClientConfig: &clientConfig, ServerConfigs: serverConfigs,
	})
	if err != nil { panic(err) }

	// 1) 注册实例：Ephemeral=true 走心跳上报（临时实例，Distro/AP）
	//    Ephemeral=false 由服务端主动探测（持久化实例，JRaft/CP）
	myIp, myPort := "10.0.0.1", uint64(8080)
	success, err := namingClient.RegisterInstance(vo.RegisterInstanceParam{
		Ip: myIp, Port: myPort, ServiceName: serviceName, GroupName: groupName,
		ClusterName: clusterName, Weight: 10, Enable: true, Healthy: true, Ephemeral: true,
		Metadata: map[string]string{"version": "v1", "zone": "cn-hangzhou-b"},
	})
	if err != nil || !success { panic(fmt.Sprintf("register failed: %v", err)) }

	// 2) 查询健康实例（消费方拉取，做兜底）
	instances, err := namingClient.SelectInstances(vo.SelectInstancesParam{
		ServiceName: serviceName, GroupName: groupName, HealthyOnly: true,
	})
	if err != nil { panic(err) }
	for _, ins := range instances {
		fmt.Printf("instance => %s:%d weight=%.1f healthy=%v meta=%v\n",
			ins.Ip, ins.Port, ins.Weight, ins.Healthy, ins.Metadata)
	}

	// 3) 订阅实例变更：本地维护可用实例列表并由事件驱动刷新，替代定时轮询
	err = namingClient.Subscribe(vo.SubscribeParam{
		ServiceName: serviceName, GroupName: groupName, Clusters: []string{clusterName},
		SubscribeCallback: func(hosts []model.Instance, err error) {
			if err != nil { fmt.Printf("subscribe error: %v\n", err); return }
			fmt.Printf("[实例变更] 可用实例数 = %d, 首个实例 = %s:%d\n", len(hosts), hosts[0].Ip, hosts[0].Port)
		},
	})
	if err != nil { panic(err) }

	// 4) 优雅下线：退出前先 Unsubscribe / DeregisterInstance（DeregisterInstanceParam 需带 Ip/Port/ServiceName/GroupName/Cluster/Ephemeral）
	select {}
}
```

### 6.3 实例健康检查与失效时间线

| 时间点 | 事件 |
| --- | --- |
| T + 0s | 客户端每 **5s** 上报一次心跳 |
| T + 15s | 连续 3 次心跳失败 → 实例标记为**不健康（unhealthy）** |
| T + 30s | 继续失败 → 实例被**摘除**（移出注册表 + 推送变更给订阅方） |
| 临时实例重启 | 重新注册即恢复，消费方通过订阅回调立即感知 |
| 持久化实例 | 无心跳概念，服务端按周期探测，不健康只标记、不删除 |

> ⚠️ 只在客户端侧用 `HealthyOnly` 过滤即可，**不要**在业务代码里自己判 `healthy` 再兜底；只有临时实例才用心跳，持久化实例必须在控制台配置探测方式（`tcp` / `http` / `mysql`）。
> ⚠️ 容器 / 多网卡环境必须显式指定注册 IP（Java `spring.cloud.nacos.discovery.ip`，Go 的 `Ip`），否则注册的是容器内网 IP。

---

## 7. 与 Spring Cloud / Kratos / K8s 的关系

| 维度 | **Nacos** | Eureka | Consul | etcd |
| --- | --- | --- | --- | --- |
| CAP 取舍 | **AP / CP 可切换** | AP | CP（默认） | CP（Raft） |
| 一致性协议 | Distro（AP）+ JRaft（CP） | 无共识协议，节点互相同步 | Raft | Raft |
| 配置中心 | **内置** | 无（需配 Spring Cloud Config） | 有，KV 形式 | 有，KV 形式（无控制台） |
| 健康检查 | 心跳 + 服务端探测 | 客户端心跳 | 服务端探测 + gossip | 租约 TTL |
| 控制台 | **完善**（服务 / 配置 / 历史 / 回滚） | 有，较简陋 | 有 | 无，需 etcdctl 或第三方 |
| 权重 / 元数据 / 灰度 | **原生支持** | 弱 | 支持 | 需自行实现 |
| 适合场景 | 微服务一体化（注册 + 配置 + 治理） | 存量 Netflix 系项目 | 多数据中心、服务网格 | K8s / 强一致 KV |

**选型口诀**：国内 Java / Go 微服务一体化 → Nacos；已在 K8s 只缺 KV → etcd；要多数据中心 + mesh → Consul。

| 框架 | 集成方式 |
| --- | --- |
| Spring Cloud | 阿里官方 Spring Cloud Alibaba 提供 `nacos-discovery` / `nacos-config` starter，替代 Eureka + Config |
| Kratos（Go） | `github.com/go-kratos/kratos/contrib/registry/nacos/v2`，底层即 `nacos-sdk-go`，实现 `registry.Registrar` / `registry.Discovery` |
| Dubbo / gRPC | `dubbo-registry-nacos` 替代 ZooKeeper；gRPC 通过自定义 `NameResolver` / `Registry` 桥接 |

| K8s 场景 | 建议 |
| --- | --- |
| 纯 K8s、只用 Service/DNS 发现、无动态配置需求 | **不需要**，K8s Service + ConfigMap 足够 |
| 需要动态配置热更新、灰度、历史回滚 | **用** Nacos 作配置中心（ConfigMap 改了通常要重启 Pod，这是短板） |
| 混合部署：K8s + 传统 VM / 多集群互调 | **用** Nacos 统一注册表，解决跨集群服务发现 |
| 已有大量 Spring Cloud 应用搬迁上 K8s | 过渡期**保留** Nacos，做「K8s Service 之外再注册一层」的桥接 |
| 只要服务发现且已在 K8s | 优先 K8s 原生，双注册中心会带来一致性 / 口径分裂 |

> ⚠️ 最忌「两套发现并存且不统一」：K8s Service 里看不到的实例 Nacos 里能看到，排障口径对不上。务必明确以哪套为准并写进规范。

---

## 8. 生产实践与常见坑

| 主题 | 做法 / 坑点 |
| --- | --- |
| **多环境隔离** | 一环境一 Namespace（`dev` / `test` / `prod`），禁止共用 `public`；Namespace ID 用有意义的短字符串而非纯 UUID |
| **配置回滚与历史** | 控制台「配置管理 → 历史版本」默认保留最近 **30 天**，支持一键回滚；生产变更走审批 + 双人复核 |
| **鉴权与 AK/SK** | `NACOS_AUTH_ENABLE=true`；关掉默认 `nacos/nacos` 改强密码；服务间用 AK/SK（`NACOS_AUTH_IDENTITY_KEY/VALUE`）叠加用户鉴权 |
| **集群节点数** | 必须**奇数** 3 / 5 / 7；所有节点 `cluster.conf` 内容一致且用**固定 IP**，不写域名 |
| **内存占用** | 默认 `-Xmx2g`；小规格机器设 `JVM_XMX=512m~1g`，监控 FullGC 与 `nacos_jvm_*` 指标；配置条数与实例数上升时堆要跟着加 |
| **配置过长** | 单条 < 100KB，按业务域拆 Data ID + `shared-configs` / `extension-configs` 组合 |
| **监听丢失重连** | 客户端会自动重连并重新订阅，但网络分区期间的变更不会补推，**兜底**：定时（如 30s）主动 `getConfig` 对账 |
| **本地快照** | 生产务必保留 `cacheDir` 并在容器里持久化挂载，否则「Nacos 抖动 + 容器重启」会导致应用批量起不来 |
| **配置优先级** | 主配置 > extension-configs[大索引] > extension-configs[小索引] > shared-configs[大索引] > shared-configs[小索引] > 本地 `application.yml` |
| **服务名与发版规范** | 服务名统一小写中划线；Namespace 做环境、Group 做系统边界；发版流程：先摘流量 → 等连接排空 → 重启 → 恢复权重，避免直接 kill 造成 502 |
| **磁盘与日志** | Nacos 日志增长快（`naming` / `config` 尤甚），配置 logback 轮转与清理，避免磁盘打满导致 Raft 写入失败 |

---

## 9. 面试官会追问什么

1. **Nacos 的 CP 和 AP 是怎么切换的？** 不是全局开关，而是**按数据类型路由**：临时实例（`ephemeral=true`）走自研 Distro，最终一致、高可用（AP）；持久化实例与配置数据走 JRaft，强一致（CP）；同一服务内实例模式必须统一。
2. **配置变更如何实时推送到客户端？** 1.x 用**长轮询**：请求被服务端 hold 约 29.5s，期间有变更立即返回；2.x 改为 **gRPC 双向长连接**（9848）主动 Push。本质是「hold 住请求 + 变更即刻响应」，不是 WebSocket 广播。
3. **临时实例宕机多久被摘除？** 客户端每 **5s** 心跳；**15s** 未收到标记不健康；**30s** 未收到则从注册表摘除并推送变更。参数可调，但过短会误摘除，过长会打到死节点。
4. **Nacos 与 Eureka 的 CAP 取舍差异？** Eureka 是纯 **AP**（peer-to-peer 复制、无共识协议，容忍短时不一致）；Nacos 是**双模**：服务发现默认 AP 保可用，配置与持久化实例 CP 保一致。所以 Eureka 只能当注册中心，Nacos 还能当配置中心。
5. **Nacos 集群为什么必须奇数台？** 配置与持久化实例走 JRaft，需要**多数派（quorum）**才能提交。3 台容忍挂 1 台，5 台容忍挂 2 台；2 台挂 1 台即失去多数派、直接不可写，可靠性反而低于 3 台。
6. **客户端连不上 Nacos 时应用还能启动吗？** 能。SDK 把配置写入**本地快照**，启动优先读快照，注册也会重试。所以生产必须在容器里持久化挂载 `cacheDir`，否则「Nacos 抖动 + 应用重启」会导致批量启动失败——最常见的生产事故模式。
7. **Nacos 挂了服务之间还能调用吗？** 短时间能：消费方本地缓存了实例列表，已有调用不受影响；但此时若有实例上下线而本地列表未更新，可能打到已下线节点，长期不可用会逐渐失效。因此 Nacos 自身必须高可用。
8. **如何做到配置的灰度 / 多环境 / 多租户？** 三层隔离：**Namespace（环境 / 租户）→ Group（业务 / 系统）→ Data ID（具体配置）**，再用 `shared-configs` / `extension-configs` 复用公共配置；灰度可用「不同 Namespace + 网关 / 标签路由」，或 Nacos 的 beta 发布能力（`publishConfig` 带 `betaIps`，只对指定 IP 生效）。

## 关联

- [../分布式/服务发现与负载均衡.md](../分布式/服务发现与负载均衡.md) — 注册发现的原理侧
- [../分布式/一致性与CAP.md](../分布式/一致性与CAP.md) — Distro 与 JRaft 的一致性取舍
- [../分布式/Raft协议.md](../分布式/Raft协议.md) — JRaft 的算法基础
- [xxl-job.md](xxl-job.md) — 另一类基础组件的对照
