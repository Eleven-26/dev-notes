# Kratos 框架实战

> 覆盖 Kratos v2 集成 ent ORM 与 validate 校验、服务注册发现与容器化部署兼容、服务间元数据传递与鉴权排查、HTTP 原始请求获取与 Protobuf JSON（Any）转换
>
> 内容整理自大厂 Go 后端面试真题视频，参考资料与原始素材见 [素材清单](../interview/素材清单.md)。

---

## Q1. 如何在 Kratos 项目中集成 ent ORM 完成数据库访问？

**来源**：`BV12qjA6aErF p=3` B站 Go 面试真题 · 时长 7分00秒
**考察意图**：① 是否懂 Kratos 的分层约束——**数据交互必须收敛在 data 层**，biz 只认 repository 接口；② 是否知道 ent 是 **schema-first 的代码生成型 ORM**（schema 是唯一事实来源，改了必须重新生成）；③ 有没有走通"定义 schema → 生成代码 → 迁移建表 → wire 注入 client"这条完整链路，而不是只会 `Create().Save()`。

### 一、数据访问全部收敛到 data 层

分层是单向的：`api → service → biz → data`。项目在 data 层下建 `internal/data/ent/` 目录，**所有和数据交互的内容都封装在这个目录里**。

| 层 | 职责 | 能否 import ent |
|---|---|---|
| `api/` | protobuf 契约 | ❌ |
| `biz/` | 业务逻辑，依赖 `UserRepo` 等**接口** | ❌ |
| `data/` | 实现 biz 的 repo 接口，持有 ent client | ✅ 仅此一层 |

### 二、装插件、初始化目录、定义 schema

ent 自带代码生成命令，先装插件（装到 `$GOPATH/bin`），生成 ent 目录后整体挪到 data 层；`schema/` 里定义**字段、表关系（edge）、索引**三样东西。

```bash

go install entgo.io/ent/cmd/ent@latest
ent new User                 # 生成 ent/，内含 schema/ 与 generate.go
mv ent internal/data/ent
```

```go

// internal/data/ent/schema/user.go
type User struct{ ent.Schema }

func (User) Fields() []ent.Field {
	return []ent.Field{
		field.String("name").NotEmpty().MaxLen(64),
		field.Int64("age").Default(0),
		field.Time("created_at").Default(time.Now),
	}
}
func (User) Edges() []ent.Edge { return nil } // 表关系：一对一 / 一对多 / 主外键写这里
func (User) Indexes() []ent.Index { return []ent.Index{index.Fields("name").Unique()} }
```

执行 `go generate ./internal/data/ent/...`，会生成一整套数据访问代码：`User` 实体、`UserClient`、`UserQuery`、`UserMutation` 以及事务（`Tx`）支持。

### 三、建表 + wire 注入 client

**表结构可以自动建/改，但数据库本身必须先存在。** 链路是 `sql.Open` 拿 driver → 包成 ent client → `client.Schema.Create` 建表，这个 client 作为 provider 交给 wire 注入。

```go

// internal/data/data.go
var ProviderSet = wire.NewSet(NewDB, NewData, NewUserRepo)

func NewDB(c *conf.Data, logger log.Logger) (*ent.Client, error) {
	drv, err := sql.Open(c.Database.Driver, c.Database.Source)
	if err != nil {
		return nil, err
	}
	client := ent.NewClient(ent.Driver(drv))
	// 需要允许自动删列/删索引时追加 migrate.WithDropColumn(true)、migrate.WithDropIndex(true)
	if err := client.Schema.Create(context.Background()); err != nil {
		return nil, err
	}
	log.NewHelper(logger).Info("ent client init success")
	return client, nil
}

// cmd/payment/wire.go
func wireApp(*conf.Server, *conf.Data, log.Logger) (*kratos.App, func(), error) {
	panic(wire.Build(server.ProviderSet, data.ProviderSet, biz.ProviderSet, service.ProviderSet, newApp))
}
```

### 四、业务侧读写

按实体取到表的操作对象：插入是 `Create().SetXxx().Save()`，查询走 `Query().Where(...)`；跨多表强一致用 `r.data.db.Tx(ctx)`，闭包内改用 `tx.User.Create()`。

```go

func (r *userRepo) Create(ctx context.Context, u *biz.User) (*biz.User, error) {
	po, err := r.data.db.User.Create().SetName(u.Name).SetAge(u.Age).Save(ctx) // 插入并保存
	if err != nil {
		return nil, err
	}
	return &biz.User{ID: po.ID, Name: po.Name, Age: po.Age}, nil
}
// 查询：r.data.db.User.Query().Where(user.NameEQ(name)).Only(ctx)，user.NameEQ 是生成的字段谓词
```

### 面试官会追问什么

- **为什么不直接在 service 层调 ent？** → 破坏分层，业务逻辑与存储实现耦死，换 ORM 或加缓存要改业务代码。
- **ent 和 GORM 怎么选？** → ent 是 schema-first + 代码生成，类型安全、edge 图遍历强；GORM 上手快但复杂查询易失控。
- **线上让 ent 自动迁移表结构吗？** → 不建议，线上用版本化 SQL 迁移（golang-migrate），`Schema.Create` 只用于本地/测试库，避免误删列。

---

## Q2. 如何用 validate 框架给 proto 字段做输入校验？

**来源**：`BV12qjA6aErF p=4` B站 Go 面试真题 · 时长 4分45秒
**考察意图**：考"校验写在哪一层"的判断力。把规则**写进 proto 契约**、由工具生成校验代码、用中间件统一拦截，体现"契约即校验、不重复造轮子"；同时要能说出 **HTTP 与 gRPC 两条 transport 都要挂中间件**，漏挂一个就有一半入口裸奔。

### 一、规则写在 proto 里，而不是写在 service 里

```proto

import "validate/validate.proto";

message CreatePaymentRequest {
  // 字符串规则：长度 1~64
  string order_no = 1 [(validate.rules).string = {min_len: 1, max_len: 64}];
  // 枚举规则：只能取已定义且在白名单内的值
  BusinessType business_type = 2 [(validate.rules).enum = {defined_only: true, in: [1, 2]}];
  // 必填，下面用它验证 400 效果
  string node_file_url = 3 [(validate.rules).string = {min_len: 1}];
}
```

`validate/validate.proto` 不用自己下载——**Kratos 创建项目模板时已把它放进 `third_party/`**，直接 import 即可；支持的规则清单见官方文档里的 protoc-gen-validate 规则表（string / int / enum / message / repeated / 嵌套）。

### 二、装插件、生成校验代码

光写 proto 不生效，规则要在编译期变成 Go 代码：

```bash

go install github.com/envoyproxy/protoc-gen-validate@latest

protoc --proto_path=. --proto_path=./third_party \
  --go_out=paths=source_relative:./api --go-http_out=paths=source_relative:./api \
  --go-grpc_out=paths=source_relative:./api \
  --validate_out=paths=source_relative,lang=go:./api api/payment/v1/payment.proto
```

会额外产出 `payment.pb.validate.go`，为每个 message 按规则生成 `Validate()`。

### 三、HTTP 与 gRPC 都要挂中间件

生成的 `Validate()` 不会自己生效，必须在服务端中间件里引用，两侧缺一不可：

```go

// internal/server/http.go
var ServerOpts = []http.ServerOption{
	http.Middleware(recovery.Recovery(), validate.Validator()),
}

// internal/server/grpc.go
var GrpcServerOpts = []grpc.ServerOption{
	grpc.Middleware(recovery.Recovery(), validate.Validator()),
}
```

`validate.Validator()` 的逻辑很朴素：请求对象实现了 `Validate() error` 就调它，失败直接返回 `errors.BadRequest("VALIDATOR", err.Error())`。

### 四、效果验证

故意把必填的 `node_file_url` 从请求里删掉再发一次，返回 **400**，字段路径直接出现在报错里：

```

HTTP/1.1 400 Bad Request
{"code":400,"reason":"VALIDATOR","message":"node_file_url: value length must be at least 1 runes"}
```

### 面试官会追问什么

- **为什么不直接在 service 层写 if 校验？** → 规则分散、无法被网关和文档复用；proto 里的规则还能生成 OpenAPI 描述，前后端共享同一份契约。
- **中间件顺序有讲究吗？** → 有。`recovery` 放最外层，`validate` 应在鉴权/日志之后、业务之前，避免对未通过鉴权的请求做无谓校验。
- **依赖 DB 的校验（如订单号是否存在）放哪？** → proto 里只放**无状态**规则，依赖外部状态的放 biz 层，两者职责不同。

---

## Q3. Kratos 如何做服务注册与发现？容器化部署时怎么兼容？

**来源**：`BV12qjA6aErF p=39` B站 Go 面试真题 · 时长 9分39秒
**考察意图**：真正的考点不是"会不会用 etcd 注册"，而是**判断力**——什么时候需要注册发现、什么时候根本不需要。能说出"容器编排本身已用 DNS + 负载均衡解决寻址，所以线上不需要注册中心"，比背注册 API 有价值得多；再深问就是"一份代码怎么兼容两种部署形态"。

### 一、项目架构：寻址问题从哪来

服务拆成四个：**支付、订单、课程**三个基础服务，外加一个**业务聚合服务**。聚合服务把其他微服务的接口收拢、**统一对外提供接口**，同时**解耦微服务之间的相互调用并对流程做编排**。它要调支付/订单/课程，就必须先找到对方地址。

### 二、本地 / 非容器化：etcd 注册 + 发现

**注册（服务端）**：构造 etcd 客户端 → 包成注册中心 → 在 `kratos.New` 时用 `kratos.Registrar` 挂上，启动后 Kratos 自动把本机地址写入 etcd。**发现（调用方）**：同样引 etcd contrib 包构造 `registry.Discovery`，客户端 endpoint 写 `discovery:///服务名`。

```go

// internal/registry/etcd.go：clientv3 客户端包一层就是 etcd 注册中心（同时实现 Registrar 与 Discovery）
client, err := clientv3.New(clientv3.Config{Endpoints: cfg.Etcd.Endpoints})
r := etcd.New(client)

// newApp：容器化部署时 r 为 nil（接口零值），此时不注册
func newApp(logger log.Logger, hs *http.Server, gs *grpc.Server, r registry.Registrar) *kratos.App {
	opts := []kratos.Option{
		kratos.Name(Name), kratos.Version(Version), kratos.Logger(logger), kratos.Server(hs, gs),
	}
	if r != nil {
		opts = append(opts, kratos.Registrar(r))
	}
	return kratos.New(opts...)
}
```

### 三、容器化部署：不做注册发现

线上是 **Docker Swarm 集群**部署，Swarm 自带①**服务发现**（直接用**服务名 + 端口**访问）和②**负载均衡**，所以容器化场景**完全不需要注册中心**。反过来说，注册发现的价值主要在**开发阶段**：多服务地址靠配置文件手改，改来改去极易出错。

| 部署形态 | 寻址方式 | 注册中心 | 负载均衡 |
|---|---|---|---|
| 本地 / 非容器化 | `discovery:///服务名`，地址由 etcd 返回 | etcd | 客户端侧（selector/balancer） |
| 容器化（Swarm / K8s） | `服务名:端口`（DNS） | 不需要 | 编排层自带 |

### 四、一份代码兼容两种形态

把"要不要注册中心"收敛成一个**可空的 `registry.Discovery`**：初始化时判断部署形态，容器化就置空，否则连 etcd；连接服务时按它是否为空切换 endpoint。

```go

type Discovery struct {
	reg registry.Discovery // nil 表示容器化部署，直接走服务名
}
func NewDiscovery(c *conf.Registry, logger log.Logger) (*Discovery, func(), error) {
	if c == nil || c.GetEtcd() == nil { // 容器化：不注册也不发现
		return &Discovery{}, func() {}, nil
	}
	client, err := clientv3.New(clientv3.Config{Endpoints: c.Etcd.Endpoints, DialTimeout: 3 * time.Second})
	if err != nil {
		return nil, nil, err
	}
	return &Discovery{reg: etcd.New(client)}, func() { _ = client.Close() }, nil
}
func (d *Discovery) ConnectService(ctx context.Context, name string, port int) (*grpc.ClientConn, error) {
	endpoint := fmt.Sprintf("discovery:///%s", name) // 非容器化：地址交给 etcd 发现
	if d.reg == nil {
		endpoint = fmt.Sprintf("%s:%d", name, port) // 容器化：服务名 + 端口直连
	}
	opts := []grpc.ClientOption{grpc.WithEndpoint(endpoint), grpc.WithMiddleware(metadata.Client())}
	if d.reg != nil {
		opts = append(opts, grpc.WithDiscovery(d.reg))
	}
	return grpc.DialInsecure(ctx, opts...)
}
```

> **容易踩的坑**：容器化分支不要返回 `(*etcd.Registry)(nil)`。那是**类型非空的 interface**，`kratos.Registrar(r)` 会当作注册中心存在并调它的方法，直接 panic；要么让接口是真的 `nil`，要么用 `if r != nil` 显式跳过 option。验证时可 `etcdctl get --prefix /kratos` 查看注册实例，里面**同时含 gRPC endpoint 和 HTTP endpoint**。

### 面试官会追问什么

- **实例下线后注册信息怎么清理？** → 注册时用 etcd 租约 + 心跳续约，进程退出或续约失败后 key 自动过期。
- **K8s 里到底要不要注册中心？** → 一般不要，Service/Headless Service 已提供 DNS + 负载均衡；需要跨集群、跨注册中心、按权重灰度路由时才引入。
- **客户端负载均衡和服务端负载均衡差在哪？** → 客户端侧省一跳、能感知实例健康但逻辑分散；服务端侧运维简单但多一跳。

---

## Q4. Kratos 服务间如何传递元数据并完成鉴权？

**来源**：`BV12qjA6aErF p=42` B站 Go 面试真题 · 时长 8分35秒
**考察意图**：考你对 Kratos **metadata 抽象**的理解——它是屏蔽 transport 差异的关键：HTTP 走 header、gRPC 走 metadata，业务代码却只用 `ctx` 一套 API。还要知道**默认前缀规范**（不在规范内的 header 拿不到），以及为什么内部服务鉴权用"固定 token"就够。

### 一、为什么用固定 token，而不是 JWT / 接口级权限

服务之间都是**内部调用**，不需要面向用户的细粒度接口授权，只要确认"调用方是不是自己人"。最简方案就够：每个服务配一个**固定 token**，调用方携带相同 token，服务端取出来与本地配置**比对**，一致则放行。

### 二、元数据的传递通道与默认前缀

| 维度 | gRPC | HTTP | 备注 |
|---|---|---|---|
| 载体 | metadata（**放在 ctx**） | **header** | 框架统一抽象 |
| 客户端写入 | `metadata.AppendToClientContext(ctx, k, v)` | 同左；直接发 HTTP 时手写 header | — |
| 服务端读取 | `metadata.FromServerContext(ctx)` → `md.Get(k)` | 同左 | **业务代码不区分 transport** |
| 客户端中间件 | `grpc.WithMiddleware(metadata.Client())` | `http.WithMiddleware(metadata.Client())` | 同一个包 |
| 服务端中间件 | `grpc.Middleware(metadata.Server())` | `http.Middleware(metadata.Server())` | 同一个包 |
| 鉴权失败返回 | 401 + reason（`errors.Unauthorized`） | 同左 | 错误码一致 |

**前缀规范必须留意**：只有带默认前缀（`x-md-global-` / `x-md-local-`）的 header 会被 Kratos metadata 中间件提取进 metadata，其余 header 拿不到，只能绕过框架从 transport 自己取——所以 token 的 key 用 `x-md-global-token` 这类规范名。

### 三、传递与读取

**客户端附加**（token 来自配置文件），**服务端取出比对**：

```go

// 客户端：把 token 放进 client 上下文，metadata.Client() 会把它带出去
ctx = metadata.AppendToClientContext(ctx, metadataTokenKey, uc.token)
reply, err := uc.paymentCli.CreatePayment(ctx, req)

// 服务端
md, ok := metadata.FromServerContext(ctx)
if !ok {
	return nil, errors.Unauthorized("MISSING_TOKEN", "no metadata in context")
}
if md.Get(metadataTokenKey) != uc.token {
	return nil, errors.Unauthorized("INVALID_TOKEN", "token mismatch")
}
```

### 四、服务端鉴权中间件（含回调白名单）

**微信支付回调是 HTTP 打进来的，渠道方不会带我们的 token，必须放行**；其余请求无 token 一律拒绝：

```go

const (
	metadataTokenKey = "x-md-global-token"
	healthCheckOp    = "/grpc.health.v1.Health/Watch" // 健康检查走流式，详见 Q5
)

func tokenAuth(expect string) middleware.Middleware {
	return func(handler middleware.Handler) middleware.Handler {
		return func(ctx context.Context, req any) (any, error) {
			if tr, ok := transport.FromServerContext(ctx); ok {
				if tr.Operation() == healthCheckOp {
					return handler(ctx, req) // 心跳不带 token，放行
				}
				if tr.Kind() == transport.KindHTTP && strings.HasSuffix(tr.Operation(), "/PayNotify") {
					return handler(ctx, req) // 第三方支付回调放行
				}
			}
			md, ok := metadata.FromServerContext(ctx)
			if !ok || md.Get(metadataTokenKey) == "" {
				return nil, errors.Unauthorized("MISSING_TOKEN", "missing service token")
			}
			if md.Get(metadataTokenKey) != expect {
				return nil, errors.Unauthorized("INVALID_TOKEN", "invalid service token")
			}
			return handler(ctx, req)
		}
	}
}
```

不走代码调用时（curl / 网关 / 其他语言服务），必须在 header 里按**同样的前缀和格式**手工写，否则 metadata 中间件提不出来：

```bash

curl -X POST http://payment.service:8001/api.payment.v1.PaymentService/CreatePayment \
  -H 'Content-Type: application/json' -H 'x-md-global-token: <与支付服务配置一致的 token>' \
  -d '{"order_no":"20260101"}'
```

### 面试官会追问什么

- **固定 token 有什么风险？** → 无法区分调用方身份、无法限权、轮换要全量重启。内部够用；涉及外部或跨团队应升级为 mTLS / JWT / 网格身份。
- **为什么用 `x-md-global-` 而不是自定义 `x-token`？** → 自定义 header 不在默认前缀内，`FromServerContext` 取不到，得自己从 transport 抠，白写一堆代码。
- **metadata 和 `context.WithValue` 的区别？** → metadata 是**可跨进程传输**的键值集合，会随请求发给对端；`WithValue` 只在单进程内可见。

---

## Q5. 服务间调用报"身份认证失败"，该怎么定位？

**来源**：`BV12qjA6aErF p=40` B站 Go 面试真题 · 时长 5分18秒
**考察意图**：考**排查方法论** + 对框架默认行为细节的掌握。业务代码方向全对、日志全对，但就是鉴权失败——分水岭是"敢不敢怀疑自己的默认假设"。知道 **gRPC 健康检查走的是流式请求（server-streaming），不走一元（unary）链路**，是这题的核心知识点。

### 一、现象与先入为主的假设

定时任务服务调用获取签名接口，报**身份认证失败**；聚合服务侧的报错是"**连接是活动的，但收到健康检查的 RPC 报错——身份认证失败**"。按代码看调用方每个请求都附加了 token，被调服务也实现了健康检查，理论上不该有问题，但服务端上下文 metadata 里**就是没有 token**。

团队此前一直用**原生 gRPC** 开发，习惯只关注**一元调用**和一元拦截器，于是默认"健康检查也是一元请求，会走一元拦截器"。

### 二、根因：健康检查走的是流式请求

1. 客户端校验连接可用性时先发**健康检查（心跳）**，它**走流式拦截器**，不走一元拦截器；
2. 健康检查请求**不携带任何业务 metadata**；
3. Kratos 的 gRPC Server 会把中间件同时套在 unary 和 stream 两条链路上，于是鉴权中间件拿不到 token；
4. 健康检查返回鉴权失败 → 连接被判为不健康 → **后续正常请求也跟着失败**。

### 三、修复：让健康检查绕过鉴权

判断"是不是健康检查请求"，是就直接放行；**只有非心跳请求才走鉴权逻辑**：

```go

const healthCheckOp = "/grpc.health.v1.Health/Watch" // 健康检查是 server-streaming

func tokenAuth(expect string, logger log.Logger) middleware.Middleware {
	return func(handler middleware.Handler) middleware.Handler {
		return func(ctx context.Context, req any) (any, error) {
			if tr, ok := transport.FromServerContext(ctx); ok {
				// 不确定方法名叫什么就打印出来，拿到后加进白名单
				log.NewHelper(logger).Infof("operation=%s kind=%s", tr.Operation(), tr.Kind())
				if tr.Operation() == healthCheckOp {
					return handler(ctx, req) // 心跳放行，不做鉴权
				}
			}
			// ... 其余请求照常校验 token
			return handler(ctx, req)
		}
	}
}
```

改完重跑，请求正常返回，附加的数据也正常传过去了。

> 复盘一句话：**Kratos 在微服务之间建立的是流式通道，至少心跳是通过流式请求发送的**；鉴权中间件必须区分"心跳请求"和"业务请求"，心跳不做鉴权。

### 面试官会追问什么

- **放行健康检查会不会造成安全漏洞？** → 只放行固定的健康检查 operation（且只返回服务状态），不放行任何业务方法；更严格的做法是健康检查只监听内网/管理端口。
- **怎么避免同类问题再犯？** → 把 health、reflection 等"系统级 RPC"白名单在中间件里集中维护，不要散落各处。
- **unary 和 stream 中间件的差异？** → stream 的 handler 是 `func(stream) error`，中间件内部调用语义不同；用同一份业务中间件时要意识到两条链路都会进。

---

## Q6. 如何获取 HTTP 的原始请求，用于特定场景？

**来源**：`BV12qjA6aErF p=41` B站 Go 面试真题 · 时长 6分26秒
**考察意图**：① 是否知道 Kratos 把 HTTP 请求封装成 transport 放进 `ctx`，能否正确**类型断言**取出 `*http.Request`；② 是否明白这招**只在 HTTP transport 下成立**，gRPC 请求断言必然失败；③ 判断力——什么场景**应该**用原始请求（能少一层解析/序列化），什么场景该老实传 message。

### 一、写法：取 transport → 断言 → 拿 request

```go

import (
	"github.com/go-kratos/kratos/v2/errors"
	"github.com/go-kratos/kratos/v2/transport"
	transhttp "github.com/go-kratos/kratos/v2/transport/http"
)

func (s *PaymentService) PayNotify(ctx context.Context, req *v1.PayNotifyRequest) (*v1.PayNotifyReply, error) {
	tr, ok := transport.FromServerContext(ctx) // 1. 取出当前请求的 transport
	if !ok {
		return nil, errors.InternalServer("TRANSPORT", "transport not found")
	}
	ht, ok := tr.(*transhttp.Transporter) // 2. 断言成 HTTP transport
	if !ok {
		return nil, errors.InternalServer("TRANSPORT", "not an http request")
	}
	raw := ht.Request() // 3. *http.Request，原始请求
	return s.payUC.HandleNotify(ctx, raw)
}
```

**前提**：请求确实是通过 HTTP 发过来的，断言才会成功；gRPC 入口这么写必然失败。

### 二、为什么放着 message 不用，非要原始请求

支付服务要接入**多个支付渠道**（微信、支付宝等）：支付完成后渠道会**回调我们的 HTTP 接口**推送结果，我们据此更新订单状态。渠道方只支持 HTTP，所以 Kratos 里同时启了 `http.Server` 和 `grpc.Server` 两种 transport——对外回调走 HTTP，内部调用走 gRPC。

回调参数**用 message 也能取到**，但**微信支付 Go SDK 的回调解析接口要求传入 `*http.Request`**：

```go

// 微信支付 SDK 的契约（示意）：必须拿到原始请求
// ParseNotifyRequest(ctx context.Context, request *http.Request, content any) (*notify.Request, error)
//   内部做两件事：① 验签，判断参数有没有被篡改；② 用平台证书 / APIv3 密钥解密回调密文，得到明文结果
```

| 做法 | 流程 | 问题 |
|---|---|---|
| 解进 message 再重新封装 | 解 body → 塞进 proto → 再拼回 request | 多一层解析 + 一次重组，**容易出错**，原始字节/签名还可能对不上 |
| 直接透传原始请求 | 取 transport → 断言 → 交给 SDK | 少一层转换，省事且不易出错 ✅ |

若坚持走 message 路线，就要把回调里**所有字段**都在 proto 里定义一遍、全部接收过来，再原样拼回去——纯重复劳动。参数只是"过路"的场景，直接传原始请求更合理。

### 面试官会追问什么

- **gRPC 入口能这么写吗？** → 不能，断言会失败，应返回明确错误，不能忽略 `ok`。
- **直接读 body 会不会影响 Kratos 自己的参数解析？** → 会。`Request.Body` 是流，读一次就空了；要么 `req.Body = io.NopCloser(bytes.NewReader(data))` 复原，要么让 SDK 先读、业务不再重复解析。
- **什么场景该用 message、什么该用原始请求？** → 需要框架校验、字段映射、跨协议统一时用 message；把请求**整体透传给第三方 SDK**（验签/解密类）时用原始请求。

---

## Q7. HTTP 请求里的 JSON 对象，如何准确转换成 Protobuf message？

**来源**：`BV12qjA6aErF p=43` B站 Go 面试真题 · 时长 3分48秒
**考察意图**：考 `google.protobuf.Any` 的 JSON 表示规范——**`@type` 字段**（值 = 命名空间 + message 名）。这是 protojson 与普通 JSON 库最不一样的地方，也是"参数随渠道变化、类型不固定"的标准解法；顺带考是否清楚 Kratos 的 HTTP transport 对 `proto.Message` 走的是 protojson 而非标准库 `encoding/json`。

### 一、场景：参数类型随渠道变化

支付服务选择支付渠道时，每个渠道需要的**特有参数不一样**，`pay_info` 不能固定成某个具体类型，于是用 `google.protobuf.Any` 做"类型盒子"。

| 渠道 | 特有参数 | 承载 message |
|---|---|---|
| 微信 Native | IP 地址等 | `WechatNativePayInfo` |
| 微信 JSAPI | `openid` | `WechatJSAPIPayInfo` |
| H5 | H5 场景信息 | `WechatH5PayInfo` |
| 支付宝 | 买家信息等 | `AlipayPayInfo` |

```proto

import "google/protobuf/any.proto";

message PayRequest {
  string order_no = 1;
  string channel  = 2;              // 渠道标识
  google.protobuf.Any pay_info = 3; // 各渠道特有参数，类型不固定
}
message WechatJSAPIPayInfo { string open_id = 1; }
```

### 二、`@type` 是准确转换的关键

用 protojson 规范传 JSON 时，`Any` 必须带 **`@type`**，值 = **命名空间（package）+ message 名**：

```json

{
  "order_no": "202601010001",
  "channel": "WX_JSAPI",
  "pay_info": {
    "@type": "type.googleapis.com/api.payment.v1.WechatJSAPIPayInfo",
    "open_id": "oXXXXXXXXXXXXXXXXX"
  }
}
```

- 可省略 `type.googleapis.com/` 前缀，只写完整的 `api.payment.v1.WechatJSAPIPayInfo`；
- **命名空间（package）不能省**，只写 `WechatJSAPIPayInfo` 会因无法唯一确定类型而报 `unable to resolve`。

Kratos 的 HTTP transport 对 `proto.Message` 用的就是 **protojson**，所以这套规范在路由上直接生效，无需额外配置。

### 三、取值与返回（gRPC 侧不需要 `@type`）

**取值**——按渠道把 Any 还原成具体 message；**返回**——返回值同样是 Any，protojson 序列化时会**自动补 `@type`**，调用方据此判断按哪个类型解析。gRPC 侧 `Any` 是二进制（type_url + value），不需要也不该手写 `@type`。

```go

// 取出 Any 里的具体类型
var detail v1.WechatJSAPIPayInfo
if err := req.GetPayInfo().UnmarshalTo(&detail); err != nil {
	return nil, errors.BadRequest("PAY_INFO", err.Error())
}
openID := detail.GetOpenId()

// 反向打包
reply, err := anypb.New(&v1.WechatJSAPIPayInfo{OpenId: openID})

// 手动解析 JSON 时：对 proto.Message 要用 protojson，而不是标准库 json.Unmarshal
req := &v1.PayRequest{}
if err := protojson.Unmarshal(body, req); err != nil {
	return nil, err
}
```

### 面试官会追问什么

- **`@type` 里的类型没编进二进制会怎样？** → 解析失败（`unable to resolve`）；解决办法是显式 import 该类型的 Go 包触发注册，或用 `protojson.UnmarshalOptions{Resolver: ...}` 提供自定义解析器。
- **`Any` 和 oneof 怎么选？** → 分支固定且都在同一 proto 内定义用 **oneof**（类型安全、无额外解析）；子类型分散在多个服务、需要动态扩展时才用 **Any**。
- **为什么不能用标准库 `encoding/json`？** → 标准库不认 `@type`，会把 `Any` 当空对象；protojson 还有字段名 camelCase、枚举名、64 位整数转字符串等规范，标准库都不遵守。

---
