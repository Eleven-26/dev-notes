# Shell与脚本

> 变量与引号、条件与循环、函数与数组、正则与 sed/awk、脚本调试与规范；
> 以 bash 为主，并强调 POSIX 兼容写法。
>
> 内容整理自个人学习笔记。**基础框架**参考《鸟哥的 Linux 私房菜：基础学习篇（第四版）》
> 相关章节（认识与学习 BASH、正则表达式、Shell Script）的读后整理；现代脚本规范（`set -euo pipefail` / shellcheck）基于官方文档与社区实践独立整理。

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

```bash
# 管道 + 重定向：把 stdout / stderr 分开处理
cmd 2>/dev/null                    # 丢弃错误输出
cmd > out.txt 2>&1                 # stdout 与 stderr 一起进文件
cmd | tee out.txt                  # 既上屏又落文件
```

⚠️ 正则的「基本」与「扩展」是两套：`grep` 默认基本正则（`+ ? |` 要转义），`grep -E` / `egrep` 才是扩展正则。

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

## 使用：一个幂等的部署脚本骨架

**本节要点**：把上面的要素拼成一个「重复执行也安全」的脚本——这是运维脚本的核心诉求。

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
  → 管道的每一段都在**子 Shell** 里执行，`a | while read` 的 while 跑在子进程中，它改的变量随子进程退出就没了。三种解法：① 用 `< file` 重定向代替管道（当前 shell 执行）；② bash 的 `shopt -s lastpipe`；③ 进程替换 `while ... done < <(cmd)`。
- **怎么让脚本幂等？**
  → 三个动作：**先判断再动手**（`[[ -f ]]` 才写）、**用「确保状态」而非「执行动作」**（`id user || useradd`、`mkdir -p`）、**可重入的更新**（先写临时文件再原子 `mv`）。判据是「连跑两次结果一致、无副作用叠加」。
- **`set -e` 为什么有时「不生效」？**
  → 它在**条件上下文**里被禁用：命令出现在 `if` / `while` 条件、`&&` / `||` 左侧、`!` 取反、或 `set -e` 之后仍显式 `|| true` 时都不会触发退出。这是 POSIX 规定的行为，不是 bug——所以关键步骤仍要显式检查 `$?`。

---

## 关联

- [常用命令.md](../常用命令.md) — 管道、重定向与文本处理的命令速查
- [05-服务与systemd.md](05-服务与systemd.md) — 脚本作为服务运行时（ExecStart）的注意点
- [10-系统管理综合.md](10-系统管理综合.md) — 例行任务（cron）与脚本的结合
- [03-用户与权限.md](03-用户与权限.md) — 脚本的执行权限与 sudo 授权
- [README.md](README.md) — 本目录导读：版本坐标与阅读顺序
