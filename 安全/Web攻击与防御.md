# Web 攻击与防御

> 素材来源：博客 [常见几种web攻击方式和防御方法](https://blog.csdn.net/weixin_43837229/article/details/90906032)（2019-08-04）—— 本篇按该文脉络整理，并给每一类防护补上**可跑的 Go 实测**（含真实 SQLite）。
>
> 七类最常见攻击的**原理、危害与防护**：SQL 注入 / XSS / CSRF / HTTP 头注入 / Cookie 窃取 / 上传文件攻击 / DDoS。

---

## 一、网站为什么必须自己扛安全？

网站的安全对网站的可持续发展不言而喻：**一个不安全的网站，轻则被攻击导致宕机、窃取用户隐私、被冒名发不良广告导致用户流失，重则影响公司形象甚至惹上官司**。

安全要从三方面入手（对照 [加密算法.md](加密算法.md) 的第一节）：

| 目标 | 解决什么 | 典型手段 |
|---|---|---|
| **认证** | 数据发给正确的服务器 / 确认请求者是谁 | 口令 + 会话、Token、双向证书 |
| **加密** | 数据中途不被窃取 | TLS、对称 + 非对称算法 |
| **完整性** | 数据不被篡改 | 摘要、HMAC、数字签名 |

⚠️ 这三件事**算法层面都有成熟方案**，但线上真正被打穿的从来不是算法，而是**输入没被当数据处理**——本文七类攻击里有五类根源是这一条。

---

## 二、SQL 注入：为什么"拼接 SQL"就是送钥匙？

### 2.1 原理与六个常见成因

SQL 注入是**把 SQL 命令插入到 Web 表单提交、域名或页面请求的查询字符串中，最终欺骗服务器执行恶意的 SQL 命令**。它利用的是应用程序把**用户输入当成了 SQL 代码**这一点。

根据技术原理，SQL 注入可分为**平台层注入**（不安全的数据库配置或数据库平台漏洞）与**代码层注入**（程序员对输入未做过滤，执行了非法数据查询）。

产生原因通常是这六条：

1. 不当的类型处理；
2. 不安全的数据库配置；
3. 不合理的查询集处理；
4. 不当的错误处理；
5. 转义字符处理不合适；
6. 多个提交处理不当。

### 2.2 两个经典 payload（以及"不加注释为什么打不进去"）

以登录为例，服务端拼出来的语句是：

```sql
SELECT * FROM `table` WHERE `user` = 'abc' AND `password` = '';
```

**payload ①：恒真式** —— 密码栏输入 `' OR '1'='1'`，语句变成：

```sql
SELECT * FROM `table` WHERE `user` = 'abc' OR '1' = '1' AND `password` = '';
```

⚠️ **这里有个坑**：SQL 里 `AND` 的优先级**高于** `OR`，上式实际等价于 `user='abc' OR ('1'='1' AND password='')` —— 只要密码不为空，仍然打不进去。实测证实了这一点（见 2.4）。

**payload ②：恒真式 + 注释** —— 输入 `' OR '1'='1' --`，`--` 把后面的密码条件整段注释掉：

```sql
SELECT * FROM `table` WHERE `user` = 'abc' OR '1' = '1' -- ' AND password = '';
```

这才是免密登录的标准写法。同理 `admin' --` 也能直接以 `admin` 身份登录。

**猜表名 / 列名**（有回显时）：输入直接拼到 SQL 后面，**返回正确就说明猜对了**。

```sql
AND 0 <> (select count(*) from admin);        -- 判断是否存在 admin 表
AND (select count(`name`) from admin) <> 0;   -- 猜列名
```

### 2.3 防护：八条通用原则 + 落到语言

**通用八条**：

1. **永远不要信任用户的输入**：校验（正则 / 长度限制）、转义单引号与双引号；
2. **永远不要动态拼装 SQL**：用**参数化查询**或存储过程；
3. **永远不要用管理员权限的数据库连接**：每个应用单独一个权限受限的连接；
4. **不要把机密信息直接存放**：密码等敏感信息加密或散列（见 [密码与敏感信息存储.md](密码与敏感信息存储.md)）；
5. **异常信息尽量少提示**：用自定义错误信息包装原始错误，别把 SQL 报错回显给前端；
6. **输入验证客户端 + 服务端都做**——客户端脚本可被绕过，服务端验证才是唯一有效的；
7. **数据库最小权限**：不需要的表不给权限；只读账号就禁止 `drop`/`insert`/`update`/`delete`；
8. **上线前做安全审评**，每次更新都要做（别因为"只是个小改动"跳过）。

**语言层落地**：PHP 里常见的是 `is_numeric()` 校验类型、正则匹配合法字符（`preg_match`），并且**用 PDO 的参数绑定 / 预编译**。

⚠️ **原文提到的 `addslashes` 只作对照，不要当防护手段**：它按字节加反斜杠，在 GBK 等多字节字符集下存在**宽字节注入**（`%df'` 被转义成 `%df%5c%27` 后，`%df%5c` 恰好组成一个汉字），且无法覆盖数字型注入。**参数化查询才是唯一可靠的解法**——它让输入永远只是数据，不可能变成语法。

### 2.4 实测：同一个输入，拼接得手、参数化失效 ⭐

用真实 SQLite（`modernc.org/sqlite`，纯 Go 驱动，无需 cgo）建一张 `users` 表，只放一行 `admin / S3cret!`：

```go
_, err = db.Exec(`CREATE TABLE users(id INTEGER PRIMARY KEY, username TEXT, password TEXT)`)
mustErr(err)
_, err = db.Exec(`INSERT INTO users(id, username, password) VALUES (1, 'admin', 'S3cret!')`)
mustErr(err)

// ① 拼接：把用户输入直接塞进 SQL
unsafe := fmt.Sprintf("SELECT id FROM users WHERE username = '%s' AND password = '%s'", user, pwd)
err = db.QueryRow(unsafe).Scan(&id)

// ② 参数化：占位符，输入永远只是数据
err = db.QueryRow("SELECT id FROM users WHERE username = ? AND password = ?", user, pwd).Scan(&id)
```

```text
拼出的 SQL： SELECT id FROM users WHERE username = 'x' OR '1'='1' AND password = 'whatever'
拼接查询结果：err=sql: no rows in result set → 登录失败（AND 优先级高于 OR，所以这次没得手）
拼出的 SQL： SELECT id FROM users WHERE username = 'x' OR '1'='1' --' AND password = 'whatever'
拼接查询结果：err=<nil> → 登录成功
拼出的 SQL： SELECT id FROM users WHERE username = 'admin' --' AND password = 'whatever'
拼接查询结果：err=<nil> → 登录成功（连密码都不用知道）
参数化查询（输入 "x' OR '1'='1"  ）结果：err=sql: no rows in result set → 登录失败
参数化查询（输入 "x' OR '1'='1' --"）结果：err=sql: no rows in result set → 登录失败
参数化查询（输入 "admin' --"     ）结果：err=sql: no rows in result set → 登录失败
```

⭐ **三条结论**：① 不加注释的恒真式**打不进去**（`AND` 优先级）；② 加了注释、或者 `admin' --` 就能**完全绕过口令**；③ 同一个输入走参数化，三次全部失败——**输入永远只是数据**。

---

## 三、XSS：被注入的是"脚本"，不是"数据"

### 3.1 定义与危害

XSS 是 Web 应用里常见的**计算机安全漏洞**：它允许恶意 Web 用户**将代码植入到提供给其他用户使用的页面中**（HTML 代码与客户端脚本）。攻击者利用 XSS 可以**旁路访问控制**（例如同源策略），因此常被用来做危害更大的**网络钓鱼**。

**危害**：窃取用户 Cookie、密码等重要数据，进而**伪造交易、盗取用户财产与个人信息**。

### 3.2 防护

1. **输入过滤**：过滤和消毒用户输入，对特殊字符做转义；
2. **输出转义**（原文之外的现代重点 ⭐）：XSS 的本质是 **「用户数据被放进了 HTML/JS 的语法位置」**，所以真正必须做的是**按输出上下文转义**——HTML 正文、属性、URL、JS 字符串各有各的转义规则；
3. **HttpOnly**：禁止页面 JS 访问带该属性的 Cookie，从而**防止 XSS 窃取 Cookie**；
4. **CSP（内容安全策略）**：限制脚本只能从同源 / 白名单加载，让注入的 `<script>` 直接失效。

### 3.3 实测：转义、主动放行与差别

同一个 payload `<img src=x onerror=alert(document.cookie)>` 走三条路：

```go
// ① 手工拼接（危险）
fmt.Printf("<div>%s</div>", payload)

// ② html/template：自动按上下文转义
tpl := template.Must(template.New("t").Parse("<div>{{.}}</div>"))
tpl.Execute(&buf, payload)

// ③ 主动放行（只有确定内容可信时才用）
template.Must(template.New("t2").Parse("<div>{{.}}</div>")).Execute(&buf2, template.HTML(payload))
```

```text
① 手工拼接（危险）：<div><img src=x onerror=alert(document.cookie)></div>
② html/template 转义：<div>&lt;img src=x onerror=alert(document.cookie)&gt;</div>
③ template.HTML 主动放行（危险）：<div><img src=x onerror=alert(document.cookie)></div>
```

⭐ ①③ 一模一样——**「放行」等于把拼接的危险重新引回来**；② 才是安全默认值。

---

## 四、CSRF：冒用你的身份发一个"合法"请求

### 4.1 与 XSS 的本质区别

CSRF（Cross-site request forgery，**跨站请求伪造**，也叫 One Click Attack / Session Riding）是一种对网站的恶意利用。**它听起来像 XSS，但与 XSS 非常不同**：

| | XSS | CSRF |
|---|---|---|
| 利用的是 | 站点内**对用户的信任** | 站点**对用户浏览器的信任**（Cookie / Session 策略） |
| 攻击者要什么 | **在页面里执行脚本** | **借你的身份发一个请求** |
| 难度 | 较低、防不胜防 | 较难，但**被认为比 XSS 更危险** |

**危害**：攻击者以用户合法身份做非法操作，如**转账交易、发布假消息**。

### 4.2 三种防护

| 手段 | 做法 | 代价 / 局限 |
|---|---|---|
| **Referer 校验** | 检查请求来源域是否合法（防图片盗链也是这个原理） | ⚠️ 该字段**可以为空或手动修改**，只能提高攻击成本，无法完全防护 |
| **表单 Token** | 页面表单里放一个随机数 Token，每次响应的值都不同；伪造请求**拿不到**该值 | 用得最多；需注意**存服务端**或 HMAC 签名，且**不能放在 Cookie 里** |
| **验证码** | 与 Token 同理，随机参数伪造者无从得知 | ⚠️ 影响体验，只在交易、登录等必要场景用 |

⭐ **现代补充：`SameSite` Cookie**（首选）。把会话 Cookie 设为 `SameSite=Lax`（或 `Strict`），**跨站请求根本不会带上 Cookie**，CSRF 的立足点直接被抽掉——这比在业务代码里逐个校验 Referer 可靠得多。

### 4.3 实测：Token 生成与校验

用 HMAC 把「服务端密钥 + 会话 ID」签成 Token，**服务端不用存、攻击者造不出**：

```go
func csrfToken(secret []byte, sessionID string) string {
	m := hmac.New(sha256.New, secret)
	m.Write([]byte(sessionID))
	return hex.EncodeToString(m.Sum(nil))
}
// 校验
ok := hmac.Equal([]byte(csrfToken(secret, sid)), []byte(token))
```

```text
服务端下发的 token（前 16 位）：7b3b4ea15c068f89…
合法请求（victim 的 session + 正确 token）→ true
攻击者伪造（自己的 session + 猜的 token）→ false
攻击者劫持（victim 的 session + 猜的 token）→ false
→ 没有 secret 就算拿到别人的 session 也造不出 token；token 只放表单、不放 cookie，跨站拿不到
```

---

## 五、HTTP 头注入：一个 CRLF 就能凭空造出一个头

### 5.1 原理

浏览器访问任何 Web 站点都用到了 HTTP 协议。⚠️ **HTTP 响应在 headers 与 content 之间有一个空行，即两组 `CRLF`（`0x0D 0x0A`）字符**——这个空行标志着 headers 结束、content 开始。**只要攻击者能把任意字符注入到 headers 中，这种攻击就可能发生。**

典型场景是登录后的重定向：`?page=http://localhost/index` 被直接写进 `Location` 头。把参数改成：

```text
http://localhost/checkout%0D%0A%0D%0A%3Cscript%3Ealert(%27hello%27)%3C%2Fscript%3E
```

解码后就是 `http://localhost/checkout` + **两组 CRLF** + `<script>alert('hello')</script>`。于是响应变成：

```text
HTTP/1.1 302 Moved Temporarily
Location: http://localhost/checkout<CRLF>
<CRLF>
<script>alert('hello')</script>
```

页面因此**意外地执行了藏在 URL 里的 JavaScript**。⚠️ 同类问题不止发生在 `Location`，也可能出现在 `Set-Cookie` 等任何 header 上——**攻击者可以自己造一个 `Set-Cookie: evil=value`**（会话固定）。

### 5.2 实测：标准库改写、裸报文拆行 ⭐

同一个输入 `http://ok/\r\nSet-Cookie: evil=1`，交给 Go 标准库 vs 自己拼裸报文：

```go
// ① handler 里把未过滤的参数写进 header
w.Header().Set("Location", r.URL.Query().Get("page"))

// ② 看它「真正写到线上时」长什么样
rec.Header().Write(&wire)

// ③ 自己拼裸报文
fmt.Fprintf(&buf, "HTTP/1.1 302 Found\r\nLocation: %s\r\n\r\n", raw)
```

```text
① handler 里设的原始值："http://ok/\r\nSet-Cookie: evil=1"
② 实际写出的头（CRLF 被 net/http 换成空格，注入不成立）：
   Location: http://ok/  Set-Cookie: evil=1
③ 手工拼的裸报文，被拆成这些行：
   [0] "HTTP/1.1 302 Found"
   [1] "Location: http://ok/"
   [2] "Set-Cookie: evil=1"
   [3] ""
   [4] ""
```

⭐ **结论**：`net/http` 在写 header 时会把换行**替换成空格**（这也是它默认免疫的原因），而**手写裸报文时同一个输入会真的变成两个头**。生产含义：**能用框架的 header API 就别自己拼响应报文**。

### 5.3 防护与 8K 上限

- **过滤所有响应头**，去掉非法字符，尤其是 `CRLF`；
- **限制请求头大小**：Apache 默认限制 request header 为 **8K**，超过返回 `400 Bad Request`。⚠️ 反过来说也是一类攻击——**把超过 8K 的 header 链接发给受害者**，会让其被服务器拒绝访问（拒绝服务）。解法：**检查 Cookie 大小、限制新增 Cookie 的总大小**。

---

## 六、Cookie 窃取：HttpOnly 是最后一道闸

### 6.1 原理

通过 JavaScript 非常容易访问当前网站的 Cookie——打开任何网站，在地址栏输入 `javascript:alert(document.cookie)` 就能看到（如果有的话）。攻击者可以**和 XSS 配合**，在你的浏览器上执行脚本、取走 Cookie；**如果这个网站仅依赖 Cookie 验证身份，攻击者就能假冒你的身份**。

### 6.2 实测：三种属性的差别

```go
weak := &http.Cookie{Name: "session", Value: "v1ctim", Path: "/"}
strong := &http.Cookie{Name: "session", Value: "v1ctim", Path: "/",
	HttpOnly: true, Secure: true, SameSite: http.SameSiteLaxMode}
```

```text
① 默认（JS 可读，document.cookie 拿得到）：session=v1ctim; Path=/
② 加固后（JS 读不到、只走 HTTPS、跨站不带）：session=v1ctim; Path=/; HttpOnly; Secure; SameSite=Lax
```

| 属性 | 挡住什么 |
|---|---|
| **HttpOnly** | 页面 JS 读不到（`document.cookie` 取不到）→ XSS 偷 Cookie 的最后一道闸 |
| **Secure** | 只在 HTTPS 下发送，防明文链路上被嗅探 |
| **SameSite** | 跨站请求不带它 → CSRF 的立足点消失 |

⚠️ **HttpOnly 挡不住 XSS 本身**：攻击者读不到 Cookie，但可以在你的页面上**直接发起请求**（以你的身份操作）。所以 XSS 的根治仍然靠转义与 CSP。

---

## 七、上传文件攻击：改个扩展名就能拿 shell

### 7.1 原理

攻击者利用网站的上传功能（头像、图片、视频）**上传可执行程序**，再通过该程序获得**服务器端命令执行能力**，进而在服务器上为所欲为。

### 7.2 实测：扩展名白名单 vs 内容嗅探

以「一个 PHP 木马改名为 `avatar.jpg`」为例，同时做两道检查：

```go
extOK := strings.HasSuffix(strings.ToLower(name), ".jpg") || strings.HasSuffix(strings.ToLower(name), ".png")
sniff := http.DetectContentType(data) // 读文件头判断真实类型
ctOK := sniff == "image/png" || sniff == "image/jpeg"
```

```text
avatar.jpg     内容类型=text/plain                   扩展名白名单=true  嗅探白名单=false → 拒绝
avatar.jpg     内容类型=image/png                    扩展名白名单=true  嗅探白名单=true  → 放行
shell.php      内容类型=text/plain                   扩展名白名单=false 嗅探白名单=false → 拒绝
```

⭐ **只查扩展名会被「改名的木马」直接绕过**（第一行：扩展名合规、内容却是 PHP 源码），必须**扩展名 + 内容嗅探双白名单**。

### 7.3 生产四件套

1. **限制上传文件类型**（白名单，不允许"黑名单不含即通过"）；
2. **上传存到专门位置**，避免影响服务器正常运行——**绝不要放在 Web 根目录的可执行路径下**；
3. **改名 + 打散目录**：用随机名（保留扩展名）而非原名，避免覆盖与路径穿越；
4. **上传域独立**：文件放到对象存储 / 独立域名，配 `X-Content-Type-Options: nosniff`，让文件即便被访问也不会被当成脚本执行。

---

## 八、DDoS：为什么单靠"防"防不住

**定义**：分布式拒绝服务（DDoS）攻击借助客户/服务器技术，**把多个计算机联合起来作为攻击平台**，对一个或多个目标发动攻击，从而**成倍地提高拒绝服务攻击的威力**：攻击者用一个窃取的账号在主控机上装 DDoS 主控程序，主控程序与大量已植入代理程序的机器通讯，**几秒钟内就能激活成百上千个代理**一起打。

原文给的六条缓解措施：

1. 全面综合地设计网络的安全体系，注意所用的安全产品与网络设备；
2. 提高网络管理人员素质，及时升级系统、加强抗攻击能力；
3. 加装防火墙，对所有出入数据包做过滤与边界安全规则检查；
4. **优化路由及网络结构**，合理设置路由器，降低被攻击的可能；
5. 限制对外提供公开服务的主机；
6. 安装入侵检测工具，定期扫描、加密系统文件并检查其变化。

⭐ **现代补充（更关键的部分）**：DDoS 的流量往往远超单机带宽，**"防"的第一步是"扛"**——用 **CDN / Anycast** 把流量分散到边缘、用**清洗中心**做特征过滤，在接入层用 **SYN Cookie / 连接数限制**防握手耗尽，应用层用**限流与降级**保住核心接口（见 [限流降级熔断.md](../分布式/限流降级熔断.md)），并配合**弹性扩容**吸收脉冲流量。

⚠️ 一个容易答错的点：**应用层限流挡不住 L3/L4 的流量型攻击**（带宽被打满时，请求根本到不了你的限流器）。**分层防护**才是答案：流量层靠清洗、连接层靠 SYN Cookie、应用层靠限流降级。

---

## 九、七类攻击速查：入口 / 得逞条件 / 那道闸

| 攻击 | 入口 | 得逞条件 | 一道闸 |
|---|---|---|---|
| **SQL 注入** | 一切拼 SQL 的输入（表单、URL、Header） | 输入被当代码解析 | **参数化查询**（预编译） |
| **XSS** | 用户内容回显到页面 | 未按上下文转义 | **输出转义** + CSP + HttpOnly |
| **CSRF** | 跨站表单 / 图片 / 链接 | 请求自动带上 Cookie | **SameSite Cookie** + 表单 Token |
| **HTTP 头注入** | 未过滤的 header 值（如重定向参数） | 值里含 CRLF | **过滤 CRLF** + 用框架 header API |
| **Cookie 窃取** | 配合 XSS 执行脚本 | Cookie 无 HttpOnly | **HttpOnly + Secure + SameSite** |
| **上传文件攻击** | 上传接口 | 只校验扩展名 / 存到可执行目录 | **双白名单 + 改名 + 独立域** |
| **DDoS** | 网络层 / 传输层 / 应用层 | 流量或连接数打满 | **CDN 清洗 + SYN Cookie + 限流降级** |

---

## 使用：把安全基线装到服务上

七类攻击里能"顺手做掉"的防护，都可以收进一个中间件 + 一份检查清单。实测的响应头如下：

```go
func SecurityHeaders(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		h := w.Header()
		h.Set("X-Content-Type-Options", "nosniff") // 禁止浏览器猜 MIME（挡住"改名木马"被判成脚本）
		h.Set("X-Frame-Options", "DENY")           // 禁止被 iframe 嵌套（点劫持）
		h.Set("Content-Security-Policy", "default-src 'self'; script-src 'self'") // 脚本只允许同源加载
		h.Set("Strict-Transport-Security", "max-age=31536000; includeSubDomains") // 一年内强制 HTTPS
		h.Set("Referrer-Policy", "strict-origin-when-cross-origin")
		h.Set("Content-Type", "text/html; charset=utf-8")
		http.SetCookie(w, &http.Cookie{Name: "session", Value: sid, Path: "/",
			HttpOnly: true, Secure: true, SameSite: http.SameSiteLaxMode})
		next.ServeHTTP(w, r)
	})
}
```

```text
GET /demo 实际写出 HTTP 200：
  Content-Security-Policy: default-src 'self'; script-src 'self'
  Content-Type: text/html; charset=utf-8
  Referrer-Policy: strict-origin-when-cross-origin
  Set-Cookie: session=v1ctim; Path=/; HttpOnly; Secure; SameSite=Lax
  Strict-Transport-Security: max-age=31536000; includeSubDomains
  X-Content-Type-Options: nosniff
  X-Frame-Options: DENY
  Body: <h1>ok</h1>
```

**上线前逐条对照**：

| # | 检查项 | 怎么做 |
|---|---|---|
| 1 | 所有 SQL 都是参数化 | 全局搜 `fmt.Sprintf` / 字符串拼接进 SQL 的代码；ORM 的 `Raw` 也要传参 |
| 2 | 模板默认转义 | 用 `html/template` 而非 `text/template`；`template.HTML` 只用于可信内容 |
| 3 | 会话 Cookie 三件套 | `HttpOnly` + `Secure` + `SameSite=Lax`（跨站要用的单独开口子） |
| 4 | 有状态变更的接口校验 CSRF Token，或依赖 SameSite | 双提交 Cookie / HMAC Token，二选一但别漏 |
| 5 | 重定向参数做白名单 | 别直接把 `?page=` 写进 `Location`（防跳转钓鱼 + 头注入） |
| 6 | 上传走双白名单 + 独立域 | 扩展名 + `http.DetectContentType`；存对象存储、随机名、禁执行 |
| 7 | 错误信息不回显 | 统一错误页 / 错误码，SQL 异常只落日志 |
| 8 | 数据库连接最小权限 | 业务账号无 `DROP`，只读账号禁写 |

---

## 延伸追问

- **SQL 注入最有效的防护是什么？为什么 `addslashes` 不够？** → **参数化查询**（预编译）。`addslashes` 按字节转义，GBK 等多字节字符集下可被**宽字节注入**绕过，且对数字型注入无效；参数化让输入永远只是数据、不参与语法解析。
- **为什么 `' OR '1'='1` 有时打不进去？** → SQL 里 **`AND` 优先级高于 `OR`**，`user='x' OR ('1'='1' AND password='')` 仍然要求密码匹配。实测证实：必须补 `--` 把后续条件注释掉（或改用 `admin' --`）才得手。
- **XSS 和 CSRF 的区别是什么？** → XSS 利用**站点对用户的信任**（在页面里执行脚本）；CSRF 利用**站点对用户浏览器的信任**（借 Cookie 自动携带的特性冒用身份）。防御也完全不同：XSS 靠输出转义 + CSP，CSRF 靠 SameSite + Token。
- **为什么"过滤输入"防不住 XSS？** → 因为 XSS 是**输出上下文**问题：同一段文本放进 HTML 正文、属性、URL、JS 字符串里，需要的转义规则各不相同。应在**输出点按上下文转义**，输入过滤只能作为纵深防御的一层。
- **CSRF Token 为什么不能放 Cookie？** → 放 Cookie 后浏览器会自动携带，攻击者的跨站请求照样带上，等于没防。Token 要放在**表单/自定义头**里（跨站拿不到），或者用**双提交 Cookie**（把同一个值既放 Cookie 又放请求体，服务端比对）。
- **HttpOnly 能防住 XSS 吗？** → 不能。它只让 JS 读不到 Cookie，攻击者仍能在页面里**以你的身份发请求**（XSS 的直接危害）。它是"降低 Cookie 被带走"的最后一道闸，不是 XSS 的解法。
- **CRLF 注入为什么在 Go 里"测不出来"？** → 因为 `net/http` 在写 header 时把换行**替换成空格**（实测：`Location` 值里的 `\r\n` 变成了两个空格），等于框架默认做了防护。自己拼裸报文（或换了不校验的组件）就会真的拆出第二个头。
- **DDoS 用限流能解决吗？** → 只能解决**应用层**那部分。L3/L4 的流量型攻击会把带宽和连接打满，请求根本到不了限流器。要分层：流量层 CDN/清洗中心，连接层 SYN Cookie，应用层限流 + 降级。
- **上传功能只校验扩展名行不行？** → 不行。实测：内容是 PHP 源码、名字改成 `avatar.jpg` 的文件能通过扩展名校验（只有内容嗅探才拦得住）。生产要扩展名 + 内容双白名单，并改名、存独立域、禁执行。

---

## 关联

- [加密算法.md](加密算法.md) — 第一节那三方面（认证/加密/完整性）的算法底座
- [摘要与数字签名.md](摘要与数字签名.md) — 防篡改与防抵赖：CSRF Token、会话签名的常用手段
- [数字证书与PKI.md](数字证书与PKI.md) — HTTPS 如何把上面这些防护放进一条可信链路
- [密码与敏感信息存储.md](密码与敏感信息存储.md) — SQL 注入之外的另一条"数据泄露"路径，以及慢哈希
- [../网络/HTTPS与TLS.md](../网络/HTTPS与TLS.md) — Secure / HSTS 起作用的协议背景
- [../分布式/限流降级熔断.md](../分布式/限流降级熔断.md) — DDoS 与应用层过载的限流、降级、熔断
- [../素材清单.md](../素材清单.md) — 本篇的博客原文登记

> 反向引用（本篇被下列文档引到）：[OpenResty.md](../中间件/OpenResty.md)、[网关选型.md](../中间件/网关选型.md)、[数据导入导出设计.md](../分布式/系统设计/数据导入导出设计.md)
