# K8s 部署与生命周期

> Helm 怎样像 apt 一样管理 K8s 应用、镜像与 K8s 相关的正确姿势、三探针与优雅关闭、滚动升级与回滚。
>
> 内容整理自大厂 Go 后端面试真题视频；本轮补充（imagePullPolicy 与 QoS、三探针与优雅关闭、滚动与回滚）参考《Docker 技术入门与实战》（第 3 版，杨保华 / 戴王剑 / 曹亚仑）。参考资料与原始素材见 [素材清单](../../面试/素材清单.md)。

---

## Q1. Helm 是什么？它怎样"像 apt 一样"管理 Kubernetes 应用？

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
| `docker pull` 拉镜像慢 | **`registry-mirrors` 镜像加速**（[Docker网络与镜像源.md](../Docker/Docker网络与镜像源.md) 对比表左列） | 宿主机 **dockerd** |
| 构建镜像时 `apt / yum` 装包慢 | **换国内软件源**（[Docker网络与镜像源.md](../Docker/Docker网络与镜像源.md)） | **镜像内部** |
| Chart / 镜像在公网拉不动，或要控版本 | **自建 OCI Registry + Helm**（Q1 实战） | **集群侧 / 制品库** |

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

## Q2. K8s 侧与镜像相关的正确姿势 ⭐

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
`Always` + 可变 tag 是最糟的组合（tag 策略见 [CI-CD.md Q2](../CI-CD.md)）。

### 二、拉取凭证与冷启动

| 主题 | 做法与坑 |
|---|---|
| 私有仓库凭证 | `kubectl create secret docker-registry` + Pod 的 `imagePullSecrets`（容易漏，可配到 default ServiceAccount）；节点级凭证粒度太粗、换仓库要动节点；托管集群的免密组件要先确认**作用范围与失效策略**（跨账号/跨地域最容易踩） |
| 拉取失败怎么分 | 事件里 `ErrImagePull`（鉴权/网络）还是 `ImagePullBackOff`（反复失败，多为 tag 不存在或限速）→ 再在节点上手工 `crictl pull` 区分"集群凭证问题"和"仓库/网络问题" |
| 冷启动加速 | 镜像做小（[镜像瘦身与构建缓存.md](../Docker/镜像瘦身与构建缓存.md)）+ 薄层增量（[镜像瘦身与构建缓存.md](../Docker/镜像瘦身与构建缓存.md)）+ 仓库就近（同 VPC/私有仓库）+ **DaemonSet 提前把关键镜像拉一遍**；⚠️ **kubelet 会按磁盘阈值 GC 掉不用的镜像**，节点磁盘压太满就会"昨天还在、今天重拉" |

### 三、`requests` / `limits` 与 QoS 三档 ⭐

| QoS | 条件 | 后果 |
|---|---|---|
| **Guaranteed** | 每个容器 `requests == limits`（CPU 和内存都设） | 节点压力下**最后被驱逐** |
| **Burstable** | 设了但不相等（含只设 requests） | 中间档；超 limit 的部分被 throttle / 被 cgroup OOM |
| **BestEffort** | 什么都不设 | 节点压力一来**最先驱逐** |

三条必讲推论：**内存没有"超用再回收"的中间态**，超 limit 直接 OOMKilled（CPU 才有 throttle）；
不设 requests 会让调度器误判容量、把 Pod 塞到已满载节点然后再驱逐（"我的 Pod 无故重启"常是邻居造成的）；
JVM/Go 要按 cgroup 算预算，见 [资源限制与运维.md](../Docker/资源限制与运维.md)。

### 四、退出码与 OOMKilled 在 K8s 里怎么看

```bash

kubectl describe pod <pod>      # Last State: Terminated → Reason: OOMKilled / Error + Exit Code
kubectl get events --sort-by=.lastTimestamp -n <ns>   # Killing / BackOff / Unhealthy / FailedScheduling
kubectl logs <pod> --previous   # 重启前那个实例的日志（关键）
```

| 看到什么 | 结论 |
|---|---|
| `Reason: OOMKilled` + `137` | 超了自己的 memory limit（**不是**节点没内存）：查泄漏或调 limit |
| `Reason: Error` + `137`，事件里有 "failed liveness probe, will be restarted" | **探针误杀**（Q3），与内存无关 |
| Exit `1` / panic 栈 | 应用自己退出：配置或依赖连不上就崩 |
| Exit `126` / `127` | 镜像里命令不存在/不可执行 → 改镜像，不是改集群 |

---

## Q3. 生命周期与优雅关闭：探针、`preStop` 与 SIGTERM ⭐

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

## Q4. 滚动升级与回滚：参数、PDB、有状态为什么特殊

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

### 面试官会追问什么（发布与回滚横向）

- **滚动发布怎么做到 0 报错？** → 把链路说全：**不可变镜像 → readiness 把关 → `maxUnavailable=0` + 资源余量
  → preStop/SIGTERM 排空 → `minReadySeconds` 观察**；少一环就漏错误。
- **"发完必然报错几条"三种根因？** → ① 没 readiness，新 Pod 未预热就接流量；② 没 preStop 或等待不够，
  摘除未收敛就关端口；③ 应用收到 SIGTERM 直接退出，在途请求被砍。
- **发布卡住先看什么？** → `kubectl rollout status` → 新 RS 的 Pod 是 Pending（调度不上 / PVC 没绑 / 资源不足）
  还是 CrashLoop（应用或配置问题）→ 再看 `maxUnavailable` 与探针是否太保守。
- **HPA 和滚动发布会打架吗？** → 发布期 readiness 抖动使可服务副本数下降 → HPA 扩容，发布完又缩；
  还有 **HPA 会接管 replicas 初值**这个坑。
- **镜像变小对发布有什么影响？** → 见 [镜像瘦身与构建缓存.md](../Docker/镜像瘦身与构建缓存.md) 的「面试官会追问什么」。

---

## 关联

- [镜像瘦身与构建缓存.md](../Docker/镜像瘦身与构建缓存.md) — 发布时长与冷启动的镜像侧收益
- [资源限制与运维.md](../Docker/资源限制与运维.md) — Pod 的 requests/limits 与 QoS 分级
- [容器原理.md](../Docker/容器原理.md) — SIGTERM 为什么能直达业务进程
- [CI-CD.md](../CI-CD.md) — 声明式部署与回滚的流水线视角
