# Shell与脚本

> 覆盖：bash 的**基础语法与执行模型**——变量与引号、条件与循环、函数与数组、正则与 `sed` / `awk`、脚本调试与规范；以及「敲下一行到它真正跑起来」之间发生了什么（分词 → 解析 → 展开 → 建管道 → 执行）。
>
> 不覆盖：管道、重定向与文本处理的**命令速查**见 [常用命令.md](../常用命令.md)；脚本作为服务运行（`ExecStart`）的注意点见 [05-服务与systemd.md](05-服务与systemd.md)；例行任务（cron）与脚本的结合见 [10-系统管理综合.md](10-系统管理综合.md)；脚本的执行权限与 `sudo` 授权见 [03-用户与权限.md](03-用户与权限.md)。
>
> 内容整理自个人学习笔记。**基础框架**参考《鸟哥的 Linux 私房菜：基础学习篇（第四版）》相关章节（认识与学习 BASH、正则表达式、Shell Script）的读后整理；现代脚本规范（`set -euo pipefail` / shellcheck）基于官方文档与社区实践独立整理。素材见 [素材清单](../../../素材清单.md)。
>
> 📎 **实测口径**：本篇的**行为验证**在容器 `w9b-shell`（镜像 `eclipse-temurin:17-jdk`，容器内 `bash --version` 为 `GNU bash, version 5.3.9(1)-release`，系统 `Ubuntu 26.04.1 LTS`，`/bin/sh` 指向 `dash`）里实跑，纯行为验证各跑一遍、原文存证 `.workbuddy/tmp/round495/w9b_shell_run1.txt`（连跑两遍 `diff` 只在输出文件名与 PID 上不同）。**未实测**：cron / systemd 联动、`shellcheck`（容器内没有这两个环境，本片不新增镜像）、alpine 的 BusyBox `sh` 差异。

---

## 一、Shell 基础：变量、引号与替换

**本节要点**：引号是 Shell 里最容易出错的地方——单引号「所见即所得」，双引号会做变量与命令替换，不加引号会做**分词**与**通配**。

### 1.1 常见 Shell

| Shell | 特点 | 何时用 |
|-------|------|--------|
| `sh` | POSIX 标准，最小集 | 可移植脚本、容器里的 `sh` |
| `bash` | Linux 默认，功能全 | 日常脚本 |
| `zsh` | 交互体验好（补全、主题） | 交互登录用，脚本仍写 bash |

### 1.2 变量与引号

```bash
name="world"
echo "hello $name"      # 双引号：替换变量 → hello world
echo 'hello $name'     # 单引号：原样输出 → hello $name
echo hello $name        # 不加引号：分词（此处无影响，含空格时就会裂开）

files=$(ls *.txt)        # 命令替换（推荐 $() 而非反引号）
echo "count: ${#files}"  # ${} 界定变量名边界
```

⚠️ **永远给变量加双引号**：`"$var"`。变量含空格 / 通配符时，不加引号会被分词或展开，是脚本事故的头号来源。

### 1.3 常用参数与特殊变量

| 变量 | 含义 |
|------|------|
| `$0` | 脚本名 |
| `$1..$9` / `${10}` | 位置参数 |
| `$#` | 参数个数 |
| `$@` / `$*` | 全部参数（`"$@"` 保留每个参数边界，推荐） |
| `$?` | 上一条命令退出码 |
| `$!` | 最近一个后台进程 PID |

---

## 二、条件与循环怎么写？

**本节要点**：`if` / `case` 做分支、`for` / `while` / `until` 做循环；判断用 `[[ ]]`（bash）或 `[ ]`（POSIX），数值比较与字符串比较是两套运算符。

### 2.1 条件判断

```bash
if [[ -f "/etc/nginx/nginx.conf" && $# -gt 0 ]]; then
  echo "配置存在且传了参数"
elif [ "$1" = "test" ]; then
  echo "test 模式"
else
  echo "其他"
fi
```

| 判断 | 写法 | 说明 |
|------|------|------|
| 文件存在 | `[ -f file ]` / `[ -d dir ]` | 文件 / 目录 |
| 字符串相等 | `[ "$a" = "$b" ]` | ⚠️ `=` 两边的空格不能省 |
| 数值比较 | `[ "$a" -gt "$b" ]` | `-eq -ne -gt -lt -ge -le` |
| 逻辑 | `[[ a && b ]]` 或 `[ a ] && [ b ]` | `[[ ]]` 才支持 `&&` / `||` / 正则 `=~` |

⚠️ `[` 是命令（`test` 的别名），**方括号内侧必须留空格**：`[ -f x ]` 对，`[-f x]` 错。

### 2.2 循环

```bash
for host in web01 web02 web03; do
  echo "checking $host"
done

while read -r line; do
  echo "line: $line"
done < servers.txt

case "$1" in
  start|stop) echo "control $1" ;;
  *) echo "usage: $0 start|stop" >&2; exit 2 ;;
esac
```

---

## 三、函数与数组

**本节要点**：函数用 `return` 只能返回**退出码**（0~255），要返回数据靠 `echo` + 命令替换；数组在 bash 里是「下标数组」，`"${arr[@]}"` 才保住元素边界。

```bash
greet() {
  local name="$1"            # 局部变量，避免污染全局
  echo "hello $name"         # 用 echo 当"返回值"
}

msg=$(greet world)           # 命令替换接住
echo "$msg"
```

```bash
arr=(a "b c" d)              # 三个元素
echo "${#arr[@]}"            # 元素个数 → 3
for x in "${arr[@]}"; do     # ⭐ @ 且加引号，保住 "b c" 是一个元素
  echo "[$x]"
done
```

⚠️ `for x in ${arr[@]}`（不加引号）会把 `b c` 拆成两个——这是把数组「当字符串用」的典型错误。

**实测**（同一容器）：`arr=(a "b c" d)` 的 `${#arr[@]}` 是 `3`；`for x in "${arr[@]}"` 依次得到 `[a]` `[b c]` `[d]`，不加引号的 `for x in ${arr[@]}` 则得到 `[a]` `[b]` `[c]` `[d]` 四个——元素边界被空格拆掉。另外 `f(){ return 3; }; f; echo $?` 打出 `3`，而 `$(f_echo world)` 打出 `hello world`，印证「`return` 只能回退出码、数据要靠 `echo` + 命令替换」。

---

## 四、正则与文本处理

**本节要点**：`grep` 找行、`sed` 改行、`awk` 按列处理——三件套的分工清晰，别用一把锤子敲所有钉子。

| 工具 | 定位 | 典型用途 |
|------|------|----------|
| `grep` | 按行**筛选** | 找日志关键词 |
| `sed` | 按行**替换 / 删除** | 批量改配置 |
| `awk` | 按**列**处理 + 聚合 | 统计、求和、取字段 |

```bash
grep -nE "ERROR|WARN" app.log            # -E 扩展正则，-n 行号
sed -i.bak "s/old/new/g" config.conf    # -i 原地改，.bak 留备份
awk '{sum += $2} END {print sum}' data.txt   # 第二列求和
awk -F: '$3 >= 1000 {print $1}' /etc/passwd  # 以冒号分隔，取 UID>=1000
```

`stdin` / `stdout` / `stderr` 在内核里就是三个**文件描述符**：`0` / `1` / `2`。重定向改的不是别的，就是这三个 fd 指向哪里——`>` 只改 `1`、`2>` 只改 `2`，而 `2>&1` 是把 `2` 改成「`1` 此刻指向的位置」。⚠️ 正因为 `&1` 取的是**当前位置**，`> f 2>&1` 与 `2>&1 > f` 的结果并不一样（后者 stderr 仍留在屏幕上）。

```bash
# 管道 + 重定向：把 stdout / stderr 分开处理
cmd 2>/dev/null                    # 丢弃错误输出
cmd > out.txt 2>&1                 # stdout 与 stderr 一起进文件
cmd | tee out.txt                  # 既上屏又落文件
```

⚠️ 正则的「基本」与「扩展」是两套：`grep` 默认基本正则（`+ ? |` 要转义），`grep -E` / `egrep` 才是扩展正则。

![一条命令的执行流程与重定向的 fd 数据流：分词 → 解析 → 展开 → 执行四个阶段，展开顺序固定为大括号 / 波浪号 / 变量 / 命令替换 / 算术 / 分词 / 通配 / 去引号；下半部分画 stdin = 0、stdout = 1、stderr = 2 三个描述符的流向，以及 `> f 2>&1` 与 `2>&1 > f` 两种顺序的不同结果](images/Shell执行流程与重定向fd数据流.svg)

**实测**（容器 `w9b-shell`，GNU bash 5.3.9）：`ls -l /proc/$$/fd` 里能看到 `0 -> /dev/null`、`1 -> …`、`2 -> …` 三条描述符；`{ echo "先 A"; echo "再 B" >&2; } > /tmp/f 2>&1` 时文件里是「先 A」「再 B」两行，而把两个重定向反过来写成 `2>&1 > /tmp/f` 时，文件里只剩「先 A」、「再 B」留在屏幕上。原文见 `.workbuddy/tmp/round495/w9b_shell_run1.txt`。

---

## 五、脚本调试与规范

**本节要点**：脚本「跑得快」不如「错得早」——`set -euo pipefail` 让错误立即暴露，`shellcheck` 静态查坑，POSIX 兼容写法保证换 Shell 也能跑。

### 5.1 三道防线

```bash
#!/usr/bin/env bash
set -euo pipefail    # e: 出错即退; u: 未定义变量报错; pipefail: 管道任一环失败即算失败
IFS=$'\n\t'        # 收紧分词边界（按行/制表符）
```

- `set -x` 打印每条命令（调试）；`bash -x script.sh` 免改文件；
- `set -e` 有盲区：命令在 `if` / `&&` / `||` 里、或用 `!` 取反时**不会**触发退出——别把它当成万无一失。

**实测**（同一容器）：`set -e` 打开后，`if false; then …; fi`、`false && … || …`、`! false` 三种写法都**没有**让脚本退出（正是上面说的盲区）；换 `set -u`，`${not_defined_here}` 会以 `not_defined_here: unbound variable` 终止、退出码 `1`，而不开 `set -u` 时同一个变量只当空串；`IFS=$'\n\t'` 之后，`x="a b"` 的 `for w in $x` 只切出一个词 `a b`，默认 IFS 下则切成 `a`、`b` 两个。原文见 `.workbuddy/tmp/round495/w9b_shell_run1.txt`。

### 5.2 规范与检查

```bash
shellcheck deploy.sh          # 静态检查（本地/CI 都可挂）
sh -n script.sh               # 只做语法检查，不执行
```

| 规范 | 理由 |
|------|------|
| 变量加双引号 `"$var"` | 防分词 / 通配 |
| 用 `$(...)` 而非反引号 | 可嵌套、可读 |
| 用 `[[ ]]` 而非 `[ ]`（bash 内） | 支持 `&&`、`=~`、免转义 |
| 写 `trap` 清理临时文件 | 异常退出也不留垃圾 |
| POSIX 场景用 `#!/bin/sh` + 只写 POSIX 语法 | 容器（alpine）里常只有 `sh` |

---

## 六、应用场景

**本节要点**：Shell 脚本在真实环境里很少出现在「写业务代码」阶段，而是集中在**构建、部署、排障**三个环节；下面按「谁在什么场景下用到本篇哪一节」列出来。

| 落地场景 | 这里的哪一节在起作用 | 本仓相关篇目 |
|---|---|---|
| CI/CD 的每个 job | 流水线默认用 `sh -c` 跑脚本，失败是否中断取决于 `set -e` 与 `pipefail`（五） | [GitLab-CI-CD.md](../../../06-工程实践/部署/GitLab-CI-CD.md) |
| 镜像构建的 `RUN` | 每条 `RUN` 都是一次 `sh -c`；`&&` 串联还是一次跑完，决定分层与失败行为（二、五） | [镜像构建与缓存.md](../../../06-工程实践/部署/docker/镜像构建与缓存.md) |
| 容器 entrypoint / K8s 探针 | 脚本要自己接住信号，别把容器里的 1 号进程让给一个不处理信号的命令（五、延伸追问） | [K8s部署流程.md](../../../06-工程实践/部署/k8s/K8s部署流程.md) |
| 服务以脚本启动 | `ExecStart` 里进退出的脚本必须前台运行，`set -e` 让失败可见 | [05-服务与systemd.md](05-服务与systemd.md) |
| 定时任务 | cron 的环境极简（PATH 短、无别名），脚本要自带绝对路径与日志重定向（四） | [10-系统管理综合.md](10-系统管理综合.md) |
| 上线 / 回滚脚本 | 「重复执行也安全」是底线：先判断再动手、确保状态而非执行动作（七） | [CI-CD面试题.md](../../../06-工程实践/部署/CI-CD面试题.md) |
| 临时进容器排障 | `docker exec` 拿到的多是 `sh`（alpine 上是 BusyBox），别写 bash 专有语法（五） | [进入运行中的容器.md](../../../06-工程实践/部署/docker/进入运行中的容器.md) |
| 看日志与统计 | `grep` / `sed` / `awk` 加管道是默认手段（四） | [常用命令.md](../常用命令.md)、[07-日志与journalctl.md](07-日志与journalctl.md) |

⭐ **三条工程判据**：

1. **变量一律加双引号**：`"$var"` 挡掉分词与通配，`"${arr[@]}"` 保住元素边界——这是脚本事故的头号来源（1.2、三）。
2. **`set -euo pipefail` 是防线、不是保证**：条件上下文里的失败不会触发退出，管道要 `pipefail` 才报错；关键步骤仍要显式检查 `$?`（五，实测见 5.1）。
3. **跨进程边界的写法要显式**：管道里的循环在子壳里跑、结果拿不出来，要就地生效就用重定向或进程替换（实测见延伸追问）。

## 七、具体使用

**本节要点**：把上面的要素拼成一个「重复执行也安全」的脚本——这是运维脚本的核心诉求；下面先给几条「一行就能验」的对照命令，再给完整骨架。

### 7.1 一行验证：引号、退出码、数组边界

```bash
f="a b.txt"
set -- $f;   echo $#    # 不加引号 → 2（被空格分词）
set -- "$f"; echo $#    # 加双引号 → 1

true;  echo $?          # 0
false; echo $?          # 1

false | true; echo $?                    # 0：默认只看管道最后一段
set -o pipefail
false | true; echo $?                    # 1：任一环失败即整条失败

arr=(a "b c" d)
for x in "${arr[@]}"; do echo "[$x]"; done   # [a] [b c] [d]
for x in ${arr[@]};    do echo "[$x]"; done  # [a] [b] [c] [d] ← 边界没了
```

### 7.2 一个幂等的部署脚本骨架

```bash
#!/usr/bin/env bash
set -euo pipefail

APP_DIR=/srv/app
trap 'rm -f /tmp/deploy.$$' EXIT      # 无论如何都清理临时文件

if [[ ! -d "$APP_DIR" ]]; then
  mkdir -p "$APP_DIR"
fi

# 幂等：已存在就不重复做
if [[ ! -f "$APP_DIR/config.yaml" ]]; then
  cp ./config.example.yaml "$APP_DIR/config.yaml"
fi

echo "deploy done: $APP_DIR"
```

判据：**连跑两次，第二次不报错、不留重复文件**（幂等）；`bash -x` 能看到每条命令；`shellcheck` 无告警。

---

## 延伸追问

- **`$?` 和 `$!` 的区别？**
  → `$?` 是**上一条命令的退出码**（0 成功、非 0 失败），每次命令执行都会刷新；`$!` 是**最近一个后台进程的 PID**（`cmd &` 之后立即取）。前者用于判成败，后者用于 `kill $!` / `wait $!`。
- **为什么管道里的 `while` 循环改变不了外层变量？**
  → 管道的每一段都在**子 Shell** 里执行，`a | while read` 的 while 跑在子进程中，它改的变量随子进程退出就没了。三种解法：① 用 `< file` 重定向代替管道（当前 shell 执行）；② bash 的 `shopt -s lastpipe`；③ 进程替换 `while ... done < <(cmd)`。本机实测（容器 `w9b-shell`）：管道版循环后 `cnt=0`，重定向版与进程替换版都是 `cnt=3`。
- **怎么让脚本幂等？**
  → 三个动作：**先判断再动手**（`[[ -f ]]` 才写）、**用「确保状态」而非「执行动作」**（`id user || useradd`、`mkdir -p`）、**可重入的更新**（先写临时文件再原子 `mv`）。判据是「连跑两次结果一致、无副作用叠加」。
- **`set -e` 为什么有时「不生效」？**
  → 它在**条件上下文**里被禁用：命令出现在 `if` / `while` 条件、`&&` / `||` 左侧、`!` 取反、或 `set -e` 之后仍显式 `|| true` 时都不会触发退出。这是 POSIX 规定的行为，不是 bug——所以关键步骤仍要显式检查 `$?`。

---

## 关联

- **上游/总纲**：[常用命令.md](../常用命令.md) — 管道、重定向与文本处理的命令速查
- **下游机制**：[05-服务与systemd.md](05-服务与systemd.md) — 脚本作为服务运行时（ExecStart）的注意点
- **下游机制**：[10-系统管理综合.md](10-系统管理综合.md) — 例行任务（cron）与脚本的结合
- **对照/互补**：[03-用户与权限.md](03-用户与权限.md) — 脚本的执行权限与 sudo 授权
- **运维/配置**：[GitLab-CI-CD.md](../../../06-工程实践/部署/GitLab-CI-CD.md) — 流水线里每一步都是一段脚本，失败口径由 `set -e` 决定
- **运维/配置**：[镜像构建与缓存.md](../../../06-工程实践/部署/docker/镜像构建与缓存.md) — `RUN` 指令的 `sh -c` 语义与分层
- **运维/配置**：[进入运行中的容器.md](../../../06-工程实践/部署/docker/进入运行中的容器.md) — `docker exec` 里能拿到哪个 shell
- **下游机制**：[07-日志与journalctl.md](07-日志与journalctl.md) — 脚本日志怎么进 journald
- **上游/总纲**：[README.md](README.md) — 本目录导读：版本坐标与阅读顺序
