# K8s 部署流程

> 从零到上线的标准路径：形态选型 → 集群搭建（kubeadm）→ 基础组件 → 应用清单 → 配置与密钥 → 发布回滚 → 检查单与排障。
>
> 内容整理自个人学习笔记。**每条机制背后的原理与常见问法**（Helm 与 values 分层、imagePullPolicy 与 QoS、
> 三探针与优雅关闭、滚动升级与回滚、PDB 与有状态负载）见 [K8s部署与生命周期面试题.md](K8s部署与生命周期面试题.md)，
> 本篇讲"一步步怎么做、每步怎么验"，两篇不重复、只互相链接。
>
> ⚠️ 本机没有可用集群（Docker daemon 未运行，也没有 kind / minikube / helm），因此
> **集群侧的命令只给标准流程与判据，不伪造输出**；第七节的实测输出全部来自本机 `kubectl` v1.36.1 客户端，可原样复现。

---

## 一、先判断：你要部署的是哪一种 K8s

**本节要点**：部署动作本身差别不大，**差别在"谁来管控制面"**。先选形态，后面每一步的准备工作都不一样。

| 形态 | 谁管控制面 | 适合 | 你要做的准备 |
|---|---|---|---|
| **托管集群**（ACK / TKE / EKS / GKE…） | 云厂商 | 绝大多数生产业务 | 节点组、CNI 插件、StorageClass、Ingress；**控制面升级由厂商推着走** |
| **自建 kubeadm** | 自己 | 私有云 / 合规要求 / 已有裸机 | 全套：系统前置、运行时、CNI、证书、升级、etcd 备份 |
| **轻量发行版**（k3s / k0s / MicroK8s） | 自己（一体化） | 边缘、单机、小规模 | 一条脚本即可；代价是**默认组件与上游有差异**，照搬网上 YAML 常踩坑 |
| **本地测试**（kind / minikube / k3d） | 自己（跑在容器里） | 开发期与 CI 里验清单 | 不用准备节点；**但存储/网络/入口与生产差异大**，只用来验语法与调度 |

⭐ 一句话判据：**生产别自建，除非有明确理由（合规、已有裸机、要玩控制面）**。
"我要学 K8s" → kind / minikube 就够；"我要上线" → 托管。

不管哪种形态，"从零到上线"都是四段，后面几节按这个顺序展开：

```text
① 集群层：集群本身 → 容器运行时 → CNI → 存储 → Ingress → 观测
② 制品层：镜像（不可变 tag）→ 清单 / Chart（进 Git）
③ 应用层：Namespace → 凭证 → ConfigMap/Secret → Deployment → Service → Ingress → HPA/PDB
④ 变更层：发布（滚动 / 灰度）→ 观测 → 回滚
```

> 顺序不能乱：**①没通就没有 ③**；**②不做（镜像可变），④的回滚就是空话**。

---

## 二、集群搭建：kubeadm 标准流程

**本节要点**：kubeadm 的每一步失败，几乎都能归到"**前置条件没对齐**"（cgroup 驱动、网段、时间、镜像源）。
所以**顺序比命令本身更重要**：先把所有节点的前置条件做成同一份脚本，再 `init`。

### 2.1 规划：动命令之前先定死这些

| 项 | 怎么定 | 定错的后果 |
|---|---|---|
| 版本矩阵 | 控制面 / kubelet / kubectl / 运行时 / CNI 版本一起定。**kubelet 可落后控制面若干个 minor、kubectl 一般只在相邻 minor 内，具体偏差策略以该版本官方文档为准** | 混装版本 → kubelet 注册不上、命令参数对不上 |
| 节点角色 | 控制面 3 台（HA）还是 1 台；控制面要不要跑业务 | 单控制面 = 单点；控制面跑业务必须显式去污点 |
| etcd 拓扑 | **堆叠**（stacked，默认，etcd 与控制面同机）vs **外部 etcd** | 规模大时堆叠 etcd 的磁盘 IO 会跟 apiserver 抢 |
| 网络三段 | **节点网** / **Service CIDR** / **Pod CIDR** 三者绝不能与已有网段重叠 | 重叠 → 路由冲突、Pod 访问不通 |
| 控制面入口 | 单机直连 IP；多机必须有 **VIP / LB** 指向所有控制面 | HA 集群写单节点 IP → 那台挂了 kubeconfig 全废 |
| 磁盘 | etcd 要低延迟盘（SSD / NVMe），最好单独分区 | etcd 抖动 → apiserver 超时 → 整个集群"变慢" |

> ⚠️ Service / Pod CIDR 的默认值**随 kubeadm 版本变化**，一定要**显式**写在 `init` 参数里，
> 并让 **CNI 的网段与 `--pod-network-cidr` 一致**——"CNI 装完了还是不通"，十有八九是两处各写各的。

### 2.2 节点准备：所有节点跑同一份脚本

1. 主机名 / hosts / **时间同步**（时间不同步 → 证书校验与 etcd 选举都会出怪问题）
2. 关 swap（kubelet 默认要求，不关则 `kubeadm` 的 preflight 直接失败）
3. 内核模块与 `sysctl`：`overlay`、`br_netfilter`，并开转发
4. 防火墙 / 安全组放行控制面与 kubelet 需要的端口（**缺一个就是"join 不上"**）
5. 装容器运行时（下一节），并让 kubelet 与运行时的 **cgroup 驱动一致**

```bash
# 所有节点：关 swap + 内核模块 + sysctl（写进文件做持久化）
swapoff -a && sed -ri '/\sswap\s/s/^#?/#/' /etc/fstab
modprobe overlay && modprobe br_netfilter
printf '%s\n' 'overlay' 'br_netfilter' > /etc/modules-load.d/k8s.conf
cat > /etc/sysctl.d/k8s.conf <<'EOF'
net.bridge.bridge-nf-call-iptables = 1
net.bridge.bridge-nf-call-ip6tables = 1
net.ipv4.ip_forward = 1
EOF
sysctl --system
```

> 用**写文件 + `sysctl --system`**，不要用 `sysctl -w`：`-w` 重启就没了，
> 而"节点重启后网络不通"的经典根因就是这几行没有持久化。

### 2.3 容器运行时：containerd

**要点**：K8s 通过 **CRI** 调运行时，dockershim 早已移除，所以现在的标准答案是 **containerd**（或 CRI-O）。
有两处必配：

| 配置 | 为什么 |
|---|---|
| **`SystemdCgroup = true`** | 与 kubelet 的 cgroup 驱动保持一致。不一致的症状是**节点偶发不稳、kubelet 报 cgroup 相关错误**，且不总是立刻失败 |
| **`sandbox_image`（pause 镜像）指向自建仓库** | 拉不到 pause 镜像 → Pod 永远起不来，事件里报 sandbox 失败，**看业务日志什么都看不到** |
| 私有仓库凭证 | 走 `config.toml` 的 registry 配置（**节点级**，所有人共用）**或** Pod 的 `imagePullSecrets`（应用级）。多租户集群应该用后者 |

```bash
# 生成默认配置后改两处：cgroup 驱动 + pause 镜像仓库
containerd config default > /etc/containerd/config.toml
sed -i 's/SystemdCgroup = false/SystemdCgroup = true/' /etc/containerd/config.toml
sed -i 's|sandbox_image = .*|sandbox_image = "registry.example.com/pause:3.9"|' /etc/containerd/config.toml
systemctl restart containerd
```

### 2.4 安装 kubelet / kubeadm / kubectl

```bash
# Debian 系示例：装完立刻锁版本，别让它跟着 apt upgrade 自己走
apt-get update
apt-get install -y kubelet=<版本> kubeadm=<版本> kubectl=<版本>
apt-mark hold kubelet kubeadm kubectl
systemctl enable --now kubelet
```

> ⚠️ **`kubeadm init` 之前 kubelet 反复重启是正常的**（它还没拿到配置），不要浪费时间"修"它。
> 判断集群是否装好要看 `kubectl get nodes`，不是看 kubelet 的 service 状态。

### 2.5 init / kubeconfig / CNI / join：顺序不能省

```bash
# 控制面第一台
kubeadm init \
  --control-plane-endpoint "k8s-api.example.com:6443" \   # 多控制面时必须给 VIP / LB
  --upload-certs \                                       # join 其他控制面要用
  --pod-network-cidr 10.244.0.0/16 \                     # ★ 必须与 CNI 的网段一致
  --service-cidr 10.96.0.0/12
mkdir -p $HOME/.kube
cp /etc/kubernetes/admin.conf $HOME/.kube/config
chown $(id -u):$(id -g) $HOME/.kube/config
```

```bash
# ★ init 成功 ≠ 集群可用：必须先装 CNI，否则 CoreDNS 永远 Pending
kubectl apply -f <CNI 的清单地址>          # Calico / Flannel / Cilium 三选一，绝不混装
# 工作节点（控制面再加 --control-plane --certificate-key <key>）
kubeadm join k8s-api.example.com:6443 --token <token> \
  --discovery-token-ca-cert-hash sha256:<hash>
```

**装完必须验的四条**（少一条都不算装好）：

```bash
kubectl get nodes                  # 全部 Ready；NotReady 多半是 CNI 或运行时没起
kubectl get pod -n kube-system     # CoreDNS / CNI / kube-proxy 全部 Running
kubectl run t1 --rm -it --image=<内网可达的镜像> -- nslookup kubernetes.default
kubectl get ns                     # 能列出来说明 apiserver 与 RBAC 都正常
```

> join token **24 小时过期**。过期了用 `kubeadm token create --print-join-command` 重新生成，
> **不需要重装节点**。

### 2.6 控制面 HA 与 etcd 备份

- **HA 的最小形态**：3 控制面 + 前置 LB，`--control-plane-endpoint` 指向 LB；控制面数必须是**奇数**。
  join 其他控制面时用 `--control-plane --certificate-key <key>`（key 由 `--upload-certs` 生成，**有有效期**）。
- **etcd 是集群唯一的事实源**：定期做快照，并且**真的演练过一次恢复**——
  控制面整机损坏时，"恢复 etcd 快照 + 重建控制面"是最后的兜底。

```bash
# 快照（在控制面节点上，用 etcd 自己的证书）
ETCDCTL_API=3 etcdctl --endpoints=https://127.0.0.1:2379 \
  --cacert=/etc/kubernetes/pki/etcd/ca.crt \
  --cert=/etc/kubernetes/pki/etcd/server.crt \
  --key=/etc/kubernetes/pki/etcd/server.key \
  snapshot save /backup/etcd-$(date +%F-%H%M).db
```

### 2.7 证书到期与版本升级

| 动作 | 标准做法 | 坑 |
|---|---|---|
| 证书 | `kubeadm certs check-expiration` 定期查（kubeadm 装的证书**默认一年**）；到期前 `kubeadm certs renew all` 然后重启控制面组件 | 证书到期 → apiserver 与 kubelet 全部握手失败，**表现为"集群突然全挂"**，最容易误判成网络故障 |
| 升级 | `kubeadm upgrade plan` 看偏差 → 先升**第一个控制面** `kubeadm upgrade apply vX.Y.Z` → 其余控制面与工作节点 `drain` 后 `kubeadm upgrade node` → 最后升级 `kubelet/kubeadm/kubectl` 三个包 | ① **一次只升一个 minor**，跨版本要逐级升；② **drain 后先升 kubelet 再 uncordon**，否则新 kubelet 跑旧配置；③ 升级前备份 etcd |

```bash
kubeadm upgrade plan                                                # 先看能不能升、哪里偏差
kubectl drain <node> --ignore-daemonsets --delete-emptydir-data     # PDB 会拦，先确认业务能停
kubeadm upgrade node
apt-get install -y kubelet=<新版本> && systemctl restart kubelet
kubectl uncordon <node>
```

### 2.8 搭建阶段的卡点表（按出现频率排）

| 现象 | 根因 | 怎么确认 |
|---|---|---|
| `kubeadm init` preflight 失败 | swap 没关 / 端口被占 / 运行时未就绪 / 镜像拉不到 | 逐条看 preflight 的报错，**别急着 `--ignore-preflight-errors`** |
| 节点 `NotReady` | CNI 没装或网段不对 / 运行时挂了 / cgroup 驱动不一致 | `kubectl describe node` 的 Ready 条件 + 节点上 `systemctl status containerd kubelet` |
| **CoreDNS 一直 Pending** | 最常见的根因就是 **CNI 没装** | `kubectl get pod -n kube-system -o wide` 看事件里是否提示没有网络 |
| join 报 `token expired` | token 24 小时过期 | `kubeadm token create --print-join-command` |
| 集群"随机变慢 / 超时" | etcd 磁盘延迟高、节点时间不同步 | etcd 延迟指标 + `journalctl -u kubelet` 里的时间跳变 |
| 换机器后 `kubectl` 连不上 | kubeconfig 的 server 指向了单控制面 IP | 改成 LB / VIP 地址 |

---

## 三、集群基础组件：按依赖顺序装

**本节要点**：组件之间有**硬依赖**——`HPA` 依赖 `Metrics Server`，`Metrics Server` 依赖 `CoreDNS`，
`CoreDNS` 依赖 `CNI`，`Ingress` 依赖 `LB`。**顺序装反就会陷入"反复重装"**。

```text
CNI（Pod 网络，一切的前提）
  └── CoreDNS（Pod 之间按名字互访）
        └── Metrics Server（kubectl top / HPA 的数据源）
              └── HPA（弹性伸缩）
  └── Ingress Controller（七层入口）
        └── LB / MetalLB（把入口 IP 暴露出来，自建集群必装）
  └── StorageClass + CSI（有状态负载的前提）
  └── 观测（日志 + 指标 + 链路）
```

| 组件 | 作用 | 不装的后果 | 装好的判据 |
|---|---|---|---|
| **CNI** | Pod 网络与 IPAM | Pod 全 Pending、CoreDNS 起不来 | `kubectl get pod -A` 里相关 Pod `Running` |
| **CoreDNS** | 集群内服务发现 | 服务之间只能写 IP | `nslookup kubernetes.default` 有响应 |
| **Metrics Server** | 资源用量指标（HPA 前提） | `kubectl top` 报 `Metrics API not available`；**HPA 永远 `<unknown>/70%`** | `kubectl top node` 有数据 |
| **Ingress Controller** | 七层入口与 TLS | 只能靠 NodePort / LB 暴露服务 | 访问一次域名能到后端 |
| **MetalLB / 云 LB** | 给 `LoadBalancer` 型 Service 发 IP | 自建集群 `EXTERNAL-IP` 永远 `<pending>` | `kubectl get svc` 有外部 IP |
| **StorageClass + CSI** | 动态供给 PVC | PVC 一直 `Pending`，有状态负载起不来 | `kubectl get sc` 有一个标了 `(default)` |
| **观测三件套** | 日志 / 指标 / 链路 | 出问题只能靠猜 | 见 [可观测性选型.md](../../可观测性/可观测性选型.md) |

> ⚠️ **HPA 的度量口径**：`--cpu-percent` 这种"利用率"是**相对容器 `requests`** 算的。
> 容器没写 `requests` 时 HPA 拿不到百分比，界面上就是 `<unknown>` ——
> 所以**先设 `requests` 再开 HPA**，顺序反了会以为 HPA 坏了。
> `kubectl autoscale --cpu-percent` 在本机 v1.36.1 上已标注 deprecated（改用 `--cpu`），参数以所用版本为准。

组件齐了之后，把**默认 StorageClass、节点标签（如 `disk=ssd`）、污点、节点池**定好——
应用清单里的 `nodeSelector` / `affinity` 全靠这些标签，**先定标签，再部署应用**。

---

## 四、应用上线的标准流程

**本节要点**：应用上线是一条**有序的清单流**：命名空间 → 拉取凭证 → 配置与密钥 → 工作负载 → 服务与入口 → 弹性与预算。
每一层都有"上一步没做，这一步就会以奇怪方式失败"的关系。

### 4.1 顺序与依赖

```text
Namespace（隔离边界）
  └── imagePullSecrets（私有仓库才能拉镜像）
        └── ConfigMap / Secret（应用启动就要读的配置）
              └── Deployment / StatefulSet（工作负载：探针、资源、优雅停机都在这）
                    └── Service（稳定的四层入口）
                          └── Ingress（七层路由 / 域名 / TLS）
                    └── HPA（依赖 requests + Metrics Server）/ PDB（依赖多副本）
```

### 4.2 最小可用的清单骨架

先建隔离边界和拉取凭证——**这一步不做，后面所有 Pod 都会 `ImagePullBackOff`**：

```bash
kubectl create namespace shop
kubectl -n shop create secret docker-registry regcred \
  --docker-server=registry.example.com --docker-username=ci --docker-password=<token>
kubectl -n shop create configmap app-config --from-literal=LOG_LEVEL=info
kubectl -n shop create secret generic app-secret --from-literal=DB_PASSWORD=<从密钥服务取>
```

工作负载用**声明式清单**——注意别把 `kubectl create deployment` 生成的东西直接拿去上线
（它没有探针、没有资源限制，见第七节实测）：

```yaml
# deploy.yaml —— 生产最小骨架：资源、探针、优雅停机、拉取凭证一个都不少
apiVersion: apps/v1
kind: Deployment
metadata:
  name: app
  labels: { app: app }
spec:
  replicas: 3
  revisionHistoryLimit: 10                                  # 回滚能退几步，别调成 0
  strategy:
    rollingUpdate: { maxUnavailable: 0, maxSurge: 1 }       # 先起新的再杀旧的：容量不下降
  selector:
    matchLabels: { app: app }                               # ⚠️ 创建后不可改，改了就报错
  template:
    metadata:
      labels: { app: app }                                  # 必须与 selector 匹配
    spec:
      imagePullSecrets: [{ name: regcred }]
      containers:
        - name: app
          image: registry.example.com/app@sha256:<digest>   # 不可变引用；至少不要用 :latest
          ports: [{ containerPort: 8080 }]
          envFrom:
            - configMapRef: { name: app-config }
            - secretRef: { name: app-secret }
          resources:
            requests: { cpu: 200m, memory: 256Mi }          # 调度的依据、HPA 的分母
            limits: { cpu: "1", memory: 512Mi }             # 内存超 limit 直接 OOMKilled
          readinessProbe: { httpGet: { path: /ready, port: 8080 }, periodSeconds: 5 }
          livenessProbe: { httpGet: { path: /healthz, port: 8080 }, periodSeconds: 10 }
          lifecycle: { preStop: { exec: { command: ["sleep", "5"] } } }
      terminationGracePeriodSeconds: 30                     # > preStop 等待 + 排空最坏耗时
      topologySpreadConstraints:                            # 副本打散到不同节点
        - maxSkew: 1
          topologyKey: kubernetes.io/hostname
          whenUnsatisfiable: ScheduleAnyway
          labelSelector: { matchLabels: { app: app } }
```

入口是两件事：**Service 管四层，Ingress 管七层**，两个都要有：

```yaml
apiVersion: v1
kind: Service
metadata: { name: app }
spec:
  selector: { app: app }          # 与 Pod 的 label 对上；对不上就是"Service 没有 Endpoint"
  ports: [{ port: 80, targetPort: 8080 }]
---
apiVersion: networking.k8s.io/v1
kind: Ingress
metadata: { name: app }
spec:
  ingressClassName: nginx         # 指定由哪个 Controller 处理，别用已废弃的注解
  tls: [{ hosts: ["shop.example.com"], secretName: app-tls }]
  rules:
    - host: shop.example.com
      http:
        paths:
          - path: /
            pathType: Prefix
            backend: { service: { name: app, port: { number: 80 } } }
```

### 4.3 落地与逐步校验

```bash
kubectl -n shop apply -f deploy.yaml                # 声明式提交，控制器负责对齐
kubectl -n shop rollout status deploy/app           # 必须看到 successfully rolled out
kubectl -n shop get pod -o wide                     # Ready 数 = 副本数，Restarts 不涨
kubectl -n shop get endpoints app                   # ★ 空的话就是 selector 没匹配上
kubectl -n shop logs deploy/app --tail=50
kubectl -n shop describe ingress app                # 确认规则与 TLS 都挂上了
```

> **`get endpoints` 是上线最容易被漏掉的一条**：Pod 都 Running、Service 也建了，
> 但请求就是不通——多半是 Service 的 `selector` 与 Pod 的 label 不一致，或者探针没过、Pod 不在 Ready。

弹性与可用性预算是两份独立清单，**HPA 依赖 `requests` + Metrics Server**：

```bash
kubectl -n shop autoscale deployment app --cpu=70% --min=2 --max=6
kubectl -n shop get hpa                             # TARGETS 不能是 <unknown>
```

```yaml
apiVersion: policy/v1
kind: PodDisruptionBudget
metadata: { name: app }
spec:
  minAvailable: 2                # 只约束"自愿中断"（drain / 集群升级），不防硬件故障
  selector: { matchLabels: { app: app } }
```

### 4.4 多环境不要复制三份 YAML

同一个应用要在 dev / test / prod 跑，**标准做法是"一份 base + 每环境一个 overlay"**（Kustomize），
或者**"一个 Chart + 每环境一个 values 文件"**（Helm，见 [K8s部署与生命周期面试题.md](K8s部署与生命周期面试题.md) 第一节）。

```text
k8s/
├── base/                # 所有环境共用：Deployment / Service
│   ├── kustomization.yaml
│   └── deploy.yaml
└── overlays/
    ├── dev/             # 只写"与 base 不同的地方"
    │   ├── kustomization.yaml
    │   └── patch-replicas.yaml
    └── prod/
```

```yaml
# overlays/prod/kustomization.yaml —— 环境差异集中在这里，流水线只改这一份
resources:
  - ../../base
namePrefix: prod-                     # ⚠️ 只改资源名，不改 label / selector（实测见第七节）
images:
  - name: registry.example.com/app
    newTag: <commit-sha>              # 由流水线注入：环境差异不进 base
replicas:
  - name: app
    count: 3
```

> ⚠️ 要给**所有资源统一加标签**时，用新的 `labels`（带 `includeSelectors` 开关），
> 老的 `commonLabels` 已废弃——而且它**会连 `selector.matchLabels` 一起改**，
> 对已存在的 Deployment 属于**不可逆变更**（selector 不可改）。本机实测见第七节。

---

## 五、配置与密钥怎么进容器

**本节要点**：ConfigMap / Secret 的两种投递方式（环境变量 vs 挂载文件）在**"改了之后要不要重启"**上行为完全不同；
而 **Secret 只是 base64、不是加密**——这两点是上线环节最容易踩的。

### 5.1 环境变量 vs 挂载文件

| | `envFrom` / `env` | `volumeMounts`（挂载成文件） |
|---|---|---|
| 应用怎么读 | 读环境变量 | 读文件（多数框架原生支持配置文件） |
| **改了 ConfigMap 之后** | ❌ 进程里的环境变量**永远不会变**，必须重启 Pod | ✅ kubelet 周期性同步挂载内容（有延迟），进程重新读就能生效 |
| 适合 | 简单开关、少量参数 | 配置文件、证书、需要热加载的场景 |

> **`subPath` 的坑**：用 `subPath` 挂单个文件时**不会收到内容同步**——
> 想要"改配置能生效"，要么挂整个目录，要么接受重启。

### 5.2 "改了配置，Pod 不重启"是默认行为

K8s **不会**因为 ConfigMap / Secret 变了就自动滚动重启 Deployment。两种标准做法：

1. **校验和注解**（推荐，声明式、Git 里能看到 diff）：在 Pod 模板上加
   `checksum/config: <ConfigMap 内容的 hash>`——内容一变 hash 变 → Pod 模板变 → 自动滚动。
2. **reloader 类工具**（watch ConfigMap 自动触发滚动重启），或在流水线里显式执行
   `kubectl rollout restart deploy/<应用>`（这条命令本身就是"滚动重启"的正确姿势）。

### 5.3 密钥的三条纪律

| 纪律 | 说明 |
|---|---|
| **不进镜像** | 一旦被 `COPY` 进层，`docker history` 与镜像层里**永远都在**，后面 `RUN rm` 只是遮蔽 |
| **不进明文环境变量 / 日志** | `kubectl describe pod`、`docker inspect` 都能看到明文；流水线还会 echo 出变量 |
| **运行时注入 + 最小暴露** | 挂载成文件、外部密钥服务（Vault / KMS / 云 Secret Manager）按需拉取；RBAC 上**只给需要它的 Pod** |

> ⚠️ 别把 Secret 当加密存储用：`kubectl get secret -o yaml` 出来的 `data` 就是 base64，
> 谁能读它就等于能拿到明文（本机实测见第七节）。真要防"谁能读"，
> 靠 **RBAC + 静态加密（EncryptionConfiguration）+ 外部密钥服务**。
> CI 侧的密钥治理见 [CI-CD面试题.md 第四节](../CI-CD面试题.md)。

---

## 六、发布、回滚与灰度

**本节要点**：K8s 里**标准发布对象是 Deployment**（有状态用 StatefulSet，见
[K8s部署与生命周期面试题.md](K8s部署与生命周期面试题.md) 第四节）。
发布 = 改镜像引用，"回滚"才有对象；而**回滚只回代码、不回数据**。

### 6.1 改镜像的两条路

| 做法 | 适用 |
|---|---|
| **改清单里的 tag（GitOps / Git 里 revert）** ⭐ | 生产。改动可审、可回溯，事实源只有 Git 一处 |
| `kubectl set image deploy/app app=<新镜像>` | 临时 / 演练。**改的是集群期望状态**，下次从 Git 同步会被纠回去 |

```bash
kubectl -n shop set image deploy/app app=registry.example.com/app:<sha>          # 临时改
kubectl -n shop annotate deploy/app kubernetes.io/change-cause="release 1.4.2"   # 写进 revision 历史
kubectl -n shop rollout status deploy/app        # 卡住就停在这条：先让人看见，再决定回不回
kubectl -n shop rollout history deploy/app       # revision 列表（含 change-cause）
kubectl -n shop rollout undo deploy/app          # 退回上一个 revision
kubectl -n shop rollout undo deploy/app --to-revision=3
```

> ⚠️ `--record` 在**本机 v1.36.1 的 `kubectl apply --help` 里已经查不到**（实测），
> 现在记变更原因的标准做法是 `kubernetes.io/change-cause` 注解。
> 另外 `rollout undo` 依赖 revision 历史，`revisionHistoryLimit` 调成 0 就等于没有回滚。

### 6.2 灰度的三条路线

| 路线 | 做法 | 特点 |
|---|---|---|
| **副本比例**（金丝雀副本） | 起两个 Deployment（`app-v1` / `app-v2`），**共享同一个 Service 的 label**，用副本数控制流量比例 | 只依赖原生对象；比例粒度粗 |
| **入口权重** | Ingress 注解 / 网关的权重路由（两套 Service 各占权重） | 粒度细，可按 header / cookie 定向；吃网关能力 |
| **渐进式发布控制器** | Argo Rollouts / Flagger（自带指标分析与自动回滚） | 功能最全，多一层控制器要学、要运维 |

> 三者共同的**前提**：镜像不可变 + 两版**能同时跑**（API 与数据结构向后兼容）。
> 灰度的判据是**指标**（错误率 / 延迟 / 饱和度），不是"看了一会儿没报错"。

### 6.3 回滚之前先判断"数据能不能回"

顺序是：**先判断是代码问题还是数据问题**。代码问题 → 换回上一个不可变 tag；
**数据问题 → 回滚只会延长故障**。

所以发布前必须确认三件事：① migration **向后兼容**（先加列、再改代码、最后清理）；
② **回滚路径演练过**（真退过一次）；③ "连回滚都救不回来"的那次，前向修复方案是什么。
原理展开见 [CI-CD面试题.md 第四节](../CI-CD面试题.md)。

---

## 七、本机实测：没有集群时，清单能验到什么程度

**本节要点**：`kubectl` 是个**客户端**，很多操作可以完全离线完成（生成清单、渲染 Kustomize）；
但**任何需要"知道集群里有哪些资源类型"的操作都必须连 apiserver**。
弄清这条边界，就能在没有集群的机器上先把清单验掉一半。

**本机环境**：`kubectl v1.36.1`（buildDate 2026-05-12，kustomize v5.8.1，go1.26.2，windows/amd64），
**没有可用集群**（Docker daemon 未运行，也没有 kind / minikube）。

### 7.1 离线能力矩阵（逐条实测）

| 命令 | 无集群时 | 原因 |
|---|---|---|
| `kubectl create deployment / namespace / configmap / secret / ingress / job --dry-run=client -o yaml` | ✅ 可用 | 纯客户端生成，不查集群 |
| `kubectl create secret docker-registry --dry-run=client -o yaml` | ✅ 可用 | 同上，`--from-*` 系列都不联网 |
| `kubectl set image --local -f x.yaml -o yaml` | ✅ 可用 | `--local` 表示只改本地对象 |
| `kubectl kustomize <dir>` | ✅ 可用 | Kustomize 是**纯本地渲染**，与集群无关 |
| `kubectl apply --dry-run=client -f x.yaml` | ❌ 失败 | 要去下载 **openapi** 做 schema 校验 |
| `kubectl apply --dry-run=client --validate=false -f x.yaml` | ❌ 仍失败 | 即使关掉校验，也需要 **RESTMapper**（"集群里有没有这个 kind"） |
| `kubectl get -f x.yaml` | ❌ 失败 | 同上：要先能识别 kind |
| `kubectl explain` / `top` / `api-resources` | ❌ 失败 | 数据源在 apiserver |

失败时拿到的**真实报错**（不是猜的）：

```text
$ kubectl apply --dry-run=client --validate=false -f deploy.yaml
error: unable to recognize "deploy.yaml": Get "http://localhost:8080/api?timeout=32s": dial tcp [::1]:8080: connectex: No connection could be made because the target machine actively refused it.
```

去掉 `--validate=false` 之后，卡点从 RESTMapper 换成了 openapi——**两种都连不上集群**：

```text
$ kubectl apply --dry-run=client -f deploy.yaml
error: error validating "deploy.yaml": error validating data: failed to download openapi: Get "http://localhost:8080/openapi/v2?timeout=32s": dial tcp [::1]:8080: connectex: No connection could be made because the target machine actively refused it.; if you choose to ignore these errors, turn validation off with --validate=false
```

> 两点结论：① **`--dry-run=client` 不等于"离线"**——它只保证"不发送创建请求"，不保证"不连集群"；
> ② `--validate=false` 的提示语会让你以为关掉校验就能跑，**实际它仍然需要 RESTMapper**。

### 7.2 生成出来的清单长什么样（照抄可复现）

`create --dry-run=client` 生成的是**能跑、但不能上线**的最小对象，正好用来说明"生产清单要补什么"：

```text
$ kubectl create deployment app --image=registry.example.com/app:v1 --replicas=3 --dry-run=client -o yaml
apiVersion: apps/v1
kind: Deployment
metadata:
  labels:
    app: app
  name: app
spec:
  replicas: 3
  selector:
    matchLabels:
      app: app
  strategy: {}
  template:
    metadata:
      labels:
        app: app
    spec:
      containers:
      - image: registry.example.com/app:v1
        name: app
        resources: {}
status: {}
```

> 这份输出里 **`strategy: {}`、`resources: {}`、没有探针、没有 `imagePullSecrets`**——
> 它就是"能起来"和"能上线"之间的差距（补齐后的骨架见第四节 4.2）。

Secret 的 `data` 只是 base64，一眼可见：

```text
$ kubectl create secret generic app-secret --from-literal=DB_PASSWORD=s3cr3t --dry-run=client -o yaml
apiVersion: v1
data:
  DB_PASSWORD: czNjcjN0
kind: Secret
metadata:
  name: app-secret
```

> `czNjcjN0` 就是 `base64("s3cr3t")`——**能读到这个 Secret 的人，就等于拿到了明文**。

### 7.3 Kustomize 的两个实测行为

```text
$ kubectl kustomize k        # kustomization.yaml: resources: [deploy.yaml]; namePrefix: shop-
apiVersion: apps/v1
kind: Deployment
metadata:
  name: shop-app             # ← 资源名加了前缀
spec:
  selector:
    matchLabels:
      app: app               # ← label selector 未被改动
```

| 字段 | 会不会改 label / selector | 说明 |
|---|---|---|
| `namePrefix` / `nameSuffix` | ❌ 不改 | 只改资源名；**引用它的地方（如 `serviceName`）要自己对齐** |
| `commonLabels` | ✅ **会改 `selector.matchLabels` 与 Pod 模板 label** | 本机给出 `'commonLabels' is deprecated. Please use 'labels' instead` 警告；对已存在的 Deployment，**改 selector 是不可逆的** |
| `labels`（带 `includeSelectors`） | 由你决定 | 新写法，显式表达"要不要连带改 selector" |

> 结论：**给"已经跑起来"的应用补标签，不要用 `commonLabels`**；
> 而 `namePrefix` 因为不碰 selector，是"一套清单部署出多环境"的安全手段。

---

## 使用：在没有集群的机器上先把清单验一遍

**本节要点**：按"能验多少验多少"的顺序排，本机（v1.36.1）实测**渲染与生成可用、干跑必须连集群**。

```bash
# ① 本地渲染：把 overlay 展开成最终 YAML（纯本地，不需要集群）★ 最有用的一步
kubectl kustomize k8s/overlays/prod > /tmp/rendered.yaml
# ② 生成清单骨架（生成出来只是"能跑"，上线前必须补资源与探针，见第四节）
kubectl create deployment app --image=registry.example.com/app:v1 --dry-run=client -o yaml > app.yaml
# ③ 生成拉取凭证 Secret（`data` 是 base64，能看穿的都不是秘密）
kubectl create secret docker-registry regcred --docker-server=registry.example.com \
  --docker-username=ci --docker-password=<token> -n shop --dry-run=client -o yaml
# ④ 语法层校验：这一条与集群无关，CI 里也能跑
python -c "import yaml;list(yaml.safe_load_all(open('/tmp/rendered.yaml')))"
# ⑤ 真正"提上去之前"的校验要在测试集群做：让服务端验 schema
kubectl apply --dry-run=server -f /tmp/rendered.yaml
```

三层校验的分工，正好对应"标准流程"里清单该怎么管：

| 层 | 在哪做 | 能发现什么 | 发现不了什么 |
|---|---|---|---|
| 渲染 | 本地 / CI（`kubectl kustomize`） | 清单拼装错误、overlay 没生效、prefix 与标签对不对 | 一切与集群有关的问题 |
| 服务端干跑 | 测试集群（`--dry-run=server` 或 CI 里的临时集群） | 字段写错、引用的 ConfigMap/Secret/StorageClass 不存在、API 版本不对、准入策略拒绝 | 运行时问题（探针、依赖、容量） |
| 线上观测 | 生产 | 真实行为 | —— |

---

## 八、上线检查单与排障顺序

**本节要点**：检查单是给**发布前**用的（挡住绝大多数低级故障）；
排障顺序是给**发布后**用的（从"最便宜的信息源"往"最贵的信息源"走）。

### 8.1 上线前检查单

| 类别 | 检查项 |
|---|---|
| 镜像 | 不可变 tag（SHA / digest）；`imagePullSecrets` 配了；`imagePullPolicy` 与 tag 类型匹配 |
| 资源 | `requests` 与 `limits` 都写了（HPA 与 QoS 都依赖）；内存 limit 按 GC / 堆预算算 |
| 探针 | readiness 反映"能不能接流量"、liveness 不依赖下游、startup 覆盖冷启动 |
| 停机 | `preStop` / SIGTERM 处理 / `terminationGracePeriodSeconds` 三者时间关系正确 |
| 可用性 | 副本 ≥2、反亲和或拓扑打散、PDB 符合业务能承受的并发中断数 |
| 配置 | ConfigMap / Secret 存在且键名对得上（`envFrom` 漏键**不会报错，只会静默为空**） |
| 入口 | Service selector 与 Pod label 一致（`get endpoints` 非空）；Ingress 的 class 与 TLS 正确 |
| 观测 | 日志、指标、链路都能按新版本维度看（见 [可观测性选型.md](../../可观测性/可观测性选型.md)） |
| 回滚 | 记住上一个可用 tag；判断清楚本次变更能不能"只回代码" |

### 8.2 排障顺序（由便宜到贵）

```bash
kubectl -n shop get pod,deploy,svc,endpoints         # 先看"形状"：Pending / CrashLoop / Restarts 在涨
kubectl -n shop describe pod <pod>                   # Events + Last State：调度、拉镜像、探针、OOM
kubectl -n shop logs <pod> --previous                # ★ 重启前那个实例的日志（关键证据）
kubectl -n shop get events --sort-by=.lastTimestamp  # 集群视角的近期事件
kubectl -n shop exec -it <pod> -- sh                 # 进容器：DNS、端口、配置文件、依赖连通性
kubectl -n shop top pod                              # 需要 Metrics Server
```

| 看到 | 先查什么 |
|---|---|
| `Pending` | 资源是否够、`nodeSelector` / 亲和是否无解、PVC 是否绑定、污点是否能容忍 |
| `ImagePullBackOff` | 镜像名与标签是否存在、拉取凭证、节点到仓库的网络 |
| `CrashLoopBackOff` | `logs --previous` 的真实报错；配置缺失常表现为启动即崩 |
| `Running` 但 `0/1 Ready` | readiness 的路径 / 端口 / 阈值；依赖是否就绪 |
| `OOMKilled`（137） | 内存 limit 与真实用量；是泄漏还是 limit 太小 |
| Service 不通 | `get endpoints`（空 = selector 不匹配或 Pod 未 Ready）→ 再查 `targetPort` |
| 入口 404 / 502 | Ingress class、`pathType`、后端 Service 端口、TLS secret 是否挂上 |

```bash
kubectl -n shop rollout undo deploy/app     # 排不出来就先止血，再回来定位
```

---

## 面试官会追问什么

- **"从零装一套 K8s，说下顺序。"** → 前置（swap / 内核 / 时间 / 端口）→ 运行时（**cgroup 驱动一致 + pause 镜像**）
  → `kubeadm init`（**网段与 CNI 对齐**）→ **装 CNI** → join → 验 CoreDNS 与 DNS → 再装 Metrics Server 等基础组件。
- **"`kubeadm init` 成功但 Pod 全 Pending，为什么？"** → 九成是 **CNI 没装或 `podSubnet` 与 CNI 网段不一致**；
  先看 `kube-system` 里 CoreDNS 的状态与 `describe node` 的 Ready 条件。
- **"Pod 都 Running，但域名访问报 502。"** → `get endpoints` 是否为空（selector / 探针）→ `targetPort` 与
  `containerPort` 是否对 → Ingress 的 class / `pathType` / TLS。
- **"改了 ConfigMap，为什么应用没变化？"** → 用 `env` 注入的**永远不会变**，必须重启；只有挂载成文件才会同步，
  且 `subPath` 不更新。生产上用 **checksum 注解**或 `rollout restart`。
- **"`kubectl rollout undo` 一定能救回来吗？"** → 不能：① `revisionHistoryLimit=0` 时没有历史；
  ② tag 可变时"退回去"可能还是同一份内容；③ **数据不会跟着回滚**，migration 不兼容时回滚只会延长故障。
- **"没有集群，怎么保证清单是对的？"** → 三层：本地 `kubectl kustomize` 渲染 → 测试集群 `--dry-run=server` →
  线上观测；并能说清 **`--dry-run=client` 仍需要连集群**（本机实测）。
- **"Secret 安全吗？"** → 它只是 base64；安全靠 **RBAC + 静态加密 + 外部密钥服务 + 不进镜像与日志**。

---

## 关联

- [K8s部署与生命周期面试题.md](K8s部署与生命周期面试题.md) — 每条机制背后的原理与常见问法：Helm、QoS、三探针、滚动与回滚、PDB
- [CI-CD面试题.md](../CI-CD面试题.md) — 镜像 tag 策略与声明式部署：本篇第六节的上游
- [GitLab CI-CD.md](../GitLab CI-CD.md) — 流水线怎么写；本篇是它部署阶段的下游
- [容器与编排选型.md](../容器与编排选型.md) — 托管 / 自建 / 轻量发行版怎么选
- [docker/镜像构建与缓存.md](../docker/镜像构建与缓存.md) — 要部署的那个镜像怎么构建出来
- [docker/镜像瘦身与构建缓存.md](../docker/镜像瘦身与构建缓存.md) — 冷启动与发布时长的镜像侧收益
- [docker/资源限制与运维.md](../docker/资源限制与运维.md) — requests / limits 与 cgroup 的底层口径
- [可观测性选型.md](../../可观测性/可观测性选型.md) — 日志 / 指标 / 链路三件套

> 反向引用（本篇被下列文档引到）：[网络通信链路详解.md](../../网络/网络通信链路详解.md)
