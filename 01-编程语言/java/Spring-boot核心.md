# Spring Boot 核心

> IoC 容器到底管什么、依赖注入的三种方式与循环依赖的**三级缓存**、Bean 生命周期与作用域（**回调顺序实测**）、
> AOP 的两种代理（**代理类名实测**）与「自调用失效」的机制证据、
> 自动装配的三段结构与条件装配（**152 个自动配置类的清单实测 + 最小启动 53 个 Bean**）。
>
> 内容整理自个人学习笔记。**全部结论配套本机实测**：Spring 6.1.6 / Spring Boot 3.2.5 /
> JDK 24.0.1（字节码按 `--release 17` 编译）；所用 jar 来自本机 `~/.m2/repository`，未联网下载。
> ⚠️ 本机**没有 `aspectjweaver`**，所以 AOP 部分用的是**编程式 `ProxyFactory`**（Spring AOP 的内核），
> `@Aspect` 注解风格未实测；`@Transactional` 因无数据源未实测，但它与 AOP 共用同一套代理机制。
> 本目录其它篇目见 [并发/锁与AQS.md](并发/锁与AQS.md)、[运行时/JVM与垃圾回收.md](运行时/JVM与垃圾回收.md)。

---

## 一、IoC 容器到底管什么？

**本节要点**：容器不是「帮你 new 对象」，而是**接管了对象的创建时机、依赖关系与生命周期**。理解了这一点，后面所有注解都只是「告诉容器怎么做」。

### 1.1 容器 vs 手工 new

```java
// 手工 new：依赖关系写死在代码里，谁依赖谁、什么时候创建、创建几个全看你
OrderService svc = new OrderServiceImpl(new OrderDaoImpl(new DataSource()));
```

```java
// 交给容器：只声明「我需要什么」，由容器决定怎么给
try (AnnotationConfigApplicationContext ctx = new AnnotationConfigApplicationContext(LifeConfig.class)) {
    System.out.println("  容器里 Bean 的数量 = " + ctx.getBeanDefinitionCount());
    System.out.println("  getBean(LifecycleBean.class) = " + ctx.getBean(LifecycleBean.class));
}
```

实测输出：

```text
== 实验 1：容器基本用法 ==
  容器里 Bean 的数量 = 8
  getBean("injectedValue") = 来自另一个 Bean
  getBean(LifecycleBean.class) = LifecycleBean(injected=来自另一个 Bean)
```

⭐ 容器带来的四件事：

| 能力 | 手工 `new` | 容器 |
|---|---|---|
| 创建时机 | 调用处决定 | **容器决定**（单例在启动时建好，可 `@Lazy` 延迟） |
| 依赖关系 | 硬编码在调用处 | **声明式**（构造器参数 / `@Autowired`） |
| 实例个数 | 每次 `new` 都是新的 | 由**作用域**决定（`singleton` / `prototype`） |
| 生命周期钩子 | 需要自己写 | `@PostConstruct` / `@PreDestroy` 自动回调 |
| 替换实现 | 改代码 | **换一个 Bean 定义即可**（如测试时换成 mock） |

### 1.2 核心概念只有三个

| 概念 | 含义 |
|---|---|
| **BeanDefinition** | 「配方」：类名、作用域、依赖、初始化方法。**容器读的是它，不是你的类** |
| **Bean** | 按配方造出来的实例 |
| **容器（ApplicationContext）** | 管理 BeanDefinition 的注册、Bean 的创建与依赖注入、生命周期回调、事件发布 |

⚠️ **「容器启动」与「Bean 创建」是两件事**：默认（`singleton` + 非 `@Lazy`）时容器启动阶段就会把所有单例建好 —— 所以**启动慢、但运行期快**；反之 `@Lazy` 是「用时才建」，启动快但第一次访问有延迟。

---

## 二、依赖注入与循环依赖

**本节要点**：三种注入方式里**构造器注入是首选**；而循环依赖只有「先造半成品再补依赖」这条路能解 —— 这就是三级缓存存在的原因。

### 2.1 三种注入方式

| 方式 | 写法 | 优点 | 缺点 |
|---|---|---|---|
| **构造器注入** | 构造器参数 | ✅ 依赖**不可变**（可 `final`）、**必然非空**、便于测试、启动时报错 | 循环依赖无法解开（见 2.3） |
| Setter 注入 | `@Autowired` 标在 setter | 可以解循环依赖、可重新注入 | 对象可能处于「依赖没配齐」的半成品状态 |
| 字段注入 | `@Autowired` 标在字段 | 写得最短 | ⚠️ 隐藏依赖、难测试（必须靠容器或反射）、字段不能 `final` |

⭐ **结论**：**优先构造器注入**（Spring 官方也推荐）。字段注入只适合快速原型；一旦项目变大，它的「看不出这个类依赖了多少东西」会成为维护负担。

### 2.2 `@Autowired` 的匹配规则

1. **先按类型找** —— 同类多个候选时：
2. **再按 `@Primary` / `@Priority` 挑**；
3. **再按字段名/参数名匹配 `@Qualifier` 或 Bean 名称**；
4. 都定不下来 → 抛 `NoUniqueBeanDefinitionException`。

⚠️ **`@Autowired(required = false)`** 会让「找不到就注入 null」，把「依赖缺失」从**启动期错误**推迟成**运行期 NPE** —— 除非确有可选项，否则别加。

### 2.3 循环依赖：为什么字段注入能解、构造器不能（实测）

```text
== 实验 3：循环依赖 —— 字段注入能解，构造器注入不能 ==
  字段注入：A 拿到了 A→B，B 拿到了 B→A  → ✅ 成功创建
  构造器注入：❌ BeanCurrentlyInCreationException
     Error creating bean with name 'ctorA': Requested bean is currently in creation: Is there an unresolvable circular reference?
```

**为什么字段注入能解开 —— 三级缓存**：

```text
① singletonObjects       成品缓存：完全初始化好的 Bean
② earlySingletonObjects  早期引用缓存：已经实例化、但还没完成依赖注入的「半成品」
③ singletonFactories     工厂缓存：能造出「提前暴露引用」的工厂（有 AOP 时这里放的是代理工厂）

流程：建 A → 实例化 A（构造器）→ 把「能拿到 A 引用」的工厂放进 ③
     → 注入 A 的字段时发现需要 B → 建 B → B 的字段需要 A
     → 从 ③ 拿到 A 的早期引用（必要时生成代理）放进 ② → B 注入完成
     → 回到 A，把 B 注入进去 → A 也完成，从 ② 移到 ①
```

⭐ **关键点**：缓存的是「**引用**」，所以「先有个空壳、再把依赖塞进去」是可行的。这就是字段/setter 注入能解循环依赖、而构造器不能的原因 —— **构造器要求「参数必须在调用前就绪」，没有「先给个空壳」的余地**。

⚠️ 三级缓存里最容易被忽略的是 **③ 是「工厂」而不是「实例」**：只有当真的被循环依赖时才调用工厂造出早期引用（必要时是 AOP 代理）。这是为了**避免每个 Bean 都提前生成代理**（性能优化），也是「为什么需要三级而不是两级」的答案。

⚠️ **能解开 ≠ 应该这么写**：循环依赖本身是**设计问题**（两个类互相依赖说明边界没划清）。Spring 2.6+ 默认已经**禁止**循环依赖（`spring.main.allow-circular-references=false`），要靠配置显式打开 —— 这正是官方把它当反模式的态度。

---

## 三、Bean 的生命周期与作用域

**本节要点**：生命周期回调的顺序是**确定性的**，可以直接实测出来背下来。

### 3.1 回调顺序（实测）

```text
== 实验 2：Bean 生命周期回调的真实顺序 ==
  ① 构造器  ② 依赖注入(setter)  ③ @PostConstruct  ④ afterPropertiesSet  ⑤ initMethod
  容器 close 后的销毁回调：⑦ @PreDestroy  ⑧ destroyMethod
```

| 阶段 | 触发方式 |
|---|---|
| ① 实例化 | 构造器 |
| ② 属性填充 | 依赖注入（setter / 字段） |
| ③ **`@PostConstruct`** | 注解（`jakarta.annotation`） |
| ④ `afterPropertiesSet()` | 实现 `InitializingBean` 接口 |
| ⑤ `initMethod` | `@Bean(initMethod = "xxx")` |
| ⑥ 使用中 | — |
| ⑦ **`@PreDestroy`** | 注解 |
| ⑧ `destroyMethod` | `@Bean(destroyMethod = "xxx")` |

⭐ **`@PostConstruct` 与 `afterPropertiesSet()` 语义等价**，但前者是注解、不用让类去实现 Spring 接口（解耦更好）—— **推荐注解**。同理销毁阶段推荐 `@PreDestroy`。

⚠️ **`@PreDestroy` 的执行条件**：Bean 必须是容器管理的**单例**，且容器要正常 `close()`（`try-with-resources` 或注册 shutdown hook）。**`prototype` 作用域的 Bean 容器不管销毁**，`@PreDestroy` 不会执行。

### 3.2 作用域（实测）

```text
== 实验 4：作用域 singleton vs prototype ==
  singleton: getBean 两次拿到同一个对象？ true
  prototype: ProtoBean#1 / ProtoBean#2  → 同一个对象？ false
```

| 作用域 | 实例数 | 创建时机 | 销毁 |
|---|---|---|---|
| `singleton`（默认） | 容器内**一个** | 容器启动时（`@Lazy` 则首次访问时） | 容器管理，执行 `@PreDestroy` |
| `prototype` | **每次 `getBean` 都新建** | 每次请求 | ⚠️ **容器不管理**，`@PreDestroy` 不执行 |
| `request` / `session` | 每个 HTTP 请求 / 会话 | Web 环境下 | 请求/会话结束时 |

⚠️ **`prototype` 注入到 `singleton` 里的经典坑**：单例创建时只注入一次，之后它持有的永远是**同一个** prototype 实例 —— 看起来「prototype 没生效」。解法是注入 `ObjectProvider<T>`（或 `@Lookup`），每次用时 `getObject()` 取新的。

---

## 四、AOP：两种代理与「自调用失效」

**本节要点**：Spring AOP 的落地就是**代理**。只要能说清「代理对象持有目标对象的引用」，就能解释所有注解失效类问题。

### 4.1 JDK 动态代理 vs CGLIB（实测代理类名）

```text
== 实验 1：JDK 动态代理 vs CGLIB（看代理类的真实名字）==
  JDK 代理类       = $Proxy0
  是接口的实例吗   = true，是实现类的实例吗 = false
  CGLIB 代理类     = SpringAopLab$OrderServiceImpl$$SpringCGLIB$$0
  是实现类的实例吗 = true  ← CGLIB 是继承目标类生成子类，所以类型成立
```

| | **JDK 动态代理** | **CGLIB** |
|---|---|---|
| 原理 | 实现目标类的**接口** | **继承**目标类，生成子类 |
| 前提 | 必须有接口 | 类不能是 `final`，方法不能是 `final` / `private` |
| 代理类类型 | `$Proxy0` —— `instanceof 实现类` 为 **false** | `Xxx$$SpringCGLIB$$0` —— `instanceof 实现类` 为 **true** |
| 由谁生成 | JDK 自带的 `Proxy` | Spring 内嵌的 CGLIB（重新打包的 `org.springframework.cglib`） |

⭐ **选择规则**（Spring Boot 2.x+ 默认 `spring.aop.proxy-target-class=true`，即**优先 CGLIB**）：类有接口时也能用 JDK 代理（但 Spring Boot 默认还是选 CGLIB，避免「注入实现类类型时拿到的是代理、强转失败」这类问题）。想强制指定就设 `proxyTargetClass`。

⚠️ **一个必踩的坑**：目标类被 JDK 代理后**不能强转成实现类**（实测 `instanceof` 为 false）—— 所以注入时应该按**接口**类型注入，不要按实现类。

### 4.2 自调用失效（实测证据）

```text
== 实验 2：为什么「自己调自己」时切面不生效 ==
  从代理调用 detail() 后，切面执行次数 = 1  ← 外部调用经过代理，切面生效
  从代理调用 create() 后，切面执行次数 = 1  ← ⚠️ 只有 create 被拦截，内部的 this.inner() 没有
  inner() 实际被调用次数 = 2（方法确实执行了，但没走代理）
```

**读这个实测的要点**：`inner()` **被调用了 2 次**（一次来自 `detail`、一次来自 `create`），但**切面只有 1 次是在它身上执行的吗？不是** —— 从代理调 `create()` 时，拦截次数只 +1（拦的是 `create` 本身），`create` 内部那句 `this.inner()` **完全没有经过代理**。

```text
外部调用  →  代理对象  →  切面链  →  目标对象.create()  ── this.inner() ──▶ 目标对象.inner()   ❌ 绕过了代理
外部调用  →  代理对象  →  切面链  →  目标对象.inner()                                        ✅ 经过代理
```

⭐ **机制**：`create()` 方法体里的 `this` 是**目标对象自己**，不是代理对象。`this.inner()` 只是一次普通的 Java 方法调用，**切面链根本不在调用路径上**。

⚠️ **这就是以下注解「静默失效」的共同原因**：

| 注解 | 自调用时的表现 |
|---|---|
| `@Transactional` | **事务不生效**（没有开启事务）→ 最危险，因为数据可能只写了一半 |
| `@Async` | 变成**同步执行**（没有切换到线程池） |
| `@Cacheable` | 缓存**不生效**（每次都查库） |
| 自定义切面注解 | 直接不触发 |

**三种解法**：① 拆到另一个 Bean（让调用跨对象）；② 注入自己（`@Autowired private MyService self;` 然后 `self.inner()`）；③ 用 `AopContext.currentProxy()`（需开 `exposeProxy = true`）。

⭐ **判断口径**：只要看到「一个类的方法 A 调用了同类的方法 B，而 B 上有注解」，就要怀疑失效 —— **除非那个注解不依赖代理**（比如 `@Scheduled` 由定时器直接反射调用，不受影响）。

### 4.3 `@Configuration` 的类也被 CGLIB 增强（实测）

```text
== 实验 3：@Configuration 里 @Bean 方法互调，为什么返回的还是同一个实例 ==
  配置类自身的类型 = SpringAopLab$Config$$SpringCGLIB$$0
  proxyBeanMethods = false 时，配置类类型 = SpringAopLab$PlainConfig
```

⭐ **实测说明**：`@Configuration` 默认 `proxyBeanMethods = true`，**配置类本身会被 CGLIB 增强**。这样在配置类里直接调用 `orderService()` 方法时，会被拦截成「先去容器里找」，而不是再 `new` 一个 —— 这就是「`@Bean` 方法互相调用仍保持单例」的实现方式。

⚠️ 反过来：`@Configuration(proxyBeanMethods = false)` 时**配置类不被增强**（实测类名没有 `$$SpringCGLIB`），启动更快。**代价是直接调用 `@Bean` 方法会真的再 new 一个** —— 所以这种模式下不要在配置类里互相调用 `@Bean` 方法。

---

## 五、自动装配：`@SpringBootApplication` 的真相

**本节要点**：那个「一个注解启动应用」的魔法，拆开就是**三个注解 + 一个选择器 + 一堆条件判断**。

### 5.1 三段结构（实测）

```text
== 实验 1：@SpringBootApplication 拆开是什么 ==
  org.springframework.boot.SpringBootConfiguration
  org.springframework.boot.autoconfigure.EnableAutoConfiguration
  org.springframework.context.annotation.ComponentScan
```

| 组成 | 作用 |
|---|---|
| `@SpringBootConfiguration` | 本质是 `@Configuration`，标记这是配置类 |
| `@EnableAutoConfiguration` | **开启自动装配** |
| `@ComponentScan` | 扫**当前包及子包**下的 `@Component` / `@Service` / `@Repository` / `@Controller` |

⚠️ **「扫当前包及子包」有个硬约束**：**启动类不能放在默认包**。实测把它放在默认包时直接启动失败：

```text
** WARNING ** : Your ApplicationContext is unlikely to start due to a @ComponentScan of the default package.
BeanDefinitionStoreException: Failed to read candidate component class: ... DataSourceAutoConfiguration$EmbeddedDatabaseConfiguration.class
```

因为默认包（无 `package`）会让 `@ComponentScan` 去扫**整个 classpath 包括所有 jar**，扫到别人 jar 里的类就炸了。**所以启动类必须在具名包下，且通常放在最外层包**（这样能覆盖所有子包）。

### 5.2 `@EnableAutoConfiguration` 怎么把 152 个配置类弄进来（实测）

```text
== 实验 2：@EnableAutoConfiguration 怎么把 152 个自动配置类弄进来 ==
  @EnableAutoConfiguration 上的 @Import = [class org.springframework.boot.autoconfigure.AutoConfigurationImportSelector]
```

⭐ 它自己**不干活**，只是 `@Import(AutoConfigurationImportSelector.class)`。选择器再去读 classpath 上所有 jar 里的清单文件。清单文件的机制在两代之间变了：

| 版本 | 机制 | 本机实测（Boot 3.2.5） |
|---|---|---|
| Boot 2.6 及以前 | `META-INF/spring.factories` 里的 `EnableAutoConfiguration=` 键 | — |
| Boot 2.7 ~ | 新增 `META-INF/spring/org.springframework.boot.autoconfigure.AutoConfiguration.imports` | 该文件 **152 行** |
| Boot 3.0 起 | **只支持 imports**，`spring.factories` 里已移除该键 | `spring.factories` 里含 `EnableAutoConfiguration` = **False** |

本机把这个 jar 打开验证过：

```text
== autoconfigure jar 里的清单文件 ==
   META-INF/spring.factories
   META-INF/spring/aot.factories
   META-INF/spring/org.springframework.boot.autoconfigure.AutoConfiguration.imports

== AutoConfiguration.imports 共 152 行自动配置类 ==
   org.springframework.boot.autoconfigure.admin.SpringApplicationAdminJmxAutoConfiguration
   org.springframework.boot.autoconfigure.aop.AopAutoConfiguration
   org.springframework.boot.autoconfigure.amqp.RabbitAutoConfiguration
   ...

== spring.factories 是否还有 EnableAutoConfiguration ==
  文件: META-INF/spring.factories | 含 EnableAutoConfiguration: False | 长度 3211
```

⚠️ 注意 `spring.factories` **文件本身还在**（长度 3211），只是不再承载自动配置 —— 它现在只留给别的扩展点（如 `LoggingSystemFactory`、`PropertySourceLoader`）。**「Boot 3 没有 spring.factories 了」这个说法是错的**，准确说法是「它不再用于自动装配」。

### 5.3 条件装配：为什么 152 个里只有少数真正生效（实测）

```text
== 实验 3：最小启动（WebApplicationType.NONE，不起 tomcat）==
  启动成功，Bean 总数 = 53
  getBean(MyService.class).hello() = 来自 @Service 的定义（@ComponentScan 扫到了同包下的 @Service）
```

⭐ **152 个候选，最后只有 53 个 Bean** —— 因为自动配置类上几乎都挂着条件注解：

| 注解 | 条件 |
|---|---|
| `@ConditionalOnClass` | classpath 上**有**某个类才生效（最常见的开关） |
| `@ConditionalOnMissingBean` | 容器里**没有**某个 Bean 才生效（**让你自己的配置优先于默认配置**） |
| `@ConditionalOnProperty` | 某个配置项存在且为特定值才生效 |
| `@ConditionalOnWebApplication` | 是 Web 应用才生效（本实验用 `NONE` 就都跳过了） |

⭐ **`@ConditionalOnMissingBean` 是理解「为什么我自定义一个 Bean 就能覆盖默认行为」的钥匙** —— 默认配置类只在「你没提供」时才生效。

⚠️ **排查自动装配问题的手段**：加 `--debug`（或 `debug=true`）会打印 **condition evaluation report**，逐条告诉你每个自动配置类「生效了 / 因为什么没生效」。实测里报错信息也提示了这一点。**这比逐个猜快得多。**

---

## 使用：`@Scheduled` 与 `@LoadBalanced` 在真实项目里的两个坑

本项目里**真实存在**的 Spring 用法在 [服务发现的Java实现.md](../../04-架构与系统/分布式/服务发现的Java实现.md)（Spring Cloud + Nacos）。挑两个注解按本节机制拆：

### 坑一：`@LoadBalanced` 为什么只能加在 `@Bean` 上

Spring Cloud 的负载均衡是靠「给 `RestTemplate` 换一个带了拦截器的实现」做到的 —— 而换实现只能发生在**容器创建 Bean 的那一刻**。所以：

- ✅ `@Bean @LoadBalanced RestTemplate restTemplate()` → 容器造出来的就是被增强过的实例；
- ❌ `new RestTemplate()` 自己造 → **完全绕过容器**，服务名不会被解析成实例地址，直接报 `UnknownHostException`。

⭐ 这正是「**第 4 节的代理机制在框架层的应用**」：`@LoadBalanced` 本质是给 Bean 加了一层装饰/代理，脱离容器就没有这层。**判据：凡是「靠注解改变对象行为」的用法，都必须由容器创建对象。**

### 坑二：`@Scheduled` 在副本部署下会重复执行

`@Scheduled` **不经过代理链**（由 `ScheduledAnnotationBeanPostProcessor` 直接注册反射调用），所以它**不受第 4.2 节自调用问题的影响** —— 但它有另一个问题：

⚠️ **每个副本都会各跑一次**。服务部署 3 个实例，定时任务就执行 3 遍。这是 [../../03-数据与中间件/中间件/xxl-job.md](../../03-数据与中间件/中间件/xxl-job.md) 里说的「定时任务重复执行」事故的典型来源 —— 解法是换成分布式任务调度（xxl-job 的调度中心只派发一次），或者用分布式锁选主。

⭐ **归纳**：

```text
① 注解要生效 → 对象必须由容器创建（@LoadBalanced 的教训）
② 注解要靠代理 → 调用必须跨对象（@Transactional / @Async 的教训）
③ 注解不靠代理 → 自调用没问题，但要想「多副本会不会重复」（@Scheduled 的教训）
```

**这三条覆盖了绝大多数「注解为什么没生效 / 生效了但有副作用」的问题。** 遇到注解类问题，先判断它属于哪一类。

---

## 延伸追问

- **`@Autowired` 和 `@Resource` 有什么区别？** → `@Autowired`（Spring 的）**先按类型**、再按名称；`@Resource`（`jakarta.annotation` 的 JSR-250 标准注解）**先按名称**、再按类型。前者支持 `required = false`，后者不支持。**标准项目推荐构造器注入，这两个注解用得都不多。**
- **为什么 Spring 官方推荐构造器注入？** → ① 依赖可以声明成 `final`（不可变）；② 依赖**必然非空**（不会出现「忘了注入、运行期 NPE」）；③ **脱离了容器也能 `new` 出来做单元测试**；④ 依赖过多时构造器会变得很长 —— 那正好是「这个类职责太多的信号」，属于**暴露问题**而不是制造问题。
- **三级缓存为什么不能只用两级？** → 因为第三级放的是**工厂**而不是实例。只有真的发生循环依赖时才调用工厂生成早期引用（有 AOP 时这里生成的是代理）。如果只有两级，就得在**每个 Bean 实例化后立刻生成代理** —— 而绝大多数 Bean 根本没有循环依赖，这个开销纯属浪费。
- **Spring 能解循环依赖，为什么还说它是反模式？** → 因为它只是**让程序能跑起来**，但没有解决「两个类互相依赖」这个设计问题。而且循环依赖 + AOP 会引出「注入的是原始对象还是代理」这类隐蔽问题。**Spring 2.6+ 默认禁止循环依赖**，态度已经很明确了。
- **`@PostConstruct` 与 `afterPropertiesSet` 该用哪个？** → 语义完全相同，**推荐 `@PostConstruct`** —— 它来自 `jakarta.annotation` 标准包，不需要让业务类实现 Spring 接口（降低耦合）。注意 Spring 6 / Boot 3 里包名从 `javax.annotation` 变成了 **`jakarta.annotation`**（这是 Jakarta EE 9 改名带来的，升级时很容易漏）。
- **JDK 动态代理和 CGLIB 该选哪个？** → Spring Boot 2.x 起**默认用 CGLIB**（`proxy-target-class=true`），因为它生成的代理 `instanceof 实现类` 成立、注入实现类类型不会失败。除非目标类是 `final` 或有大量 `final` 方法（CGLIB 无法代理），此时才用 JDK 代理。
- **`@ComponentScan` 为什么要求启动类放在最外层包？** → 它默认只扫**启动类所在包及子包**。放在最外层能覆盖全部业务代码；放在默认包则会扫整个 classpath（本机实测直接启动失败）。这也是「启动类的位置不是随便定的」的原因。
- **自动装配出问题时怎么排查？** → 加 `--debug` 打印 **condition evaluation report**：它会逐条列出每个自动配置类是「匹配了」还是「没匹配、因为什么」。比翻文档猜条件快得多。另外 `spring.autoconfigure.exclude` 可以显式排除某个自动配置类。

## 关联

- [并发/锁与AQS.md](并发/锁与AQS.md) — 单例 Bean 的线程安全是「共享可变状态」问题，容器不替你解决
- [../../04-架构与系统/分布式/服务发现的Java实现.md](../../04-架构与系统/分布式/服务发现的Java实现.md) — 「使用」一节的代码出处：`@LoadBalanced` 与 `@Scheduled` 的落地
- [../../05-设计模式/创建型/工厂模式.md](../../05-设计模式/创建型/工厂模式.md) — IoC 容器本质是一个**通用工厂**，与那里讲的「工厂 vs 容器 DI 的边界」直接呼应
- [../../05-设计模式/原则/面向切面编程.md](../../05-设计模式/原则/面向切面编程.md) — AOP 不必依赖框架：PHP 里手写切面的四种方式与代价
- [../../05-设计模式/结构型/代理模式.md](../../05-设计模式/结构型/代理模式.md) — JDK 动态代理 / CGLIB 就是代理模式在框架层的实现
- [../../05-设计模式/行为型/模板方法模式.md](../../05-设计模式/行为型/模板方法模式.md) — `ApplicationContext.refresh()` 的流程是模板方法的经典应用（父类定骨架、子类填步骤）
- [../../05-设计模式/行为型/观察者模式.md](../../05-设计模式/行为型/观察者模式.md) — Spring 的事件机制（`ApplicationEvent` / `@EventListener`）就是观察者模式
- [../../03-数据与中间件/中间件/xxl-job.md](../../03-数据与中间件/中间件/xxl-job.md) — `@Scheduled` 在多副本下的重复执行问题与分布式调度的解法
