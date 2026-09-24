# Go 调试与 IDE 配置

> VSCode（gopls + dlv）的开发运行环境配置，以及日常最常用的调试技巧。
>
> 内容整理自大厂 Go 后端面试真题视频，参考资料与原始素材见 [素材清单](../interview/素材清单.md)。

---

## Q1. VSCode 如何配置 Go 的开发与运行环境？

**来源**：`BV12qjA6aErF p=52` B站 Go 面试真题 · 时长 6分38秒
**考察意图**：考工程环境熟练度。面试官常拿"你平时怎么调试"当幌子，看的是三点：**工具链装在哪、多 main 包怎么跑起来、能不能带参数和环境变量调试**。

### 一、前提：SDK 装好，工具链一次装齐

1. 先装 Go SDK 并配好 `PATH`——**没装 SDK 时 `Ctrl+Shift+P` 里的 Go 命令根本不会出现**；
2. 扩展商店搜 `Go`（发布者 golang.go）安装；
3. `Ctrl+Shift+P` → `Go: Install/Update Tools` → 全选 → 确定（`gopls`、`dlv`、`staticcheck`、`goimports`、`gofumpt` 等一次装齐）；
4. 这些工具**装在 `$(go env GOPATH)/bin`**（Windows 一般是 `C:\Users\<你>\go\bin`），必须加进系统 `PATH`，否则 VSCode 找不到 `gopls` / `dlv`。

```bash

go env GOPATH GOBIN                        # 确认工具安装目录
go install golang.org/x/tools/gopls@latest # 单独补装也行
```

### 二、`settings.json`：语言服务 + 保存即格式化

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

### 三、`launch.json`：让运行/调试按钮真正可用

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

### 面试官会追问什么

- **`gopls` 报错不准 / 补全卡住？** → `Go: Restart Language Server` 重启并清缓存，再确认 VSCode 里的 `GOPATH`/`GOROOT` 与终端一致（多版本 SDK 最容易踩）。
- **断点是灰色打不上？** → 触发优化/内联了，用 `-gcflags="all=-N -l"`；或该代码路径根本没走到；或调试的二进制与源码不同版本。
- **`args` 和 `env` 有什么区别？** → `args` 是命令行参数（对应 `os.Args`），`env` 是进程环境变量（对应 `os.Getenv`）。

---

## Q2. 常用调试技巧有哪些？

**来源**：`BV12qjA6aErF p=52` B站 Go 面试真题 · 时长 6分38秒
**考察意图**：考"会不会用调试器解决问题"而不是"会不会打日志"。断点条件、命中计数、远程调试、测试缓存这几件事，是区分熟练与不熟练的分水岭。

### 一、先解决"看不到值"

在 `launch.json` 的配置里加 `"buildFlags": "-gcflags=all=-N -l"`（`dlv debug` 模式默认已带），再把 `dlvLoadConfig.maxStringLen` / `maxArrayValues` 调大，才不至于看到 `{...}` 或 `<optimized out>`。

### 二、四种比 `println` 高效的断点

| 手段 | 场景 | 用法 |
|---|---|---|
| **条件断点** | 只在 `i == 997` 时停下 | 右键断点 → Edit Breakpoint → 输入表达式 |
| **命中计数断点** | 循环第 100 次才停 | Edit Breakpoint → Hit Count |
| **日志断点** | 不想改代码、只想打印且不中断 | 右键 → Add Logpoint，`${expr}` 插值 |
| **panic 断点** | 抓 panic 现场 | 在 `runtime.gopanic` 上打断点后 `continue` |

### 三、三种调试姿态

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

### 四、不打断点的三个偏方

```bash

go build -gcflags="-S" . > asm.txt           # 汇编落到文件慢慢比对
GOSSAFUNC=Foo go build .                     # 生成 SSA 图 ssa.html
go test -count=1 -gcflags="all=-N -l" ./...  # 调测试必加 -count=1
```

### 面试官会追问什么

- **调试时变量显示 `<optimized out>` 是什么原因？** → 优化把变量放进寄存器或被常量传播了，用 `-N -l` 重新构建。
- **线上服务怎么调试？** → 不建议在生产开 `-N -l`；常规做法是 `dlv attach` 到进程、用日志断点/条件断点最小化停顿，或者靠 pprof + 日志回放复现。
- **`dlv` 和 `gdb` 有什么区别？** → dlv 是 Go 生态的调试器，理解 goroutine、defer、interface 等运行时结构；gdb 只能看汇编层面的现场，调 Go 程序经常错乱。

---

## 关联

- [编译与gcflags.md](编译与gcflags.md) — 断点打不上时先看编译优化开关
- [协程泄漏与死锁.md](协程泄漏与死锁.md) — 卡死现场怎么用 pprof / dlv 抓
- [../linux/性能排查.md](../linux/性能排查.md) — 线上没有 IDE 时的排查手段
