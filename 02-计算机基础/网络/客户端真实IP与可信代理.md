# 客户端真实 IP 与可信代理

> 网关部署在 LB / SLB / CDN 后面时，`r.RemoteAddr` **恒为直接对端（代理）的地址**。
> 于是所有匿名客户端坍缩到同一个限流桶里 —— 一个客户端打满，全网匿名流量一起 429；
> 审计日志里记的也全是 LB 的 IP，出事后追不到人。
>
> 但修法**不是「去读 `X-Forwarded-For`」** —— 那是客户端能自己塞的普通请求头。
> 正确做法是**先判定直接对端是否可信**，只有转发头确实由我们自己部署的代理写下时，它才有证据价值。
>
> 内容整理自个人学习笔记，实测基于自研网关项目 [gateway](https://github.com/Eleven-26/gateway)（module `gwlab`，Go 1.26.5）的
> `internal/clientip/`（本篇 `text` 块都是本机真实运行结果，**只有第六节 6.3 例外，已在原处标注**）。
> 另有单元测试钉住每个边界（`TestResolve` 25 个子用例、`TestParseTrusted` 12 个、`TestResolveMultipleXFFHeaders`）。
>
> ⭐ **分工**：本篇讲**可信代理链的解析规则与安全边界**，以及**出站时该写谁**（第七节）；
> 反向代理怎么写入 / 不写入这些头见 [反向代理原理与实现.md](反向代理原理与实现.md)；
> 网络链路上每一跳改了什么见 [网络通信链路详解.md](网络通信链路详解.md)。

---

## 一、`r.RemoteAddr` 为什么不可用？

**本节要点**：它给出的是「**谁在跟我说话**」，不一定是「**谁最终发起了这个请求**」。

```text
用户浏览器 → CDN → SLB → 网关 → 上游
                          ↑
                    RemoteAddr = SLB 的 IP
```

在网关这一层，所有请求的 `RemoteAddr` **都是 SLB 的地址**。后果有两条，都很具体：

| 后果 | 表现 |
|---|---|
| **限流维度坍缩** | 网关用 IP 做桶 key → 全网匿名客户端共用一个桶 → 一个客户端刷接口，正常用户一起被限 |
| **审计日志失真** | 日志里 `client=10.0.1.5` 永远是那个 LB，出事后无法定位到真实来源 |

⭐ 注意这里有个容易被忽略的点：**这个问题会随部署架构变化而突然出现**。
单机直连部署时 `RemoteAddr` 就是客户端 IP，一切正常；
上了 LB 之后，功能没有任何改动，但限流与审计**同时静默失效**。

## 二、`X-Forwarded-For` 的语义：它是一条链，不是一个值

**本节要点**：这是整篇最核心的一条认知 —— **XFF 是「逐跳追加」的**，
所以**最左边是客户端自称的值，最右边才是最近一跳写下的**。

```text
客户端发请求：  X-Forwarded-For: 1.1.1.1        ← 客户端自己塞的（可以是假的）
经过第一跳代理：X-Forwarded-For: 1.1.1.1, 10.0.1.5   ← 代理追加了它的对端地址
经过第二跳代理：X-Forwarded-For: 1.1.1.1, 10.0.1.5, 10.0.2.7
                                ↑ 最左（客户端自称）    ↑ 最右（最近一跳）
```

⭐ **所以「取最左」是最常见也最危险的写法**：

```text
客户端直接发：  X-Forwarded-For: 9.9.9.9      ← 随便填一个
网关取最左 → 认为客户端是 9.9.9.9 → 限流按这个假 IP 建桶
   → 攻击者每次换一个伪造值，就等于**每次都是新用户**，限流完全失效
```

**正确的方向是「从右往左」**：

```text
从最右开始往左扫，跳过所有可信代理，第一个不可信地址就是与最近可信代理直连的那一方。
```

| 方向 | 含义 |
|---|---|
| 从**右**往左 | 越靠右越接近「网关自己」，越可信 |
| 从**左**往右 | 越靠左越接近客户端，**也越容易被伪造** |

## 三、正确做法：五条规则

### 3.1 规则全景

```go
func Resolve(remoteAddr string, h http.Header, trusted []netip.Prefix) string {
    host := hostOnly(remoteAddr)
    peer, ok := parseIP(host)
    if !ok { return host }

    // ① 对端不可信：转发头一律不采信。这是本包的安全底线。
    if !trustedHas(trusted, peer) { return peer.String() }

    // ② 从 XFF 最右侧往左走，跳过可信跳，第一个不可信地址即真实客户端。
    chain := parseChain(h.Values("X-Forwarded-For"))
    for i := len(chain) - 1; i >= 0; i-- {
        if !trustedHas(trusted, chain[i]) { return chain[i].String() }
    }

    // ③ 整条链都在可信网段内：取最左那个
    if len(chain) > 0 { return chain[0].String() }

    // ④ 没有可用的 XFF：回落 X-Real-IP
    if ip, ok := parseIP(hostOnly(h.Get("X-Real-IP"))); ok { return ip.String() }

    // ⑤ 兜底：对端自己（它本身是可信代理）
    return peer.String()
}
```

⭐ **规则 ③ 为什么要取「最左」而不是「退回对端」**：

```text
场景：内网办公网客户端直连 SLB，而 SLB 与内网同属 10.0.0.0/8 这一条 trusted
   → XFF 里全是可信跳（客户端自己也在可信网段内）
   → 此时最左那条正是真实客户端，且它仍能区分不同客户端
   → 若退回对端（SLB）地址，这些客户端又会坍缩成一个限流桶
```

这条规则与 nginx `realip` 模块的行为一致：它同样从右往左扫，扫完所有可信跳后使用最左那个地址。

### 3.2 本机实测

可信列表：`10.0.0.0/8`、`127.0.0.1`、`::1`。

```text
=== ① 可信代理链解析 ===

  ① 对端不可信 + 伪造 XFF    remote=203.0.113.9:5000   XFF=1.2.3.4                    → 203.0.113.9
  ② 对端可信 + XFF 单跳      remote=127.0.0.1:5000     XFF=9.9.9.9                    → 9.9.9.9
  ③ 对端可信 + XFF 多跳      remote=127.0.0.1:5000     XFF=9.9.9.9, 10.1.1.1          → 9.9.9.9
  ④ XFF 最左是伪造值         remote=127.0.0.1:5000     XFF=1.1.1.1, 9.9.9.9, 10.1.1.1 → 9.9.9.9
  ⑤ XFF 含非法条目           remote=127.0.0.1:5000     XFF=garbage, 9.9.9.9           → 9.9.9.9
  ⑥ XFF 全是可信跳           remote=127.0.0.1:5000     XFF=10.7.7.7, 10.8.8.8         → 10.7.7.7
  ⑦ XFF 缺失 → X-Real-IP     remote=127.0.0.1:5000     XFF=(无)                       → 9.9.9.9
  ⑧ IPv4-mapped IPv6 归一    remote=127.0.0.1:5000     XFF=::ffff:9.9.9.9             → 9.9.9.9
  ⑨ IPv6 对端方括号形式        remote=[::1]:5000         XFF=2001:db8::1                → 2001:db8::1
  ⑩ 全部不可解析              remote=127.0.0.1:5000     XFF=garbage                    → 127.0.0.1
```

⭐ 四个最有价值的读数：

| 用例 | 证明了 |
|---|---|
| ① | **对端不可信时，XFF 一个字节都不看** —— 伪造完全无效（这是安全底线） |
| ④ | 从右往左扫：最左的 `1.1.1.1` 被跳过，取到 `9.9.9.9` ✅ 不是「取最左」 |
| ⑤ | 非法条目**跳过继续往左**，而不是放弃整条链（见第四节坑③） |
| ⑧ | `::ffff:9.9.9.9` 被归一成 `9.9.9.9` —— 否则它既不会被 IPv4 前缀命中，形态也不规范 |

## 四、五个容易做错的地方

**本节要点**：这五条每一条都有对应的测试钉住，也都是真实项目里出现过的写法。

### 4.1 坑①：无条件信任 `X-Forwarded-For`

```text
⚠️ 这是最危险的写法。XFF 是客户端能自己塞的普通头，
   不看对端就采信 = 任何匿名攻击者都能伪造 IP 绕过限流。
```

**硬规则**：**对端不可信时，`XFF` / `X-Real-IP` 一个字节都不看**（直接短路返回对端）。

### 4.2 坑②：从 XFF 最左侧取值

见第二节。**正确方向是从右往左**。

### 4.3 坑③：遇到解析不了的条目就整条链放弃

```text
攻击者只要在 XFF 里塞一个 garbage 就能把真实 IP 顶掉。
正确做法是跳过该条目继续往左 —— 非法值既不能采信，也不该破坏其余证据。
```

⭐ 这条的直觉是反的：安全场景里通常「遇到异常就拒绝」，
但这里**拒绝整条链反而给了攻击者一个「让保护失效」的开关**。
**正确姿势是「忽略异常项、继续按规则取证」**。

### 4.4 坑④：用 `net.ParseIP` 再拼字符串

```text
会把 `::ffff:1.2.3.4`、`[::1]` 这类形式带进返回值。
本包统一走 net/netip 的 Addr.Unmap() + Addr.String()，输出唯一的规范形态。
```

**为什么要归一**：下游拿这个 IP 去比对可信网段时，
`::ffff:10.1.1.1` **不会**被 `10.0.0.0/8` 命中（它是个 IPv6 形态），
于是「本该被判为可信代理的地址」被当成不可信 —— 整条链的判定就错了。

### 4.5 坑⑤：忘了 IPv6 的 `[::1]:1234` 形式

```go
// 端口剥离必须用 net.SplitHostPort（它会剥方括号），失败时才退化为「整个字符串就是主机」
func hostOnly(addr string) string {
    if host, _, err := net.SplitHostPort(addr); err == nil { return host }
    return strings.TrimSuffix(strings.TrimPrefix(addr, "["), "]")
}
```

⚠️ **不能自己按 `:` 切**：IPv6 地址里到处都是 `:`，自己切会把地址切坏。

## 五、可信列表的配置与容错

```go
// ParseTrusted 把 CIDR 字符串列表（也允许裸 IP，如 "10.0.0.1"）解析成前缀集合
```

| 输入 | 结果 | 为什么这样设计 |
|---|---|---|
| `10.0.0.0/8` | `10.0.0.0/8` | 正常 CIDR |
| `192.168.0.1`（裸 IP） | `192.168.0.1/32` | 便利：只写一个 IP 就能放行单机 |
| `10.0.0.1/8` | `10.0.0.0/8`（`Masked()` 规范化） | 避免 `Contains` 语义被误读 |
| `""`（空白项） | **跳过** | 配置里常见的尾随逗号不该让网关起不来 |
| `not-an-ip` | ⚠️ **返回错误** | 「可信代理列表写错」属于**安全配置错误**，静默吞掉等于把 XFF 变成不可信来源却不自知 |

```text
=== ② ParseTrusted 的输入容错 ===
  ["10.0.0.0/8", " 192.168.0.1 ", "", "::1"]  → [10.0.0.0/8 192.168.0.1/32 ::1/128]
  ["10.0.0.1/8"]                              → [10.0.0.0/8]
  ["not-an-ip"]                               → 报错: clientip: 第 1 个可信代理 "not-an-ip" 既不是 CIDR 也不是 IP: ParseAddr("not-an-ip"): unable to parse IP
```

⭐ **「空白项跳过、错误项报错」这个反差是刻意的**：
前者是**书写习惯**（多打了一个逗号），后者是**配置错误**（本该写网段却写错了）。
对两者一视同仁（都跳过）会让「可信列表其实没配上」这件事悄无声息。

## 六、链还有哪些形态？

**本节要点**：XFF 不只有「逗号分隔的一条链」——它还可能**拆在多个同名头里**；
链上的每个条目、以及 `RemoteAddr` 自己，也都有「带不带端口」两种写法；
而在 L4 转发场景下，**这些头压根不存在**。

### 6.1 多个同名 `X-Forwarded-For` 头

HTTP 允许同一个头出现多次，语义上等价于**按到达顺序用逗号拼接**。Go 的 `http.Header` 正好把这两种形态暴露成两个方法：

| 方法 | 行为 | 用来解析 XFF 时 |
|---|---|---|
| `h.Get("X-Forwarded-For")` | 只返回**第一个**头的值 | ❌ 链被截断，后面的跳全丢 |
| `h.Values("X-Forwarded-For")` | 返回**全部**头的值（按到达顺序） | ✅ 按序拼接才是一条完整的链 |

```text
两个头：X-Forwarded-For: 10.9.9.9        ← 第 1 跳（可信网段内的代理写下）
        X-Forwarded-For: 9.9.9.9         ← 第 2 跳（真实客户端）

  h.Values 拿到的原始值           = [10.9.9.9 9.9.9.9]
  h.Values（正确）                → 9.9.9.9
  h.Get（错误，只拿到第一个头）    → 10.9.9.9     ← 可信跳被当成客户端，真实 IP 丢了
```

⭐ 注意这里的**失败方向**：`h.Get` 既不报错也不返回空，它返回的是**一个看起来很合法的 IP**（`10.9.9.9`）。
所以在限流与审计里，表现是「客户端 IP 变成内网地址」而不是「解析失败」—— **又是一次静默失效**。

⚠️ 这与第三节并不冲突：`h.Get` 的错**不在「取最左」**，而在它**把一条两跳的链截成了只有一跳的链**，
于是「从右往左扫」这个正确算法拿到了错误的输入。**算法对、输入错，结果一样是错的。**

本包的做法是让 `parseChain` 收 `[]string` 而不是 `string`：

```go
chain := parseChain(h.Values("X-Forwarded-For"))   // 收全部同名头，内部再按 ',' 拆

func parseChain(values []string) []netip.Addr {
    var out []netip.Addr
    for _, v := range values {                       // 先遍历「多个头」
        for _, seg := range strings.Split(v, ",") {  // 再拆「一个头里的逗号列表」
            ip, ok := parseIP(hostOnly(strings.TrimSpace(seg)))
            if !ok {
                continue // 非法条目跳过并继续，不能因此放弃整条链
            }
            out = append(out, ip)
        }
    }
    return out
}
```

> 📎 `X-Real-IP` 没有这个问题（单值语义，`h.Get` 即可），代价是它**表达不了多跳** ——
> 这正是「使用」段第 3 条口径的来源。

### 6.2 带端口 / 不带端口的形态

`RemoteAddr` 一定是 `IP:port`，但 XFF 的条目**没有统一规定**要带端口 ——
实践里两种都能遇到（有的 LB 会写成 `1.2.3.4:5678`）。本机实测四种形态都能正确剥离：

```text
  XFF 单条目带端口        remote=127.0.0.1:5000  XFF=9.9.9.9:1234                    → 9.9.9.9
  XFF 中间条目带端口       remote=127.0.0.1:5000  XFF=1.1.1.1, 9.9.9.9:1234, 10.1.1.1 → 9.9.9.9
  对端不带端口            remote=127.0.0.1       XFF=9.9.9.9                         → 9.9.9.9
  对端方括号无端口          remote=[::1]           XFF=2001:db8::1                     → 2001:db8::1
```

⭐ 这四条其实都靠**同一个函数**兜住 —— `hostOnly`（先 `net.SplitHostPort`，
失败就按「整串即主机」并去掉方括号）：

```go
func hostOnly(addr string) string {
    if host, _, err := net.SplitHostPort(addr); err == nil {
        return host
    }
    return strings.TrimSuffix(strings.TrimPrefix(addr, "["), "]")
}
```

链里的每个条目、以及 `RemoteAddr` 自己，走的都是它。**只有一处实现，就不存在「某条路径忘了剥端口」的可能** ——
这也是为什么第四节坑⑤值得单独列一条：它的正确修法就是「别自己按 `:` 切」。

### 6.3 L4 场景：链上根本没有 HTTP 头

**如果前面那一跳不做 L7 终结，本节前面所有规则都不适用**：

```text
客户端 → L4 负载均衡（TCP 转发 / DNAT）→ 网关
              ↑ 只改 IP 包，不解析 HTTP
                因此没有 X-Forwarded-For、没有 X-Real-IP，也没有 Host
```

这类场景（AWS NLB、LVS/DR 模式、HAProxy `mode tcp`）想拿到真实 IP，只有两条路：

| 方案 | 怎么传 | 条件 |
|---|---|---|
| **PROXY protocol** | LB 在接受连接后、转发真实数据**之前**，先发一段独立的头：v1 是文本 `PROXY TCP4 1.2.3.4 5.6.7.8 40000 80`，v2 是二进制格式 | LB 与网关**两端都要显式开启**（HAProxy `send-proxy`、nginx `proxy_protocol`） |
| **在 LB 上做 L7 终结** | 让 LB 自己解析 HTTP，再按第二节的方式追加 XFF | LB 支持，且你愿意承担 L7 的开销 |

⭐ **PROXY protocol 的性质和 XFF 完全不同**：它**不在 HTTP 报文里**，
而是**连接建立阶段的一个独立前缀** —— 所以**客户端伪造不了**（除非它能直接连到网关的端口）。
「可信」这件事由**网络拓扑**保证，而不是靠一个网段列表去猜。
代价是**要么整条链都开这个开关、要么完全不用**：链上任何一跳没开，后面就什么都拿不到。

> ⚠️ **本节未在本机实测**：本机没有真实的 L4 负载均衡，也没有可用的转发拓扑，
> PROXY protocol 的端到端行为无法复现（它需要在 LB 与网关两端同时开启）。
> 上面是协议事实与配置项对照。落地时请在真实拓扑上验证「LB 是否真的在转发数据前发了 PROXY 头」，
> 以及网关这一侧是否已把这个前缀接进「客户端地址」的判定（否则它只是一段没人读的字节）。

## 七、`X-Real-IP` 由谁写？——「入口解析、出口复用」

**本节要点**：前六节都在讲**怎么读**转发头；但同一个头**由谁写、写的是谁**同样决定结论 ——
这里有一个极易复发的位置：入口已经解析对了，**出口又按原始地址推了一遍**。

第四节给出的上游侧口径是「取真实客户端 IP 要用 `X-Real-IP`」。那这个头是谁写的？—— **网关**（见 [反向代理原理与实现.md](反向代理原理与实现.md) 第三节）。它在 `Director` 里写。⚠️ 下面这版是**反面写法**：

```go
// ⚠️ 反面写法：从 RemoteAddr 推 —— 写出来的是「直连对端」，不一定是客户端
clientIP, _, _ := net.SplitHostPort(req.RemoteAddr)
req.Header.Set("X-Real-IP", clientIP)
```

⭐ 为什么它在 LB 后面会错：`req.RemoteAddr` **在出站请求里仍然是「入站时的直接对端」**。
这一点可以直接在标准库里核对 —— `net/http/httputil/reverseproxy.go` 里 `RemoteAddr` 只出现两次、
**都是读入站请求**，并且**从没有给 `outreq.RemoteAddr` 赋过值**（出站请求是 `req.Clone` 来的，原样带走）：

```go
// net/http/httputil/reverseproxy.go 第 519 行起（节选，注释原文）
if clientIP, _, err := net.SplitHostPort(req.RemoteAddr); err == nil {
    // If we aren't the first proxy retain prior
    // X-Forwarded-For information as a comma+space
    // separated list and fold multiple headers into one.
    prior, ok := outreq.Header["X-Forwarded-For"]
    ...
    outreq.Header.Set("X-Forwarded-For", clientIP)
}
```

⭐ 顺带两个副产品：① 它读的是**入站** `req.RemoteAddr`，所以写往上游的 XFF 末尾那一段就是**直接对端**；
② 注释里 `fold multiple headers into one` 说明标准库**自己就会把多个同名 XFF 头折叠成一条链** ——
这正是第六节 6.1 要处理的形态从哪来的。

于是「从 `RemoteAddr` 推」的后果是：

| 部署形态 | `RemoteAddr` | 写出的 `X-Real-IP` | 上游看到的「客户端」 |
|---|---|---|---|
| 网关**直接**面对客户端 | 真实客户端 | 真实客户端 ✅ | 正确 |
| 网关在 **LB 后面** | LB | **LB** ❌ | 又变回代理地址 |

这与第一节是**同一个错误的两次出现**：入口侧因为 `RemoteAddr` 不可用，我们解出了 `clientIP`；
可如果出站头仍从 `RemoteAddr` 取，等于把刚修好的问题在**出口处又写回去了**。

### 7.1 正确写法：解析结果顺着 context 走

项目里的 `X-Forwarded-User` 早就是这个写法 —— 它从 `auth.FromContext` 取，而不是从入站头里读。
真实客户端 IP 现在也走同一条路：

```go
// 入口：解析一次，并把结果挂到 ctx（internal/gateway/gateway.go）
clientIP := clientip.Resolve(r.RemoteAddr, r.Header, snap.trustedProxies)
r = r.WithContext(clientip.WithClient(r.Context(), clientIP))

// 出口：优先取 ctx 里的解析结果，只有没挂过时才退回 RemoteAddr（internal/proxy/proxy.go）
func clientIPOf(req *http.Request) string {
    if ip, ok := clientip.FromClient(req.Context()); ok {
        return ip
    }
    if host, _, err := net.SplitHostPort(req.RemoteAddr); err == nil {
        return host
    }
    return req.RemoteAddr
}
```

⚠️ 别小看那个**回落分支**：它让「直接调用 `proxy` 包 / 单测里手工构造的请求」仍有确定语义，
同时顺手修掉一个边界 —— 旧写法 `clientIP, _, _ := net.SplitHostPort(...)` **忽略了错误**，
`RemoteAddr` 不带端口时会写出一个**空的 `X-Real-IP`**。

⭐ **转码那条出口也要同源**：走 `Transcode`（HTTP → gRPC）的请求，网关把**同一个**解析结果作为
metadata `x-real-ip` 带上去（键是 `transcode.MetadataClientIP`，值同样取自 `clientip.FromClient(ctx)`）。
本机实测（起一个最小 gRPC 上游回显 metadata）：

```text
=== HTTP → gRPC 转码：客户端 IP 走 metadata ===
  ctx 里有入口解析结果               → 上游 metadata: x-real-ip=[9.9.9.9]        x-trace-id=[trace-abc]
  ctx 里没有（直接调用本包）            → 上游 metadata: x-real-ip=(缺)              x-trace-id=[trace-abc]
```

⭐ 为什么值得单独写一次：**出口不止一个**。只在 HTTP 的 `Director` 里修，同一个请求走 REST 出去带真实客户端、
走 gRPC 出去什么都没有 —— 上游两个口径，而问题会以「某个接口的审计日志里 IP 全是空的」这种形式出现。
**判据**：新增任何一条「把请求转给别人」的出口时，都要问一句「这条路上客户端是谁，和别的路一致吗」。

### 7.2 本机实测（含反例）

`go test` 里两条端到端用例（`httptest` 上游 + 真网关，见 `internal/gateway/realip_test.go`）：

```text
TestRealIPReachesUpstreamThroughContext
   入站 RemoteAddr=127.0.0.1:5000（可信代理）、XFF=9.9.9.9  → 上游看到 X-Real-IP=9.9.9.9        ✅
TestRealIPUntrustedPeerIgnoresXFF
   入站 RemoteAddr=203.0.113.9:5000（不可信）、XFF=9.9.9.9  → 上游看到 X-Real-IP=203.0.113.9   ✅（伪造无效）
```

⭐ **把出口改回「从 `RemoteAddr` 推」后，第一条立刻失败**（本机实跑，输出未加工）：

```text
realip_test.go:74: 上游看到的 X-Real-IP = "127.0.0.1"，期望 "9.9.9.9"；若为 127.0.0.1 说明出口又从 RemoteAddr 推了一遍
--- FAIL: TestRealIPReachesUpstreamThroughContext
```

那个 `127.0.0.1` 就是「LB 的地址」在单机环境里的等价物 ——
一次**请求能通、日志也正常、只是值错了**的失败，正是它最难被发现的原因。

⭐ 两条用例必须配套：只有正向那条，说明不了「走 ctx 没有绕过可信代理判定」；
而**反例必须真的跑一遍**（否则很容易写出一个「错误版居然也对」的假反例）。

⭐ 归纳成一条通用判据：**「入站时解析出来的可信身份」，出站时必须从 context 取，不能重新从原始头 / 地址推导。**
否则就是「入口修一次、出口又坏一次」，而且两次都只在**多跳部署**下才暴露 —— 本地怎么跑都是对的。

## 使用：给网关配可信代理

### 1) 配置

```json
{
  "trusted_proxies": ["10.0.0.0/8", "172.16.0.0/12", "127.0.0.1", "::1"]
}
```

⚠️ **空列表 = 没有任何可信代理**，等价于「完全不看转发头」——
这是最安全的默认值。**只有明确知道自己前面有几跳代理时，才去填它。**

### 2) 配多少跳：按实际拓扑数

```text
用户 → CDN → SLB → 网关
        ↑      ↑
      两跳都是「我们自己部署的」 → 两条网段都要写进 trusted

用户 → CDN(第三方) → SLB → 网关
        ↑ 第三方 CDN 的网段可能频繁变化，且不由你控制
      → 这种情况通常只信 SLB 那一段，第三方 CDN 的追加视情况取舍
```

⭐ **一个实用判据**：**能控制它的配置与网段的，才写进 trusted**。
把不可控的第三方网段写进去，等于把「伪造 IP」的能力交给那个第三方。

### 3) 三条使用口径

| 场景 | 该用哪个 |
|---|---|
| **限流桶的 key** | 解析后的客户端 IP（本包 `Resolve` 的输出） |
| **审计日志的 client 字段** | 同上（**不要**直接记 `RemoteAddr`，也不要直接记 XFF） |
| **需要「直连对端」时**（例如判断是不是来自本机） | `RemoteAddr`（这是唯一能直接采信的地址） |

### 4) 三个必踩的坑

| 情况 | 现象 | 判定 |
|---|---|---|
| **`trusted` 配空但依赖 XFF** | 所有请求的 client 都是 LB 地址，限流坍缩 | 空列表是**安全默认**；要生效必须显式配置 |
| **只配了 LB 那一跳，忘记 CDN** | 解析出的「客户端」其实是 CDN 节点 → 又坍缩成少数几个桶 | 按实际拓扑把**每一跳自有代理**都配上 |
| **把 `X-Real-IP` 当第一优先** | 它只有一个值（不像 XFF 是链），无法表达多跳；且同样可伪造 | 只在「对端可信且没有 XFF」时回落使用 |

> ⚠️ **单跳部署的常见误解**：如果网关**直接**面对客户端（前面没有 LB），
> 那么 `RemoteAddr` 就是真实客户端 IP，`trusted` 应当留空 ——
> 此时任何 XFF 都是伪造的。**先画清楚拓扑，再决定配什么。**

### 5) 只解析一次，三处共用同一个值

```go
// ===== 入口：整个请求只解析这一次 =====
clientIP := clientip.Resolve(r.RemoteAddr, r.Header, snap.trustedProxies)
entry.ClientIP = clientIP                        // ① 审计日志的 client 字段

// ===== ③ 限流：key = 路由 + 用户 + IP =====
g.rl.Allow(ratelimit.Key(m.Route.Name, identity.Subject, clientIP), p)

// ===== ④ 选节点：一致性哈希没有显式 key 时回落到它 =====
hashKey = clientIP
```

⭐ **这三处必须共用同一个值**，不能各算各的：否则同一个请求在限流里是 A、在日志里是 B、在哈希环上是 C。
本包的 `Resolve` 是**纯函数**（不缓存、不共享状态），所以「只调用一次」是为了**口径一致**，不是为了性能。
⭐ 同理，**出口也取这一个值**：HTTP 侧写 `X-Real-IP`（第七节 7.1），gRPC 转码侧写 metadata `x-real-ip`。
**入口解析、出口复用** —— 全链路只有一个口径，上游不管走哪条路看到的都是同一个客户端。

⚠️ 一个容易忽略的推论：`ratelimit.Key` 的设计是**登录用户按 `subject`、匿名才按 IP**：

```go
func Key(routeName, subject, ip string) string {
    if subject != "" && subject != "anonymous" {
        return "route:" + routeName + "|user:" + subject
    }
    return "route:" + routeName + "|ip:" + ip
}
```

所以 **IP 坍缩只影响匿名流量** —— 这也解释了这个问题为什么常常「迟迟没被发现」：
登录接口与内部接口看起来一切正常，只有匿名接口在 LB 后面悄悄共用一个桶。

## 延伸追问

- **为什么不干脆在 LB 上就把 XFF 清干净？** →
  可以，而且**这是最彻底的方案**（LB 作为边界，剥掉所有入站 XFF，只写自己的一份）。
  但很多时候网关前面不止一个组件、且不都由你控制 ——
  「按可信网段从右往左解析」是**在你的边界内**能做的正确事情。
- **`X-Real-IP` 和 `X-Forwarded-For` 有什么区别？** →
  `X-Real-IP` 是单个值（nginx 的惯例），`XFF` 是一条链。
  多跳场景下 XFF 信息更全（能看出经过了哪些代理），`X-Real-IP` 只能表达「最近一跳看到的客户端」。
- **`Forwarded`（RFC 7239）比 XFF 更好吗？** →
  格式更规范（能带 `proto` / `host` / `for` 且带引号与 IPv6 括号规则），
  但生态支持不如 XFF 广。实践上 XFF 仍是事实标准，
  而**因它可伪造，网关侧通常把它列入「入站一律剥离」的列表**（见 [反向代理原理与实现.md](反向代理原理与实现.md)）。
- **伪造 XFF 的攻击者能造成什么？** →
  绕过基于 IP 的限流（每次换一个假 IP）、污染审计日志、让风控把正常用户误判
  （把自己的攻击流量写成别的 IP）。**注意最后一条：伪造不只是「自己逃逸」，还能「栽赃」**。
- **IPv6 场景有什么额外坑？** →
  ① 端口的方括号形式（本节的坑⑤）；
  ② IPv4-mapped 形式（`::ffff:a.b.c.d`）必须归一，否则可信网段匹配会失败；
  ③ 一个 IPv6 网段（如 `/64`）覆盖的地址数极大，**做 IP 维度的限流时粒度会很粗**。
- **客户端 IP 能不能作为身份？** →
  不能。它只是**弱标识**：NAT 后面的用户共享 IP，同一个用户也可能在移动网络中换 IP。
  可以作为限流/风控的**加权因子**，不能当作鉴权依据。
- **L4 负载均衡（只做 TCP 转发）下没有 `X-Forwarded-For`，怎么办？** →
  两条路：**PROXY protocol**（连接建立阶段的独立前缀，可信性由拓扑保证、客户端伪造不了，
  但要求链上每一跳都开启），或**把那一跳改成 L7 终结**。详见第六节 6.3。
- **CDN 的私有头（`True-Client-IP` / `CF-Connecting-IP` / `X-Real-IP` 各家的变体）能用吗？** →
  一律当作**不可信的普通头**处理 —— 它们只是「厂商自己约定的字段名」，
  没有任何协议保证它们由谁写入。要用就把它那一跳写进 `trusted`，
  但第三方 CDN 的网段会变、且不由你控制，管控成本往往高于收益（见「使用」段第 2 条判据）。
- **多个 `X-Forwarded-For` 头要不要合并？** →
  必须。`h.Get` 只拿第一个头，会把**两跳的链截成一跳**、让「从右往左扫」这个正确算法拿到错输入，
  且失败得很安静（返回的是内网地址而不是错误）。用 `h.Values` 按序拼接，见第六节 6.1。

## 关联

- [反向代理原理与实现.md](反向代理原理与实现.md) — 网关如何写入 `X-Real-IP` / 为什么不手写 `X-Forwarded-For`；⚠️ 它写的是**对端地址**还是客户端，取决于出口有没有从 context 取（本篇第七节）
- [网络通信链路详解.md](网络通信链路详解.md) — 代理这一跳在整条链路上改了什么
- [DNS解析.md](DNS解析.md) — 另一类「客户端看起来来自哪里」的问题（就近接入与 GSLB）
- [JWT与APIKey鉴权.md](../../06-工程实践/安全/JWT与APIKey鉴权.md) — 「不信任客户端输入」这条原则在鉴权侧的体现
- [Web攻击与防御.md](../../06-工程实践/安全/Web攻击与防御.md) — 伪造头、绕过限流这类攻击的整体视角
- [负载保护.md](../../04-架构与系统/分布式/服务治理/负载保护.md) — 并发维度上的保护（与「按 IP 限流」互补）
> 反向引用（本篇被下列文档引到）：[不使用protoc的gRPC.md](../../01-编程语言/go/不使用protoc的gRPC.md)、[从零实现网关.md](../../01-编程语言/go/从零实现网关.md)、[网关路由匹配.md](../../01-编程语言/go/网关路由匹配.md)
