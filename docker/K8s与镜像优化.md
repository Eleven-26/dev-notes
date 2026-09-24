# K8s 与镜像优化

> Docker 启用 IPv6 联网、Dockerfile 里换国内镜像源、Helm 像 apt 一样管理 Kubernetes 应用
>
> 内容整理自大厂 Go 后端面试真题视频，参考资料与原始素材见 [素材清单](../interview/素材清单.md)；本轮补充（镜像瘦身、构建缓存、imagePullPolicy 与 QoS、三探针与优雅关闭、滚动与回滚）参考《Docker 技术入门与实战》（第 3 版，杨保华 / 戴王剑 / 曹亚仑）。

---

## Q1. Docker 怎样启用 IPv6 联网访问？

**来源**：`BV12qjA6aErF p=9` B站 Go 面试真题 · 时长 3分25秒
**考察意图**：考的是**能不能自己动手把 Docker 的网络配置改对**——
配置文件在哪、改哪几项、怎么让它生效、怎么验证。
顺带考一个常见认知：**Docker 默认支持 IPv6，但默认是关着的。**

### 一、结论先行

Docker 本身**支持 IPv6，但默认不启用**。启用要三件事：

1. **宿主机内核没禁用 IPv6**（前提条件）；
2. 改 **Docker 守护进程配置 `daemon.json`**（视频的主体内容）；
3. **重载 + 重启 Docker**，再验证容器确实拿到了 IPv6 地址。

### 二、内核参数：先确认宿主机支持 IPv6

```bash

cat /proc/sys/net/ipv6/conf/all/disable_ipv6   # 应该是 0
# 如果是 1，说明内核层把 IPv6 关了，Docker 怎么配都没用
sysctl -w net.ipv6.conf.all.disable_ipv6=0
```

### 三、改 `daemon.json`（核心步骤）

配置文件位置：**`/etc/docker/daemon.json`**。
**如果这个文件不存在，就自己创建**（视频里强调的正是这一步）。

```json

{
  "ipv6": true,
  "fixed-cidr-v6": "2001:db8:1::/64"
}
```

| 配置项 | 作用 |
|---|---|
| **`"ipv6": true`** | 打开 Docker 的 IPv6 联网能力 |
| **`"fixed-cidr-v6"`** | 给 Docker 指定一个默认的 IPv6 网段，**容器才能动态分到 IPv6 地址** |

> ⚠️ 生产上请把 `fixed-cidr-v6` 换成**自己实际拥有的 IPv6 网段**。
> `2001:db8::/32` 是 RFC 3849 专门保留给文档示例的地址段，不能真的拿来用。

### 四、生效与验证

```bash

systemctl daemon-reload     # 重新加载配置
systemctl restart docker    # 重启 Docker（容器会重建，注意影响）

docker start nginx

# IPv4 回环
curl http://127.0.0.1:<port>/

# IPv6 回环（URL 里的 IPv6 地址必须用方括号包起来）
curl -g "http://[::1]:<port>/"
```

用 `ifconfig` 看网卡，会同时看到 IPv4 地址和 IPv6 地址。直接用 IPv6 地址访问时：

> **⚠️ 用链路本地地址（`fe80::/10` 开头）访问时，必须带上网卡作用域，
> 也就是要指定网络接口**（如 `fe80::xxxx%eth0`），否则访问不通。
> 视频里"访问不了 → 指定网络接口后就能访问"演示的就是这个点。
> 浏览器访问同理：`http://[IPv6 地址]:端口/`。

**反证**：把容器停掉（`docker stop nginx`）之后再访问就访问不到了——
说明刚才访问到的确实是这个容器。

### 五、补充：自定义网络与运行参数

默认 `bridge` 网络靠 `daemon.json` 里的 `ipv6` + `fixed-cidr-v6` 就够了；
自定义网络需要显式开启：

```bash

# 创建带 IPv6 子网的自定义网络
docker network create --ipv6 --subnet=fd00:1::/64 mynet6
# 容器加入该网络，即可拿到 IPv6 地址
docker run -d --network=mynet6 --name nginx6 nginx
```

### 面试官会追问什么

- **为什么 `fixed-cidr-v6` 是必须的？** → 不给 Docker 一个 IPv6 网段，
  它就没法给容器分配地址，`"ipv6": true` 形同虚设。
- **只开 IPv6 的容器怎么访问 IPv4 外网？** → 需要 **NAT64 / DNS64** 做协议与地址转换，
  这类能力一般由网络侧统一提供，不是 Docker 自己能解决的。
- **`daemon-reload` 和 `restart docker` 的区别？** → `daemon-reload` 只是让 systemd
  重读 unit 文件；**改了 `daemon.json` 必须 `restart docker` 才生效**（重启会重建容器）。

---


---

## Q2. Dockerfile 构建镜像时如何修改基础镜像的镜像源地址？

**来源**：`BV12qjA6aErF p=10` B站 Go 面试真题 · 时长 5分18秒
**考察意图**：这是一道**踩过坑才知道**的题。考两件事：
① 你知不知道**构建镜像的环境和宿主机是隔离的**，宿主机配好的加速在构建时是无效的；
② 遇到"基础镜像里压根没有 `sources.list`"这种情况，你会怎么处理。

### 一、背景：境外能构建，搬回境内就构建不动

视频里的场景很典型：要部署一个支付服务，Dockerfile 是微服务脚手架生成的模板，
只需要改编译镜像、编译镜像版本、配置文件和 cmd。在**境外服务器**上构建一切正常，
**搬到境内服务器**后卡住了——因为 Dockerfile 里有 `apt install` 这一步，
走的还是**境外的软件源**。

> 这和 Go 编译要设 `GOPROXY` 国内代理是**一模一样的逻辑**：
> 容器内的网络环境，需要单独为容器内配置。

### 二、两种改法

#### 情况一：基础镜像有 `/etc/apt/sources.list`（Debian / Ubuntu 常见）

```dockerfile

RUN sed -i 's@deb.debian.org@mirrors.aliyun.com@g; s@security.debian.org@mirrors.aliyun.com@g' /etc/apt/sources.list \
    && apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates tzdata \
    && rm -rf /var/lib/apt/lists/*
```

用 `sed -i` 把文件里**每一行的源地址**替换成国内地址（**阿里云镜像源**是最常用的之一），
替换后再 `apt-get update`，装包就正常了。

#### 情况二：基础镜像里**根本没有这个文件**（视频遇到的坑）

视频里用 `sed` 替换时才发现：**这个镜像的 `/etc/apt` 下面没有 `sources.list`**，
自然替换失败。先验证一下：

```bash

# 交互式跑起来看一眼（用完即删）
docker run --rm -it --name test <镜像名> ls -l /etc/apt/
# 确认确实没有 sources.list / sources.list.d
```

**解法：文件不存在，就自己创建。**

```dockerfile

# 目录 / 文件不存在就直接写进去，然后再装包
RUN mkdir -p /etc/apt \
    && echo "deb https://mirrors.aliyun.com/debian bookworm main" > /etc/apt/sources.list \
    && apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates
```

> 改的位置是 **Dockerfile 里真正执行安装的那个阶段**——多阶段构建时
> **不要改错到运行阶段**：运行阶段根本不需要 apt，改了既没用又白增镜像层。

> 补充：新版 Debian（≥ 12）和 Ubuntu（≥ 24.04）用的是 deb822 格式，
> 源文件在 `/etc/apt/sources.list.d/debian.sources` / `ubuntu.sources`；
> CentOS / Rocky 则改 `/etc/yum.repos.d/*.repo` 里的 `baseurl`。

### 三、⚠️ 面试重点：这和 `registry mirror` 完全是两回事

很多人会把两者混为一谈，这里必须分清楚：

| | **`registry-mirrors`（镜像加速）** | **换 apt / yum 源（本 Q）** |
|---|---|---|
| 配在哪 | **宿主机** `/etc/docker/daemon.json` | **镜像内部**（Dockerfile）的 `/etc/apt/sources.list` 等 |
| 加速的是什么 | **`docker pull` 拉取镜像层** | **容器内 `apt-get install` 下载软件包** |
| 生效时机 | 拉镜像时（守护进程层面） | 构建 / 运行容器时（容器内部） |
| 能加速装包吗 | ❌ 不能 | — |
| 能加速拉镜像吗 | — | ❌ 不能 |

```json

// /etc/docker/daemon.json —— 这配的是 docker pull 的加速，和装包速度无关
{
  "registry-mirrors": ["https://<你的加速地址>.mirror.aliyuncs.com"]
}
```

一句话记：**mirror 管"拉镜像"，apt 源管"装软件"，两个加速要分别配。**

### 面试官会追问什么

- **为什么宿主机配了代理，构建镜像还是慢？** → **容器是相对独立的环境**，
  宿主机的东西它不一定继承；构建时必须把它当成一台全新的虚拟机，
  该配的加速要在 Dockerfile 里单独配。
- **怎么确认镜像里到底有没有源文件？** → `docker run --rm -it <镜像> ls -l /etc/apt/`，
  用完即删，不影响本地环境。
- **换源之后镜像体积会不会变大？** → 顺手 **`rm -rf /var/lib/apt/lists/*`** 清掉包索引缓存，
  并把多个 `RUN` 合并以减少镜像层数。

---


---

## Q3. Helm 是什么？它怎样"像 apt 一样"管理 Kubernetes 应用？

**来源**：`BV12qjA6aErF p=11` B站 Go 面试真题 · 时长 3分17秒
**考察意图**：考的是**K8s 的工程化能力**。会写 YAML 只是入门，面试官想确认你知不知道：
**怎么把一堆 YAML 打包、版本化、参数化，并且一条命令装上、一条命令卸掉**——
也就是"包管理"这件事在 K8s 里是怎么落地的。

### 一、先记住这个类比

| | 传统 Linux | Kubernetes |
|---|---|---|
| 包管理器 | Ubuntu 的 **APT**、CentOS 的 **YUM** | **Helm** |
| 软件包 | `.deb` / `.rpm` | **Chart** |
| 仓库 | apt / yum 仓库 | **Chart 仓库**，也可以是 **OCI Registry** |
| 安装一次 | 装出来的一份实例 | **Release**（一次安装 = 一个 Release） |
| 参数文件 | — | **`values.yaml`** |
| 卸载 | `apt remove` / `yum remove` | `helm uninstall` |

> 这就是标题里"**像 apt 一样管理 K8s 应用**"的含义——Helm 是 **Kubernetes 的包管理器**
> （该项目在 GitHub 上拿到 **27k+** star）。大量开源项目都会附带 Helm 安装方式：
> 直接给你一条 `helm install` 命令，就能把整套应用部署进集群。

### 二、四个核心概念

| 概念 | 含义 |
|---|---|
| **Chart** | 应用包：一组**带 Go 模板语法的 K8s YAML** + 默认值 `values.yaml` + `Chart.yaml`（元信息） |
| **values.yaml** | 模板的**参数**。模板里的 `{{ .Values.replicaCount }}` 就是从这里取值的 |
| **模板渲染（render）** | Helm 把 Chart + values **渲染成最终的 K8s YAML**，再提交给 API Server |
| **Release** | Chart **在集群里的一次安装实例**。同一个 Chart 可以装很多次，每次一个 Release，名字不同、互不干扰 |

> 关键理解：**Chart 是"源码"，Release 是"运行起来的实例"，`values.yaml` 是"编译参数"。**
> 改参数不必改 YAML 本身，改 values 重新渲染即可。

### 三、常用命令（含视频里的实战流程）

视频是**三节点 K8s 集群 + 本地 OCI Registry**，从 Registry 拉 Chart 部署：

```bash

# 0. 安装 Helm（官方脚本）
curl https://raw.githubusercontent.com/helm/helm/main/scripts/get-helm-3 | bash

# 1. 先确认集群里啥也没有
kubectl get pod -A

# 2. 从本地 OCI Registry 安装（Chart 就是一个 OCI 制品）
helm install my-nginx oci://registry.example.com/charts/nginx \
  --version 0.1.0 \
  --plain-http          # ★ 本地 Registry 没启用 HTTPS，必须走 HTTP
```

> ⚠️ 视频里特别说明：本地 OCI Registry **没有做 HTTPS**，
> 所以要**显式指定用 HTTP 方式**（`--plain-http`），否则拉取会失败。

```bash

# 3. 看装出来什么了
helm list
kubectl get pod,deploy,svc

# 4. 卸载（连带该 Release 创建的所有资源一起删）
helm uninstall my-nginx
```

**一个很重要的点**：安装完会创建**哪些资源，完全取决于 Chart 里定义了什么**——
Chart 里有 `Deployment` 就有 Deployment，有 `Service` 就有 Service。
视频里那个 Nginx Chart 就同时装出了 Deployment 和 Service；
**Chart 里没定义过的资源，装完自然不会有。**

### 四、除了 install，还要会这几条

```bash

helm repo add bitnami https://charts.bitnami.com/bitnami   # 加仓库
helm repo update                                           # 更新索引
helm search repo nginx                                     # 搜 Chart
helm show values bitnami/nginx                             # 看默认值（改哪些参数先看这个）
helm install nginx bitnami/nginx -f my-values.yaml         # 用自定义 values 安装
helm upgrade nginx bitnami/nginx -f my-values.yaml         # 升级
helm rollback nginx 1                                      # 回滚到指定版本（Release 有版本历史）
helm template nginx bitnami/nginx                          # 只渲染不部署（排查用）
helm push mychart-0.1.0.tgz oci://registry.example.com/charts   # 推 Chart 到 OCI Registry
```

### 五、国内网络环境下的工程实践（三题串起来看）

| 痛点 | 手段 | 落在哪一层 |
|---|---|---|
| `docker pull` 拉镜像慢 | **`registry-mirrors` 镜像加速**（Q2 对比表左列） | 宿主机 **dockerd** |
| 构建镜像时 `apt / yum` 装包慢 | **换国内软件源**（Q2） | **镜像内部** |
| Chart / 镜像在公网拉不动，或要控版本 | **自建 OCI Registry + Helm**（Q3 实战） | **集群侧 / 制品库** |

> 串起来就是一条完整链路：**镜像从哪来（registry mirror）→ 构建时依赖从哪来（apt / yum 源）
> → 应用制品怎么管（Helm + 私有 OCI Registry）**。
> 面试时把这三层讲清楚，比只说一句"我会用 Helm"高一个层次。

### 六、values 分层、子 chart 依赖与 hooks（用 Helm 落地时的三个坑）

| 主题 | 正确做法 | 坑 |
|---|---|---|
| **values 分层** | Chart 自带 `values.yaml` 只放**默认值**；环境差异放**独立的 values 文件**（`-f prod-values.yaml`），版本/镜像 tag 由 CI 注入 | 直接改 Chart 里的 `values.yaml` → 升级/拉取上游 Chart 时全丢 |
| **`--set` 的坑** | 少量、临时覆盖用 `--set`；稳定的配置一律落到文件里 | ① `--set` 的优先级高于 `-f`，容易"我明明改了文件怎么没生效"；② **`--set` 的值都是字符串**，数字/布尔要写成 `--set replicaCount=3 --set autoscaling.enabled=true` 这种显式形式；③ 特殊字符（逗号、点）要转义或改用 `--set-string`/文件；④ **命令行里的值会进 shell history 与 Release 的元数据**，别拿它传密码 |
| **子 chart 依赖** | `Chart.yaml` 里**锁死依赖 Chart 的版本**（`version` + `appVersion` 语义分开），`helm dependency update` 后把 `Chart.lock` **提交进仓库** | 只写 `latest`/范围 → 某天 `helm dependency update` 拉到新版本，集群里多出来的资源没人解释得清 |
| **hooks** | 需要"装之前/升级之前跑一次"的动作（**DB migration**、建表、预热 job）用 `helm hook` 标注 | migration 放普通 Deployment/Job 里 → 每次 `helm upgrade` 都可能重复执行；放 hook 里才能控制时机与"失败就中断这次安装" |

> ⚠️ hooks 的代价：**它跑在 Helm 客户端的语义里，不在 GitOps 控制器的期望状态里**。
> 用 ArgoCD/Flux 这类声明式方案时，migration 通常改为"同步阶段的前置 Job"（Sync Wave / pre-sync hook），
> 而不是随手一个 hook —— 否则"仓库里的 YAML"就不再是完整事实了。

### 面试官会追问什么

- **Helm 2 和 Helm 3 最大的区别？** → Helm 2 有一个服务端组件 **Tiller**
  （部署在集群里、需要配 RBAC、权限过大）；
  **Helm 3 移除了 Tiller**，直接用本地 `kubeconfig` 的权限访问集群，更安全也更简单。
- **`helm install` 和 `kubectl apply -f` 的区别？** → `apply` 只管"把这份 YAML 提交上去"，
  没有版本、没有回滚、没有参数化；`helm install` 多了 **Release 概念、版本历史、
  参数渲染和回滚能力**。
- **OCI Registry 和传统 Chart 仓库的区别？** → 传统方式是 `helm repo add` 一个 `index.yaml` 索引；
  **OCI 方式把 Chart 当标准容器制品推拉**，可以直接复用已有的 registry 与鉴权体系，
  不用再额外维护索引文件。
- **Chart 装坏了怎么快速恢复？** → `helm rollback <release> <revision>`，
  这正是"包管理器"相比裸 `kubectl apply` 最大的工程价值。

---

## Q4. 镜像瘦身怎么做才有效？（Go / Java 各自的办法）⭐

**考察意图**：区分"知道 alpine 小"和"知道**为什么**小、变小要付出什么"。
主线：**能砍的依次是 编译期依赖 → 运行时库 → 无关系统文件**，每砍一刀都要说清"排障时怎么办"。

### 一、Go：静态编译才有资格用最小基座

```dockerfile

FROM golang:1.24 AS build
WORKDIR /src
COPY go.mod go.sum ./
RUN go mod download
COPY . .
RUN CGO_ENABLED=0 GOOS=linux go build -trimpath -ldflags "-s -w" -o /out/app ./cmd/app

FROM scratch                       # 静态二进制可以什么都不带
COPY --from=build /out/app /app
COPY --from=build /etc/ssl/certs/ca-certificates.crt /etc/ssl/certs/
ENTRYPOINT ["/app"]
```

| 要点 | 为什么 |
|---|---|
| **`CGO_ENABLED=0`** | 只要引了 CGO，二进制就动态链接 **libc** → 运行时镜像必须有**同一个** libc；glibc 编的扔进 alpine（musl）会直接加载失败。这才是"`FROM scratch` 跑不起来"的根因 |
| 什么时候**不能**关 CGO | 用到必须 CGO 的东西（`os/user` 的 cgo 路径、部分 SQLite 驱动、依赖 NSS 的行为）→ 要么保留 Debian slim 基座，要么改纯 Go 实现 |
| `-ldflags "-s -w"` / `-trimpath` | 去符号表与 DWARF、去本机路径；代价是**二进制里的符号信息变差**，线上靠 pprof 与日志而不是靠符号（pprof 见 [Linux 性能排查](../linux/性能排查.md)） |
| `scratch` 还欠什么 | 没 CA（调 HTTPS 报 unknown authority）、没时区文件、没 shell —— 这是"瘦身后半夜被叫起来"的两大经典原因 |

### 二、Java：分层提取优先，jlink 是进阶

| 手段 | 适用 | 代价 |
|---|---|---|
| **分层提取**（jar 按 依赖 / 快照依赖 / 资源 / 自身代码 拆层，Dockerfile 逐层 `COPY`） | 依赖基本不变、只有业务代码变 → 每次只重建最薄那层 | 需要支持分层打出的形态（Spring Boot 这类 `layertools`）；uber jar 享受不到 |
| **jlink 自定义运行时** | 应用**模块化**、能列清依赖哪些 `java.*` 模块 | 反射/动态代理多的框架容易漏模块，**运行期才报错** → 必须完整跑集成测试 |
| 换更小的 JDK 基座（slim / alpine 类） | 省掉一大部分体积（完整发行版里大量运行时用不到的文件） | JVM 在 musl 上有过兼容性问题，上生产前压测 |
| AppCDS / AOT | 主要打**启动速度**（滚动发布、扩容冷启动） | 体积收益有限、构建流程变复杂 |

### 三、基础镜像怎么选 ⭐

| 基座 | 好处 | 代价（面试就考这个） |
|---|---|---|
| **`*-alpine`** | 很小，且有 `apk` 能进去装工具 | 用 **musl**：① CGO 编的二进制不兼容；② resolver 与 glibc 行为不同——K8s 里 `resolv.conf` 的 search 域很长、`options`（如 ndots）需要特殊处理时，**偶发解析变慢/首轮超时**多发生在 musl 上；③ 少数发行包只有 glibc 版 |
| **`*-slim`**（Debian slim） | **glibc，兼容性最好**，已砍文档/locale | 还能 `apt-get`，所以还是会被装上东西 |
| **distroless** | **没 shell、没包管理器** → 攻击面和"顺手装个东西"同时归零 | **`kubectl exec` 进不去**：要靠 `kubectl debug` 临时容器（挂同一进程 namespace 看 `/proc`）或 debug 变体 |
| **`scratch`** | Go/静态二进制专用，最小 | CA、tzdata、DNS 全得自己拷 |

> 默认选 **slim**；**先把"多阶段 + `.dockerignore` + 层顺序"三件免费的事做完**，这三件通常比换基座更划算。
> "把不常变的放前面"省的是**两件事**：构建时间（层 digest 不变就命中缓存）与**分发时间**
> （节点已有这些层 → 只拉薄层）。K8s 里后者直接决定 Pod 从 Pending 到 Running 的时间。
> 瘦完的冒烟测试四项：**DNS 解析、HTTPS 出网、时区、`docker stop` 是不是 137**（信号处理）。

---

## Q5. 构建缓存：CI 上为什么每次从零构建 ⭐

层缓存存在**执行构建的那个 daemon/构建器本地**。CI 的 job 容器每次全新 → 本地缓存必为空。
所以缓存只能搬到共享存储：

| 做法 | 说明 | 注意 |
|---|---|---|
| **registry 缓存**（`--cache-from` 指向旧镜像层 / `--cache-to` 把缓存推回仓库） | 跨 Runner、跨机器都能复用 | 缓存 tag 与发布 tag 分开；缓存会一直长大，要配套清理 |
| 固定复用同一台 Runner / 把构建器缓存目录挂出来 | 命中率高、不额外占仓库 | 机器坏了就全丢；并发 job 抢同一份缓存要限流 |
| 依赖级缓存（包管理器 cache 目录复用） | 对"依赖层重建"最有效 | 并发写与容量要管 |

> 参数名与"是否需要构建器把缓存元数据内联进镜像"这类开关，**以所用 BuildKit / buildx 版本文档为准**；
> 面试把"CI 上缓存为什么会失效"讲对就够。

**Go 依赖层缓存的唯一正确顺序**：先 `COPY go.mod go.sum ./` → `RUN go mod download` → 再 `COPY . .` → build。

| 情况 | 结果 |
|---|---|
| 只改业务代码 | 前两层命中缓存，**构建时间几乎只剩编译** |
| `go.mod` / `go.sum` 变了 | 依赖层起全部重建（这是**正确**的失效，别指望绕过） |
| 上来就 `COPY . .` 再 `go mod download` | 每次提交缓存全废 —— **最常见的错误写法** |
| Go 版本 / 构建 `ARG` 变了 | 链条从基础镜像就断，等于重来 |

> ⚠️ 两点补充：别把 `GOMODCACHE` 带进**最终**镜像（用多阶段，缓存留在构建阶段）；
> 私有仓库依赖的凭证要在**依赖层**就配好，否则缓存命中时看着正常、一失效就构建不出来。

---

## Q6. K8s 侧与镜像相关的正确姿势 ⭐

### 一、`imagePullPolicy` 与"节点上镜像漂移" ⭐

`Always` 每次启动都去仓库确认；`IfNotPresent` 本地有这个 tag 就直接用、**不再问仓库**；`Never` 只用已有。
**默认值取决于 tag**：`:latest` → `Always`，固定版本 tag → `IfNotPresent`。于是：

```
同一个 tag（v1 / latest）被覆盖推送
  → 老节点本地已有旧内容，IfNotPresent 不再拉 → 跑的是旧镜像
  → 新节点拉到新内容 → 同一个 Deployment 的副本行为不一致
  → Pod 一重启就"换了一个镜像"
```

解法只有两条，推荐第一条：**镜像不可变（用 commit SHA 做 tag）**；或退一步用 `@sha256:<digest>` 引用。
`Always` + 可变 tag 是最糟的组合（tag 策略见 [CI-CD.md Q2](CI-CD.md)）。

### 二、拉取凭证与冷启动

| 主题 | 做法与坑 |
|---|---|
| 私有仓库凭证 | `kubectl create secret docker-registry` + Pod 的 `imagePullSecrets`（容易漏，可配到 default ServiceAccount）；节点级凭证粒度太粗、换仓库要动节点；托管集群的免密组件要先确认**作用范围与失效策略**（跨账号/跨地域最容易踩） |
| 拉取失败怎么分 | 事件里 `ErrImagePull`（鉴权/网络）还是 `ImagePullBackOff`（反复失败，多为 tag 不存在或限速）→ 再在节点上手工 `crictl pull` 区分"集群凭证问题"和"仓库/网络问题" |
| 冷启动加速 | 镜像做小（Q4）+ 薄层增量（Q5）+ 仓库就近（同 VPC/私有仓库）+ **DaemonSet 提前把关键镜像拉一遍**；⚠️ **kubelet 会按磁盘阈值 GC 掉不用的镜像**，节点磁盘压太满就会"昨天还在、今天重拉" |

### 三、`requests` / `limits` 与 QoS 三档 ⭐

| QoS | 条件 | 后果 |
|---|---|---|
| **Guaranteed** | 每个容器 `requests == limits`（CPU 和内存都设） | 节点压力下**最后被驱逐** |
| **Burstable** | 设了但不相等（含只设 requests） | 中间档；超 limit 的部分被 throttle / 被 cgroup OOM |
| **BestEffort** | 什么都不设 | 节点压力一来**最先驱逐** |

三条必讲推论：**内存没有"超用再回收"的中间态**，超 limit 直接 OOMKilled（CPU 才有 throttle）；
不设 requests 会让调度器误判容量、把 Pod 塞到已满载节点然后再驱逐（"我的 Pod 无故重启"常是邻居造成的）；
JVM/Go 要按 cgroup 算预算，见 [Docker.md Q9](Docker.md)。

### 四、退出码与 OOMKilled 在 K8s 里怎么看

```bash

kubectl describe pod <pod>      # Last State: Terminated → Reason: OOMKilled / Error + Exit Code
kubectl get events --sort-by=.lastTimestamp -n <ns>   # Killing / BackOff / Unhealthy / FailedScheduling
kubectl logs <pod> --previous   # 重启前那个实例的日志（关键）
```

| 看到什么 | 结论 |
|---|---|
| `Reason: OOMKilled` + `137` | 超了自己的 memory limit（**不是**节点没内存）：查泄漏或调 limit |
| `Reason: Error` + `137`，事件里有 "failed liveness probe, will be restarted" | **探针误杀**（Q7），与内存无关 |
| Exit `1` / panic 栈 | 应用自己退出：配置或依赖连不上就崩 |
| Exit `126` / `127` | 镜像里命令不存在/不可执行 → 改镜像，不是改集群 |

---

## Q7. 生命周期与优雅关闭：探针、`preStop` 与 SIGTERM ⭐

**考察意图**：这题最能看出"有没有真在生产发布过服务"。三探针各解决什么、误配的**具体后果**、
以及"`preStop` 里 sleep 几秒"到底在服务什么。

### 一、三个探针与误配后果

| 探针 | 回答的问题 | 失败的后果 |
|---|---|---|
| **startupProbe** | "它启动完了吗"（把冷启动豁免期显式化） | 超过阈值仍失败 → **杀掉重启** |
| **readinessProbe** | "现在能接流量吗" | **只从 Service endpoint 摘掉，不重启** |
| **livenessProbe** | "是不是僵住了、必须重启才能救" | 重启容器 |

| 误配 | 后果链条 |
|---|---|
| **liveness 太紧**（超时短 / `failureThreshold=1` / 探针本身依赖 DB 或下游） | 依赖抖动或 GC 卡顿 → 探针失败 → 杀 → 冷启动+重新预热 → 更多探针失败 → **重启风暴**，故障期吞吐比不加探针还差 |
| **readiness 与 liveness 配成一样** | readiness 的价值（"摘流量、不重启"）被 liveness 覆盖，一慢就重启 —— **白配且更危险** |
| 只配 liveness 不配 readiness | 新 Pod 刚起、还没预热就被打流量 → 一批 5xx（readiness 是"接流量的门票"） |
| 用 `initialDelaySeconds` 当启动保险 | 猜不准就还是误杀；**用 startupProbe 表达"启动要多久"更准确** |

### 二、优雅关闭的时序与代码要点

```
delete pod 之后两条路并行：
 (A) API 打 deletionTimestamp → endpoint 摘除 → 各节点 kube-proxy / Ingress 规则更新  【异步、要传播】
 (B) kubelet：先跑 preStop hook → 再发 SIGTERM → 到 terminationGracePeriodSeconds 仍存活 → SIGKILL
```

应用侧四步（顺序重要）：**① 停止 accept 新连接 → ② 主动摘注册中心 / 让 readiness 转失败 →
③ 等在途请求处理完（必须有上限）→ ④ 关资源（连接池、flush、消费者 offset）→ `exit 0`**。

```go

// 收到 SIGTERM 后停止接收并等在途请求结束（等待上限必须小于 grace period）
sigCtx, stop := signal.NotifyContext(context.Background(), syscall.SIGTERM, os.Interrupt)
defer stop()
<-sigCtx.Done()
ctx, cancel := context.WithTimeout(context.Background(), 15*time.Second)
defer cancel()
_ = srv.Shutdown(ctx)   // 不再 accept，等在途请求完成
db.Close()
```

> Java 侧同理：注册 shutdown hook + 打开框架自带的优雅停机开关（以所用框架文档为准），
> 线程池 `shutdown()` 之后要有 `awaitTermination()`。

### 三、"`preStop` 里 sleep 几秒"：常见但讲不清原理的那句 ⭐

- 它在服务 **(A) 这条路**：给"endpoint 摘除 + 各节点转发规则同步"争取时间。规则还没收敛就关端口，
  客户端就看到 `connection reset` / 5xx。
- 为什么"土"：这段时间是拍脑袋的常数，不随集群规模变化；而且 **preStop 的时间算在 grace period 里面**，
  所以 `terminationGracePeriodSeconds > preStop 等待 + 应用排空最坏耗时 + 余量`，否则最后一步被 SIGKILL。
- 新版本 K8s 已对"终止信号与 endpoint 摘除"做了先后顺序上的保证，但**规则传播到每个节点仍需时间**，
  所以留一小段等待仍然是稳妥做法。⚠️ exec 形式的 `sleep` 要求镜像里**有 shell**（scratch/distroless 会失败）。

---

## Q8. 滚动升级与回滚：参数、PDB、有状态为什么特殊

### 一、`maxSurge` / `maxUnavailable` 与 PDB

| 组合 | 效果 | 适用 |
|---|---|---|
| 默认（两者各 25%，默认值以所用版本为准） | 发布最快，过程内容量可能下降 | 无特殊要求 |
| `maxUnavailable=0, maxSurge=1` | **容量绝不下降**（先起新的再杀旧的） | 在线服务；⚠️ 新 Pod 一直起不来时发布会**卡住**，要有人看 `rollout status` |
| `maxSurge=0, maxUnavailable=20%` | 不超发资源，但先降容量 | 节点池固定 / GPU 场景 |

配合 `minReadySeconds`（Ready 后再观察一会儿才算数）能挡"启动即崩"的版本。
**PDB 只保证"自愿中断"的下限**（drain、集群升级会被预算拦住），**不保证**硬件故障、OOM、探针误杀，
也不管滚动发布本身的替换 → 真正确定可用性的是：多副本 + 反亲和打散 + readiness + 合理的 `maxUnavailable`。

### 二、回滚：`rollout undo` 还是"重新部署上一版镜像"

| | `kubectl rollout undo` | 改回上一版镜像 tag 再部署（GitOps 里就是 revert） |
|---|---|---|
| 做了什么 | 把 Pod 模板换回**上一个 ControllerRevision** | 显式指定一个已知的**不可变**版本 |
| 可预测性 | 依赖"上一个 revision 恰好是好的那个"，多次快发容易退错 | 由你指定，明确 |
| tag 可变时 | ⚠️ 模板里还是 `v1`，而 `v1` 已被覆盖 → 拉到的可能仍是当前内容，**回滚等于没生效** | 用 SHA/digest 就不有这问题 |
| 与 GitOps | 改了集群期望状态，**与仓库不一致**，下次同步会被纠回 | 事实源一致、审计完整 |

> 结论：**紧急时 `rollout undo` 止血，长期靠"不可变 tag + 版本回退"**；`undo` 之后必须把改动落回仓库。
> 另一件必须说的：**回滚只回滚代码，不回滚数据** —— 靠"向后兼容的 migration 序列"（加列 → 双写/回填 →
> 切读 → 再清理）让代码能单独退，否则回滚反而延长故障。

### 三、有状态工作负载为什么不能随便滚（定性）

| 原因 | 应对思路 |
|---|---|
| 代码与数据格式不向后兼容：新副本写了新格式，回滚后旧副本读不懂 → **回滚不再是安全网** | 升级前确认兼容窗口；migration 先于代码上线 |
| 集群有最小在线节点数 / quorum（DB、ES、ZK/Kafka 类），同时挂太多会失去仲裁并触发重平衡 IO 风暴 | PDB 限制同时中断数；StatefulSet 用 `partition` 分批灰度 |
| 更新顺序有意义（先存储后计算、先 follower 后 leader） | `podManagementPolicy` + `partition` 控制节奏 |
| RWO 的 PVC 不能双挂：旧 Pod 没真正终止，新 Pod 就 Pending | 保证旧 Pod 彻底终止；别用 Deployment 跑有状态（身份与卷要稳定，用 StatefulSet） |

### 面试官会追问什么（Q4~Q8 横向）

- **滚动发布怎么做到 0 报错？** → 把链路说全：**不可变镜像 → readiness 把关 → `maxUnavailable=0` + 资源余量
  → preStop/SIGTERM 排空 → `minReadySeconds` 观察**；少一环就漏错误。
- **"发完必然报错几条"三种根因？** → ① 没 readiness，新 Pod 未预热就接流量；② 没 preStop 或等待不够，
  摘除未收敛就关端口；③ 应用收到 SIGTERM 直接退出，在途请求被砍。
- **发布卡住先看什么？** → `kubectl rollout status` → 新 RS 的 Pod 是 Pending（调度不上 / PVC 没绑 / 资源不足）
  还是 CrashLoop（应用或配置问题）→ 再看 `maxUnavailable` 与探针是否太保守。
- **HPA 和滚动发布会打架吗？** → 发布期 readiness 抖动使可服务副本数下降 → HPA 扩容，发布完又缩；
  还有 **HPA 会接管 replicas 初值**这个坑。
- **镜像瘦了一个数量级，值不值？** → 值在**发布时长与冷启动**（拉取更快、kubelet 镜像 GC 触发得更少、
  节点重启后恢复更快），不是"仓库省空间"；但要拿"能不能 exec 进去排障"这个代价换（distroless/scratch 尤其）。

---
