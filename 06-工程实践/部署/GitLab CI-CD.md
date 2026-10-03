# GitLab CI/CD

> GitLab CI/CD 的**语法与用法手册**：对象模型 → `rules` → 复用三件套（`extends` / `!reference` / `include`）
> → 变量与优先级 → `cache` 与 `artifacts` → `needs` → 触发方式 → 一条完整的多环境流水线，
> 以及**怎么在本地把一份 `.gitlab-ci.yml` 验证一遍**。
>
> 内容整理自个人学习笔记。**工程取舍、事故与落地细节**（DooD 模式与 `docker.sock`、
> executor 选型、镜像 tag 策略、声明式部署与回滚、流水线事故表）见 [CI-CD面试题.md](CI-CD面试题.md)，本篇不重复、只链接。
>
> ⚠️ **本篇的语法结论全部在本机实跑验证过**（`gitlab-ci-local@4.75.1`，第三方本地实现，
> **不需要 GitLab 服务器**），各节贴的是原始输出；`include` 一节未能本地验证，原因写在那一节里。
> 该工具是 GitLab 行为的**再实现**而非官方 runner：**语法解析与 job 过滤**层面与 GitLab 一致，
> **执行语义**（容器内怎么跑、缓存真否命中）不能以它为准。

---

## 一、对象模型：Pipeline / Stage / Job / Runner / Executor

**本节要点**：把五个词的位置摆对，后面所有语法都是在给「哪个层级」加配置。最常见的混淆是
**Runner 与 Executor** —— Runner 是注册到 GitLab 的代理进程，Executor 是它**用什么方式**把 job 跑起来。

```text
GitLab 服务端
  └── Pipeline        一次流水线运行，由一次事件（push / MR / 定时 / API）触发
        └── Stage     阶段，默认按 stages 声明的顺序串行
              └── Job 最小执行单元（一个 script 集合）
                   ↑ 由谁执行？
Runner（注册到 GitLab 的代理进程）
  └── Executor        Runner 用什么方式跑 job：shell / docker / kubernetes
```

三个容易记错的点：

| 点 | 说明 |
|---|---|
| **job 的 `stage` 必须先声明** | 不在 `stages` 列表里就直接报错。默认 stage 名是 `test`，所以只写 `stages: [build]` 而 job 没写 `stage` 时报 `stage:test not found for <job>` |
| **`.pre` / `.post` 是隐式补上的** | 只写 `stages: [build]`，展开后仍是 `.pre → build → .post`（实测见下） |
| **stage 只是默认顺序，不是硬约束** | 用 `needs` 可以跨 stage 直接依赖（见第六节） |

实测：只声明一个 stage，看展开结果 —— `.pre` / `.post` 被自动加上：

```yaml
stages: [build]
```

```text
---
stages:
  - .pre
  - build
  - .post
```

---

## 二、最小骨架与关键字地图

**本节要点**：一份能跑的 `.gitlab-ci.yml` 只需要 `job 名 + script`。其余关键字按「作用层级」记，
而不是背列表。

```yaml
# 全局层
stages: [build, test, deploy]
variables:
  APP: order-service
default:
  image: alpine:3.19          # 所有 job 的默认值
  retry: 1

# 模板 job（名字以 . 开头 = 不执行，只给 extends 用）
.build-base:
  stage: build
  script:
    - echo "compile"

# 普通 job
build:
  extends: .build-base
  script:
    - echo "build $APP"
```

| 层级 | 关键字 |
|---|---|
| **全局** | `stages` `variables` `default` `workflow` `include` `image` `services` `cache` |
| **job 级** | `stage` `script` `before_script` `after_script` `image` `services` `variables` `rules` `needs` `dependencies` `artifacts` `cache` `extends` `retry` `timeout` `allow_failure` `environment` `resource_group` `parallel` `interruptible` `tags` |
| **触发/调度相关** | `workflow:rules`（决定整条 pipeline 跑不跑）、`rules`（决定单个 job 创不创建）、`when`、`only` / `except`（旧写法） |

> 命名约定：以 `.` 开头的 job（`.build-base`）**不会被创建**，惯例用来放模板给 `extends`。

---

## 三、`rules`：决定「这个 job 这次要不要创建」⭐

**本节要点**：`rules` 是**逐条求值、命中即停**——第一个条件为真的规则决定这个 job 的行为；
**没有任何规则命中时，job 根本不会被创建**（不是"跳过"，是"不存在"）。这跟 `only/except` 的行为差别很大，
也是"为什么我改了 YAML 但那个 job 没出现"的头号原因。

### 3.1 实测：同一份 YAML，5 种输入下的 job 集合

被测文件（`e1-rules.yml`）：

```yaml
stages: [build, deploy]

build:
  stage: build
  script: [echo build]

deploy:develop:
  stage: deploy
  script: [echo deploy-dev]
  rules:
    - if: '$CI_COMMIT_BRANCH == "develop"'

deploy:main:
  stage: deploy
  script: [echo deploy-main]
  rules:
    - if: '$CI_COMMIT_BRANCH == "main"'

deploy:tag:
  stage: deploy
  script: [echo deploy-tag]
  rules:
    - if: '$CI_COMMIT_TAG'
      when: manual
      allow_failure: true

deploy:fallback:
  stage: deploy
  script: [echo deploy-fallback]
  rules:
    - if: '$CI_COMMIT_BRANCH == "main"'
      when: never
    - when: on_success
```

用 `--list` 看每种输入下**实际会被创建的 job**（原始输出）：

```text
----- 输入: （不注入，当前分支 master）
name             description  stage   when        allow_failure  environment  needs
build                         build   on_success  false
deploy:fallback               deploy  on_success  false

----- 输入: CI_COMMIT_BRANCH=main
build                         build   on_success  false
deploy:main                   deploy  on_success  false

----- 输入: CI_COMMIT_BRANCH=develop
build                         build   on_success  false
deploy:develop                deploy  on_success  false
deploy:fallback               deploy  on_success  false

----- 输入: CI_COMMIT_BRANCH=feature/x
build                         build   on_success  false
deploy:fallback               deploy  on_success  false

----- 输入: CI_COMMIT_TAG=v1.2.3
build                         build   on_success   false
deploy:tag                    deploy  manual       true
deploy:fallback               deploy  on_success   false
```

读出五条结论：

1. **不匹配的 job 直接消失**（master 分支下没有 `deploy:main`），不是 `skipped`；
2. **`when: never` 是"这条规则命中就否决"**：`deploy:fallback` 第一条在 main 分支命中 `never`，
   于是走不到第二条 —— 所以 **main 分支下它反而不出现**（表里 main 那行只有 `deploy:main`）；
3. **没有 `if` 的规则是兜底**：`- when: on_success` 在任何分支都命中 → 所以 master/develop/feature
   下它都在；
4. **`when: manual` 的 job 仍然"存在"**：`--list` 把它列出来、`when` 列显示 `manual`、
   `allow_failure=true`（手动 job 不跑不算失败）；
5. **`$CI_COMMIT_TAG` 与 `$CI_COMMIT_BRANCH` 是互斥的**：tag 触发时分支变量为空，
   所以按 tag 触发的 pipeline 里 `deploy:main` 不会出现。

> `--list` 的口径：**`when: never` 的 job 被排除**（要连它一起看用 `--list-all`）。
> 上表里 `deploy:fallback` 在 main 分支下是被 `never` 否决的，因此该行不出现。

### 3.2 规则里能写什么

| 键 | 作用 |
|---|---|
| `if` | 单个表达式（`$VAR`、`==`、`!=`、`=~` 正则、`&&`、`\|\|`、括号） |
| `changes` | 指定路径的文件是否变更（配合 `--evaluate-rule-changes` 控制求值） |
| `exists` | 仓库里是否存在某个文件 |
| `when` | `on_success`(默认) / `on_failure` / `always` / `manual` / `delayed` / `never` |
| `allow_failure` | 该 job 失败是否阻断流水线 |
| `variables` | 命中这条规则时**额外**注入的变量（优先级高于 job 级 `variables`，见第四节） |
| `needs` | 命中时才生效的依赖（可以做"只在 MR 里跑某几步"这种分支） |

> `rules:if` 里的变量在**创建 pipeline 时**求值 —— 所以它判断的是"这次要不要建这个 job"，
> 而不是"跑到这里再看"。这与 `script` 里的 shell 判断是两件事。

---

## 四、复用三件套：`extends` / `!reference` / `include`

**本节要点**：三者解决的不是同一个问题 —— **`extends` 是"整体继承 + 覆盖"，`!reference` 是"片段内联**"，
**`include` 是"跨文件拼装"**。日常最常用的组合是 `extends`（配模板 job）+ `include`（配共享片段）。

### 4.1 `extends`：`variables` 合并，`script` 整体替换

被测文件：

```yaml
stages: [build]

.base:
  stage: build
  image: alpine:3.19
  variables:
    A: from-base
    B: from-base
  before_script:
    - echo base-before
  script:
    - echo base-script

child:
  extends: .base
  variables:
    B: from-child
  script:
    - echo child-script
```

`--preview` 展开后（**这就是 GitLab 侧实际拿到的配置**）：

```text
---
stages:
  - .pre
  - build
  - .post
child:
  stage: build
  image:
    name: alpine:3.19
  variables:
    A: from-base
    B: from-child
  before_script:
    - echo base-before
  script:
    - echo child-script
```

两条关键语义：

- **`variables` 是合并**：`A` 从模板继承下来，`B` 被子 job 覆盖；
- **`script` 是整体替换**（不是追加）：子 job 写了 `script`，模板的 `echo base-script` 就**没了**。
  想"在模板基础上再加几行"只能用 `!reference`（4.2）或把公共行放进 `before_script`。

> `image` 继承下来后从字符串变成 `{name: ...}` 的对象形式 —— 这是 GitLab 的内部规范化，
> 两种写法等价。

### 4.2 `extends` 多继承：数组靠后的赢

```yaml
.a:
  stage: build
  image: alpine:3.19
  variables:
    X: from-a
    Y: from-a
  script: [echo script-a]

.b:
  stage: build
  variables:
    Y: from-b
    Z: from-b
  script: [echo script-b]

multi:
  extends: [.a, .b]
```

展开结果：

```text
multi:
  stage: build
  image:
    name: alpine:3.19
  variables:
    X: from-a
    'Y': from-b
    Z: from-b
  script:
    - echo script-b
```

- **`variables` 三方合并**（`X` 只有 `.a` 有、`Z` 只有 `.b` 有、`Y` 冲突时 **`.b` 赢**）；
- **`script` 取 `.b` 的**；
- `image` 只有 `.a` 定义，照样继承下来。

⭐ 结论：**`extends: [A, B]` 中后面的覆盖前面的**。这条在"基础模板 + 语言模板 + 项目覆盖"三层叠加时是设计依据。

### 4.3 `!reference`：把片段内联到当前位置

```yaml
.setup:
  script:
    - echo step1
    - echo step2

assembled:
  stage: build
  script:
    - !reference [.setup, script]
    - echo step3
```

展开结果 —— 三行**按位置拼接**：

```text
assembled:
  stage: build
  script:
    - echo step1
    - echo step2
    - echo step3
```

与 `extends` 的本质差别（同一文件里的第二个 job 正好做了对照）：

```text
merged:
  script:
    - echo step1
    - echo step2
    - echo extra
  stage: build
```

`merged` 里只写了 `!reference` 那两行 + `extra`，
**模板的 `script` 没有先"继承"进来再重复一次** —— 说明 `!reference` 是**取值展开**，不参与继承链。

| | `extends` | `!reference` |
|---|---|---|
| 粒度 | 整个 job（或整个 job 的多个键） | **某个键的某一段**（如 `script`、`rules`） |
| 语义 | 继承 + 覆盖（同名键被替换） | 在**书写位置**插入内容 |
| 能否拼接 | ❌ 同名键只能整体替换 | ✅ 可以在前面/后面继续加行 |
| 适合 | 模板 job → 具体 job | 复用一段固定步骤，再各加自己的行 |

### 4.4 `include`：跨文件拼装（本节未在本机验证）

`include` 的语义按 **GitLab 官方文档**口径（本机实验环境无法满足它的文件要求，见文末说明）：

| 类型 | 用途 |
|---|---|
| `local` | 同一仓库、**同一分支**里的文件 |
| `project` | 同一实例的**另一个项目**里的文件（配 `include:file`，可选 `include:ref`） |
| `remote` | 完整 URL 拉取（HTTP/HTTPS GET，**不带认证**） |
| `template` | GitLab 官方内置模板 |
| `component` | CI/CD Component（`<FQDN>/<项目路径>/<组件名>@<版本>`） |

三条最常见的坑：

1. ⚠️ **`local` 路径以「仓库根」为基准**，不是以 `.gitlab-ci.yml` 所在目录为基准 ——
   所以 `include: local: /ci/foo.yml` 指的是仓库根下的 `ci/foo.yml`。
   更细一层：**include 始终按"包含这个 include 关键字的那份文件的位置"求值**，
   嵌套 include 时不会改用当前项目的路径；
2. **支持通配符**（`*`、`**`），扩展名必须是 `.yml` / `.yaml`；
3. **数量上限：默认每个 pipeline 最多 150 个 include（含嵌套）**，解析总时长 30 秒；
   重跑单个 job **不会**重新拉取 include 文件，重跑整条 pipeline 才会。

**合并顺序**：include 的文件先求值，再与 `.gitlab-ci.yml` 合并 —— **主文件覆盖被包含文件**。

---

## 五、变量：来源与优先级

**本节要点**：同名变量"谁赢"由**来源**决定，而不是由"写在哪一行"决定。
最容易踩的是：**UI 上配的项目变量会覆盖 `.gitlab-ci.yml` 里的同名值** ——
所以在 YAML 里改了半天没反应时，先去看 Settings → CI/CD → Variables。

按官方口径，优先级**从高到低**（节选常用部分）：

| 排名 | 来源 | 备注 |
|---|---|---|
| 1 | 策略变量（pipeline / scan execution policy） | 只作用于策略注入的 job |
| 2 | 手动作业变量 | 手动跑 job 时填的 |
| 3 | **流水线变量** | Run pipeline 页面 / schedule / Pipelines API / trigger / 上游流水线 |
| 4 | **项目变量** | 项目 Settings → CI/CD → Variables |
| 5 | 组变量 | 组 Settings → CI/CD；**子组优先于父组** |
| 6 | 实例变量 | 管理员 Admin → Settings → CI/CD |
| 7 | `dotenv` 报告变量 | 来自 `needs` / `dependencies` 里 job 的 dotenv 产物 |
| 8 | **`.gitlab-ci.yml` 内的变量** | 内部还有四级：`rules:variables` > job `variables` > `workflow:rules:variables` > 顶层 `variables` |
| 9 | 部署变量 | 预定义变量中的部署类 |
| 10 | 预定义变量 | 部分**不可覆盖**：`CI_ENVIRONMENT_ID` / `CI_ENVIRONMENT_SLUG` / `CI_ENVIRONMENT_URL` / `CI_PAGES_URL` |

官方给的例子正好说明第 4 与第 8 的关系：

```yaml
variables:
  DEPLOY_TARGET: "staging"     # 顶层

deploy:
  variables:
    DEPLOY_TARGET: "review"    # job 级（比顶层高）
  script:
    - echo "Deploying to $DEPLOY_TARGET"
```

| 情形 | 打印 |
|---|---|
| 只有上面的 YAML | `Deploying to review`（job 级 > 顶层） |
| 另外在项目里配了 `DEPLOY_TARGET=production` | `Deploying to production`（**项目变量 > YAML**） |
| 手动跑 pipeline 时填 `canary` | `Deploying to canary`（流水线变量 > 项目变量） |

本机实测也印证了第 8 级内部的两条（`job variables` 覆盖顶层 `variables`）——
4.1 的展开结果里 `B: from-child` 就是这条规则。

**几个实用判断**：

- **`rules:variables` 比 job 级 `variables` 还高** → 可以按分支给同一 job 换配置（如生产分支用不同的 registry）；
- **预定义变量原则上别覆盖**（本机工具甚至为此发警告），要改行为用自定义变量；
- **密钥类变量**应在 UI 配成 **Protected + Masked**：Protected 只注入到受保护分支/标签的流水线，
  Masked 让它在日志里显示为 `[MASKED]`（但**不是万无一失**，值太短或含特殊字符时会被拒绝）。

---

## 六、`cache` 与 `artifacts`：两件不同的事

**本节要点**：一句话区分 —— **`cache` 是"加速用的、允许丢失"，`artifacts` 是"产物、要被下一个 stage 用"**。
把它俩当同一件事是"缓存永远不命中"和"产物莫名丢失"的共同根源。

| | `cache` | `artifacts` |
|---|---|---|
| 目的 | 加速（依赖目录、编译中间件） | 传递产物 / 留存证据（可下载） |
| 保证 | **不保证存在**（可能过期、被清） | 由 GitLab 存储并可在 UI 下载 |
| 谁用 | 通常是**下次**的同名 job | **后续 stage 的 job**（默认自动下载） |
| 存储位置 | Runner 侧（本地/分布式缓存） | GitLab 服务端（对象存储） |
| 生命周期 | 由 `cache:key` 命中决定 | `expire_in`（默认 30 天） |
| 典型内容 | `.m2/`、`node_modules/`、Go module 缓存 | 二进制、`dist/`、测试报告 |

**`cache:key` 是命中率的全部**：

| key 写法 | 适用 |
|---|---|
| `cache:key: $CI_COMMIT_REF_SLUG` | **按分支隔离**（最常用；不隔离会跨分支互相污染） |
| `cache:key:files: [go.sum]` | 按**依赖清单内容**生成 key —— 依赖没变就命中，变了自动失效 |
| 固定字符串（如 `gomod`） | 只适合"所有分支共享同一份依赖缓存"且你清楚风险时 |
| 不给 key | 默认 key，几乎必然互相干扰 |

常见误用：

- ⚠️ **`artifacts` 当缓存用** → 每个 job 都往服务端传几十 MB，流水线变慢、存储爆掉；
- ⚠️ **缓存里放密钥**（如 `.npmrc` 带 token）→ 缓存可能被其他分支/其他项目命中；
- ⚠️ **以为 `cache` 一定命中** → 缓存 miss 是正常状态，**流水线不能依赖缓存存在**才能正确运行。

---

## 七、`needs`：用 DAG 打破 stage 串行

**本节要点**：`needs` 让 job 只要**它依赖的那些 job** 跑完就能开始，不必等同 stage 全部结束。
它是"把 30 分钟的流水线压到 10 分钟"最直接的手段，代价是**依赖关系变复杂、可读性下降**。

实测（`--list` 里的 `needs` 列）：

```text
name     description  stage    when        allow_failure  environment  needs
build                 build    on_success  false
test:a                test     on_success  false                       [build]
lint                  test     on_success  false
package               package  on_success  false                       [test:a]
```

读法：`package` 只 `needs: [test:a]`，**同 stage 的 `lint`、以及 stage 顺序都被绕过** ——
只要 `test:a` 完成，`package` 就能开始。

| 概念 | 含义 |
|---|---|
| `needs` | **DAG 依赖**：只等指定的 job，跨 stage 也行；被依赖的 job 若不存在/未创建会报错 |
| `dependencies` | **只控制"下载哪些 job 的 artifacts"**，不改变执行顺序（老概念，常与 `needs` 混用） |
| `needs: []` | 立即开始（不等任何前置） |
| `needs:project` / `needs:parallel:matrix` | 跨项目依赖 / 依赖矩阵 job 的某个实例 |

⚠️ 使用 `needs` 后的两个典型坑：

1. **被依赖的 job 因 `rules` 没被创建 → 依赖它的 job 直接因"依赖不存在"而失败**；
   本机工具提供 `--validate-dependency-chain` 专门提前校验这种断链；
2. **`needs` 会改变 artifacts 的下载范围**：默认只下载 `needs` 里列的 job 的 artifacts，
   而不是同 stage 全部 —— 想拿别的 job 的产物就得显式加进 `needs` 或 `dependencies`。

---

## 八、触发方式与 `workflow:rules`

**本节要点**：`rules` 管**单个 job**，`workflow:rules` 管**整条 pipeline 要不要跑**。
两者配错会出现"push 了但什么都没发生"或者"起了两条重复流水线"。

| 触发方式 | `$CI_PIPELINE_SOURCE` | 常见用途 |
|---|---|---|
| push | `push` | 主流程 |
| 合并请求 | `merge_request_event` | MR 检查（**与分支 pipeline 可能重复触发**） |
| 定时 | `schedule` | 夜间回归、定期清理 |
| 手动 / Web | `web` | 手动发布（配合 `when: manual`） |
| API / Trigger | `api` / `trigger` | 被外部系统调用 |
| 上游流水线 | `pipeline` | 多项目串接 |
| 父子流水线 | `parent_pipeline` | 动态生成子流水线（`trigger:` + `generate`） |

**避免重复流水线的标准写法**（MR 与分支二选一）：

```yaml
workflow:
  rules:
    # MR 场景：只在 MR 事件里跑，不跑分支 pipeline
    - if: '$CI_PIPELINE_SOURCE == "merge_request_event"'
    # 分支场景：分支已经开了 MR 时，push 不再单独触发
    - if: '$CI_COMMIT_BRANCH && $CI_OPEN_MERGE_REQUESTS'
      when: never
    - if: '$CI_COMMIT_BRANCH'
    - if: '$CI_COMMIT_TAG'
```

> `workflow:rules` 里**必须至少有一条能命中**，否则整条 pipeline 都不创建 ——
> 这是"改了 `workflow` 之后完全没反应"的典型原因。

---

## 九、一条完整的两环境流水线

把前面的点拼起来：**构建一次（与环境无关）+ 按分支决定部署到哪**，
`rules` 控制 job 创建、`cache` 加速依赖、`artifacts` 传产物、`resource_group` 防止并发部署打架。

```yaml
stages: [build, test, deploy]

variables:
  IMAGE: $CI_REGISTRY_IMAGE:$CI_COMMIT_SHA   # 不可变 tag：与 commit 一一对应
  GOMODCACHE: $CI_PROJECT_DIR/.cache/go

# ---------- 模板 ----------
.default:
  image: golang:1.26
  cache:
    key:
      files: [go.sum]           # 依赖清单变了才换 key
    paths: [.cache/go]

# ---------- 构建 ----------
build:
  extends: .default
  stage: build
  script:
    - go build -o app ./cmd/app
  artifacts:
    paths: [app]
    expire_in: 1 day            # 产物只需活到部署那一刻

# ---------- 测试（并行，且不必等彼此）----------
test:unit:
  extends: .default
  stage: test
  needs: [build]
  script: [go test ./...]

test:lint:
  extends: .default
  stage: test
  needs: []                     # 与构建无关，立刻跑
  script: [go vet ./...]

# ---------- 部署：靠 rules 区分环境 ----------
.deploy-template:
  stage: deploy
  resource_group: $CI_ENVIRONMENT_NAME   # 同一环境串行，避免并发部署互相覆盖
  script:
    - kubectl apply -f "deploy/$CI_ENVIRONMENT_NAME.yaml"
    - kubectl rollout status "deploy/$CI_ENVIRONMENT_NAME"
    - kubectl rollout undo "deploy/$CI_ENVIRONMENT_NAME"   # 失败前的止血位，见 CI-CD面试题.md 第四节
  environment:
    name: $CI_ENVIRONMENT_NAME

deploy:staging:
  extends: .deploy-template
  variables:
    CI_ENVIRONMENT_NAME: staging
  rules:
    - if: '$CI_COMMIT_BRANCH == "develop"'

deploy:production:
  extends: .deploy-template
  variables:
    CI_ENVIRONMENT_NAME: production
  rules:
    - if: '$CI_COMMIT_BRANCH == "main"'
      when: manual              # 生产必须有人点一下
      allow_failure: false
```

> 这段是语法层面的完整示例；**它没有在本机执行过**（本机无 Docker，`gitlab-ci-local` 只能做
> 语法与 job 过滤层面的验证）。镜像构建与部署那一段的工程细节（缓存、tag 策略、密钥、回滚）
> 见 [CI-CD面试题.md](CI-CD面试题.md) 第二节与第四节。

---

## 十、对照：GitHub Actions 的最小 Go CI

**本节要点**：GitLab CI 的写法讲完了，用一份**真跑在 CI 上**的 GitHub Actions 配置做对照 ——
概念几乎一一对应，但有四个「Go 项目特有」的点值得单独记。

### 10.1 完整配置

```yaml
name: ci
on:
  push:
    branches: [master]
  pull_request:
    branches: [master]
jobs:
  check:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-go@v5
        with:
          go-version-file: go.mod   # 版本以 go.mod 为准，避免两处各写一遍
          cache: true
      - name: gofmt
        run: |
          unformatted="$(gofmt -l .)"
          if [ -n "$unformatted" ]; then
            echo "以下文件未格式化，请跑 make fmt："
            echo "$unformatted"
            exit 1
          fi
      - name: go vet
        run: go vet ./...
      - name: go test -race
        run: go test -race ./... -count=1
      - name: build 三个入口
        run: go build -o bin/gateway ./cmd/gateway
      - name: benchmark（仅记录）
        run: go test ./internal/router/ -run XXX -bench . -benchtime 200ms -count=1
```

### 10.2 四个值得单独说的点

**① `gofmt -l` 有输出就失败** —— 把格式当成**门禁**，而不是 review 意见。
注意失败信息里要告诉人怎么修（提示 `make fmt`），否则每次都要去猜。

**② `go-version-file: go.mod`** —— 版本以 `go.mod` 为**单一来源**，
避免「CI 用 1.22、本地用 1.26」这种只能靠偶发编译错误才发现的漂移。

**③ ⭐ `-race` 只在 Linux runner 上跑** —— 本机（Windows + `CGO_ENABLED=0` + 无 gcc）的真实报错：

```text
$ go test ./internal/clientip/ -race -count=1
go: -race requires cgo; enable cgo by setting CGO_ENABLED=1

$ go test ./internal/clientip/ -count=1          # 不带 -race 正常
ok  	gwlab/internal/clientip	0.834s
```

→ 竞态检测需要 cgo（Linux runner 默认可开）。**这不是「本地可以不测」的理由**，
而是「把这一项放到 runner 上去做」的理由 —— 并发代码的竞态本来就在本地最难触发。

**④ benchmark 只记录、不设阈值** —— 机器规格差异太大，写死阈值只会天天假报警。
两个细节：`-run XXX` 让测试不跑（只跑基准）；阈值更好的替代是**与上一次比**（把数字存成 artifact）。

### 10.3 GitLab ↔ GitHub 概念对照

| 概念 | GitLab CI | GitHub Actions |
|---|---|---|
| 配置文件 | `.gitlab-ci.yml`（单文件） | `.github/workflows/*.yml`（可多文件） |
| 执行单位 | stage → job | job（内部 steps 顺序执行） |
| 顺序控制 | `stages` + `needs` | `needs`（默认并行） |
| 变量 / 密钥 | `variables` / CI/CD Variables | `env` / `secrets` / `vars` |
| 缓存 | `cache:` | `actions/cache`，或 `setup-*` 自带 `cache: true` |
| 条件 | `rules:` / `only` / `except` | `on:` + `if:` |
| 触发 | `trigger` / webhook | `on:` |
| 复用 | `extends` / `!reference` / `include` | 复合 action / reusable workflow |

### 10.4 选哪个

| 判据 | 选 |
|---|---|
| 仓库就在 GitHub，想直接用 marketplace 上现成的 action | GitHub Actions |
| 自建 runner、要与 Issue / MR / Registry 一体 | GitLab CI |
| 只要「vet + test + build」这三条 | 两家都够用 —— **别为了 CI 换托管平台** |

## 使用：不装 GitLab 也能把 YAML 验证一遍

**本节要点**：改 `.gitlab-ci.yml` 最怕"推上去才发现 job 没被创建"。用 `gitlab-ci-local`
可以**在本地把解析结果跑出来** —— 它只做语法解析与 job 过滤，不需要 GitLab 服务器、也不需要 Docker。

```bash
# 装（一次性；npx 直接跑也行）
npx --yes gitlab-ci-local@4.75.1 --version

# ① 看这次会创建哪些 job（受 rules / workflow 影响后的真实集合）
npx gitlab-ci-local --list

# ② 验证：某个分支下到底哪些 job 会跑（覆盖预定义变量来模拟分支）
npx gitlab-ci-local --list --variable CI_COMMIT_BRANCH=main

# ③ 看 extends / !reference / include 展开后的最终配置（排查"继承没生效"最有效）
npx gitlab-ci-local --preview

# ④ 校验 needs / dependencies 有没有断链（被依赖的 job 没被创建）
npx gitlab-ci-local --list --validate-dependency-chain
```

四条命令的定位：

| 命令 | 回答的问题 |
|---|---|
| `--list` | 这次会跑哪些 job？（`when: never` 的被排除；加 `--list-all` 全看） |
| `--preview` | `extends` / `!reference` 展开后**真实配置**长什么样？ |
| `--validate-dependency-chain` | `needs` 指向的 job 这次会不会被创建？ |
| `--evaluate-rule-changes=false` | 忽略 `rules:changes`（把所有 `changes` 当成立）来排查它 |

⚠️ **三个使用前提**（否则会被误判为"GitLab 有问题"）：

1. **它是第三方再实现**，不是官方 runner —— **语法解析、job 过滤**层面与 GitLab 一致，
   但**执行语义**（容器怎么跑、缓存真否命中）不能用它下结论；
2. **本地目录必须在 git 仓库内且文件被跟踪** —— 放在 `.gitignore` 里（含 `.workbuddy/` 这类工具目录）时，
   `include: local` 会报 `Local include file cannot be found`。这是本篇 `include` 一节没能本机验证的原因：
   实验文件放在被忽略的目录下，而 AI 不擅自 `git add`；
3. **覆盖预定义变量（如 `CI_COMMIT_BRANCH`）只是模拟手段**，工具会发警告 ——
   这正说明生产里不该靠覆盖预定义变量来改行为。

---

## 延伸追问

- **`rules` 和 `only/except` 该用哪个？** → 新项目一律用 `rules`：它能表达 `if` / `changes` / `exists` 的组合，
   且**可以给每条规则单独设 `when` 与 `variables`**。`only/except` 是旧写法，两者**不能在同一 job 里混用**。
- **`extends` 和 YAML 锚点（`&` / `*`）有什么区别？** → 锚点是 **YAML 语法层**的展开（纯文本替换，带合并语义），
   `extends` 是 **GitLab 语义层**的继承（能写多继承、能继承隐藏 job、按 GitLab 的键合并规则处理）；
   跨文件复用时 `extends` 配 `include` 更清晰，锚点只在同一文件内方便。
- **`needs` 和 `dependencies` 分不清怎么办？** → 记住 `needs` 是**执行顺序**（DAG），
   `dependencies` 是**下载哪些 artifacts**。只写 `needs` 不写 `dependencies` 时，artifacts 的下载范围跟着 `needs` 走。
- **为什么我在 YAML 里改了变量但没生效？** → 先看变量优先级：**项目/组的变量高于 `.gitlab-ci.yml`**。
   另外 `rules:variables` 比 job 级 `variables` 还高；预定义变量不该被覆盖。
- **缓存为什么老是不命中？** → 依次查：`cache:key` 是否按分支隔离、key 是否含变化的内容、
   Runner 是 docker executor（**每次新容器，本地缓存天然是空的**时要配分布式缓存或 `cache` 挂载）；
   另外"缓存未命中"本身是**正常状态**，流水线必须在没有缓存时也能跑通。
- **为什么 push 之后什么都没跑？** → 查 `workflow:rules` 是否全都没命中（整条 pipeline 不创建）、
   再看单个 job 的 `rules` 是否把 job 过滤掉了；用 `--list` 一眼就能看出。
- **job 卡在 pending 是什么原因？** → 没有匹配 `tags` 的 Runner、Runner 并发已满、Runner 离线或被暂停。
- **同一个 job 想跑多个组合（Go 多个版本 / 多平台）怎么办？** → `parallel:matrix`，
   它会按矩阵展开成多个 job 实例；注意 `needs` 依赖矩阵时要指定具体实例。

---

## 关联

- [CI-CD面试题.md](CI-CD面试题.md) — 工程取舍与事故：DooD 模式、executor 选型、镜像 tag 策略、声明式部署与回滚
- [docker/镜像构建与缓存.md](docker/镜像构建与缓存.md) — 流水线里"构建镜像"那一段的上下文与层缓存
- [docker/镜像瘦身与构建缓存.md](docker/镜像瘦身与构建缓存.md) — CI 上为什么每次从零构建、怎么把缓存搬到仓库
- [k8s/K8s部署与生命周期面试题.md](k8s/K8s部署与生命周期面试题.md) — 部署阶段的声明式与回滚、`rollout undo`
- [k8s/K8s部署流程.md](k8s/K8s部署流程.md) — 流水线的下游：集群搭建、基础组件、应用清单与上线检查单
- [容器与编排选型.md](容器与编排选型.md) — CI/CD 四方案对比与 GitOps 的取舍
- [../版本控制/Git命令与使用场景.md](../版本控制/Git命令与使用场景.md) — 流水线里用到的 git 命令
> 反向引用（本篇被下列文档引到）：[拓扑排序.md](../../02-计算机基础/算法/图论算法/拓扑排序.md)
