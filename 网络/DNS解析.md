# DNS 解析

> 域名 → IP 的分布式命名系统：层级结构、递归/迭代查询、资源记录、负载均衡与安全，以及 Go / Java 两版使用示例
>
> 内容整理自个人学习笔记。

---

## 一、一句话定位：它解决什么问题

> **DNS（Domain Name System）是一个「域名 → IP」的分布式命名系统**，把人类可读的 `www.example.com` 翻译成机器可路由的 `93.184.216.34`。

**为什么不直接用 hosts 文件？**

| 维度 | hosts 文件 | DNS |
|---|---|---|
| 规模 | 单机一份，全球域名放不下 | 分布式、分层，理论上无上限 |
| 更新 | 改一台机器只影响一台，靠人工同步 | 改权威记录即可全网生效（受 TTL 约束） |
| IP 变更 | 服务端换 IP，所有客户端要手动改 | 只需改一处权威记录 |
| 组织方式 | 扁平 | 树形分层 + 委派，各域自治 |

**协议与端口**：DNS 是**应用层协议**，默认 **UDP 53**（报文小、一次往返、无连接开销）；**区域传送（AXFR/IXFR）**和**超过 UDP 上限的大响应**（尤其带 DNSSEC）走 **TCP 53**（EDNS0 把 UDP 上限抬到 4096 字节，超了仍会置 TC 位截断并回退 TCP）。加密变体：**DoT 853 / DoH 443 / DoQ**。

---

## 二、域名层级与服务器类型

### 域名树

```

.                            ← 根域（root），日常书写省略
├── com. / cn.               ← 顶级域 TLD（gTLD：.com/.net / ccTLD：.cn）
└── example.com.             ← 二级域（注册域）
    └── www.example.com.     ← 子域 / 主机名
```

- 每一级用 `.` 分隔，**从左到右层级递减**（`www` 是 `example.com` 的子域）；
- **FQDN（完全限定域名）** 以根域的 `.` 结尾，如 `www.example.com.` —— 那个尾点表示「**到此为止，不再往上拼搜索域**」；
  ⚠️ 不加尾点会被当作相对名，解析器会套用 `/etc/resolv.conf` 里的 `search` 域，K8s 里 `nslookup svc` 先试 `svc.ns.svc.cluster.local` 就是这个机制。

### 四类服务器

| 类型 | 职责 | 关键事实 |
|---|---|---|
| **根域名服务器** | 不解析具体域名，只告诉「`.com` 该问谁」 | 全球 **13 组**（`a`~`m`.root-servers.net），实为**任播**部署的数百个物理实例 |
| **顶级域名服务器（TLD）** | 管辖 `.com`/`.cn` 等，返回该 TLD 下域名的权威服务器 | 由对应注册局运营（Verisign 管 `.com`） |
| **权威域名服务器** | 真正持有并回答某域名的资源记录 | 域名持有者自己配置（如 `ns1.dnsprovider.com`），⚠️ **不做递归** |
| **本地/递归 DNS（LDNS）** | 替客户端跑完全过程并返回最终结果 | 运营商分配 / `8.8.8.8`、`114.114.114.114`、`223.5.5.5` |

> ⭐ 易混点：从根到 TLD 到权威，全都是**权威服务器，但只给"下一级线索"**；真正「替你跑腿」的只有**递归服务器（LDNS）**。

---

## 三、解析流程（⭐ 核心）

### 递归查询 vs 迭代查询

| | 递归查询（Recursive） | 迭代查询（Iterative） |
|---|---|---|
| 语义 | 「你帮我查完，给我**最终答案**」 | 「你**知道的告诉我**，不知道就给下一家地址」 |
| 请求方负担 | 零，只等结果 | 自己一次次去问 |
| 标志位 | `RD=1`（期望递归） | `RD=0` |
| 谁对谁 | **客户端 → LDNS** | **LDNS → 根 / TLD / 权威** |

> ⭐ **一句话记法**：**客户端到 LDNS 是递归，LDNS 到各级服务器是迭代**。让千万客户端都去问根服务器不可接受，把「跑腿」集中到少数递归服务器，再用**缓存**摊薄成本。

### 完整流程（一次浏览器访问）

1. **浏览器缓存**（Chrome 独立维护，约 60s）→ 命中即返回；
2. **操作系统缓存** → 再查 **`hosts` 文件**（`/etc/hosts` / `C:\Windows\System32\drivers\etc\hosts`）→ 命中即返回；
3. **LDNS（递归服务器）** 先查自己的缓存，未过期直接返回（非权威应答 `aa=0`）；
4. 缓存没有 → LDNS **迭代**发问：

```

LDNS ──1──▶ 根服务器   : "www.example.com 的 IP？"
根    ──2──▶ LDNS      : "不知道，去问 .com 的 TLD：a.gtld-servers.net"
LDNS ──3──▶ .com TLD   : "www.example.com 的 IP？"
TLD   ──4──▶ LDNS      : "去问 example.com 的权威：ns1.dnsprovider.com"
LDNS ──5──▶ 权威服务器  : "www.example.com 的 IP？"
权威  ──6──▶ LDNS      : "A = 93.184.216.34，TTL=300"（权威应答 aa=1）
LDNS ──7──▶ 客户端     : 返回 IP，并把结果按 TTL 缓存
```

5. 客户端拿到 IP 才开始建 TCP 连接（本篇不展开，见 [TCP三次握手.md](TCP/TCP三次握手.md)）；
6. ⚠️ **CNAME 会重启一轮**：第 6 步若返回 CNAME，LDNS 要**对别名再解析一次**，直到拿到 A/AAAA。

### TTL 与缓存（⚠️ 高频故障）

| 环节 | TTL 来源 | 说明 |
|---|---|---|
| 权威服务器 | 域名持有者配置（如 600 / 3600） | **决定全网缓存时长** |
| LDNS | 权威返回的 TTL，**递减计时** | 归零后回源重取 |
| OS / 浏览器 | 系统实现，通常尊重 TTL 但有上下限 | Linux `systemd-resolved`/`nscd`、Windows DNS Client 服务 |

**⚠️「改了解析不生效」的标准解释**：① 旧 TTL 是 3600 就最长要等 3600 秒，**不可能立即生效**；② 各地 LDNS 过期时刻不同，导致**各地生效时间不一致**；③ 部分运营商/企业网关**无视 TTL 超期缓存**，可能几天不生效；④ 浏览器还有独立一层，`ipconfig /flushdns` 只清 OS 层。

> **正确姿势**：变更前**先把 TTL 调小**（3600 → 60），**等一个旧 TTL 周期**，再改 IP，观察稳定后再把 TTL 调回去。

---

## 四、资源记录类型（⭐）

| 类型 | 作用 | 示例值 | 典型用途 |
|---|---|---|---|
| **A** | 域名 → IPv4 | `www  A  93.184.216.34` | 最基础的解析 |
| **AAAA** | 域名 → IPv6 | `www  AAAA  2606:2800::2` | IPv6 接入 |
| **CNAME** | 域名 → 另一个域名（别名） | `cdn  CNAME  xxx.cdnprovider.com` | 接 CDN、接 SaaS、域名收敛 |
| **MX** | 邮件服务器 + 优先级（数字小者优先） | `@  MX 10 mail.example.com` | 收邮件路由，`@` 表示域名本身 |
| **NS** | 指定该域的权威服务器 | `example.com  NS  ns1.dnsprovider.com` | 域名委派、子域授权 |
| **TXT** | 任意文本 | `v=spf1 include:_spf.google.com ~all` | **域名所有权校验**（ACME DNS-01 等）、**SPF/DKIM/DMARC** 反垃圾 |
| **PTR** | IP → 域名（反向解析） | `34.216.184.93.in-addr.arpa  PTR  www.example.com` | 邮件反查、日志可读化 |
| **SRV** | 服务发现：主机 + 端口 + 优先级/权重 | `_sip._tcp  SRV 0 5 5060 sipserver` | K8s、SIP、部分 RPC 服务发现 |
| **SOA** | 区文件起始记录：主服务器、序列号、刷新/重试/过期时间 | 每个 zone 有且仅有一条 | 主从同步依据（序列号变大触发 AXFR） |
| **CAA** | 指定允许哪家 CA 签发证书 | `example.com  CAA 0 issue "letsencrypt.org"` | 防误签，合规要求 |

### ⚠️ CNAME 的三个坑

| 坑 | 说明 |
|---|---|
| **CNAME 与 A 不能共存** | RFC 规定：**一个名字上一旦有 CNAME，就不能再有 A/AAAA/MX/TXT 等任何其他记录**（NS/SOA 除外）。`www` 做了 CNAME 就不能再给它加 A |
| **不能用在根域名（zone apex）** | `example.com.` 本身已有 NS/SOA，再加 CNAME 会冲突 → 根域要指向 CDN 只能用 **ALIAS / ANAME / Flattening**（各云厂商私有实现，本质是把 CNAME 解析成 A 后返回） |
| **CNAME 链过长** | `a → b → c → A` 每多一跳就多一轮查询，时延与失败面都增加 → **控制在 1~2 跳**，不要指向跨服务商的深层域名 |

---

## 五、DNS 与负载均衡

### DNS 轮询（Round-Robin）

同一个名字配**多条 A 记录**，权威服务器每次应答**打乱顺序**返回，客户端通常取第一条：

```

www  A  1.1.1.1
www  A  1.1.1.2
www  A  1.1.1.3     ← 应答顺序随机轮换
```

**缺点（⭐ 常考）**：① **缓存导致不均衡**——LDNS 缓存期内经它解析的用户全拿到同一个 IP，而各运营商用户量差异巨大，流量严重倾斜；② **无法感知故障**——后端挂了 DNS 仍会把它返回给用户；③ **TTL 两难**——短则查询量大、长则切换慢；④ **没有健康检查、权重、连接数/延迟感知**。

### GSLB / 智能 DNS

按**来源 IP → 运营商 / 地域 / 国家**返回**不同 IP**，把用户调度到最近机房；进阶可结合健康检查做**故障切换**、按权重灰度、按运营商做多线调度。

> ⚠️ **EDNS Client Subnet（ECS）**：让权威服务器看到「真实用户 IP 段」而非 LDNS 的 IP，否则会按 LDNS 位置误判；代价是缓存 key 多了 IP 段，**命中率下降**。

### 与 LB 的分工

| 层次 | 手段 | 解决什么 |
|---|---|---|
| **DNS / GSLB** | 轮询、智能 DNS | **跨地域、跨机房**的粗粒度调度；第一跳就近接入 |
| **四层 LB**（LVS、云 CLB 四层） | VIP + DR/NAT/TUNNEL | 机房内**高并发流量分发** |
| **七层 LB**（Nginx、云 CLB 七层、Ingress） | 按 Host/Path/Header 路由 | **按业务规则**分发、健康检查、熔断、灰度 |

> ⭐ **DNS 决定"去哪个机房"，LB 决定"去机房里的哪台机器"。** DNS 只做粗筛与兜底，真正的故障摘除交给 LB 的健康检查。

---

## 六、常见问题与安全（⭐ 实务）

### DNS 劫持 / 污染

| | **DNS 劫持** | **DNS 污染（缓存投毒）** |
|---|---|---|
| 位置 | 运营商 LDNS / 本机 / 路由器被改 | 递归服务器**缓存里被塞进伪造记录** |
| 原理 | 直接篡改应答或改 DNS 配置，返回广告页/钓鱼 IP | 抢在真响应前伪造应答（早期靠猜 UDP 源端口 + 事务 ID，即 Kaminsky 漏洞） |
| 表现 | 访问正常网站弹广告、解析到明显错误 IP | 部分地区解析异常，换个 DNS 就正常 |

**验证手段**：① `dig @8.8.8.8 域名` 与 `dig @223.5.5.5 域名` 对比，**结果不一致基本可判定被污染**；② `dig +trace` 看链路中哪一级返回异常；③ 检查 `/etc/resolv.conf` 和路由器 DNS 设置是否被改；④ `whois <ip>` 看返回的 IP 是否属于知名 CDN/云厂商段。

**应对**：改用可信公共 DNS；开启 DoH/DoT；服务端全站 HTTPS + **严格证书校验**（HTTPS 下劫持通常只能让你连不上，很难伪造内容）；移动端上 HTTPDNS。

### HTTPDNS

**原理**：App 不走 UDP 53 问 LDNS，而是**通过 HTTP(S) 接口直接查厂商的 DNS 集群**，携带域名（通常还有客户端 IP），服务端返回 IP 列表。

```

App ──HTTPS──▶ HTTPDNS 服务（阿里云 / 腾讯云 DNSPod 等）──▶ 返回 IP 列表 ──▶ App 用 IP + 正确的 Host/SNI 直连业务服务器
```

| 优点 | 缺点 / 代价 |
|---|---|
| **绕开运营商 LDNS**，根治劫持与污染 | 需接 SDK，Web 端用不了 |
| **精准调度**：服务端拿到真实用户 IP，比 ECS 更准 | 需自己实现**缓存、TTL、容灾** |
| 可自定义：多 IP 返回、**故障探测 + 快速切换**、按业务分流 | HTTPDNS 服务本身不可用时要有**本地兜底**（系统 DNS / 内置 IP） |
| 时延可控（可预热、可批量预解析） | ⚠️ HTTPS 下用 IP 直连有**证书/SNI 校验**问题，需配置 `ServerName`（Go）/ `HostnameVerifier`（Java） |

### DoH / DoT

| | DoT | DoH |
|---|---|---|
| 传输 | TLS，**853 端口** | HTTPS（TLS + HTTP/2），**443 端口** |
| 解决什么 | 加密 DNS 报文，**防窃听、防篡改** | 同上，且流量**混在 443 里**更难被识别/封锁 |
| 代价 | 专用端口易被识别并封禁 | 需额外 HTTP 栈；部分网络仍会拦截知名 DoH 端点 |

> ⚠️ 两者**只加密「客户端 ↔ 递归服务器」这一段**，权威侧与递归服务器本身仍能看到你的查询——防的是**链路上的中间人**，不是防 DNS 服务商。另有 **DNSSEC**：用签名链保证应答**未被篡改**（解决完整性，不解决隐私）。

### 解析失败 / 慢的排查思路

1. **先定性**：所有域名都失败（LDNS / 网络问题）还是单个域名失败（权威 / 记录配置问题）；
2. `dig @8.8.8.8 域名 +short` —— **换公共 DNS 对比**，判断是否为本地 LDNS 问题；
3. `dig +trace 域名` —— **逐级跟踪**，看卡在根 / TLD / 权威哪一级（`SERVFAIL`/`NXDOMAIN`/超时含义不同）；
4. `dig 域名 NS` —— 权威服务器是否与注册商处设置一致（改了 NS 但注册商没改是常见坑）；
5. 看 **TTL** 是否过大或异常；检查 `/etc/resolv.conf`（含 `search`/`ndots`）、`ipconfig /all` 的 DNS 配置；
6. **清缓存**后重试：`ipconfig /flushdns` / `systemd-resolve --flush-caches`；必要时 `tcpdump -i any port 53 -nn` 抓包（DoH/DoT 抓不到明文，只能看是否还有 53 流量）。

---

## 七、排查命令速查（⭐）

| 命令 | 作用 |
|---|---|
| `dig example.com` | 默认查 A 记录，输出完整应答（含 TTL、`aa` 标志、各段） |
| `dig example.com +short` | 只输出最终结果，脚本友好 |
| `dig example.com AAAA +short` | 指定记录类型（`A`/`AAAA`/`CNAME`/`MX`/`TXT`/`NS`/`SOA`/`CAA`/`SRV`） |
| `dig @8.8.8.8 example.com` | ⭐ **指定 DNS 服务器**，绕过本地 LDNS（对比排障首选） |
| `dig +trace example.com` | ⭐ **从根开始逐级跟踪**，看迭代全过程 |
| `dig +norecurse @a.gtld-servers.net example.com` | 模拟迭代查询（`RD=0`） |
| `dig -x 8.8.8.8 +short` | 反向解析（PTR） |
| `nslookup example.com 223.5.5.5` | Windows / Linux 通用，也支持交互模式 |
| `host -a example.com` | 列出该域名所有记录（`ANY` 查询，很多服务器已限制） |
| `whois example.com` / `whois 93.184.216.34` | 注册商、NS、到期时间；后者反查 IP 归属（判断劫持很有用） |
| `ipconfig /flushdns` | **Windows** 清 DNS 缓存（配套 `/displaydns` 查看） |
| `systemd-resolve --flush-caches` | **Linux**（systemd-resolved），新版等价 `resolvectl flush-caches`；**macOS** 用 `sudo killall -HUP mDNSResponder`（`dscacheutil -flushcache` 已失效） |
| `cat /etc/resolv.conf` | 查看本机 DNS 服务器、`search` 域、`ndots` |
| `tcpdump -i any port 53 -nn` | 抓 DNS 明文报文（DoH/DoT 抓不到） |

---

## 八、使用一：Go（⭐）

### 标准库基本用法

```go

package main

import (
	"context"
	"fmt"
	"net"
	"time"
)

func main() {
	ctx, cancel := context.WithTimeout(context.Background(), 3*time.Second)
	defer cancel()

	// 1) 最常用：IP 列表（v4/v6 都返回）
	ips, err := net.DefaultResolver.LookupIPAddr(ctx, "www.example.com")
	fmt.Println(ips, err)

	// 2)~5) 包级便捷函数（内部即 DefaultResolver，无 ctx 版本）
	hosts, _ := net.LookupHost("www.example.com") // 字符串形式地址列表
	cname, _ := net.LookupCNAME("www.github.com") // 跟随 CNAME 链的最终规范名
	mxs, _ := net.LookupMX("gmail.com")           // 邮件交换记录，按 Pref 升序
	txts, _ := net.LookupTXT("example.com")       // 另有 LookupNS / LookupAddr（反向 PTR）

	fmt.Println(ips, err, hosts, cname, mxs, txts)
}
```

> 需要超时/取消控制时用 `Resolver` 上的 `LookupXXX(ctx, ...)` 方法版本；`net.Dialer` 也有 `Resolver` 字段，可给拨号器单独挂解析器。

### 自定义解析器：`net.Resolver`（⭐ 指定 DNS 服务器）

```go

// 忽略 resolv.conf 里的服务器，改为自己指定的 DNS（如 223.5.5.5:53）
func newResolver(dnsServer string) *net.Resolver {
	return &net.Resolver{
		PreferGo: true, // 强制纯 Go 解析器，否则可能回退 cgo
		Dial: func(ctx context.Context, network, address string) (net.Conn, error) {
			// ⚠️ address 是解析器从 resolv.conf 取出的服务器，这里刻意忽略
			d := net.Dialer{Timeout: 2 * time.Second}
			return d.DialContext(ctx, "udp", dnsServer)
		},
	}
}

func demo() {
	r := newResolver("223.5.5.5:53")
	ips, err := r.LookupIPAddr(context.Background(), "www.example.com")
	fmt.Println(ips, err)
}
```

### HTTPDNS 风格：自查询 + 自缓存 + `DialContext` 落地

```go

type HTTPDNS struct {
	endpoint string // "https://dns.example.com/resolve?name="
	client   *http.Client
	mu       sync.RWMutex
	cache    map[string]cacheItem // host -> {ip, expireAt}
}

type cacheItem struct {
	ip       string
	expireAt time.Time
}

// ⭐ Go 解析器默认无缓存，这一层必须自己补
func (h *HTTPDNS) Lookup(host string) (string, error) {
	h.mu.RLock()
	if it, ok := h.cache[host]; ok && time.Now().Before(it.expireAt) {
		h.mu.RUnlock()
		return it.ip, nil
	}
	h.mu.RUnlock()

	resp, err := h.client.Get(h.endpoint + url.QueryEscape(host))
	if err != nil {
		return "", err
	}
	defer resp.Body.Close()
	var out struct {
		IPs []string `json:"ips"`
		TTL int      `json:"ttl"`
	}
	if err := json.NewDecoder(resp.Body).Decode(&out); err != nil || len(out.IPs) == 0 {
		return "", fmt.Errorf("httpdns: resolve %s failed", host)
	}
	ttl := time.Duration(out.TTL) * time.Second
	if ttl <= 0 {
		ttl = 60 * time.Second
	} // 服务端没给 TTL 就兜底 60s
	h.mu.Lock()
	h.cache[host] = cacheItem{ip: out.IPs[0], expireAt: time.Now().Add(ttl)}
	h.mu.Unlock()
	return out.IPs[0], nil
}

// 接到 http.Transport 上：建连前把域名换成 IP
func transportWithHTTPDNS(h *HTTPDNS, sni string) *http.Transport {
	dialer := &net.Dialer{Timeout: 3 * time.Second, KeepAlive: 30 * time.Second}
	return &http.Transport{
		DialContext: func(ctx context.Context, network, addr string) (net.Conn, error) {
			host, port, err := net.SplitHostPort(addr)
			if err != nil {
				return dialer.DialContext(ctx, network, addr)
			}
			target := addr // 兜底：HTTPDNS 失败就用系统 DNS 解析
			if ip, err := h.Lookup(host); err == nil {
				target = net.JoinHostPort(ip, port)
			}
			return dialer.DialContext(ctx, network, target)
		},
		// ⚠️ HTTPS 下用 IP 直连必须显式设 ServerName，否则证书校验失败
		TLSClientConfig: &tls.Config{ServerName: sni},
	}
}
```

### ⚠️ Go 解析器的两个坑

| 坑 | 说明 | 应对 |
|---|---|---|
| **cgo 解析器 vs 纯 Go 解析器** | 默认**两者混用**：简单情况走纯 Go，遇到 `/etc/resolv.conf` 复杂、`nsswitch`、特殊域名时**回退 cgo**（调 glibc `getaddrinfo`）。两者行为不同（超时、搜索域、缓存），且 **cgo 解析占用一个 OS 线程**，高并发下线程数暴涨 | `PreferGo: true`；或 `GODEBUG=netdns=go`（/`cgo`）全局指定；容器里常用 `netdns=go` 规避 musl/glibc 差异 |
| **默认无缓存** | ⭐ Go 解析器**不做任何缓存**，`LookupHost` 每调一次就发一次 UDP 查询。高频调用（如每次 RPC 前解析一次）会放大 DNS 压力与时延 | 自己做缓存（`sync.Map` + TTL，配 `singleflight` 防击穿）；HTTP/gRPC 客户端靠**连接池**避免重复 resolve，但换域名/重连时仍会触发 |

---

## 九、使用二：Java（⭐）

### 基本用法

```java

import java.net.InetAddress;
import java.util.Arrays;

public class DnsDemo {
    public static void main(String[] args) throws Exception {
        // 1) 解析域名（走 JVM 缓存）
        InetAddress addr = InetAddress.getByName("www.example.com");
        System.out.println(addr.getHostAddress());   // 93.184.216.34

        // 2) 一个域名的全部 A/AAAA 记录
        InetAddress[] all = InetAddress.getAllByName("www.example.com");
        System.out.println(Arrays.toString(all));

        // ⚠️ getByAddress(byte[]) 不做查询只按字面量构造；getHostName() 可能触发反向 PTR；
        //    isReachable(2000) 会真的发包，别拿它当健康检查
    }
}
```

### ⚠️ JVM DNS 缓存（高频坑）

| 属性 | 含义 | 默认值 |
|---|---|---|
| `networkaddress.cache.ttl`（旧名 `sun.net.inetaddr.ttl`） | **成功解析结果**缓存秒数 | 未配置且**无 SecurityManager**：**30 秒**；有 SecurityManager：**`-1` 永久缓存** |
| `networkaddress.cache.negative.ttl`（旧名 `sun.net.inetaddr.negative.ttl`） | **解析失败**结果缓存秒数 | 未配置且无 SecurityManager：**10 秒**；有 SecurityManager：**`-1` 永久** |

> 取值含义：**`-1` 永不过期；`0` 不缓存；正数 = 缓存秒数**。三处可配：启动参数 > `Security.setProperty` > `$JAVA_HOME/conf/security/java.security`，且**必须在任何解析发生前设置**。

```bash

java -Dsun.net.inetaddr.ttl=60 -Dsun.net.inetaddr.negative.ttl=10 -jar app.jar
```

**常见坑**：① ⚠️ **缓存永不过期** → 后端 IP 迁移 / 故障切换后 JVM 仍连旧 IP，**重启才恢复**；容器化场景 Pod IP 随时变，建议 TTL 设 5~30s 或干脆设 `0`；② ⚠️ **负缓存更隐蔽**：一次抖动导致解析失败后，10 秒（或永久）内一直失败，表现为"偶发故障后持续不可用"；③ **客户端还有一层**：HTTP 连接池会长期持有旧 IP，需配合**连接最大存活时间**（OkHttp `ConnectionPool`、HttpClient `validateAfterInactivity`、Netty `ChannelPool` maxLifeTime）才能真正切换；④ 这层缓存与 OS 缓存、LDNS 缓存**各自独立**，排障要分开看。

### 自定义 DNS 解析

```java

// Netty DnsNameResolver：指定 DNS 服务器 + 自带 TTL 缓存
DnsNameResolver resolver = new DnsNameResolverBuilder()
        .channelType(NioDatagramChannel.class)
        .nameServerProvider(new SequentialDnsServerAddressStreamProvider(
                new InetSocketAddress("223.5.5.5", 53)))  // 自定义 DNS 服务器
        .resolvedAddressTypes(ResolvedAddressTypes.IPV4_PREFERRED)
        .queryTimeoutMillis(2000)
        .ttl(5, 30)      // 缓存 TTL 下限 / 上限（秒），Netty 自己维护
        .negativeTtl(5)  // 负缓存 TTL
        .build();
try {
    List<InetAddress> addrs = resolver.resolveAll("www.example.com").get();
    System.out.println(addrs);
} finally {
    resolver.close();
}
```

```java

// OkHttp：实现 Dns 接口即可接入 HTTPDNS / 固定 hosts
OkHttpClient client = new OkHttpClient.Builder()
        .dns(hostname -> {
            if ("api.example.com".equals(hostname)) {
                return List.of(InetAddress.getByName("1.2.3.4")); // HTTPDNS 结果
            }
            return Dns.SYSTEM.lookup(hostname);
        })
        .build();
```

> **JDK 原生 SPI**：JDK 8 可用 `sun.net.spi.nameservice.NameService` + `NameServiceDescriptor` 替换全局解析器；JDK 9+ 因模块化封装受限（`jdk.naming.dns.JdkDnsProvider` 是官方可用实现之一），⚠️ 工程上更推荐 **Netty `DnsNameResolver`** 或 **OkHttp `Dns`**，侵入性更低。

---

## 十、面试官会追问什么（⭐）

| # | 追问 | 答题要点 |
|---|---|---|
| 1 | **DNS 解析的完整过程？** | 浏览器缓存 → OS 缓存 → `hosts` → LDNS（含缓存）→ LDNS 迭代问根 → TLD → 权威 → 返回 + 按 TTL 层层缓存；命中 CNAME 要再解析一轮 |
| 2 | **递归查询和迭代查询的区别？** | 递归 = 你替我查完给最终答案（`RD=1`）；迭代 = 知道多少说多少，给下一家地址（`RD=0`）。**客户端→LDNS 递归，LDNS→各级迭代**；这样设计是为减少根服务器压力并用缓存摊薄成本 |
| 3 | **DNS 用什么协议、什么端口？** | 应用层，**UDP 53**；**区域传送 AXFR/IXFR 和大响应走 TCP 53**（EDNS0 上限 4096，超了置 TC 回退）；加密变体 DoT 853 / DoH 443 |
| 4 | **CNAME 和 A 记录的区别？** | A 直接给 IP，CNAME 给**别名**（要二次解析）；同一名字上 **CNAME 不能与其他记录共存**；**不能用在根域（zone apex）**，根域只能用 ALIAS/ANAME；链过长会拖慢解析 |
| 5 | **DNS 轮询做负载均衡有什么问题？** | 缓存导致**粒度粗、不均衡**（运营商用户量不同）；**无健康检查**，后端挂了仍返回；TTL 长短两难；无权重、无延迟/连接数感知 → 只适合跨机房粗调度，机房内交给 LVS/Nginx |
| 6 | **DNS 劫持怎么应对？** | `dig @8.8.8.8` 对比 + `+trace` 定位；服务端全站 HTTPS + 严格证书校验；移动端上 **HTTPDNS**；链路加密 **DoH/DoT**；完整性 **DNSSEC** |
| 7 | **HTTPDNS 解决什么问题？** | 绕过运营商 LDNS，**根治劫持/污染**；服务端拿真实用户 IP 做**精准调度**（比 ECS 准）；可返回多 IP + 故障切换。代价：要自己做缓存/容灾兜底，HTTPS 直连需处理 SNI 与证书校验 |
| 8 | **为什么改了解析不立即生效？** | **各级缓存按 TTL 过期才回源**：浏览器 → OS → LDNS → 权威。旧 TTL 多大就最多等多久；各地 LDNS 过期时刻不同；部分运营商超期缓存；OS 层可用 `ipconfig /flushdns`、`systemd-resolve --flush-caches` 清。**变更前先调小 TTL** |
| 9 | **DoH / DoT 是什么？** | DoT = DNS over TLS（853），DoH = DNS over HTTPS（443）。解决**链路窃听与篡改**，DoH 走 443 更难被识别封锁；但它们只保护「客户端 ↔ 递归服务器」这一段，不等于匿名 |
| 10 | **LDNS 和权威服务器有什么区别？** | LDNS 做**递归 + 缓存**，服务客户端；权威服务器**只回答自己管辖的域**，不递归。授权链是根 → TLD → 权威，靠 NS 记录一级级委派 |

## 关联

- [TCP/TCP三次握手.md](TCP/TCP三次握手.md) — 拿到 IP 之后才建连
- [HTTPS与TLS.md](HTTPS与TLS.md) — SNI 与证书校验里的域名
- [HTTP与gRPC.md](HTTP与gRPC.md) — 连接复用与 DNS 缓存的关系
- [../分布式/服务发现与负载均衡.md](../分布式/服务发现与负载均衡.md) — DNS 轮询 / GSLB 与服务发现的边界
