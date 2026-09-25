# Docker 网络与镜像源

> 容器怎么拿到 IPv6 地址、以及 Dockerfile 里换国内镜像源/软件源的完整改法。
>
> 内容整理自大厂 Go 后端面试真题视频，参考资料与原始素材见 [素材清单](../../素材清单.md)。

---

## 一、Docker 怎样启用 IPv6 联网访问？

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

## 二、Dockerfile 构建镜像时如何修改基础镜像的镜像源地址？

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

## 关联

- [网络与存储.md](网络与存储.md) — Docker 网络驱动的整体模型与 `-p` 的 DNAT 本质
- [进入运行中的容器.md](进入运行中的容器.md) — 改完配置怎么进去验证
- [DNS解析.md](../../网络/DNS解析.md) — 拉镜像走的是 DNS 与 HTTPS
