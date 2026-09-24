# Docker

> 进入运行中的容器、构建镜像、多阶段构建
>
> 内容整理自大厂 Go 后端面试真题视频，参考资料与原始素材见 [素材清单](../interview/素材清单.md)；本轮补充（namespace 与 cgroup、PID 1 信号、网络与存储模型、构建缓存、资源限制与 137）参考《Docker 技术入门与实战》（第 3 版，杨保华 / 戴王剑 / 曹亚仑）。

---

## Q1. Docker 怎样进入运行中的容器？

**来源**：`p=26` 百度 Go 开发日常实习面试 · 时长 2分45秒

### 常用做法

```bash

docker ps                          # 先查看正在运行的容器，拿到容器名/ID
docker exec -it <容器名> /bin/bash  # 进入容器（镜像里没有 bash 就用 /bin/sh）
```

### 必须记住的两个参数（面试考点就在这）

| 参数 | 全称 | 作用 |
|---|---|---|
| **`-i`** | interactive | **保持标准输入打开**。交互既要输出也要输入，不打开 stdin 就没法输入命令 |
| **`-t`** | tty | **分配一个伪终端**，让命令行有正常的终端体验（提示符、颜色、Ctrl+C 等） |

**记忆点**：`-i` 管输入，`-t` 管终端；**交互场景必须 `-it` 一起用**。

### 一个容易踩的坑

> **容器里可能没有你想用的工具**——比如进入 Redis 容器后执行 `redis-cli`，
> 可能提示"没有这个命令"，因为**容器里未必装了这个客户端**。
>
> 解决办法：用容器里已有的命令（`ls`、`pwd` 等），
> 或者**把需要的工具想办法拷贝/安装进容器**。

### 面试官会追问什么

- **`docker exec` 和 `docker attach` 的区别？** → `exec` 是在容器内**新开一个进程**（推荐）；
  `attach` 是**接入容器的主进程**（1 号进程）的 stdio，退出可能导致容器停止，且无法脱离。
- **进入容器后能做哪些操作？** → 查看文件、检查配置、排查日志、临时调试；
  但**生产环境改容器内文件不是好习惯**——容器重启后就丢了，正确做法是**改镜像重新部署**。

---


---

## Q2. Docker 怎样构建镜像（build）？

**来源**：`p=27` 百度 Go 开发日常实习面试 · 时长 5分05秒

### 前置：必须先有 Dockerfile

`Dockerfile` 是**告诉 Docker 如何构建镜像**的说明文件。

### ⚠️ 关键认知：容器是"相对独立的环境"

这是本题最容易答错的隐含考点：

> 一个很常见的错误想法是："我的虚拟机/物理机已经配好了加速代理，容器应该也能用吧？"
>
> **不是的。容器是相对独立的环境**——虚拟机配的代理是**虚拟机的事情**，
> **构建镜像时必须把它当成一台全新的虚拟机来对待**，该配的加速代理要在 Dockerfile 里**单独配置**。

举例（前端镜像的 Dockerfile）：

```dockerfile

# 第一阶段：编译，拿到构建产物
FROM node:20 AS builder
RUN npm config set registry https://registry.npmmirror.com   # ★ 单独配国内源
COPY . .
RUN npm install && npm run build

# 第二阶段：真正运行，只带构建产物
FROM nginx:alpine
COPY --from=builder /app/dist /usr/share/nginx/html
```

**多阶段构建的好处**：第一阶段（`FROM node`）的 Node 环境和依赖**不会进入最终镜像**，
最终镜像只包含编译产物 + Nginx，**体积小得多**。

### 构建与运行命令

```bash

docker build -t <仓库名>:<标签> <构建上下文>     # 构建
docker run -d --rm -p 8080:80 <镜像名>          # 运行
```

### 参数逐个解释（面试重点）

| 部分 | 含义 |
|---|---|
| **`-t`（tag）** | 指定**标签**，由两部分组成：**Repository（仓库）+ Tag（版本）** |
| **Repository（仓库）** | 类似代码仓库。**每个镜像都是一个仓库**，因为同一镜像有**不同版本**（如 Redis 有多个版本）。<br>所以必须**指定仓库** |
| **Tag（版本）** | 如 `latest`、`7.2`。不写默认 `latest` |
| **构建上下文（context）** | **参与镜像构建的素材所在的位置**（通常是 `.` 当前目录）。<br>Docker 会把该目录打包发给 daemon，所以**上下文越大会越慢** |
| **`-d`** | 后台运行 |
| **`--rm`** | **临时运行**——容器停止后**自动删除容器**，适合一次性测试 |
| **`-p 8080:80`** | 端口映射：宿主机 `8080` → 容器 `80` |

### 关于镜像全名（容易忽略的细节）

> `docker build -t redis` 看起来只给了个名字，**实际上是完整的**：
> ```
> docker.io / library / redis : latest
>   注册中心    命名空间   仓库   标签
> ```
> 所以直接 `docker push` 时，**默认会推送到 Docker 官方的注册中心**。
> 要推到自己的私有仓库，必须**写全限定名**（如 `registry.mycorp.com/library/redis:latest`）。

### 面试官会追问什么

- **多阶段构建怎么减小镜像体积？** → 用 `COPY --from=builder` 只复制产物，
  不带编译期依赖；还可以选 `alpine` 这类小基础镜像。
- **怎么验证构建结果？** → `docker images` 看镜像列表，`docker run` 跑起来访问验证
  （原视频就是启动后用浏览器访问 `192.168.x.x:8080` 确认服务正常）。

---

## Q3. Docker 容器是怎样生成的？内部结构是怎样的？

**来源**：`BV1xSRXBwEeM p=80` 小米云原生 Go 一面 · 时长 13分17秒
**考察意图**：这道题不是背几条命令就能过的。面试官真正在问的是
**"容器到底是不是一个轻量虚拟机"**——只要你能先说清"容器的本质是进程"，
后面的镜像分层、Namespace 隔离、cgroup 资源控制、容器与虚拟机的差异都能顺着推导出来。
原视频把考点拆成四个：**① 容器的本质 ② 容器与镜像的关系 ③ 容器创建的核心步骤 ④ 隔离与资源控制**。

### 一、一句话结论：容器的本质是**被隔离的进程**

> **容器 = 一组被 Namespace 隔离、被 cgroup 限制资源、挂载了独立根文件系统的进程。**

它没有自己的内核，也不是一台"小虚拟机"。所谓"容器"只是**内核能力组合出来的假象**——
让进程以为自己独占了一台机器。

### 二、容器与镜像：模板与实例

| 概念 | 类比 | 说明 |
|---|---|---|
| **镜像（Image）** | 类 / 内核对象 | 只读的**模板**，一个镜像可以启动**一个或多个**容器 |
| **容器（Container）** | 实例 / 对象 | 基于镜像运行的实例，**本质是进程** |

说镜像像"模板"只是方便理解，**实质上是容器运行时需要引用的一组文件**（外加一份启动配置）。

### 三、镜像分层与联合文件系统（UnionFS）

镜像不是一个大文件，而是**一层叠一层**：

- 每个**镜像层（只读层）**只读，**上一层只读引用下一层**，一层层引用下去；
- 容器启动时，在镜像最上层再挂一个**可读可写的"容器层"**；
- 由 **UnionFS（联合文件系统，Docker 上通常落地为 `overlay2` 驱动）**
  把"只读的镜像层 + 可写的容器层"**合并挂载**成一个完整的文件系统给容器使用。

**写时复制（Copy-on-Write）是这套机制的核心**：

| 场景 | 行为 |
|---|---|
| 只读层有 `F1`，容器**没动过**它 | 直接引用只读层里的 `F1`，**不产生任何拷贝** |
| 只读层有 `F2`，容器**要修改**它 | 先把 `F2` **从只读层复制到可写层**，再改；之后引用可写层的新文件，老文件不再被引用 |
| 容器**新增**文件 | 只写进**容器层**，只读镜像层纹丝不动 |

这就是"镜像层只读、容器层可写"的真正含义：**改了才复制，不改就共享**——
所以同一个镜像起的 100 个容器，磁盘上只存一份镜像层。

```bash

docker run -d --name nginx-demo nginx:alpine
docker history nginx:alpine                            # 看镜像的分层历史
docker inspect -f '{{.GraphDriver.Name}}' nginx-demo   # 存储驱动：overlay2
docker inspect -f '{{.GraphDriver.Data}}' nginx-demo   # LowerDir / UpperDir / MergedDir
docker diff nginx-demo                                 # 看容器可写层到底改了什么
```

`overlay2` 的挂载参数可以在**宿主机**上这样验证：

```bash

mount | grep overlay
# overlay on /var/lib/docker/overlay2/<id>/merged type overlay (rw,lowerdir=...,upperdir=...,workdir=...)
#                                             ↑ 合并视图    ↑ 只读镜像层   ↑ 容器可写层
```

### 四、容器创建的核心步骤（原视频的四步）

```
① 创建隔离环境   → 把进程放进新的 Namespace，让它看不到别的进程/网络/主机名
② 设置资源限制   → 用 cgroup 限制它能用多少 CPU、多少内存
③ 挂载文件系统   → 新建可写的容器层，与只读镜像层联合挂载成容器根文件系统
④ 启动主进程     → 执行镜像里配置的启动命令，这个进程就是容器里的 1 号进程
```

### 五、`runc` 在创建时到底做了什么

上面四步的实际执行者是 **OCI 运行时**（Docker 默认用 **`runc`**）。
`runc` 是 OCI 运行时规范的参考实现，它干的是**内核系统调用级**的活儿：

| 动作 | 用到的内核能力 |
|---|---|
| **创建隔离环境** | `clone()` 系统调用，带上 `CLONE_NEWPID / CLONE_NEWNET / CLONE_NEWNS ...` 标志位，**在创建进程的同时创建新的 Namespace** |
| **设置资源限制** | 写 **cgroup** 的 `memory.max`、`cpu.max` 等文件，把新进程挂进对应的 cgroup |
| **切换根文件系统** | 用 **`pivot_root`**（或 `chroot`）把进程根目录切到联合挂载出来的 rootfs，并卸载旧根，**让进程彻底看不到宿主机文件系统** |
| **收窄权限** | 通过 **capabilities** 丢弃大部分特权（默认只保留十来个），防止容器内 root 越界操作宿主机 |
| **启动进程** | 设置好 cwd、环境变量、`/proc`，最后 `execve` 执行容器主进程 |

> **关键点**：`clone` 是"**创建进程**"和"**创建命名空间**"合二为一的系统调用——
> 这也从内核层面印证了"容器的本质是进程"：
> **容器里的 1 号进程，在宿主机上就是某个普通 PID。**

```bash

docker run -d --cap-drop=ALL --cap-add=NET_BIND_SERVICE nginx:alpine
docker inspect -f '{{.HostConfig.CapDrop}} {{.HostConfig.CapAdd}}' nginx-demo
docker exec -it nginx-demo capsh --print      # 镜像里装了 libcap 时，看容器实际持有的 capability
```

### 六、从 `docker run` 到应用进程：完整调用链

很多人的知识盲区在这：**`docker` 命令并不是容器的父进程**。真实链路是：

```
docker run -d nginx:alpine
      │  ① docker CLI 把请求发到 /var/run/docker.sock
      ▼
dockerd                    ② 镜像下载/解压、网络与存储卷准备，往下转成运行时的调用
      │
      ▼
containerd                 ③ 容器生命周期管理，初始化 snapshot（overlay2 目录结构）
      │
      ▼
containerd-shim-runc-v2    ④ 每个容器一个 shim 进程，负责替容器"守尸"
      │
      ▼
runc                       ⑤ 调 clone / cgroup / pivot_root 创建容器进程，然后**退出**
      │
      ▼
应用进程（nginx，容器内 PID=1） ⑥ 父进程变成 shim，由 shim 转交 stdout/stderr、回收退出码
```

几处必须讲清的点：

- **`runc` 是"一次性"的**：它只负责把进程建起来，建完就退出，
  **不会成为容器的父进程**——否则 dockerd / containerd 升级重启就会牵连所有容器。
- **`containerd-shim` 才是容器的"监护人"**：容器退出后它负责回收、上报退出码；
  也正是它让 dockerd / containerd **重启时容器照常运行**。
- `dockerd`、`containerd` 负责的是**管理与编排**，真正碰内核的是 **`runc`**。

```bash

docker info | grep -E 'Server Version|Storage Driver|Cgroup Driver'
ps -ef | grep -E 'dockerd|containerd|shim|runc' | grep -v grep
pstree -p $(pgrep -x containerd)     # 能看到 containerd → shim → 应用进程的层级
```

### 七、Namespace 隔离：有哪几种？隔离了什么？

**Namespace（命名空间）是内核提供的资源隔离技术**：它把系统的全局资源
（进程 ID、网络、文件系统挂载点、主机名……）打上"视图"的标签，
让不同 Namespace 里的进程各自拥有一份独立的资源视图，**彼此无法感知对方的存在**。

> 用原视频的说法：**每个进程都以为自己拥有全部资源**——
> 它在自己的 Namespace 里看到的东西"都是我的"，
> 而看不到的东西，"可能压根就不是我的"。

常见的六种 Namespace：

| Namespace | 隔离内容 | 对应 `clone` 标志 | 效果举例 |
|---|---|---|---|
| **PID** | 进程 ID | `CLONE_NEWPID` | 容器里的 nginx 是 **PID 1**，看不到宿主机其他进程 |
| **NET** | 网络设备、协议栈、端口、路由表 | `CLONE_NEWNET` | 容器有**独立的网卡/IP/端口**和专属 `lo` |
| **IPC** | 信号量、消息队列、共享内存 | `CLONE_NEWIPC` | 容器间的 SysV IPC 互不可见 |
| **MNT** | 挂载点 / 文件系统树 | `CLONE_NEWNS` | 容器有**独立根文件系统**，`mount` 不影响宿主机 |
| **UTS** | 主机名与域名 | `CLONE_NEWUTS` | 容器可以有自己的 **hostname** |
| **USER** | 用户与用户组 ID | `CLONE_NEWUSER` | 容器内 `root`（UID 0）映射到宿主机上某个**普通用户**（如 UID 1000） |

这就是为什么容器里 `whoami` 是 `root`，宿主机上 `ps -o user= -p <PID>` 查出来却是别的用户。

动手验证（需要 root / `sudo`）：

```bash

# ① 拿容器主进程在宿主机上的真实 PID
PID=$(docker inspect -f '{{.State.Pid}}' nginx-demo); echo $PID

# ② 看这个进程分别属于哪些 Namespace（NS 列的编号各不相同，说明确实是独立隔离的）
sudo lsns -p $PID

# ③ 钻进它的网络命名空间看网卡——能看到容器专属的 eth0 和 lo
sudo nsenter -t $PID -n ip addr

# ④ 钻进它的 PID 命名空间执行命令，会发现自己只剩极少的进程
sudo nsenter -t $PID -p -n -m -u -i --root /bin/sh
# 进入容器视角后再执行 ps -ef  →  只看到容器自己的进程，nginx 就是 1 号

# ⑤ 反过来：不用 Docker 也能亲手创建一个隔离环境
sudo unshare -pf --mount-proc /bin/bash
# 新 shell 里执行 echo $$  →  输出 1，说明 PID Namespace 已经生效
```

### 八、cgroup 资源控制：限制"能用多少"

只有隔离是不够的。原视频点出了一个很真实的场景：

> **死机往往不是因为"所有进程都出问题"，而是因为某一个进程把系统资源全吃掉了**，
> 导致其他进程申请不到资源 → 整个系统卡死。

所以还需要 **cgroup（control group，控制组）** 来给进程**设上限**。
**Namespace 管的是"能看到什么"，cgroup 管的是"能用多少"。**

Docker 对外暴露的两大部分：

| 维度 | 子项 | 对应 `docker run` 参数 |
|---|---|---|
| **CPU** | `cpu.weight`（`cpu.shares`）相对权重、`cpu.max`（`cpu.cfs_quota_us`）绝对配额、cpuset 绑核 | `--cpus=1.5`、`--cpu-shares=512`、`--cpuset-cpus=0-1` |
| **内存** | `memory.max`（硬上限）、`memory.swap.max`、OOM 行为 | `-m 512m`、`--memory-swap=1g`、`--oom-kill-disable` |

```bash

docker run -d --name limited --cpus=1.5 -m 512m nginx:alpine
docker inspect -f '{{.HostConfig.NanoCpus}} {{.HostConfig.Memory}}' limited   # 1.5e+09 536870912
docker stats limited --no-stream

# 在宿主机上直接看该进程的 cgroup 归属与限制（cgroup v2 路径）
PID=$(docker inspect -f '{{.State.Pid}}' limited)
cat /proc/$PID/cgroup
# 0::/system.slice/docker-<容器ID>.scope
cat /sys/fs/cgroup/system.slice/docker-<容器ID>.scope/memory.max   # 536870912
cat /sys/fs/cgroup/system.slice/docker-<容器ID>.scope/cpu.max      # 150000 100000
```

> cgroup v1 的路径形如 `/sys/fs/cgroup/memory/docker/<容器ID>/memory.limit_in_bytes`，
> v2 是统一层级（`memory.max` / `cpu.max`）。先用 `cat /proc/$PID/cgroup` 确认自己在哪一版。

### 九、容器内部结构小结

| 组成 | 内容 |
|---|---|
| **文件系统** | **镜像提供的只读内容** + **容器运行期产生的可写内容** = 容器看到的完整根文件系统 |
| **Namespace** | 独立的**进程空间**、**网络栈**、**用户与权限**、**主机名**、**IPC**、**挂载点** |
| **cgroup** | 该容器**资源使用量的上限**，保证不影响其他容器或进程 |
| **进程视图** | 容器内 PID 1 的主进程，在宿主机上只是"某个进程"；对相邻容器**完全不可见** |

> 一句话概括：**宿主机有的东西容器里也有，只是内容不一样**——
> 主机有网络信息，容器有自己独立的网络信息；主机有进程表，容器也有自己独立的进程表。

### 面试官会追问什么

- **"容器的本质是什么？"** → **进程**。一句话定调，后面全部由它推导：
  因为它是进程，所以共享内核 → 所以能秒起 → 所以隔离比 VM 弱 → 所以要用 Namespace + cgroup 兜底。
- **Namespace 和 cgroup 分别解决什么？** → Namespace 管"**看得见什么**"（资源视图隔离），
  cgroup 管"**能用多少**"（资源额度限制）。**两者互补，缺一不可**。
- **容器里的 PID 1 在宿主机上也是 1 号进程吗？** → 不是。容器内 PID 1 在宿主机上只是一个**普通 PID**；
  容器里看不到宿主机其他进程，宿主机却能通过 `docker inspect` 找到它。
- **为什么容器里 `whoami` 是 root，宿主机上却是普通用户？** → **User Namespace 的 UID 映射**。
- **镜像层能不能改？** → 不能，镜像层**只读**；所有改动落在**容器可写层**，
  靠**写时复制**做到"改哪层复制哪层"；容器删除后可写层一起消失
  （所以容器内改文件不能持久化，正确做法是改镜像重新部署）。
- **`docker run` 之后为什么 `runc` 进程不见了？** → `runc` 是**一次性**的，
  建完容器进程即退出，容器由 **`containerd-shim`** 接管守护，这样升级 dockerd 不会杀掉容器。

---

## Q4. 容器和虚拟机（VM）有什么区别？

**来源**：`BV1xSRXBwEeM p=80` 小米云原生 Go 一面 · 时长 13分17秒
**考察意图**：这是"容器的本质是进程"的**直接推论题**。
答这题不要背参数，而是**从"有没有自己的内核"推**：
VM 各自独立内核、资源独占 → 必须加载内核 → 启动要几分钟；
容器共享宿主机内核、本质是进程 → 只是启动一个应用进程 → 启动非常快。
**能一条逻辑推到底，比逐条罗列更有说服力。**

### 核心对比

| 对比项 | **容器** | **虚拟机（VM）** |
|---|---|---|
| **本质** | 被隔离的**进程** | 带**独立内核**的完整机器（由 Hypervisor 虚拟） |
| **内核** | **共享宿主机内核** | 每个 VM **各自独立的内核** |
| **启动速度** | **毫秒~秒级**（只是启动一个进程） | **分钟级**（要加载内核、走完引导流程） |
| **资源开销** | 只有进程开销，几乎没有额外损耗 | 要为每个 VM 预留 CPU/内存/磁盘，资源**独占** |
| **隔离级别** | **进程级（软件隔离）**，强度低于 VM | **硬件级（Hypervisor + CPU 虚拟化）**，隔离更强 |
| **镜像体积** | 通常几 MB~几百 MB（分层共享，只存一份） | 通常几 GB（含完整 OS） |
| **单机密度** | 高，可跑几十上百个 | 低，受资源独占限制 |
| **典型风险** | **内核漏洞 = 逃逸 = 影响宿主机** | 逃逸还要再突破 Hypervisor，更难 |

> **记忆钩子**：VM 吃的是**硬件**，容器吃的是**内核**。
> 所以"启动快"不是容器被优化出来的，而是因为它**压根不需要启动操作系统**。

### 面试官会追问什么

- **"容器启动为什么比虚拟机快这么多？"** → 虚拟机启动要**加载内核 + 内核初始化 + init 引导**；
  容器是"已经在运行的宿主机上再跑一个应用进程"，**内核是现成的**，自然快到不是一个量级。
- **容器能完全替代虚拟机吗？** → 不能。**多租户强隔离**场景（不同客户、互不信任）仍然更适合 VM 或
  "**VM + 容器**"混合（在 VM 里跑容器），用硬件级隔离兜住内核逃逸风险。
- **同一个镜像跑 10 个容器，占多少磁盘？** → 镜像层**只读共享**，磁盘只存**一份镜像层**，
  额外的只有每个容器各自很小的**可写层**。
- **容器里的 root 和宿主机的 root 是同一个吗？** → 不是。靠 **User Namespace 的 UID 映射**隔离；
  但**默认配置下容器内 root 仍有部分内核能力**，这也是要配 `--cap-drop` / 非 root 用户运行的原因。

---

## Q5. 隔离机制往下推一层：Namespace / cgroup / rootfs / PID 1 的信号 ⭐

**考察意图**：Q3 摆出了"容器 = Namespace + cgroup + rootfs + 一个进程"，这题考**推论**：
为什么跑不了不同内核版本的镜像？为什么一个内核漏洞能跨容器逃逸？为什么 `docker stop` 有时杀不掉容器？

### 一、三件套的分工（最常混的一点）

| 能力 | 管什么 | 明确**不管**什么 |
|---|---|---|
| **Namespace** | **可见性**：能看到哪些 PID / 网卡 / 挂载点 / 主机名 / IPC | 用量 |
| **cgroup** | **配额**：CPU、内存、IO 带宽、PID 个数 | 可见性——没套 Namespace 的进程照样能在 `/proc` 里看全机进程 |
| **rootfs + capabilities + seccomp** | 文件系统视图、可调用哪些 syscall、持有哪些特权 | 用量 |

六类 Namespace 清单见 Q3 第七节；内核另有 **cgroup namespace**、**time namespace**（Docker 默认不开，
所以容器内 `date` 就是宿主机时间）。容器内 `/proc/cpuinfo` 列全部核、`free` 显示宿主内存，
都因为它们是**内核全局视图**，而 cgroup 只改额度、不改视图（见 [Linux 内存与文件系统](../linux/内存与文件系统.md)）。

### 二、"共享宿主内核"的两个必然后果

1. **跑不了不同内核版本的镜像**：镜像里只有**用户态**（库 + 二进制），**没有内核**。Linux 镜像只能跑在
   Linux 内核上，Windows 容器只能跑在 Windows 内核上；macOS 上的 Docker 其实**起了一个轻量 Linux VM**。
2. **内核漏洞 = 跨容器逃逸**：一个本地提权 / 越界读写漏洞就能从容器读到宿主内存、影响同机其他容器
   （VM 还要再突破一层 Hypervisor）。→ **多租户强隔离**要么"VM 里跑容器"，要么换用户态内核 /
   轻量虚拟化运行时（gVisor、Kata 类），代价是 syscall 兼容性与性能。

### 三、rootfs 是 `pivot_root` 换的，不是 `chroot`

`chroot` 只改进程的根路径，**旧根仍在挂载树里**——拿着之前打开的 fd 往上 `..` 就能跳出去，只是遮眼。
`runc` 用 **`pivot_root`**：把联合挂载出的 `merged` 挂成**真正的根**、挪走并卸载旧根，再挂上容器自己的
`/proc`、`/dev`、（只读的）`/sys`，最后 `execve` 主进程。**`/proc` 必须在切根与 PID namespace 之后挂**，
否则容器里 `ps` 会看到全机进程。

### 四、为什么容器里的 PID 1 "杀不掉" ⭐

内核**对 PID 1 不套用信号默认动作**：未注册处理器的 `SIGTERM`/`SIGINT`/`SIGHUP` 会被**直接丢弃**
（`SIGKILL`/`SIGSTOP` 例外）。宿主机上不理 SIGTERM 的进程会正常终止，**容器里同样的代码纹丝不动**。

| 由此产生的故障 | 原因 |
|---|---|
| `docker stop` 卡到超时才被硬杀、退出码 **137** | SIGTERM 被丢 → 等满 grace period → SIGKILL |
| 容器里一堆 `<defunct>`、最后 `fork` 失败 | 1 号进程不 `wait` 回收僵尸，PID 与 `pids.max` 被耗尽 |
| 子进程收不到停止信号 | `docker stop` 只把信号发给 **PID 1**，不广播整个进程树 |

**tini / dumb-init 的价值** = 当这个"专职 1 号进程"，只做两件事：**转发信号给子进程树** + **回收僵尸**。

```dockerfile

ENTRYPOINT ["tini", "--"]     # 应用变成它的子进程；exec 形式的 CMD 才能让应用自己是 1 号进程
CMD ["myapp"]
```

### 面试官会追问什么

- **cgroup 能限制"能看到几个 CPU"吗？** → 不能，`--cpus` 只改配额；`--cpuset-cpus` 绑核时 `nproc` 会变小
  （它读 CPU 亲和性），但 `/proc/cpuinfo` 仍列全部核。
- **容器里能改内核参数吗？** → 大部分 `/proc/sys` **全局共享**（所以默认只读）；网络类 sysctl 因 NET
  namespace 才有独立视图。**优雅关闭是应用的责任，不是 Docker 的责任**（`stop` 只负责发信号）。
- **为什么不推荐 `--privileged`？** → 它把宿主设备、全部 capability、更宽的 syscall 都放进来，
  等于**主动放弃隔离**；需要单个能力时用 `--cap-add=XXX` 最小化授予。

---

## Q6. Docker 网络模型 ⭐

**考察意图**：不是背驱动名字，而是**"选它换来什么、代价是什么"**，
以及那条加分推导：**端口映射是 DNAT，所以监听 127.0.0.1 会不通**。

### 一、驱动对照表

| 驱动 | 机制 | 适用 | 代价 / 注意 |
|---|---|---|---|
| **bridge**（默认 `docker0`、自定义 bridge） | veth 一对 + Linux bridge + NAT | 单机绝大多数场景 | 多一跳转发；跨主机不通；**默认 bridge 不支持按名字解析** |
| **host** | 共用宿主机 network namespace，**不建 veth** | 极致性能、宿主级网络组件 | `-p` 无意义；**端口直接和宿主抢**；隔离最弱 |
| **none** | 只有 `lo` | 纯计算任务 | 要网络得自己 `docker network connect` 补 |
| **overlay** | VXLAN 隧道做跨主机二层 | 多主机 Swarm / 跨节点服务 | 封装开销、对底层 MTU 有要求 |
| **macvlan** | 容器挂在物理网段上，有自己的 MAC、可被外部路由 | 需要"局域网 IP" | **同一 macvlan 网络上两个容器默认不能互访**（都经父接口，内核不允许父接口内部转发） |
| **ipvlan** | 复用父接口 MAC，L2/L3 模式 | 规避上面那条限制、更高端口密度 | 仍受父接口与交换机策略约束 |

### 二、`-p` 的本质是 DNAT，以及那个经典坑 ⭐

```
外部包 → 宿主机:8080 →（nat 表 DNAT：老版本 iptables，新默认 nftables）→ 目的改写成 容器IP:80
       → 经 docker bridge 送达容器 netns 的 eth0
容器内看到的目的地址 = 容器自己的 IP，**不是 127.0.0.1**
```

**推论（高频考点）**：容器内服务必须监听 `0.0.0.0`。只监听 `127.0.0.1` 时 DNAT 后的包
**匹配不上回环监听的 socket**——这就是"本地跑得好、一进容器映射端口就 connection refused"的第一原因。

| 反向坑 | 说明 |
|---|---|
| `-p 8080:80` | 绑在宿主 **0.0.0.0**，同网段机器都能访问（数据库类镜像这样暴露过很多次事故）；要收紧就写 `-p 127.0.0.1:8080:80`（只绑宿主回环，外部机器访问不到） |
| 宿主上 `curl 127.0.0.1:8080` 通、外部不通 | 端口被宿主上别的进程占了 / 防火墙拦了 |

**排障顺序**（命令在 [命令速查](命令速查.md)）：容器内监听在哪个地址 → DNAT 规则有没有下发 →
容器 IP 之间通不通（`docker network inspect` 确认在同一网络）。

### 三、容器互访与内嵌 DNS

自定义 bridge 里的容器，`/etc/resolv.conf` 指向**容器内的内嵌 DNS**（`127.0.0.11`），按**容器名 /
网络别名**解析——所以 compose 里服务之间用服务名当主机名；**默认 `bridge` 不做名字解析**，只能用 IP
（"两个容器互 ping 不通"的第一顺位原因）。跨主机/跨编排的解析靠服务发现与集群 DNS，见
[服务发现与负载均衡](../distributed/服务发现与负载均衡.md)、[DNS 解析](../network/DNS解析.md)。

> 顺带两问：**容器网段和内网/VPN 撞了** → 换自定义网络的 `--subnet`，或在 daemon 侧改默认网段（`bip` / 地址池）；
> **容器之间通但上不了外网** → 依次看宿主 `ip_forward`、NAT/POSTROUTING 规则、容器内能否解析域名。

---

## Q7. 存储：overlay2、卷与日志 ⭐

### 一、overlay2 的合并视图与 copy-up（Q3 的机制，落到"成本"上）

`overlay2` 的挂载参数是 `lowerdir`（只读镜像层，可多层）+ `upperdir`（容器唯一可写层）+ `workdir`，
联合成 `mergeddir`；**改**已存在的文件要把**整个文件**从 lower 拷到 upper（copy-up）再改，
**删**下层的文件只写一个 **whiteout** 遮蔽。两条能直接落地的结论：

1. **copy-up 粒度是整个文件，没有块级 COW**。在大文件（DB 文件、日志、模型权重）上追加写，
   第一次就付"整份复制到 upper"的代价，之后所有增量都堆在可写层
   → **任何有写行为的目录都该用 volume，不要写在容器层。**
2. **删不掉下层的体积**：`rm -rf /var/lib/apt/lists/*` 必须**和 `apt-get install` 写在同一个 `RUN`** 里，
   放到下一个 `RUN` 只是"在更上层遮蔽"，**镜像体积不会变小**。多阶段构建只拷产物，本质也是在跟这条规律较劲。

### 二、volume / bind mount / tmpfs 怎么选 ⭐

| | **named volume** | **bind mount** | **tmpfs** |
|---|---|---|---|
| 路径由谁决定 | Docker 管理（`/var/lib/docker/volumes/<name>/_data`） | 你给宿主绝对路径 | 内存，不落盘 |
| 生命周期 | 容器删掉**卷还在**，要 `docker volume rm` | 与宿主目录同生命周期 | 容器停止即消失 |
| 典型用途 | 数据库数据、上传目录 | 挂配置 / 挂源码热重载 / 挂 `docker.sock` | 密钥、临时缓存 |
| 常见坑 | 孤儿卷越积越多 | 宿主目录不存在时会被创建成**空目录**（"挂进去看着是空的"）；属主按**宿主 uid** 解释，容器里的 `www-data` 可能对应不上 | 占内存，**算进 memory limit**，写多了直接 OOM |
| SELinux | 一般无关 | 需 `:z`（多容器共享内容）/ `:Z`（私有标注）打标，否则 `permission denied` | — |

> 生产编排文件建议一律用 `--mount type=volume|bind|tmpfs`（**把类型写明白**），
> 而不是 `-v name:/data` 这种"看着像绝对路径就被当成 bind mount"的短语法。

**`--rm` 与数据生命周期**：`--rm` 删的是**容器（含可写层和它的日志）**——命名卷不受影响，
匿名卷会随容器一起清掉（等同 `docker rm -v`）。所以"跑临时任务但结果要留下"必须显式挂卷。

### 三、日志驱动：默认 `json-file` **不轮转**，会把磁盘写满 ⭐

容器 stdout/stderr 由 dockerd 落到 `/var/lib/docker/containers/<ID>/<ID>-json.log`，**默认没有大小上限**
——一个疯狂打日志的服务能单独把节点磁盘写满，然后**同机所有容器一起挂**。

```json

// /etc/docker/daemon.json —— 全局兜底，生产建议开
{ "log-driver": "json-file", "log-opts": { "max-size": "10m", "max-file": "3" } }
```

- 改完要 `systemctl restart docker`，**且已存在的容器要重建**才带上新的日志配置；临时验证用
  `docker run --log-opt max-size=10m --log-opt max-file=3 ...`。
- 定位：`docker inspect -f '{{.LogPath}}' <容器>`；⚠️ 清理**不要 `rm`**（进程持有 fd，删了空间也不释放），
  要 `: > 该文件` 截断，原理见 [Linux 性能排查](../linux/性能排查.md)。
- 要集中采集就把 driver 换成 syslog / journald / 采集器方案：**日志的持久化不该指望节点磁盘**。

### 面试官会追问什么

- **命名卷的数据实际存在哪？能直接改吗？** → `/var/lib/docker/volumes/<name>/_data`，宿主 root 可直接读写；
  但**跑着的服务不感知你换了文件**（数据库有自己的页缓存与日志），要改数据走应用接口，别手改目录。
- **容器里改了文件，重启后还在吗？** → `docker restart` 复用**同一个可写层**，改动在；
  **删容器重建**就没了——所以要么改镜像重新部署，要么写进 volume（`docker commit` 只是救急）。
  查"谁往可写层里写了东西"用 `docker diff`（命令见 [命令速查](命令速查.md) 第十节）。

---

## Q8. 构建：缓存失效条件、层顺序、ENTRYPOINT vs CMD ⭐

### 一、缓存到底什么时候失效

每层缓存 key = **指令文本 + 基础镜像层 ID +（`COPY`/`ADD` 时）被拷贝文件的内容校验和**。

| 现象 | 为什么 |
|---|---|
| 改了个 README，`COPY . .` 之后全废 | `COPY` 的 key 是**上下文里所有被 include 文件**的校验和 → 唯一有效解是 **`.dockerignore`**（至少排除 `.git`、`node_modules`、构建产物、`*.log`、测试数据、`.env`；它同时还减小发给 daemon 的上下文体积） |
| 前面一层失效，后面的 `RUN` 必然重建 | 缓存是**链式**的：层 ID 依赖上一层 ID，指令字符串没变也没用 |
| 基础镜像升版（`node:20` → `node:22`）全废 | 链条从头断 |

> ⚠️ `ADD` 比 `COPY` 多两个行为（自动解压 tar、支持 URL），且 **URL 内容不参与校验和**，
> 缓存行为更容易让人意外 → 构建镜像一律用 `COPY`，需要远程文件就在 `RUN` 里 `curl` + 校验。

### 二、指令顺序：把"不常变"的放前面

依赖清单 → 装依赖 → 再拷源码（`npm ci` / `pip install -r` / `mvn dependency` 同理，
正确写法与失败条件见 [K8s 与镜像优化](K8s与镜像优化.md) Q5）。配套习惯：系统依赖放最前、
多个命令用 `&&` 合并进**一个 `RUN`**、包管理加 `--no-install-recommends` 并在同一个 `RUN` 里清索引缓存。

### 三、多阶段构建解决什么、`RUN` 里为什么不能放交互式命令

**多阶段**解决的是"**构建期依赖不该出现在运行镜像里**"：编译器、包管理器缓存、测试夹具留在中间阶段，
最终镜像 `COPY --from=` 只要产物，**体积与安全面同时受益**（没编译器、没 shell）。

构建阶段**没有 tty、没有可读 stdin**：`apt install`（不带 `-y`）、`mysql` 客户端、要输密码的 `su`、`read`、
`vim`/`less` 都会**挂到超时**或直接报错。要外部输入就用 `ARG`/`ENV`，要人工操作就 `docker run -it`。
另一类常见错误：`RUN systemctl restart xxx` —— 构建容器里**没有 init/服务管理器**，
起服务是 `ENTRYPOINT`/`CMD` 的活儿。

### 四、`ENTRYPOINT` vs `CMD` 与"参数追加"语义 ⭐

| | 定位 | `docker run <镜像> 参数` 时 |
|---|---|---|
| **CMD** | 默认**参数**（没写 ENTRYPOINT 时才是默认命令） | **被整体覆盖** |
| **ENTRYPOINT** | "这个镜像**是**哪个程序" | **保留**，`run` 后面的参数**追加**到它后面 |

`ENTRYPOINT ["nginx"]` + `CMD ["-g", "daemon off;"]` → 默认执行 `nginx -g daemon off;`；
`docker run img -v` → 实际执行 `nginx -v`：用户改的是**参数**，换不掉入口程序。
这就是"通用镜像"（用 CMD 让人填命令）与"应用镜像"（用 ENTRYPOINT 钉死程序）的分界。
**exec（数组）形式**才能让程序直接当 1 号进程；**shell 形式**包了一层 `/bin/sh -c`，
程序变子进程 → 信号收不到（回到 Q5 第四节）。

### 五、`HEALTHCHECK` ≠ K8s 探针

| | Docker `HEALTHCHECK` | K8s 探针 |
|---|---|---|
| 谁执行 | dockerd 按 `interval/timeout/retries` 跑你给的命令 | kubelet 执行 httpGet / tcpSocket / exec |
| 不健康会怎样 | 只标成 `unhealthy`；**普通 `docker run` 不会因此重启你**（Swarm 会；compose 的 `depends_on: condition: service_healthy` 会等就绪） | liveness 失败 → 重启容器；readiness 失败 → **只摘流量不重启** |
| K8s 读不读 Dockerfile 的 HEALTHCHECK | — | **不读**，必须在 Pod 里写（见 K8s 篇 Q7） |

### 面试官会追问什么

- **构建慢怎么定位？** → 看输出里 `CACHED` 停在第几层、上下文有多大 → 十有八九是 `.dockerignore` 缺失。
- **怎么让镜像跑在非 root 下？** → 先建用户再 `USER 10001`，并注意**挂进来的目录属主要匹配**（换非 root
  后最常见的启动失败原因）；改属主用 `COPY --chown=user:group`，别再用 `RUN chown -R` 多复制一层。

---

## Q9. 资源限制与日常运维 ⭐

### 一、限额怎么落地，"被杀"怎么判定

| 限制 | cgroup 落地 | 超限的行为 |
|---|---|---|
| `--cpus=1.5` | `cpu.max`（v1：`cpu.cfs_quota_us` / `cpu.cfs_period_us`） | **throttle**：到点被暂停调度 → 变慢，**不会被杀** |
| `--cpu-shares` / `--cpuset-cpus` | 相对权重 / 绑核 | 只在争抢时体现 / 改变 CPU 亲和性 |
| `-m 512m`（+ `--memory-swap`） | `memory.max` / `memory.swap.max` | **cgroup 内的 OOM Killer 挑进程杀** → `.State.OOMKilled = true`、退出码 **137** |
| `--pids-limit` | `pids.max` | 无法 `fork`（没有 reaper 时最先撞上） |

> ⭐ **137 有两种含义，面试区分开来很加分**：`137 = 128 + 9`（SIGKILL）。
> `OOMKilled=true` → 内存超限被杀（调 limit 或查泄漏）；`OOMKilled=false` → 多半是 `docker stop`
> 宽限期到点被硬杀（查优雅关闭，见 Q5 第四节）。`--oom-kill-disable` 只是把容器排除在**宿主全局**
> OOM 之外，触到自己的 `memory.max` 照样被杀。

### 二、为什么 JVM / Go runtime "感知 cgroup" 要版本配合

cgroup 的上限是**内核侧**的事实，但运行时算自己的预算时传统上读 `/proc/cpuinfo`、`/proc/meminfo`
——那是**宿主机视图**，于是按整机规格决定线程数与堆大小，在容器里"胆子太大"。

| 运行时 | 定性表述 |
|---|---|
| **JVM** | 较新的 JDK 默认按 cgroup 配额算堆，**老的 JDK 8 小版本要显式开容器感知开关**；且堆 ≠ 进程总内存（Metaspace、线程栈、DirectBuffer、CodeCache 都在堆外），**`Xmx == limit` 基本必被 OOMKilled**，要留 25%~40% 余量（见 [Linux 内存与文件系统](../linux/内存与文件系统.md)） |
| **Go** | `GOMAXPROCS` 决定并发跑的线程数（P 的数量，见 [GMP 调度](../go/GMP调度.md)），早期只看宿主核数；较新的 runtime 会读 cgroup CPU 配额自动设定，老版本用 `automaxprocs` 或显式设置。内存侧用 `GOMEMLIMIT`（Go 1.19+，软限制）设成 limit 的 80%~90%，让 **GC 主动收紧**而不是等 cgroup 动手 |

> 具体开关名与默认行为**以所用版本文档为准**——讲清"为什么需要感知"比背 flag 更值钱。

### 三、共享边界、`inspect` 抓手与常见故障

**和宿主机共享的东西**：内核版本/模块/`dmesg` 全是宿主的；`/proc/sys` 大部分**全局共享**（默认只读），
网络类因 NET namespace 才有独立视图；**时钟共享**（改时区用 `TZ` 或挂 `/etc/localtime`，别在容器里
`date -s`）；不启用 user namespace 时**容器内 root = 宿主 root**，bind mount 的属主按宿主 uid 解释。
（`docker inspect` 的常用字段与命令写法在 [命令速查](命令速查.md) 第七节。）

| 现象 | 第一定位手段 | 典型根因 |
|---|---|---|
| 僵尸进程堆积、最后 `fork` 失败 | `docker top`、宿主 `ps -eo pid,ppid,stat` 看 `Z` | 主进程不是 1 号 / 无 reaper / 无 `pids-limit` 兜底 |
| 节点磁盘写满、容器集体异常 | `docker system df` → `du -sh /var/lib/docker/*` | 日志无轮转、构建缓存、镜像堆积、**大文件写进容器可写层** |
| 容器秒退 / 反复重启 | `docker logs --tail 100` → `docker inspect` 看 ExitCode | ① 命令与配置错（125/126/127、ENTRYPOINT 被覆盖）② 依赖不通（DB/配置中心连不上就退出）③ 被杀（137：OOM 或探针误杀） |
| 服务起来了但访问不通 | 容器内看监听地址 → 宿主看 DNAT 规则 | 监听在 `127.0.0.1`（Q6 第二节）、不在同一网络、宿主端口被占 |

### 面试官会追问什么

- **加了内存限制，为什么进程还能被 OOM Killer 杀掉？** → 限制**就是**靠 OOM 实现的：触及 `memory.max`
  且回收失败，就在**这个 cgroup 内**挑进程杀，与宿主还剩多少内存无关。
- **CPU 设 0.5 会怎样？** → 每个调度周期只能跑一半时间，超了 throttle；延迟敏感服务表现为**长尾变差**，
  而不是吞吐线性下降。`--memory-swap` 的不设行为在不同版本上有过差异，**显式写清**比依赖默认安全。
- **单机跑容器最先要配好的三件事？** → ① 日志轮转（Q7 第三节）② 资源上限（不配 = 一个容器吃满整机）
  ③ `--restart` 策略与 daemon 的 `live-restore`（重启 dockerd 不牵连容器，见 Q3 第六节的 shim）。

---
