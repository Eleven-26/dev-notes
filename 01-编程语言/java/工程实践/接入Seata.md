# 接入 Seata

> 覆盖：Java 侧接入 Seata 的选型、前提、依赖、TC 连接与数据源代理、AT 与 TCC 两套写法、XID 跨进程与跨线程传播、优雅退出、验证与排障口径。
>
> 不覆盖：2PC / XA 的协议与阻塞分析见 [2PC与3PC.md](../../../04-架构与系统/分布式/分布式事务/2PC与3PC.md)；TCC 的空回滚、悬挂与隔离性设计见 [TCC.md](../../../04-架构与系统/分布式/分布式事务/TCC.md)；方案谱系与选型见 [README.md](../../../04-架构与系统/分布式/分布式事务/README.md)；DTM 与 Seata 的产品横评见 [分布式事务选型对比.md](../../../03-数据与中间件/中间件/分布式事务/分布式事务选型对比.md)；Go 侧接 DTM 见 [接入DTM.md](../../go/工程实践/接入DTM.md)。
>
> 内容整理自个人学习笔记；依赖坐标、注解与表结构来自 Seata 官方文档与仓库。素材见 [素材清单.md](../../../素材清单.md)。
>
> **验证情况**：⚠️ 本篇代码**未在本机编译、未连接 Seata Server 运行**，依赖版本以 `seata-spring-boot-starter 2.6.0` 书写；API 名称按 2.x 公开接口核对，落地前以所用版本源码与官方兼容矩阵为准。逐块说明见文末「校验口径」。

---

## 一、客户端选型与依赖

**本节要点**：先选模式，再定依赖；四种模式的业务侵入与一致性语义不同，不要为了「少写代码」默认选 AT 却忽略它的隔离性边界。

| 模式 | 核心机制 | 隔离 / 一致性 | 侵入性 | 适用 |
| --- | --- | --- | --- | --- |
| **AT** | SQL 代理生成前后镜像，`undo_log` 反向补偿，全局锁防脏写 | 最终完成回滚；默认全局读未提交 | ⭐ 低 | 关系型数据库、普通 CRUD |
| **TCC** | 业务实现 Try/Confirm/Cancel，可启用事务栅栏 | 业务层强一致倾向 | 高 | 高并发核心资源操作 |
| **Saga** | 状态机编排本地事务和补偿 | 最终一致，无全局隔离 | 中 | 长事务、多服务流程 |
| **XA** | 数据库原生 XA Prepare/Commit | 强一致倾向 | 低 | 短事务、数据库支持 XA |

选型判据：

- 普通 CRUD、能接受全局读未提交 → **AT**；
- 资金、库存等可冻结资源、要求更强隔离 → **TCC**；
- 长流程、多服务、每步可补偿 → **Saga**；
- 数据库原生 XA、事务很短 → **XA**。

依赖坐标：

```xml
<properties>
    <seata.version>2.6.0</seata.version>
</properties>
<dependency>
    <groupId>io.seata</groupId>
    <artifactId>seata-spring-boot-starter</artifactId>
    <version>${seata.version}</version>
</dependency>
```

> ⚠️ 版本必须与 Seata Server、Spring Boot / Spring Cloud Alibaba 的兼容矩阵一致；只对齐客户端版本、不对齐服务端，常见结果是启动能连上但分支注册失败。

## 二、前提

**本节要点**：Seata 客户端不创建服务端资源；连上之前，TC、事务分组、业务库的 `undo_log`、TCC 的 `tcc_fence_log` 必须先在服务端与数据库侧齐备，缺一样都有对应报错。

| 准备项 | 说明 | 漏了会怎样 |
|---|---|---|
| **Seata Server（TC）** | 默认 TC 端口 `8091`，控制台 `7091`；需先启动并可被应用访问 | 启动时报 `no available service ... found` |
| **事务分组映射** | `tx-service-group` 与 `vgroup-mapping` / `grouplist` 对应 | 分支注册不到正确的 TC 集群 |
| **注册 / 配置中心** | 生产通常用 Nacos、Consul 等；本地可直连 `grouplist` | 多环境地址漂移，或配置中心不可用时无法启动 |
| **业务库 `undo_log`** | 每个参与 AT 的库都要建，业务表需有单列主键 | 第一次分支执行报 `undo_log` 表不存在 |
| **TCC 栅栏表 `tcc_fence_log`** | 使用 `useTCCFence = true` 时必须建 | TCC 分支报栅栏表相关错误 |
| **被调服务接入** | 下游也要接 Seata 并透传 XID | 下游操作不在同一全局事务里，回滚不生效 |

`undo_log` 建表：

```sql
CREATE TABLE `undo_log` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `branch_id` BIGINT NOT NULL,
  `xid` VARCHAR(128) NOT NULL,
  `context` VARCHAR(128) NOT NULL,
  `rollback_info` LONGBLOB NOT NULL,
  `log_status` INT NOT NULL,
  `log_created` DATETIME NOT NULL,
  `log_modified` DATETIME NOT NULL,
  `ext` VARCHAR(100) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_undo_log` (`xid`, `branch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

> 每个业务库都要建；不要只建在某个中心库。AT 的一阶段会把业务写与 `undo_log` 放在同一个本地事务里。

## 三、初始化

**本节要点**：初始化只做三件事——让 Starter 接管数据源、告诉客户端 TC 在哪、确认全局事务切面生效；初始化代码放在应用启动配置，不要放进请求处理。

```yaml
seata:
  enabled: true
  tx-service-group: order_tx_group
  data-source-proxy-mode: AT
  enable-auto-data-source-proxy: true
  service:
    vgroup-mapping:
      order_tx_group: default
    grouplist:
      default: 127.0.0.1:8091
```

Starter 默认自动把应用 `DataSource` 包装为 `DataSourceProxy`，RM 才能拦截 SQL、记录镜像并注册分支。

若关闭 `enable-auto-data-source-proxy`，可手动暴露代理：

```java
import io.seata.rm.datasource.DataSourceProxy;
import org.springframework.context.annotation.Bean;
import org.springframework.context.annotation.Primary;

import javax.sql.DataSource;

public class SeataDataSourceConfig {
    @Bean
    @Primary
    public DataSource dataSource(DataSource originDataSource) {
        return new DataSourceProxy(originDataSource);
    }
}
```

> ⚠️ 自动代理和手动代理只能选一种，避免重复代理；手动代理时还要确保 MyBatis / JPA 使用的是这个 `@Primary` 数据源。

初始化失败时的典型报错：

| 报错 | 原因 |
|---|---|
| `no available service 'default' found` | TC 未启动、地址错误或 `vgroup-mapping` 不匹配 |
| `can not get cluster name in registry config` | 注册中心配置缺失或 namespace / group 不一致 |
| `Table 'xxx.undo_log' doesn't exist` | 业务库未建 `undo_log` |
| 分支没有注册 | 数据源没有被代理，SQL 绕过了 Seata |

## 四、单例边界 ⭐

**本节要点**：判断只看一件事——对象内部有没有长活资源。`DataSourceProxy`、TC 客户端、全局事务扫描器都持有连接或后台线程，必须是进程级；`RootContext` 是线程级，不能当单例缓存。

| 对象 | 是否单例 | 原因 | 写错的后果 |
|---|---|---|---|
| `DataSourceProxy` | ✅ 进程级，每个物理数据源一个 | 包装连接、维护代理与 RM 注册 | 每请求新建会重复代理、连接泄漏 |
| TC 客户端（TM / RM Client） | ✅ 进程级，由 Starter 管理 | 维护与 TC 的长连接与重连 | 手动反复创建会耗尽连接与线程 |
| `GlobalTransactionScanner` | ✅ 进程级 | 扫描 `@GlobalTransactional` / `@LocalTCC` 并注册切面 | 多次注册导致重复拦截 |
| `RootContext` | ❌ 线程级 | 内部是 `ThreadLocal` 存 XID | 跨线程直接读取会拿到 `null` |

> 本篇不写 `sync.Once` 一类封装：Spring 容器本身就是单例边界，用 `@Bean` 默认单例即可；不要为了「看起来像单例」再包一层静态工厂。

## 五、初始化顺序 ⭐

**本节要点**：⭐ 判据是「谁在初始化时捕获了数据源」——凡是持有 `DataSource` 引用的下游组件，都必须排在数据源代理之后初始化，否则它拿到的是原始数据源，AT 完全不生效且不报错。

正确的顺序：

```text
配置加载 → 数据源创建 → DataSourceProxy 包装
        → MyBatis SqlSessionFactory / JPA EntityManagerFactory
        → Repository / Mapper
        → 业务 Service（@GlobalTransactional）
```

两个常见错误：

1. **手动配置 `SqlSessionFactory` 时先注入原始 `DataSource`**：MyBatis 后续所有 SQL 都绕过代理，`undo_log` 永远为空，回滚也不会发生。
2. **在 `@PostConstruct` 里提前拿数据源执行 SQL**：此时代理可能还没包装完成，初始化 SQL 不进入全局事务；初始化逻辑应放到应用就绪后。

> 跨组件的顺序问题同理：只要下游在构造时捕获了上游对象，就必须排在被捕获者之后；这是「初始化顺序」的通用判据，不只是 Seata。

## 六、单例与依赖注入的封装

**本节要点**：AT 不需要业务封装单例，Starter 已经把数据源代理与 TC 客户端做成容器级单例；业务侧只需要把「全局事务入口」收敛到一个应用服务方法。

```java
import io.seata.spring.annotation.GlobalTransactional;
import lombok.RequiredArgsConstructor;
import org.springframework.stereotype.Service;

@Service
@RequiredArgsConstructor
public class OrderApplicationService {
    private final OrderRepository orderRepository;
    private final InventoryClient inventoryClient;
    private final AccountClient accountClient;

    @GlobalTransactional(name = "create-order", rollbackFor = Exception.class)
    public void createOrder(OrderCommand command) {
        orderRepository.insert(command);
        inventoryClient.deduct(command.skuId(), command.quantity());
        accountClient.debit(command.userId(), command.amount());
    }
}
```

封装原则：

- `@GlobalTransactional` 只放在**入口方法**，不要在每个 Repository / Client 上重复标注；
- 入口方法必须是 Spring Bean 的**外部调用**，同类自调用不会经过 AOP 代理；
- 全局事务方法尽量短，避免在里面做慢 RPC、文件上传或等待用户输入；
- 分支方法只做本地事务，不要自己再开一个全局事务。

## 七、实际调用

**本节要点**：AT 用「数据源代理 + 注解」覆盖普通 CRUD；TCC 用「三接口 + 栅栏」覆盖需要资源预留的核心链路；两者都由 `@GlobalTransactional` 开启全局事务。

### 7.1 AT 模式

AT 一阶段在同一本地事务内提交业务数据和 `undo_log`，提交前获取 TC 管理的全局锁；二阶段提交只需异步清理日志，回滚则校验数据镜像后按 `undo_log` 生成反向 SQL。

注意事项：

| 事项 | 要求 |
|---|---|
| 主键 | 业务表必须有单列主键；联合主键 / 无主键表不支持 |
| SQL 形态 | 复杂 SQL、存储过程、部分函数可能无法解析；上线前用真实 SQL 验证 |
| 隔离性 | 默认全局读未提交；需要更强读隔离时用 `@GlobalLock` + `SELECT ... FOR UPDATE` |
| 异常 | 入口若吞掉异常，`@GlobalTransactional` 感知不到失败，全局事务不会按预期回滚 |
| 热更新 | 批量更新涉及大量行时会放大全局锁竞争，尽量拆小 |

### 7.2 TCC 模式

```java
import io.seata.rm.tcc.api.BusinessActionContext;
import io.seata.rm.tcc.api.BusinessActionContextParameter;
import io.seata.rm.tcc.api.LocalTCC;
import io.seata.rm.tcc.api.TwoPhaseBusinessAction;

import java.math.BigDecimal;

@LocalTCC
public interface AccountTccAction {
    @TwoPhaseBusinessAction(
        name = "accountTccAction",
        commitMethod = "commit",
        rollbackMethod = "rollback",
        useTCCFence = true
    )
    boolean prepare(
        BusinessActionContext context,
        @BusinessActionContextParameter(paramName = "orderId") String orderId,
        @BusinessActionContextParameter(paramName = "amount") BigDecimal amount
    );

    boolean commit(BusinessActionContext context);

    boolean rollback(BusinessActionContext context);
}
```

```java
import io.seata.rm.tcc.api.BusinessActionContext;
import lombok.RequiredArgsConstructor;
import org.springframework.stereotype.Service;

@Service
@RequiredArgsConstructor
public class AccountTccActionImpl implements AccountTccAction {
    private final AccountService accountService;

    @Override
    public boolean prepare(BusinessActionContext context, String orderId, BigDecimal amount) {
        return accountService.freeze(orderId, amount);
    }

    @Override
    public boolean commit(BusinessActionContext context) {
        String orderId = String.valueOf(context.getActionContext("orderId"));
        return accountService.confirmFrozen(orderId);
    }

    @Override
    public boolean rollback(BusinessActionContext context) {
        String orderId = String.valueOf(context.getActionContext("orderId"));
        return accountService.cancelFrozen(orderId);
    }
}
```

`@TwoPhaseBusinessAction` 标注的 `prepare` 就是 Try；Seata 根据方法名调用 Confirm / Cancel，并用 `BusinessActionContext` 传递业务参数。

`useTCCFence = true` 时还要在参与库创建官方版本对应的 `tcc_fence_log` 表，用持久化栅栏处理幂等、空回滚和悬挂；业务冻结流水仍应以 `orderId` 建唯一约束。

全局入口仍由 `@GlobalTransactional` 包裹，然后调用一个或多个 `prepare(null, ...)`。

### 7.3 AT 与 TCC 怎么配合

- 普通订单落库、状态更新用 AT；
- 余额、库存等需要预留的资源用 TCC；
- 同一个全局事务里可以混用，但入口仍只有一个 `@GlobalTransactional`；
- 不要在同一业务动作上同时用 AT 和 TCC 改同一行，否则全局锁与业务冻结会互相干扰。

## 八、跨进程与跨线程传播

**本节要点**：XID 存在 `RootContext` 的 `ThreadLocal` 里；跨进程要显式放进协议头，跨线程要显式 `bind` / `unbind`，线程池不会自动传递。

### 8.1 跨进程

| 调用方式 | 传播方式 |
|---|---|
| Spring Cloud OpenFeign / RestTemplate | 引入 Seata 的 Spring Cloud 集成，拦截器自动透传 XID |
| Dubbo | 引入 `seata-dubbo`，通过 attachment 透传 |
| 手写 HTTP | 发送方把 `RootContext.getXID()` 放进 Header；接收方 `RootContext.bind(xid)` |
| 消息队列 | 把 XID 放进消息 Header；消费端按需 `bind` |

手写 HTTP 的接收端：

```java
import io.seata.core.context.RootContext;

public void handle(String xid, Runnable business) {
    RootContext.bind(xid);
    try {
        business.run();
    } finally {
        RootContext.unbind();
    }
}
```

> ⚠️ 不要把「消息消费」轻易纳入生产者的全局事务：MQ 消费通常是异步、延迟、可重试的，更适合走最终一致（见 [最终一致方案.md](../../../04-架构与系统/分布式/分布式事务/最终一致方案.md)）；只有明确需要「消费也参与同一全局事务」时才绑定 XID，并确认消费失败时的回滚语义。

### 8.2 跨线程

`@GlobalTransactional` 的 XID 在 `ThreadLocal` 中，提交给线程池的任务默认读不到。两种做法：

1. 在任务外层捕获 XID，任务内 `bind` / `unbind`；
2. 使用支持上下文传递的线程池包装器（如 Spring 的 `TaskDecorator`），在提交时复制、执行后清理。

```java
String xid = RootContext.getXID();
executor.submit(() -> {
    RootContext.bind(xid);
    try {
        business();
    } finally {
        RootContext.unbind();
    }
});
```

> ⚠️ 必须 `unbind`；否则线程池复用线程时会把上一个请求的 XID 带给下一个任务，造成跨请求污染。

## 九、优雅退出

**本节要点**：退出顺序是「先摘流量 → 等在途全局事务结束 → 关闭容器」；不要 `kill -9`，否则一阶段已提交、二阶段尚未完成的分支只能等 TC 重试。

| 步骤 | 动作 | 原因 |
|---|---|---|
| 1 | 从注册中心摘除 / 停止接收新请求 | 避免新全局事务进入 |
| 2 | 等待在途请求与全局事务结束 | 减少未完成分支 |
| 3 | 关闭 Spring 容器 | Starter 会释放 TC 连接与扫描器 |
| 4 | 确认 TC 侧无该实例的未完成分支 | 避免长期悬挂 |

- 应用重启后，TC 会继续重试未完成的二阶段；业务侧要保证分支接口幂等。
- 若使用 TCC，Confirm / Cancel 必须能在重启后继续执行，不能依赖内存状态。
- 日志中保留 `xid` 与实例标识，便于 TC 侧对账。

## 十、验证与排查

**本节要点**：先确认「代理真的生效」，再看 TC 与分支状态；多数问题不是 Seata 本身，而是数据源没代理、XID 没透传或异常被吞。

### 10.1 先确认生效

| 检查项 | 预期 |
|---|---|
| 全局事务内 `RootContext.getXID()` | 非 `null` |
| 一阶段后 `undo_log` | 有对应 `xid` / `branch_id` 的记录 |
| 二阶段提交后 | `undo_log` 记录被清理 |
| TC 控制台 / 日志 | 能看到全局事务与分支状态 |
| 下游服务日志 | 收到同一个 `xid` |

### 10.2 常见故障速查

| 症状 | 先查什么 |
|---|---|
| 启动报 `no available service 'default' found` | TC 是否启动；`tx-service-group` 与 `vgroup-mapping` 是否一致 |
| `undo_log` 表不存在 | 每个参与库是否都建表 |
| 全局事务不回滚 | 入口是否吞异常；是否同类自调用；下游是否接入并透传 XID |
| 下游 `RootContext.getXID()` 为 `null` | 拦截器 / Header / 线程池传递 |
| 频繁 `LockConflictException` | 热点行竞争；缩短事务、拆小批量、增加重试 |
| AT 解析 SQL 失败 | 是否用了存储过程、复杂函数、无主键表 |
| TCC 报栅栏表错误 | `tcc_fence_log` 是否创建；`useTCCFence` 是否与表版本匹配 |
| 回滚时提示脏数据 | 一阶段后数据被其他事务改过；检查是否绕过全局锁 |

| 排查项 | 命令 / 位置 |
|---|---|
| TC 是否可达 | `telnet <tc-host> 8091` 或查看客户端连接日志 |
| 全局事务状态 | TC 控制台 / `global_table`、`branch_table` |
| 分支镜像 | 业务库 `undo_log.rollback_info` |
| 事务分组 | 应用配置 `seata.tx-service-group` 与 TC 侧映射 |

## 十一、校验口径

> ✅ 本篇代码的校验方式与范围（JDK 17 / Seata 2.6.0 口径）：

- **未编译**：第一节依赖、第三节 YAML 与数据源配置、第七节 AT 入口与 TCC 接口/实现、第八节 XID 传播代码均未在本机编译。
- **未运行**：未启动 Seata Server，未连接 MySQL，未验证 AT 回滚、TCC 栅栏、XID 透传与优雅退出。
- **核对方式**：依赖坐标、`undo_log` 表结构、`@GlobalTransactional` / `@LocalTCC` / `@TwoPhaseBusinessAction` 注解与 `RootContext` API 按 Seata 2.x 官方文档与公开接口核对。
- **未实测项**：注册中心（Nacos / Consul）、TC 集群、Saga 状态机、XA 模式、MQ 场景下的 XID 传播。
- **与 DTM 的对照**：Go 侧 DTM 的屏障与实测读数见 [接入DTM.md](../../go/工程实践/接入DTM.md)，两边不要混用同一套表结构。

| 依赖 | 实际版本 |
| --- | --- |
| JDK | 17（书写口径） |
| `io.seata:seata-spring-boot-starter` | 2.6.0（未编译，见上） |
| Seata Server | 未启动 |
| MySQL | 未连接 |

## 延伸追问

- **AT 和 XA 怎么选？** → 普通 CRUD、能接受全局读未提交选 AT；需要数据库原生 XA 语义、事务很短选 XA。差别见 [2PC与3PC.md](../../../04-架构与系统/分布式/分布式事务/2PC与3PC.md) 第六节。
- **为什么下游也要接 Seata？** → 分支要在同一个全局事务里注册；下游不接，它的本地事务不受 TC 协调，回滚不会生效。
- **`@GlobalTransactional` 为什么没回滚？** → 常见原因是异常被吞、同类自调用绕过代理、`rollbackFor` 不匹配，或下游没有透传 XID。
- **AT 的全局锁会不会成为瓶颈？** → 会。热点行更新会排队；能拆小事务、错峰或改用 TCC 预留。
- **TCC 的 `useTCCFence` 解决什么？** → 用 `tcc_fence_log` 处理幂等、空回滚和悬挂；业务侧的冻结流水仍要自己的唯一键。
- **异步线程里怎么带 XID？** → 捕获后 `bind`，`finally` 中 `unbind`；不要依赖线程池自动传递。
- **消息消费要不要绑定 XID？** → 多数场景不需要；异步消费更适合最终一致。只有明确要求消费参与同一全局事务时才绑定，并想清楚失败回滚语义。

## 关联

- **上游/总纲**：[README.md](../../../04-架构与系统/分布式/分布式事务/README.md) — 方案谱系与选型
- **下游机制**：[2PC与3PC.md](../../../04-架构与系统/分布式/分布式事务/2PC与3PC.md) — AT / XA 与两阶段提交的关系
- **下游机制**：[TCC.md](../../../04-架构与系统/分布式/分布式事务/TCC.md) — 空回滚、悬挂与隔离性设计
- **下游机制**：[最终一致方案.md](../../../04-架构与系统/分布式/分布式事务/最终一致方案.md) — 消息路线的边界
- **对照/互补**：[接入DTM.md](../../go/工程实践/接入DTM.md) — Go 侧 DTM 的屏障与实测
- **产品选型**：[分布式事务选型对比.md](../../../03-数据与中间件/中间件/分布式事务/分布式事务选型对比.md) — DTM vs Seata
- **框架基础**：[Spring-boot核心.md](../Spring-boot核心.md) — 自动配置、AOP 代理与 Bean 生命周期
- **并发写**：[写冲突控制的Java落地.md](../写冲突控制的Java落地.md) — 唯一索引、条件更新与全局锁的关系
- **素材**：[素材清单.md](../../../素材清单.md)
