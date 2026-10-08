# Go 调试与 IDE 配置

> VSCode（gopls + dlv）的开发运行环境配置，以及日常最常用的调试技巧。
>
> 内容整理自大厂 Go 后端面试真题，参考资料与原始素材见 [素材清单](../../../素材清单.md)。

---

## 一、VSCode 如何配置 Go 的开发与运行环境？

**本节要点**：核心是工程环境熟练度。常有人拿"你平时怎么调试"当幌子，看的是三点：**工具链装在哪、多 main 包怎么跑起来、能不能带参数和环境变量调试**。

### 1.1 前提：SDK 装好，工具链一次装齐

1. 先装 Go SDK 并配好 `PATH`——**没装 SDK 时 `Ctrl+Shift+P` 里的 Go 命令根本不会出现**；
2. 扩展商店搜 `Go`（发布者 golang.go）安装；
3. `Ctrl+Shift+P` → `Go: Install/Update Tools` → 全选 → 确定（`gopls`、`dlv`、`staticcheck`、`goimports`、`gofumpt` 等一次装齐）；
4. 这些工具**装在 `$(go env GOPATH)/bin`**（Windows 一般是 `C:\Users\<你>\go\bin`），必须加进系统 `PATH`，否则 VSCode 找不到 `gopls` / `dlv`。

```bash
go env GOPATH GOBIN                        # 确认工具安装目录
go install golang.org/x/tools/gopls@latest # 单独补装也行
```

### 1.2 `settings.json`：语言服务 + 保存即格式化

```json
{
  "go.useLanguageServer": true,
  "gopls": { "ui.semanticTokens": true, "ui.completion.usePlaceholders": true },
  "go.formatTool": "goimports",
  "go.lintTool": "staticcheck",
  "go.toolsManagement.autoUpdate": true,
  "go.testFlags": ["-v", "-count=1"],
  "editor.formatOnSave": true,
  "[go]": {
    "editor.insertSpaces": false,
    "editor.defaultFormatter": "golang.go",
    "editor.codeActionsOnSave": { "source.organizeImports": "explicit" }
  },
  "go.delveConfig": {
    "apiVersion": 2,
    "dlvLoadConfig": {
      "followPointers": true, "maxVariableRecurse": 1,
      "maxStringLen": 64, "maxArrayValues": 64, "maxStructFields": -1
    }
  }
}
```

- **`gopls`** 是官方 Language Server，补全/跳转/诊断/重命名全靠它，由 `go.useLanguageServer` 控制（新版默认开启）；
- **`gofmt` 只管格式，`goimports` 还管 import**（自动增删并分组），日常直接选 `goimports`；
- **`dlvLoadConfig` 决定调试时变量面板的"可视深度"**——默认会截断长字符串、大数组、指针链，调试时看到 `{...}` 通常就是这里配小了；
- `go.testFlags` 里的 `-count=1` 顺手解决"断点进不去"（测试结果被缓存）。

### 1.3 `launch.json`：让运行/调试按钮真正可用

不放 `launch.json` 时按 `F5`，VSCode 会**自动生成模板并把 `program` 设成工作目录**（`${workspaceFolder}`）——一个仓库有多个 `main` 包时就会跑错目标，所以要按项目改 `program`。

```json
{
  "version": "0.2.0",
  "configurations": [
    {
      "name": "Launch Current File",
      "type": "go", "request": "launch", "mode": "auto",
      "program": "${fileDirname}",
      "cwd": "${workspaceFolder}"
    },
    {
      "name": "Launch cmd/api",
      "type": "go", "request": "launch", "mode": "auto",
      "program": "${workspaceFolder}/cmd/api",
      "args": ["-conf", "./configs"],
      "env": { "GIN_MODE": "debug", "APP_ENV": "dev" },
      "cwd": "${workspaceFolder}",
      "showLog": true
    },
    {
      "name": "Debug Current Test",
      "type": "go", "request": "launch", "mode": "test",
      "program": "${fileDirname}",
      "args": ["-test.run", "TestUserRepo", "-test.v"]
    }
  ]
}
```

- `mode` 常用取值：`auto`（按 program 自动判定）、`test`、`exec`、`remote`；
- `configurations` 是数组，**配几段就能在运行下拉框里选几个入口**，这是"多应用/多目录"的标准做法；
- 不调试只想跑一遍用 `Ctrl+F5`；命令行 `go run ./cmd/api -conf ./configs` 与 IDE 按钮完全等价。

### 延伸追问

- **`gopls` 报错不准 / 补全卡住？** → `Go: Restart Language Server` 重启并清缓存，再确认 VSCode 里的 `GOPATH`/`GOROOT` 与终端一致（多版本 SDK 最容易踩）。
- **断点是灰色打不上？** → 触发优化/内联了，用 `-gcflags="all=-N -l"`；或该代码路径根本没走到；或调试的二进制与源码不同版本。
- **`args` 和 `env` 有什么区别？** → `args` 是命令行参数（对应 `os.Args`），`env` 是进程环境变量（对应 `os.Getenv`）。

---

## 二、常用调试技巧有哪些？

**本节要点**：核心是"会不会用调试器解决问题"而不是"会不会打日志"。断点条件、命中计数、远程调试、测试缓存这几件事，是区分熟练与不熟练的分水岭。

### 2.1 先解决"看不到值"

在 `launch.json` 的配置里加 `"buildFlags": "-gcflags=all=-N -l"`（`dlv debug` 模式默认已带），再把 `dlvLoadConfig.maxStringLen` / `maxArrayValues` 调大，才不至于看到 `{...}` 或 `<optimized out>`。

### 2.2 四种比 `println` 高效的断点

| 手段 | 场景 | 用法 |
|---|---|---|
| **条件断点** | 只在 `i == 997` 时停下 | 右键断点 → Edit Breakpoint → 输入表达式 |
| **命中计数断点** | 循环第 100 次才停 | Edit Breakpoint → Hit Count |
| **日志断点** | 不想改代码、只想打印且不中断 | 右键 → Add Logpoint，`${expr}` 插值 |
| **panic 断点** | 抓 panic 现场 | 在 `runtime.gopanic` 上打断点后 `continue` |

### 2.3 三种调试姿态

```bash
dlv debug ./cmd/api -- -conf ./configs      # ① 本地从源码起（自动带 -N -l）
dlv attach <pid>                            # ② 附加到已运行进程
dlv --headless --listen=:2345 --api-version=2 --accept-multiclient exec ./app-debug  # ③ 远程/容器
```

第 ③ 种配合 attach + remote 的 `launch.json`：

```json
{
  "name": "Attach to Remote (container)",
  "type": "go", "request": "attach", "mode": "remote",
  "host": "127.0.0.1", "port": 2345,
  "substitutePath": [{ "from": "${workspaceFolder}", "to": "/app" }]
}
```

`substitutePath` 是容器调试的关键：把宿主机源码路径映射到容器内路径，否则断点会落在"源码找不到"的死点上。

### 2.4 不打断点的三个偏方

```bash
go build -gcflags="-S" . > asm.txt           # 汇编落到文件慢慢比对
GOSSAFUNC=Foo go build .                     # 生成 SSA 图 ssa.html
go test -count=1 -gcflags="all=-N -l" ./...  # 调测试必加 -count=1
```

### 延伸追问

- **调试时变量显示 `<optimized out>` 是什么原因？** → 优化把变量放进寄存器或被常量传播了，用 `-N -l` 重新构建。
- **线上服务怎么调试？** → 不建议在生产开 `-N -l`；常规做法是 `dlv attach` 到进程、用日志断点/条件断点最小化停顿，或者靠 pprof + 日志回放复现。
- **`dlv` 和 `gdb` 有什么区别？** → dlv 是 Go 生态的调试器，理解 goroutine、defer、interface 等运行时结构；gdb 只能看汇编层面的现场，调 Go 程序经常错乱。

---

## 三、远程与容器内调试：headless 模式

**本节要点**：本机 VSCode 那一套解决不了"程序跑在容器 / 服务器上"的情况。
远程调试只有一种标准形态：**在目标机上起 `dlv` 的 headless 服务，本地连过去**。

### 3.1 Delve 的三种启动方式

```bash
go install github.com/go-delve/delve/cmd/dlv@latest   # 装（实测版本见下）
dlv debug    # 直接编译并调试当前包（临时产物，用完即弃）
dlv exec ./app          # 调试一个已经编好的二进制
dlv attach <pid>        # ⚠️ 挂到正在运行的进程上（会把它暂停）
dlv test                # 调试测试
```

实测（容器 `golang:1.26-alpine`，go1.26.8）：

```text
Delve Debugger
Version: 1.27.2
Build: $Id: 360e7b2181d3da54115aaee4f569954bca95cfe3 $
```

### 3.2 ⭐ 调试构建必须关掉优化与内联

```bash
go build -gcflags="all=-N -l" -o app-dbg .
```

- `-N`：关闭优化
- `-l`：关闭内联

实测体积：

```text
  默认构建       2397478 字节
  -N -l 构建     2334532 字节
```

⚠️ 不关这两个的后果（这是 Go 调试最高频的困惑）：

1. **断点打不上**（目标行被内联进调用者）；
2. **变量显示 `<optimized out>`**（它已被优化掉，没有存储位置）；
3. **单步执行会"跳行"**（指令顺序和源码顺序不一致）。

⭐ 判据：**凡是要挂调试器，就一定用 `-N -l` 重新构建**；生产产物不要用这份（详见
[编译与gcflags.md](编译与gcflags.md) 第二节）。

### 3.3 headless 模式实测

在目标机（容器里）起服务端：

```bash
dlv exec ./app-dbg --headless --listen=:40000 --api-version=2 --accept-multiclient
```

实测服务端输出：

```text
API server listening at: [::]:40000
connections are not authenticated nor encrypted
```

⭐⭐ **第二行是 dlv 自己的安全警告，必须当回事**：
headless 的远程连接**既不认证也不加密** —— 任何能连到这个端口的人都可以
**任意读写目标进程的内存、甚至注入代码执行**。所以：

- **不要把它暴露到公网 / 生产网段**；
- 常见做法：只监听回环地址（`--listen=127.0.0.1:40000`），再用 **ssh 隧道**把端口转到本地；
- **用完即关**，不要常驻。

客户端连上之后（`break main.main` → `continue` → `bt`）的实测：

```text
Breakpoint 1 set at 0x4bcc0e for main.main() ./main.go:13
> [Breakpoint 1] main.main() ./main.go:13 (hits goroutine(1):1 total:1) (PC: 0x4bcc0e)
    14:		r := compute(10)
0  0x00000000004bcc0e in main.main
   at ./main.go:13
```

⚠️ 两条说明：

1. **断点地址（这里是 `0x4bcc0e`）在同一份二进制下是固定的**，换一份构建就会变 ——
   引用时以"函数名 + 源码行号"为准；
2. **`dlv exec` 启动时停在运行时最入口**（`_rt0_amd64_linux`），
   想看到 `main` 必须先 `break main.main` 再 `continue` —— 这是新手最常见的"bt 出来一片问号"的原因。

### 3.4 VSCode 连远程的 `launch.json`

```json
{
  "name": "Attach to remote dlv",
  "type": "go",
  "request": "attach",
  "mode": "remote",
  "port": 40000,
  "host": "127.0.0.1",
  "remotePath": "/app",
  "substitutePath": [
    { "from": "${workspaceFolder}", "to": "/app" }
  ]
}
```

⭐ **`substitutePath` 是最关键的一行**：它把"容器里的源码路径"映射回"本地工作区路径"，
不配它就会报"找不到源文件"。

### 3.5 `dlv attach` 的两条纪律

⚠️ **`attach` 会暂停目标进程** —— 在生产上等于一次局部停机：

1. **只在排查窗口内 attach**，且提前告知；
2. **准备好"调试完立刻 detach"**；detach 不干净会让进程卡在暂停态。

---

## 四、线上调试的三条纪律

**本节要点**：线上不是"能调试"就"该调试"。这三条是本仓认为必须守住的边界。

1. **优先用可观测性而不是调试器** —— 指标、日志、trace 是无侵入的；
   调试器是**侵入式**的（改状态、暂停进程）。顺序应该是
   **[runtime调试与trace.md](../运行时/runtime调试与trace.md)** →
   [pprof性能分析.md](../运行时/pprof性能分析.md) → 实在不行才 dlv。

2. **调试器只连"一次性排查实例"** —— 从负载均衡后面摘掉一台，或者起一个专门的副本，
   在它上面挂调试器。**不要在扛流量的实例上 attach**。

3. **任何调试动作都要有"退出条件"** —— 提前写好"看什么、看到什么就收手、最多花多久"，
   超时就放弃、换方案。

⚠️ 还有一条**禁止项**：**不要在常驻服务上长期开着 headless dlv**
（3.3 那条安全警告 + 它是一个可任意读写进程内存的后门）。

### 延伸追问

- **为什么我的断点打不上？** → 大概率没加 `-gcflags="all=-N -l"`：
  目标行被内联进调用者了（3.2 实测：不关优化与内联会出现 `<optimized out>`）。
- **为什么 `bt` 出来一片 `???`？** → `dlv exec` 启动时停在**运行时最入口**（`_rt0_amd64_linux`），
  要先 `break main.main` 再 `continue`（3.3 实测）。
- **远程调试安全吗？** → **不安全**。dlv 自己会打印
  `connections are not authenticated nor encrypted` —— 只监听回环 + ssh 隧道，用完即关（3.3）。
- **`dlv attach` 会影响线上服务吗？** → 会，**它会暂停目标进程**。
  只在摘掉流量的实例上做，且提前告知（3.5 / 第四节）。
- **VSCode 连容器调试为什么找不到源码？** → 缺 `substitutePath`（把容器路径映射回本地，3.4）。
- **调试构建能用在生产吗？** → 不要。`-N -l` 关掉了优化与内联，产物行为与生产不一致
  （详见 [编译与gcflags.md](编译与gcflags.md)）。

---

## 关联

- [编译与gcflags.md](编译与gcflags.md) — 断点打不上时先看编译优化开关
- [协程泄漏与死锁.md](../并发编程/协程泄漏与死锁.md) — 卡死现场怎么用 pprof / dlv 抓
- [性能排查.md](../../../02-计算机基础/linux/性能排查.md) — 线上没有 IDE 时的排查手段
- [runtime调试与trace.md](../运行时/runtime调试与trace.md) — IDE/dlv 断点之外的运行期自省入口（GODEBUG、runtime/trace、metrics）在彼
> 反向引用（本篇被下列文档引到）：[测试与Mock.md](测试与Mock.md)
