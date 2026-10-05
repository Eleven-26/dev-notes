# OpenResty

> OpenResty 的定位（为什么要在 Nginx 里跑脚本）、**执行阶段模型**、够用版的 Lua 语法、nginx 配置语法要点（location 优先级 / `proxy_pass` 拼接 / `rewrite` / `limit_req`，**全部本机实测**）、`ngx.*` API 速查，以及六个典型应用（鉴权 / 限流 / 灰度 / 动态路由 / WAF / 聚合）。
>
> ⭐ 本篇的 **nginx 配置行为**用本机 `nginx/1.15.11` 建隔离实例**真跑验证**（location 优先级 5 例、`proxy_pass` 2 例、`rewrite` 3 例、`limit_req` 正反例对照）；**Lua 部分本机没有 OpenResty / LuaJIT，未实跑**，语法与 API 以官方文档为准——文中已逐处标注。
>
> 内容整理自个人学习笔记，素材见 [素材清单](../../../素材清单.md)。限流的算法原理见 [限流算法.md](../../../04-架构与系统/分布式/服务治理/限流算法.md)，跨机限流与 Redis+Lua 见 [稳定性三件套.md](../../../04-架构与系统/分布式/服务治理/稳定性三件套.md)；网关选型见 [中间件选型.md](../中间件选型.md) 第四节。

---

## 一、OpenResty 是什么？为什么不直接在 Nginx 里写 C 模块？

**本节要点**：OpenResty **不是另一个服务器**，它就是 Nginx（配置 100% 兼容）**加上 LuaJIT 和一个脚本运行时**。理解它要回答一个问题：**只改配置的 Nginx，什么时候不够用？**

### 1.1 Nginx 的天花板在哪

Nginx 的配置是**声明式**的，规则在**启动 / reload 时**就定死了。这带来三个"配置改不动"的困境：

| 困境 | 例子 |
|---|---|
| **要按请求内容动态决策** | 按 JWT 里的 `company_id` 路由到不同 upstream；按 `User-Agent` 决定是否限流 |
| **决策前要访问外部资源** | 先查 Redis 才知道这个 token 有没有被吊销；先查配置中心才知道这条路由存不存在 |
| **改一条规则要 reload** | 动态路由、灰度比例调整、黑名单更新——reload 有代价，且频繁 reload 会掉连接 |

### 1.2 三条出路，OpenResty 是第三条

| 出路 | 代价 |
|---|---|
| **写 C 模块** | 开发慢、内存安全自担、每次升级 Nginx 都要重新编译 |
| **放到上游服务**（网关自己写一版） | 多一跳网络开销；占用应用运行时的资源；性能天花板低于 Nginx |
| ⭐ **OpenResty（在 Nginx 进程内跑 LuaJIT）** | 脚本在**同一个进程内**执行，性能接近 C；改脚本**不用重编也不重启** |

**OpenResty = Nginx + LuaJIT + 一组 `lua-resty-*` 库**（`lua-resty-core`、`lua-resty-redis`、`lua-resty-mysql`、`lua-resty-limit-traffic`、`lua-resty-string`、`lua-resty-upload` …）。

⭐ 所以它的定位一句话：**请求入口上的编程**——南北向 API 网关、WAF、动态路由、限流、响应聚合。

⚠️ 两个常见误解：

1. **"OpenResty 是 Nginx 的替代品"** —— 不是。它**就是** Nginx，`nginx.conf` 的语法、模块、指令全部照旧；Lua 只是多出来的一种"可编程能力"。
2. **"用了 Lua 就会变慢"** —— 关键看**写在哪**。LuaJIT 把热点代码编译成机器码，简单判断与字符串处理的性能接近 C；真正拖慢的是**用错阶段**（比如在 `log_by_lua` 里做阻塞 I/O）和**每次请求都重新 `require` 模块**。

---

## 二、执行阶段：理解 OpenResty 的关键 ⭐

**本节要点**：Nginx 处理一个请求是一条**流水线**，每个阶段只允许做特定的事。**「这段逻辑该写在哪个阶段」是 OpenResty 里最核心的判断题**——写错阶段，代码要么不执行、要么报错。

### 2.1 Nginx 的阶段与对应的 Lua 入口

| Nginx 阶段 | OpenResty 指令 | 典型用途 | 该阶段**不能**做什么 |
|---|---|---|---|
| （配置加载，master 进程） | `init_by_lua*` | 预加载模块、初始化共享内存 | 不能用 `ngx.var` / `ngx.req` 等请求级 API |
| （worker 启动） | `init_worker_by_lua*` | 起定时器、预热连接池 | 没有请求上下文 |
| **SERVER_REWRITE / REWRITE** | `set_by_lua*` / `rewrite_by_lua*` | 改 URI、重定向、（轻量）变量赋值 | 不能产生响应体（`set_by_lua` 只能返回一个值） |
| **PREACCESS / ACCESS** | `access_by_lua*` | ⭐ **鉴权、限流、IP 白名单** | — |
| **CONTENT** | `content_by_lua*` | ⭐ 生成响应（`ngx.say`）、调子请求、调 Redis | 一个 location 只能有一个 content handler |
| （上游选择） | `balancer_by_lua*` | 动态选 upstream（服务发现） | 只能决定"发给谁" |
| （响应头过滤） | `header_filter_by_lua*` | 改响应头、算耗时 | 不能改响应体 |
| （响应体过滤） | `body_filter_by_lua*` | 改/替换响应体（可能被**多次**调用） | 不能假设一次拿到完整 body |
| （日志） | `log_by_lua*` | 打点、异步上报 | ⚠️ 不能做阻塞 I/O（会拖住连接） |
| （TLS 握手） | `ssl_certificate_by_lua*` | 按 SNI 动态选证书 | — |

⭐ **一句话判据**：

- **要拦人**（鉴权、限流、黑名单）→ `access_by_lua*`
- **要改 URI**（重写、跳转）→ `rewrite_by_lua*`
- **要产出响应**（自己返回数据）→ `content_by_lua*`
- **要改别人的响应**（加头、改 body）→ `header_filter_by_lua*` / `body_filter_by_lua*`

### 2.2 ⭐ 本机实测：阶段顺序不是"背下来"的，是能测出来的

「阶段到底谁先谁后」有一个非常干净的验证方式——**把限流和 `return` 放在同一个 location 里**。

本机 `nginx/1.15.11` 隔离实例，两个 location 配**完全相同**的限流参数（`rate=2r/s, burst=1, nodelay`），唯一差别是**有没有 content handler**：

```nginx
# ④a 反例：本 location 里有 return
location = /limited-return {
    limit_req zone=perip burst=1 nodelay;
    return 200;
}

# ④b 正例：交给静态文件做 content handler
location = /limited {
    limit_req zone=perip burst=1 nodelay;
    root html;
}
```

连发 6 个请求的实测结果：

```text
④a /limited-return（内含 return 200）: [200, 200, 200, 200, 200, 200]   → 限流完全没生效
④b /limited（静态文件当 content handler）: [200, 200, 503, 503, 503, 503] → 第 3 个起被拒
再等 2 秒单发 1 个: 200（令牌补充回来了）
```

⭐ **根因**（这正是阶段模型的直接证据）：

```text
nginx 阶段顺序:  REWRITE  →  POST_REWRITE  →  PREACCESS  →  ACCESS  →  CONTENT
                   ↑                            ↑
               return / rewrite 在这里      limit_req 在这里

④a：return 在 REWRITE 阶段就把请求结束了 → limit_req 所在的 PREACCESS 根本没跑到
④b：没有 return，请求正常走到 PREACCESS → 限流生效
```

⚠️ **推论（很容易踩）**：**任何用 `return` 直接返回的 location，写在它里面的 `limit_req` / `access_by_lua*` 都可能够不着**。这解释了一个常见困惑——"我明明配了限流，为什么测不出来"：不是限流错了，是**测试用的 location 太早返回了**。

> 同理，OpenResty 里的**鉴权必须写在 `access_by_lua*`**而不是 `rewrite_by_lua*`：写在 rewrite 阶段虽然在 `limit_req` 之前，但它**挡不住**后续阶段的逻辑（写错了也能"跑通"，直到某天发现限流没生效）。

---

## 三、Lua 语法（够用版）

**本节要点**：只讲 **OpenResty 里真会用到的** Lua 子集，重点是**和主流语言不一样、容易写错**的地方。

⚠️ **本机没有 OpenResty / LuaJIT，本节与第五节的 Lua 代码未实跑**，语法以 Lua 5.1 / LuaJIT 官方手册为准。

### 3.1 `local` 必须写：这是第一条军规

```lua
-- ❌ 不写 local：变量进全局表 _G
count = 0

-- ✅ 写 local：局部变量，作用域到当前块
local count = 0
```

为什么在 OpenResty 里**尤其**要写 `local`：

1. **`_G` 是所有请求共享的**——不写 `local` 就是跨请求污染（一个用户的数据可能被另一个用户的请求改掉）；
2. **访问全局变量要走一次哈希查找**，`local` 是寄存器访问，在热点路径上差距明显；
3. 惯用法是把常用 API 先本地化：

```lua
-- ✅ 常见做法：模块顶部把常用函数/模块拿到 local
local ngx_req   = ngx.req
local ngx_var   = ngx.var
local tonumber  = tonumber
local string_format = string.format
```

### 3.2 ⚠️ 真值判断：只有 `nil` 和 `false` 为假

这是**最容易踩的坑**——Lua 里 **`0` 和 `""` 都是真**：

```lua
if 0 then     ngx.say("0 为真")  end   -- ✅ 会打印
if "" then    ngx.say("空串为真") end  -- ✅ 会打印
if nil then   ngx.say("不会打印") end   -- 不会打印
if false then ngx.say("不会打印") end   -- 不会打印
```

对照其它语言：C / PHP / JS 里 `0` 和 `""` 通常是假，Lua 里**不是**。所以从 `ngx.var` 读到的值（**永远是字符串**）要显式转换：

```lua
-- ⚠️ ngx.var.xxx 永远是字符串；缺失时是 nil
local uid = tonumber(ngx.var.arg_uid)        -- "123" → 123；nil/非数字 → nil
if not uid then
    return ngx.exit(400)                     -- 参数缺失/非法
end
if uid == 0 then                             -- ⚠️ 用 == 比较，不要用 if uid then
    -- ...
end
```

### 3.3 table：Lua 唯一的复合结构

数组、字典、对象、模块——**全都是 table**：

```lua
local arr = { "a", "b", "c" }        -- 数组：下标从 1 开始（不是 0！）
local map = { name = "go", ver = 1.26 }  -- 字典
local mix = { "x", "y", key = "v" }  -- 混合（不推荐）

ngx.say(arr[1])      -- "a"；arr[0] 是 nil，不是第一个元素
ngx.say(#arr)        -- 3，`#` 取长度（对"含 nil 的数组"不可靠）
ngx.say(map.name)    -- 等价于 map["name"]
```

遍历有两种，**别混用**：

```lua
for i, v in ipairs(arr) do      -- ⭐ 数组用 ipairs：从 1 开始、遇到 nil 就停
    ngx.say(i, ":", v)
end

for k, v in pairs(map) do       -- ⭐ 字典用 pairs：遍历所有键，⚠️ 顺序不确定
    ngx.say(k, "=", v)
end
```

⚠️ 三个常见错误：`ipairs` 遍历字典会得到空循环；`pairs` 的顺序**不保证**（要排序得先收 key 再 `table.sort`）；`#t` 在表中间有 `nil` 时结果未定义。

### 3.4 字符串：拼接用 `..`，不是 `+`

```lua
local name = "world"
ngx.say("hello " .. name)        -- ✅ .. 拼接
-- ngx.say("hello " + name)      -- ❌ 报错（+ 是算术运算）

-- 数字与字符串会自动转换（这点像 PHP）
ngx.say("1" + 1)                 -- 2

-- 常用函数
local s = "a,b,c"
local parts = {}                 -- ⭐ 手写 split（Lua 没有内置 split）
for piece in s:gmatch("[^,]+") do
    parts[#parts + 1] = piece
end
ngx.say(#parts)                  -- 3
ngx.say(string.format("%.2f%%", 12.345))   -- 12.35%
ngx.say(("abc"):upper())         -- ABC（字符串也能用 ':' 调方法）
```

### 3.5 函数、闭包、多返回值

```lua
-- 函数是一等公民；多返回值是常态
local function divmod(a, b)
    return math.floor(a / b), a % b     -- 返回两个值
end
local q, r = divmod(7, 2)               -- q=3, r=1

-- 闭包：OpenResty 里做"每请求缓存"很常见
local function make_counter()
    local n = 0
    return function() n = n + 1; return n end
end
```

⭐ 一个实用惯用法——**用 `and`/`or` 当三元表达式**（短路求值）：

```lua
local timeout = tonumber(ngx.var.arg_timeout) or 3      -- 默认值：nil 时取 3
-- ⚠️ 注意陷阱：如果候选值是 false，or 会跳过它（与"默认值"直觉不符）
```

### 3.6 模块：`require` 返回一个 table

```lua
-- lua/auth.lua
local _M = {}

function _M.verify(token)
    if not token or token == "" then
        return false, "empty token"
    end
    return true, nil
end

return _M          -- ⭐ 模块必须返回一个值（惯例是 table）
```

```lua
-- 使用方（放在文件顶部，不要在 handler 里反复 require）
local auth = require("auth")
local ok, err = auth.verify(ngx.var.http_authorization)
```

⚠️ **`require` 只在第一次真正加载并编译**（LuaJIT 会缓存），但**在 handler 里反复写 `require` 仍有查表开销** → 提到模块顶部。

### 3.7 错误处理：`pcall` 与 `ngx.exit`

```lua
-- pcall：捕获运行时错误，不让它变成 500
local ok, res = pcall(function()
    return do_something_risky()
end)
if not ok then
    ngx.log(ngx.ERR, "failed: ", res)
    return ngx.exit(500)
end

-- ngx.exit：结束请求并返回状态码（⭐ 结束请求只能用 ngx.exit，return 只是结束当前 handler）
ngx.exit(ngx.HTTP_FORBIDDEN)    -- 403
```

⚠️ 两个区别要清楚：

| 写法 | 效果 |
|---|---|
| `return` | 只结束**当前 handler 函数**，后续阶段（如 `header_filter`）照常执行 |
| `ngx.exit(status)` | ⭐ **结束整个请求**（status ≥ 200 时），后续阶段不再执行 |
| `error("msg")` | 抛 Lua 异常 → 若没被 `pcall` 包住，返回 **500** |

### 3.8 常用代码片段

```lua
-- 读请求体（⚠️ 默认已读进内存；大 body 用 ngx.req.socket 流式处理）
ngx.req.read_body()
local body = ngx.req.get_body_data()

-- 读 JSON 体（需要 lua-cjson，OpenResty 自带）
local cjson = require("cjson.safe")     -- ⭐ 用 safe 版：解析失败返回 nil+err 而不是抛异常
local obj, err = cjson.decode(body)
if not obj then
    return ngx.exit(400)
end

-- 输出 JSON
ngx.header["Content-Type"] = "application/json; charset=utf-8"
ngx.say(cjson.encode({ code = 0, data = obj }))
```

---

## 四、nginx 配置语法要点（本机实测）

**本节要点**：OpenResty 的底座还是 Nginx，**这四件事配错了，Lua 写得再好也没用**。以下全部用本机 `nginx/1.15.11` 隔离实例实测。

### 4.1 location 匹配优先级 ⭐

规则（按检查顺序）：

```text
1. `=`  精确匹配        → 命中就用它，结束
2. `^~` 前缀匹配        → 若它是最长前缀，则「抑制正则」，用它
3. `~` / `~*` 正则      → 按配置文件中的出现顺序，第一个匹配的胜出
4. 普通前缀（无修饰符）  → 取「最长前缀」
5. `/`                  → 兜底
```

实测配置与结果（5 个 location 同时存在）：

```nginx
location = /a     { ... }   # 精确
location ^~ /a/b  { ... }   # 前缀 + 抑制正则
location ~ ^/a/c  { ... }   # 正则
location /a/      { ... }   # 普通前缀
location /        { ... }   # 兜底
```

```text
请求        X-Matched 响应头                结论
/a          exact (=)                       精确匹配优先于一切
/a/b/c      caret-prefix (^~)               ^~ 是最长前缀 → 抑制正则
/a/c/x      regex (~)                       正则优先于「普通前缀 /a/」
/a/x        普通前缀 (/a/)                   最长前缀命中
/z          普通前缀 (/ fallback)            兜底
```

⭐ **两条最常被搞错**：

1. **`^~` 不是"优先级更高的前缀"**，而是"**如果我最长，就别再看正则了**"。`/a/b/c` 命中 `^~ /a/b` 而不是正则 `~ ^/a/c`，就是它在起作用。
2. **正则一旦匹配就胜出，会盖掉更长的普通前缀**：`/a/c/x` 的最长前缀是 `/a/`，但正则 `^/a/c` 出现了 → 正则赢。

### 4.2 `proxy_pass` 末尾那个斜杠，差一个字符结果完全不同 ⭐

实测（`/api/` 与 `/api2/` 两个 location，只有 `proxy_pass` 的末尾斜杠不同）：

```nginx
location /api/  { proxy_pass http://127.0.0.1:18081/; }   # 带斜杠
location /api2/ { proxy_pass http://127.0.0.1:18081;  }   # 不带斜杠
```

```text
请求 /api/users?x=1  → 后端收到 /users?x=1         ← 带斜杠：剥掉 location 前缀
请求 /api2/users?x=1 → 后端收到 /api2/users?x=1    ← 不带斜杠：保留完整 URI
```

⭐ **记忆口径**：`proxy_pass` 的 URI 部分**只要写了（哪怕只是一个 `/`），就用它替换掉 location 匹配到的那一段**；**完全没写 URI** 时，原样透传。

⚠️ 这个差异在网关改造里是大坑：后端服务期望的路径是 `/users`，而 location 是 `/api/`，此时**必须**写成 `proxy_pass http://backend/`（带斜杠），否则后端会收到 404。

⚠️ 另一个连带坑：`proxy_pass` 里**用了变量**（如 `proxy_pass http://$upstream;`）时，nginx 会改用"原样透传"，此时**必须**自己拼好 URI——动态 upstream 场景最容易踩。

### 4.3 `rewrite` 的三个 flag：`last` / `break` / `redirect`

实测：

```nginx
location /r/  { rewrite ^/r/(.*)$  /a/$1 last; }        # last
location /rb/ { rewrite ^/rb/(.*)$ /a/$1 break;
                add_header X-Matched "break (same location)" always; return 200; }
location /rd/ { rewrite ^/rd/(.*)$ /a/$1 redirect; }     # 302
```

```text
请求 /r/x   → 200  X-Matched: 普通前缀 (/a/)           ← last：改了 URI 后「重新走 location 匹配」
请求 /rb/x  → 404  X-Matched: break (same location)   ← break：停在本 location
请求 /rd/x  → 302  Location: http://127.0.0.1:18080/a/x  ← redirect：返回 302 + 绝对 URL
```

⭐ 三个 flag 的准确语义：

| flag | 做什么 | 后续 |
|---|---|---|
| `last` | 重写 URI | **重新走一遍 location 匹配**（在同一个 server 内，最多 10 次防循环） |
| `break` | 重写 URI | **停在本 location**，不再匹配、且**跳过本 location 里排在后面的 rewrite 模块指令** |
| `redirect` | 不改 URI | 返回 **302**，`Location` 是**绝对 URL** |
| `permanent` | 同上 | 返回 **301** |

⚠️ **实测里那个 404 是个绝佳的反面教材**：`/rb/x` 里 `rewrite ... break` 之后**同一 location 里的 `return 200` 没有执行**（因为 `rewrite` 与 `return` 同属 rewrite 模块，`break` 把它后面的同胞都跳过了），于是请求落到 content 阶段去找文件 `/a/x` → 不存在 → 404。但 `add_header ... always` 属于 header filter 阶段，仍然生效——所以响应里**既有 404 又有那个头**。

### 4.4 `limit_req`：三个参数与它"够不着 return"的坑

```nginx
http {
    limit_req_zone $binary_remote_addr zone=perip:1m rate=2r/s;   # 定义在 http 层：共享内存区
    server {
        location /limited {
            limit_req zone=perip burst=1 nodelay;
        }
    }
}
```

| 参数 | 含义 |
|---|---|
| `zone=perip:1m` | 共享内存区（1MB 大约能存 1.6 万个 IP 的状态）+ **限流键**（这里是按 IP） |
| `rate=2r/s` | 令牌生成速率（可写 `30r/m`） |
| `burst=1` | 允许的突发排队数；**超出就拒**（默认返回 503） |
| `nodelay` | 突发不排队、**立即处理**；不写则是"排队 + 延迟处理" |

实测（`rate=2r/s, burst=1, nodelay`，连发 6 个）：

```text
[200, 200, 503, 503, 503, 503]     ← 前 2 个：1 个立即可用的令牌 + 1 个 burst
再等 2 秒单发 1 个: 200             ← 令牌按速率补充回来了
```

⚠️ **最大的坑上一节已经实测过**：**配了 `limit_req` 的 location 里如果写了 `return`，限流根本不会执行**（`return` 在 REWRITE 阶段先返回，`limit_req` 在 PREACCESS 阶段够不着）。测限流时 location 里**必须有一个真实的 content handler**（静态文件、`proxy_pass`、或者 OpenResty 的 `content_by_lua*`）。

⭐ 还有一个**进阶口径**：`limit_req` 是**漏桶式的排队整形**（要么排队、要么拒绝），**不是令牌桶**——它**不允许突发**（除非配 `burst`），这与 [限流算法.md](../../../04-架构与系统/分布式/服务治理/限流算法.md) 第四节讲的令牌桶语义不同。要真正做"允许突发 + 跨机共享配额"，得用 OpenResty 的 `resty.limit.req`（见第六节）或 Redis+Lua 方案（见 [稳定性三件套.md](../../../04-架构与系统/分布式/服务治理/稳定性三件套.md)）。

---

## 五、`ngx.*` API 速查

**本节要点**：OpenResty 给 Lua 注入了一个全局 `ngx` 表。**记住每一类 API 属于哪个阶段**，比记住函数名更重要。

⚠️ 本节代码**未实跑**（本机无 OpenResty），签名以官方文档为准。

### 5.1 输出与结束请求

| API | 说明 |
|---|---|
| `ngx.say(...)` | 输出并**追加换行**（多个参数依次输出） |
| `ngx.print(...)` | 输出**不追加换行** |
| `ngx.exit(status)` | ⭐ **结束整个请求**（≥200 时后续阶段不再执行）；`ngx.exit(0)` 表示"当前阶段到此为止，继续往下走" |
| `ngx.status = 404` | 只改状态码，**不结束请求** |
| `ngx.redirect(url, 302)` | 发 302/301 跳转并结束请求 |

⚠️ **`ngx.status` 与 `ngx.exit` 的区别**是新手最常混的：`ngx.status = 403` 之后如果继续 `ngx.say("x")`，客户端会收到 **403 + "x"**；而 `ngx.exit(403)` 会立刻终止。

### 5.2 读请求

| API | 说明 |
|---|---|
| `ngx.var.arg_name` | URL 查询参数（`?name=1`）；**永远是字符串或 nil** |
| `ngx.var.http_x_foo` | 请求头 `X-Foo`（**下划线转义**：header 里的 `-` 写成 `_`） |
| `ngx.var.remote_addr` / `uri` / `request_uri` / `host` / `scheme` | 常用内置变量 |
| `ngx.req.get_uri_args()` | 返回 table（同名参数会变成**数组**，这点很坑） |
| `ngx.req.get_headers()` | 返回请求头 table（同上，同名多值变数组） |
| `ngx.req.read_body()` + `ngx.req.get_body_data()` | 读请求体（⚠️ 大 body 会落盘，要配合 `client_body_buffer_size`） |
| `ngx.req.set_header(k, v)` / `ngx.req.clear_header(k)` | 改/删请求头（**转发给上游前改**） |
| `ngx.req.set_uri(uri, jump)` | 改 URI；`jump=true` 会**重新走 location 匹配**（相当于 `rewrite ... last`） |

### 5.3 写响应（多在 `header_filter_by_lua*`）

| API | 说明 |
|---|---|
| `ngx.header["X-Foo"] = "bar"` | 设响应头；`ngx.header["X-Foo"] = nil` 删除 |
| `ngx.header.content_length = nil` | 改了 body 之后必须把 `Content-Length` 清掉，让 nginx 用 chunked |
| `ngx.arg[1]` / `ngx.arg[2]` | **`body_filter_by_lua*` 专用**：`arg[1]` 是本次数据块，`arg[2]` 表示是否最后一块 |

⚠️ `body_filter_by_lua*` 会被**多次调用**（每个响应数据块一次），不能假设一次拿到完整 body——要聚合必须自己用 `ngx.ctx` 攒。

### 5.4 子请求与上下文

| API | 说明 |
|---|---|
| `ngx.location.capture(uri, opts)` | 发起**内部子请求**（等价于 curl 自己），返回 `{status, header, body}`；⭐ **必须配 `proxy_pass` 到 `127.0.0.1` 的 location**，且**不能捕获 `content_by_lua*` 的 location**（会报错） |
| `ngx.location.capture_multi({...})` | **并发**发多个子请求（⭐ 这是 OpenResty 做"响应聚合"的核心） |
| `ngx.ctx` | **单请求生命周期**内的 table（贯穿所有阶段），用来在 `access` 阶段存数据给 `content` 用 |
| `ngx.shared.DICT` | **跨 worker 共享**的字典（`get/set/incr/ttl`），限流计数、配置热更新都靠它 |

⚠️ `ngx.ctx` 与 `ngx.shared.DICT` 的区别是高频考点：

| | `ngx.ctx` | `ngx.shared.DICT` |
|---|---|---|
| 作用范围 | **单个请求** | **整个 nginx（所有 worker）** |
| 生命周期 | 请求结束即销毁 | 常驻（可设 `expire`） |
| 能存什么 | 任意 Lua 值 | 只有**字符串 / 数字 / 布尔 / nil** |
| 典型用途 | 传参（鉴权结果 → content） | 限流计数、配置缓存、锁 |

### 5.5 时间、日志、定时器

| API | 说明 |
|---|---|
| `ngx.now()` / `ngx.time()` | 返回**缓存**的当前时间（`ngx.now` 有小数，**不自动刷新**，要先用 `ngx.update_time()`） |
| `ngx.log(ngx.ERR, ...)` | 写 error_log；级别：`ngx.STDERR/EMERG/ALERT/CRIT/ERR/WARN/NOTICE/INFO/DEBUG` |
| `ngx.timer.at(delay, fn)` | 延时执行（**异步、不阻塞请求**）；`ngx.timer.every` 是周期版 |
| `ngx.thread.spawn/wait` | 轻量线程（可在同一请求内并发多个 cosocket 操作） |

⚠️ `ngx.now()` **不刷新时间**是个经典坑：在一个长 handler 里连续调用会拿到同一个值（精度够用但"时间不动"）。要准就先 `ngx.update_time()`。

### 5.6 cosocket：为什么能连 Redis 却不阻塞

**cosocket（cosock）是 OpenResty 的核心发明**：它让 Lua 里写"看起来同步"的网络代码，实际由 nginx 事件循环驱动，**不阻塞 worker**。

```lua
local redis = require("resty.redis")
local red = redis:new()
red:set_timeouts(100, 100, 100)   -- connect/send/read 各 100ms
local ok, err = red:connect("127.0.0.1", 6379)
if not ok then
    ngx.log(ngx.ERR, "connect failed: ", err)
    return ngx.exit(500)
end
-- ⭐ 这一段"阻塞式"写法实际是非阻塞的：在等待网络时 worker 会去处理别的请求
local res, err = red:get("token:" .. token)
red:set_keepalive(60000, 100)     -- ⭐ 放回连接池（不写这句每次都要 TCP 握手）
```

⚠️ **cosocket 的三条硬限制**（写错就报 `API disabled in the context of ...`）：

1. **不能在 `init_by_lua*` / `init_worker_by_lua*` 的同步代码里用**（那时还没有请求上下文）——要连数据库只能放进 `ngx.timer.at(0, fn)`；
2. **不能在 `set_by_lua*` 里用**（该阶段要求纯计算、同步返回）；
3. **有连接池就要 `set_keepalive`**，否则每个请求都新建连接，性能直接塌掉。

---

## 六、六个典型应用

**本节要点**：这六个场景覆盖了 OpenResty 的绝大多数真实用途。每个都只给**骨架**（够开工），细节靠第五节的 API。

⚠️ 本节代码**未实跑**（本机无 OpenResty / LuaJIT）。

### 6.1 鉴权网关：把 JWT 解析搬到入口

```lua
-- access_by_lua_file auth.lua
local cjson = require("cjson.safe")

local token = ngx.var.http_authorization
if not token or token == "" then
    ngx.header["Content-Type"] = "application/json"
    ngx.status = 401
    ngx.say(cjson.encode({ code = 401, msg = "missing token" }))
    return ngx.exit(401)
end

local claims, err = verify_jwt(token)        -- 校验签名与过期
if not claims then
    ngx.log(ngx.WARN, "invalid token: ", err)
    return ngx.exit(401)
end

-- ⭐ 核心动作：把身份注入请求头，后端不再重复解析 JWT
ngx.req.set_header("X-Company-Id", claims.company_id)
ngx.req.set_header("X-User-Id",    claims.user_id)

-- ⭐ 安全必做：剥离客户端伪造的同名头（否则越权）
ngx.req.clear_header("X-Company-Id")
ngx.req.set_header("X-Company-Id", claims.company_id)
```

⚠️ **最后两行的顺序就是重点**：一定要先 `clear`（清掉外部传进来的）再 `set`（写自己算出来的），否则客户端只要在请求头里塞一个 `X-Company-Id` 就能冒充别的租户——**多租户系统的头号越权漏洞**。

⭐ 另一个现实问题：JWT 校验要算 HMAC / RSA，纯 Lua 实现慢。常见做法是**第一次校验结果存 `ngx.shared.DICT`**（key 用 token 的 hash、TTL 设成 token 剩余有效期），后续请求只查内存。

### 6.2 限流：`resty.limit.req`（令牌桶 + 共享计数）

```nginx
http {
    lua_shared_dict limit_req_store 10m;    # ⭐ 所有 worker 共享的计数器
    server {
        location /api/ {
            access_by_lua_block {
                local limit_req = require("resty.limit.req")
                -- 按 company_id 限流：500 r/s，突发可到 600
                local lim, err = limit_req.new("limit_req_store", 500, 100)
                if not lim then
                    ngx.log(ngx.ERR, "limit init failed: ", err)
                    return ngx.exit(500)
                end
                local key = ngx.var.http_x_company_id or ngx.var.remote_addr
                local delay, err = lim:incoming(key, true)
                if not delay then
                    if err == "rejected" then
                        return ngx.exit(503)          -- 超出 burst
                    end
                    ngx.log(ngx.ERR, "limit failed: ", err)
                    return ngx.exit(500)
                end
                if delay > 0 then
                    ngx.sleep(delay)                  -- ⭐ 非阻塞延时（cosocket 驱动）
                end
            }
            proxy_pass http://backend/;
        }
    }
}
```

⭐ 与 nginx 原生 `limit_req` 的关键差异：

| | nginx `limit_req` | `resty.limit.req` |
|---|---|---|
| 限流维度 | 主要按 `$binary_remote_addr` 等**变量** | ⭐ **任意 key**（company_id、user_id、API path） |
| 突发 | 靠 `burst`（**排队或拒绝**） | `burst` 是**额外额度**，且可 `ngx.sleep(delay)` 平滑 |
| 拒绝方式 | 直接 503 | 可**自定义**（改状态码、返回 JSON、降级到缓存） |
| 精确性 | 漏桶式整形 | 令牌桶（允许突发） |

### 6.3 灰度发布：按 company_id 分流

```lua
-- access_by_lua_block（放在 proxy_pass 之前）
local uid = ngx.var.http_x_company_id or ngx.var.remote_addr
local bucket = ngx.crc32_short(uid) % 100        -- 稳定哈希：同一租户永远进同一组
if bucket < 10 then
    ngx.var.backend = "http://127.0.0.1:8081"    -- 10% 走新版本
else
    ngx.var.backend = "http://127.0.0.1:8080"
end
```

```nginx
location /api/ {
    set $backend "http://127.0.0.1:8080";        # ⭐ 先在配置里声明变量
    access_by_lua_file gray.lua;                 # 由 Lua 改写 $backend
    proxy_pass $backend;                         # ⚠️ 用变量时 URI 不再自动替换（见 4.2）
}
```

⚠️ 两个坑：① **必须先 `set $backend` 声明**，否则 Lua 里写 `ngx.var.backend` 会报 "variable not found"；② **`proxy_pass` 用变量时不自动替换 URI**（4.2 实测过），要自己拼：`proxy_pass $backend$request_uri;`。

⭐ 为什么用**稳定哈希**而不是随机：灰度要"同一个租户始终命中同一版本"，否则用户会看到版本来回跳。

### 6.4 动态路由：从共享字典 / Redis 读配置

```lua
-- 配置热更新：定时器每 5 秒从 Redis 拉一次路由表
local routes = ngx.shared.routes

local function load_routes(premature)
    local red = require("resty.redis"):new()
    if not red:connect("127.0.0.1", 6379) then return end
    local data = red:get("gateway:routes")
    if data and data ~= ngx.null then
        routes:set("table", data)                 -- ⭐ 只存字符串，用 cjson 编解码
    end
    red:set_keepalive(60000, 100)
end

ngx.timer.at(0, load_routes)                      -- init_worker 里起定时器，之后周期刷新
```

```lua
-- access 阶段查表
local cjson = require("cjson.safe")
local raw = ngx.shared.routes:get("table")
local map = raw and cjson.decode(raw)
if map and map[ngx.var.uri] then
    ngx.var.backend = map[ngx.var.uri]
end
```

⭐ **这就是 OpenResty 相比原生 nginx 最大的价值**：路由表变了**不用 reload**（原生 nginx 改 upstream 必须 reload，会掉长连接）。APISIX / Kong 的核心机制就是这一套（它们把路由存在 etcd / PostgreSQL，再下发到共享字典）。

### 6.5 WAF：拦截明显的注入特征

```lua
local function has_dangerous(s)
    if not s then return false end
    -- ⚠️ 这里只做"粗筛"，真正生产要用成熟规则集（如 lua-resty-waf）
    return s:find("union%s+select", 1, true) ~= nil
        or s:find("<?php", 1, true) ~= nil
        or s:find("%.%.%/", 1, true) ~= nil        -- 路径穿越
        or s:find("<script", 1, true) ~= nil
end

local uri   = ngx.var.request_uri
local ua    = ngx.var.http_user_agent
if has_dangerous(uri) or has_dangerous(ua) then
    ngx.log(ngx.WARN, "blocked suspicious request: ", uri)
    return ngx.exit(403)
end
```

⚠️ 三条必须说清的边界：

1. **正则匹配请求体代价高**（要读 body、要跑正则）→ 通常只查 URI / UA / 少量参数，body 交给应用自己防（参数化查询才是根本）；
2. **WAF 是"降低噪声"，不是"安全边界"**——真正防注入靠**参数化 SQL**（见 [Web攻击与防御.md](../../../06-工程实践/安全/Web攻击与防御.md)）；
3. **规则要可热更新**，否则每次加规则都得 reload（同 6.4 的做法）。

### 6.6 响应聚合与缓存：`capture_multi` + 共享字典

```lua
-- content_by_lua_block：并发调用多个内部接口再合并
local res = { ngx.location.capture_multi({
    { "/_internal/user",   { method = ngx.HTTP_GET } },
    { "/_internal/orders", { method = ngx.HTTP_GET } },
}) }

local cjson = require("cjson.safe")
local body = cjson.encode({
    user   = cjson.decode(res[1].body),
    orders = cjson.decode(res[2].body),
})

-- ⭐ 进程内缓存（跨 worker 共享）
local cache = ngx.shared.cache
local key = ngx.var.request_uri
local hit = cache:get(key)
if hit then
    ngx.header["X-Cache"] = "HIT"
    return ngx.say(hit)
end
cache:set(key, body, 10)          -- 10 秒过期
ngx.say(body)
```

⚠️ 三个限制：① `capture` 的子请求**必须落在能 `proxy_pass` 的 location 上**，不能是另一个 `content_by_lua*`；② 共享字典的**容量是静态的**（`lua_shared_dict cache 10m`），写满后 `set` 会失败（**必须判返回值**，否则缓存静默失效）；③ 共享字典**没有淘汰策略**（LRU 之类要自己实现），生产更适合用 `lua-resty-lrucache`（worker 内 LRU）+ 共享字典两级。

---

## 使用：把 OpenResty 接在 photography-server 的入口

**本节要点**：拿一个真实的 Go 后端（多租户摄影 SaaS）走一遍——**哪些该放在网关、哪些绝不能放**。

### 场景

[photography-server](https://github.com/Eleven-26/photography-server) 是 `Go + Gin + GORM` 的多租户服务（按 `company_id` 隔离数据），直接对外暴露。引入 OpenResty 做**南北向入口**后，能下沉这些事：

| 放在网关（`access_by_lua*`） | 留在后端 |
|---|---|
| JWT 验签 + 注入 `X-Company-Id` / `X-User-Id` | 业务鉴权（这个用户能不能看这个订单） |
| 按 `company_id` 限流 | 业务流程 |
| `/internal/`、`/metrics` 不对外 | 数据访问与事务 |
| 灰度（按 `company_id` 分流 v1/v2） | — |
| 访问日志 / 耗时埋点 | 业务日志 |

⚠️ **判据（与 [中间件选型.md](../中间件选型.md) 第四节的口径一致）**：网关只做**所有请求都需要的横切关注点**；**业务逻辑（订单校验、库存判断）绝不进网关**——否则网关会变成"最难发布、最不敢改"的组件。

### 目录结构与配置

```text
gateway/
├── conf/nginx.conf
└── lua/
    ├── auth.lua          # JWT 校验 + 注入身份头
    ├── limit.lua         # 按 company_id 限流
    └── routes.lua        # 动态路由表热更新
```

```nginx
http {
    lua_shared_dict limit_store 10m;
    lua_shared_dict routes      1m;
    lua_package_path "/path/to/gateway/lua/?.lua;;";   # ⚠️ 末尾的 ;; 表示"再带上默认搜索路径"

    server {
        listen 80;

        # 内部接口与指标不对外
        location ~ ^/(internal|metrics)/ {
            return 404;
        }

        location /api/ {
            # ① 身份
            access_by_lua_file /path/to/gateway/lua/auth.lua;
            # ② 限流
            access_by_lua_file /path/to/gateway/lua/limit.lua;

            # ⚠️ 安全兜底：无论 Lua 怎么写，先把外部传的租户头清掉
            proxy_set_header X-Company-Id "";
            proxy_set_header X-User-Id "";

            proxy_pass http://127.0.0.1:8080/;         # ⚠️ 带斜杠：剥掉 /api 前缀
            proxy_http_version 1.1;
            proxy_set_header Connection "";
        }
    }
}
```

### 上线前必须确认的四件事

| 项 | 为什么 |
|---|---|
| `lua_code_cache on`（默认） | ⚠️ `off` 只用于**开发**：每个请求都重新编译 Lua，性能差一个数量级 |
| 每个 handler `require` 提到文件顶部 | 避免每次请求查 `package.loaded` |
| cosocket 用完 `set_keepalive` | 否则每次请求都重建 TCP 连接 |
| 共享字典 `set` 判返回值 | 容量写满后 `set` 返回 `nil, "no memory"`，**缓存会静默失效** |

⭐ 回到第一步的实测：**`/metrics` 用 `return 404` 是安全的**（就是要它早早返回），但如果想把**限流**也加到这个 location 上，那个 `limit_req` / `access_by_lua*` **永远不会执行**——这正是 4.4 节实测出来的阶段顺序。

---

## 延伸追问

- **OpenResty 和 APISIX / Kong 是什么关系？** → 后两者**都基于 OpenResty**：APISIX / Kong 是"OpenResty 之上的网关产品"（把路由、插件、控制面做成产品），OpenResty 是"能写网关的运行时"。选型见 [中间件选型.md](../中间件选型.md) 第四节。
- **为什么鉴权要写在 `access_by_lua*` 而不是 `rewrite_by_lua*`？** → 因为 rewrite 阶段**早于** `limit_req` 等 PREACCESS 阶段的指令；写在 rewrite 里虽然"能跑"，但一旦同 location 里还有限流、其他 access 检查，顺序就乱了。**规则：要拦人就用 access。**
- **`ngx.exit(403)` 和 `ngx.status = 403` 有什么区别？** → `ngx.exit` **结束整个请求**（后续阶段不执行）；`ngx.status` 只改状态码，代码继续跑，响应体还会输出。写错会导致"403 里带着正常响应体"。
- **cosocket 为什么不能在 `init_by_lua*` 里用？** → cosocket 依赖 nginx 的事件循环与请求上下文，配置加载阶段（master 进程、无请求）没有这些东西。要在启动时连 Redis，只能 `ngx.timer.at(0, fn)` 把任务推迟到事件循环里。
- **`lua_shared_dict` 写满了会怎样？** → `set` 返回 `nil, "no memory"`，**缓存/计数静默失效**（不报错、不影响请求）。所以要么判返回值 + 降级，要么定期 `flush_expired()`。
- **共享字典能不能替代 Redis？** → 只能替代**单机**场景。它不跨机器（每个 nginx 实例各有自己的），所以多实例限流仍要 Redis（见 [稳定性三件套.md](../../../04-架构与系统/分布式/服务治理/稳定性三件套.md)）；它的优势是**零网络开销**。
- **LuaJIT 到底有多快，什么时候会慢？** → 热点代码被 JIT 编译成机器码，字符串与算术接近 C；**慢的三种情况**：① 每次请求重新 `require` 或重建 table；② 用 `string.find` 跑复杂正则（用 `ngx.re` 走 PCRE 更快）；③ 写错阶段导致阻塞（如 `log_by_lua` 里做同步 I/O）。
- **能不能在 OpenResty 里直接连 MySQL？** → 能（`lua-resty-mysql`），但**不建议**：SQL 逻辑会散落到网关，且结果集在 Lua 里处理很笨。网关只连 **Redis / 配置中心**这类"决策用"的存储。
- **`ngx.location.capture` 和直接 `proxy_pass` 有什么区别？** → `capture` 是**内部子请求**（不出网卡、不发真实 HTTP），能在一个响应里聚合多个上游；`proxy_pass` 是直接把请求转给上游。前者用于聚合，后者用于转发。
- **和 Go 写的网关相比怎么选？** → 要**极致性能 + 复用现有 Nginx 运维体系** → OpenResty；要**强类型、复杂业务编排、团队是 Go 栈** → Go 网关（[Kratos框架.md](../../../01-编程语言/go/工程实践/Kratos框架.md) 的网关层）。⚠️ 别在网关里写业务逻辑，这条与选哪种技术栈无关。
- **`proxy_pass` 带变量的那个坑有多坑？** → 很坑：写了变量就**不再自动替换 URI**（4.2 实测），动态 upstream 场景必须自己写 `$backend$request_uri`，否则上游收到的路径是错的。而且这类错误**只在动态路径上出现**，平时测不出来。

---

## 关联

- [网关选型.md](网关选型.md) — **本篇的上一层**：四层横向对比（流量网关 / API 网关 / 微服务网关 / K8s 入口）、Nginx 能力边界的四组本机实测、跟服务网格的边界
- [中间件选型.md](../中间件选型.md) — 第四节「API 网关怎么选」的速查表
- [限流算法.md](../../../04-架构与系统/分布式/服务治理/限流算法.md) — 令牌桶 / 漏桶的原理，与 `limit_req` 的"漏桶式整形"口径对照
- [稳定性三件套.md](../../../04-架构与系统/分布式/服务治理/稳定性三件套.md) — 跨机限流：Redis + Lua 的原子计数（与 `lua_shared_dict` 的单机局限互补）
- [网络通信链路详解.md](../../../02-计算机基础/网络/网络通信链路详解.md) — 请求到达网关之前那一跳（NAT / LB / CDN）改了什么
- [服务发现与负载均衡.md](../../../04-架构与系统/分布式/服务治理/服务发现与负载均衡.md) — 动态 upstream 的控制面（`balancer_by_lua*` 的上一层）
- [Web攻击与防御.md](../../../06-工程实践/安全/Web攻击与防御.md) — 6.5 的 WAF 只是"降噪"，真正的注入防御在参数化查询
- [HTTPS与TLS.md](../../../02-计算机基础/网络/HTTPS与TLS.md) — `ssl_certificate_by_lua*` 按 SNI 动态选证书的前置知识
- [容器原理.md](../../../06-工程实践/部署/docker/容器原理.md) — Nginx / OpenResty 的官方镜像与容器化部署（`nginx -g` 传参那点事）
- [Kratos框架.md](../../../01-编程语言/go/工程实践/Kratos框架.md) — 网关与微服务框架的分工边界
- [14-OpenResty生态.md](../../../02-计算机基础/linux/深入理解Nginx/14-OpenResty生态.md) — **生态与版本增量**：`lua-resty-*` 三层地图、1.31.1.1 变更清单、LuaJIT `ffi.abi()`（本篇讲用法，那篇讲版本与生态）
> 反向引用（本篇被下列文档引到）：[从零实现网关.md](../../../01-编程语言/go/从零实现网关.md)、[网关路由匹配.md](../../../01-编程语言/go/网关路由匹配.md)、[01-编译安装与配置.md](../../../02-计算机基础/linux/深入理解Nginx/01-编译安装与配置.md)、[04-HTTP框架执行流程.md](../../../02-计算机基础/linux/深入理解Nginx/04-HTTP框架执行流程.md)、[06-HTTP模块开发入门.md](../../../02-计算机基础/linux/深入理解Nginx/06-HTTP模块开发入门.md)、[07-配置解析与请求上下文.md](../../../02-计算机基础/linux/深入理解Nginx/07-配置解析与请求上下文.md)、[08-upstream与子请求.md](../../../02-计算机基础/linux/深入理解Nginx/08-upstream与子请求.md)、[09-HTTP过滤模块.md](../../../02-计算机基础/linux/深入理解Nginx/09-HTTP过滤模块.md)、[10-HTTP变量机制.md](../../../02-计算机基础/linux/深入理解Nginx/10-HTTP变量机制.md)、[13-njs模块与动态脚本.md](../../../02-计算机基础/linux/深入理解Nginx/13-njs模块与动态脚本.md)、[15-Tengine生态.md](../../../02-计算机基础/linux/深入理解Nginx/15-Tengine生态.md)、[16-模块开发实战.md](../../../02-计算机基础/linux/深入理解Nginx/16-模块开发实战.md)
