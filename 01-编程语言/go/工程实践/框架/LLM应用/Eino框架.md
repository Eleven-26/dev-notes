# Eino 框架

> 字节开源的大模型应用框架 Eino：定位与核心抽象、组件构成、编排方式与调试工具，以及它在 Go 大模型应用开发中所处的位置。
>
> 内容整理自大厂 Go 后端面试真题，参考资料与原始素材见 [素材清单](../../../../../素材清单.md)。

---

## 一、延伸话题：字节开源的 Eino 大模型应用框架

**本节要点**：**这不是必须会的题**，但如果你做过后端 + AI 相关项目，
它就是很好的亮点。**了解它"由哪些组件构成"就够用**——不需要背 API。

### 1.1 它是什么

> **字节开源的大模型应用开发框架**，用于**基于大模型做业务开发**——
> 比如**聊天助手、智能体（Agent）开发**。
>
> （字节自家豆包的部分能力就是用 Eino 开发的。）

**支持多模型扩展插件**：OpenAI、Ollama、DeepSeek 等开源/闭源大模型都有对应扩展。
下文以 **Ollama** 为例。

### 1.2 与大模型交互的三个步骤（最基本的使用模型）

```text
① 创建模板（Template）+ 创建消息（Message）
② 创建大语言模型对象（Model）
③ 输出结果（生成）
```

#### 第 ① 步：模板 + 消息

**模板包含三块内容**：

| 内容 | 作用 |
|---|---|
| **系统消息模板** | 告诉大模型**扮演什么角色、用什么风格**回答 |
| **历史对话记录** | 用于**上下文联想**（把前面几轮对话传进去） |
| **用户消息** | 用户本次的问题（**可以用占位符**，运行时替换） |

> **一个关键认知**：
> **你每次看起来只输入了一个问题，但后台实际发过去的是三部分**——
> 系统提示词 + 历史对话 + 当前问题。
> **这解释了为什么"同样的提问，换个系统提示词效果完全不同"。**

#### 第 ② 步：创建模型对象

找到对应扩展（Ollama / OpenAI / …），初始化 `model` 实例。

#### 第 ③ 步：输出结果（两种方式，重点区别）

| 方式 | 做法 | 特点 |
|---|---|---|
| **一次性输出** | 等**全部内容推理完**，一次性打印/返回 | **前期耗时长**——因为推理是**一个字一个字（或一段一段）**产生的，<br>要输出一两千字符就得等完整轮推理 |
| **流式输出** ⭐ | 推理出三个字、五个字就**立刻输出** | **用户体验好**——因为人看结果也是**从前往后看**（像读书），<br>**输出过程中就能开始阅读** |

> **工程启示**：**面向用户的生成类功能一定要做流式输出**（SSE / WebSocket），
> 否则用户会盯着空白屏幕等十几秒。

### 1.3 Eino 提供的组件（知道"有哪些能力"即可）

| 组件 | 作用 |
|---|---|
| **Document Loader** | **加载文档**（RAG 场景：把知识库文档读进来） |
| **文档转化** | **解析文档内容** |
| **Embedding** | 把**文本转成向量表示**——RAG 中要与向量库做**相似度匹配**，<br>匹配到的结果作为**上下文**喂给模型 |
| **文档处理** | 对文档做**分割、过滤、合并** |
| **Lambda** | **允许在工作流中嵌入自定义函数**（灵活扩展） |
| **Index** | **存储和索引文档**（写入后台系统） |
| **ChatModel** | **与大语言模型交互**（上面用的那个） |
| **Template** | **定义消息格式** + 用占位符替换用户问题 |
| **ToolsNode** | **扩展模型能力**——例如让 AI 生成流程图，就需要它去**调用外部工具** |
| **Retriever** | **检索组件**：根据用户查询做向量匹配，从向量库 / Redis 取回文本 |

### 1.4 编排（Orchestration）

**为什么要编排**：

> 大模型和各种组件提供的都是**原子能力**——
> 文档加载、解析、向量转化、对话……每一项都是独立的原子能力。
>
> 但做**智能体**时，往往需要**多次对话、组合多个原子能力**。
> 例如"图书馆借还系统"：需要依次问清**借几天、谁借、借什么书**，
> 再调用相应能力完成操作。
>
> **这就需要"编排"——把多个原子能力串联/组合起来，满足具体业务。**

**Eino 支持**：编排 + 工作流（提供 **ReactAgent**、**HostMultiAgent** 两种智能体范式）。

### 1.5 开发工具（这一点对 Go 开发者很友好）

| 工具 | 作用 |
|---|---|
| **可视化编程插件** | 在 **GoLand / VSCode** 中安装插件，**拖控件**的方式做编排 |
| **可视化调试插件** | 调试时可以对编排产物（**Graph 和 Channel**）**可视化调试** |

> **为什么值得注意**：这两个工具**直接集成在 IDE 里**，
> 对 Go 开发者来说上手成本很低——这也是 Eino 相比其他 Python 生态框架的差异点。

### 1.6 要讲这个框架时（建议的说法）

```text
① 定位：字节开源的大模型应用开发框架，用于 Agent / 聊天助手开发
② 核心抽象：Template + Message → ChatModel → 流式生成
③ 关键组件：Document Loader / Embedding / Retriever / ToolsNode（构成 RAG 链路）
④ 加分观点：
   - 流式输出是用户体验的刚需（生成类功能必做）
   - RAG 的本质是"检索 + 拼上下文"，检索质量决定回答质量
   - 面试若问"你怎么看 Go 做 AI 应用"→ 说清 Go 强在工程化与服务化
     （高并发网关、Agent 编排服务），弱在模型训练生态
```

> **⚠️ 提醒**：**不要为了显得懂而去硬聊你没用过的框架**。
> 如果你确实没做过，就说"了解过它的设计思路"，**把重点放回你熟悉的工程能力上**。

---

---

### 1.7 版本与模块结构（实测取证）

容器 `golang:1.26-alpine` 里实测拉依赖：

```text
go: added github.com/cloudwego/eino v0.9.21

require github.com/cloudwego/eino v0.9.21
```

⭐⭐ **一条很容易撞到的坑**：**Eino 的子包是各自独立的 module**，
只 `go get` 主模块**不能**直接用 `schema` / `compose` / `components/*`：

```text
missing go.sum entry for module providing package github.com/nikolalohinski/gonja
  (imported by github.com/cloudwego/eino/schema); to add:
	go get github.com/cloudwego/eino/schema@v0.9.21
missing go.sum entry for module providing package github.com/wk8/go-ordered-map/v2
  (imported by github.com/cloudwego/eino/schema); to add:
	go get github.com/cloudwego/eino/schema@v0.9.21
```

正确姿势是**按用到的子包逐个拉、且版本要对齐**：

```bash
go get github.com/cloudwego/eino@v0.9.21
go get github.com/cloudwego/eino/schema@v0.9.21
go get github.com/cloudwego/eino/compose@v0.9.21
go get github.com/cloudwego/eino/components/model@v0.9.21
```

⚠️ **版本必须显式写一致** —— 子包各自独立发版，只写 `@latest` 会拉到不同的版本号组合。

**依赖面**（实测 `go mod tidy` 之后）：主模块 + **29 个间接依赖**，其中值得一提的：

| 依赖 | 用途 |
|---|---|
| `nikolalohinski/gonja` | **Jinja 风格模板引擎** —— 这就是 Template 组件渲染占位符的底座 |
| `wk8/go-ordered-map/v2` | 有序 map（工具参数的顺序有语义） |
| `eino-contrib/jsonschema` | 工具（Tool）的参数 schema |
| `bytedance/sonic` | 字节自家的 JSON 库 |

⭐ 判据：**引入 Eino 等于引入一套不小的依赖树**，如果项目只是"调一次 HTTP 接口问大模型"，
直接写 `net/http` 反而更轻 —— Eino 的价值在**多组件编排**，不在单次调用。

### 1.8 核心抽象的真实签名（`go doc` 取证，v0.9.21）

⚠️ 这一节的字段与签名**逐字取自 `go doc`**，不是凭记忆写的 ——
LLM 框架这类快速演进的库，凭记忆写 API 几乎必错。

**① `schema.Message`**（`go doc github.com/cloudwego/eino/schema Message`）：

```go
type Message struct {
	Role    RoleType   `json:"role"`    // "system" / "user" / "assistant" / "tool"
	Content string     `json:"content"` // 用户文本输入 / 模型文本输出

	// MultiContent 已废弃，改用下面两个
	UserInputMultiContent  []MessageInputPart  // 多模态输入（图 / 音频…）
	AssistantGenMultiContent []MessageOutputPart // 多模态输出

	Name string // 可选：多角色场景

	ToolCalls  []ToolCall // 仅 assistant：模型要求调用哪些工具
	ToolCallID string     // 仅 tool：对应哪一次调用
	ToolName   string     // 仅 tool

	ResponseMeta *ResponseMeta // token 用量、finish_reason 等

	ReasoningContent string        // 思考过程（带推理能力的模型会返回）
	Extra            map[string]any
}
```

⭐ 三条从字段里读出来的东西：

1. **消息是一条结构体、角色只是一个字符串字段** —— 所谓"系统提示 + 历史 + 当前问题"，
   落到这里就是**一个 `[]*schema.Message` 切片**，顺序即语义；
2. **多模态是"内容部分"的扩展**（`UserInputMultiContent`），不是另一种消息类型；
3. **`ToolCalls` / `ToolCallID` 是工具调用闭环的关键字段** —— 模型返回 `ToolCalls`，
   你执行工具，再把结果以 `Role=tool` + `ToolCallID` 发回去。

**② `model.BaseChatModel`**：

```go
type BaseChatModel = BaseModel[*schema.Message]
```

⭐ **它是 `BaseModel` 针对 `*schema.Message` 的类型别名**（向后兼容设计），两种交互模式：

| 模式 | 行为 |
|---|---|
| `Generate` | **阻塞**直到模型返回完整响应 |
| `Stream` | 返回 `schema.StreamReader`，**逐块产出**消息片段 |

输入统一是 `[]*schema.Message`（一段对话）。

⚠️ 注意真正的接口是 `ChatModel = BaseChatModel + BindTools`：

```go
type ChatModel interface {
	BaseChatModel
	BindTools(tools []*schema.ToolInfo) error
}
```

**③ `compose.Chain`**：

```go
type Chain[I, O any] struct { /* 未导出字段 */ }
```

⭐⭐ 这条**印证了"强类型 + 编译期校验"的说法** —— `Chain[I, O any]` 是**泛型**，
输入输出类型被写进类型参数里，拼错在**编译期**就报错。
官方注释还说明了两点：

- 节点可以是**并行 / 分支 / 顺序**三种形态；
- **builder 模式，使用前必须 `Compile()`**。

### 1.9 ⚠️ 本篇的实测边界

按本仓的诚实口径，把"哪些跑过、哪些没跑"写清楚：

- ✅ **已实测**：依赖可拉取（`v0.9.21`）、子包必须单独 `go get`、依赖树规模、
  `schema.Message` / `BaseChatModel` / `Chain[I,O]` 的**真实签名**；
- ⚠️ **未实测**：**真实模型调用**（需要 OpenAI / 豆包 / Ollama 的 endpoint 与凭据）、
  **完整编排的运行**（Chain/Graph 的 `Compile` + `Invoke`）、**端到端流式输出**。
  上面 1.1~1.6 关于"三步走""组件清单""两种智能体范式"的叙述属于**官方口径与文档阅读**，
  不是本机跑出来的读数。

⭐ 判据：引用本篇内容时，**签名与版本可以放心引用**（取证过）；
**行为与性能相关的说法要自己跑一遍再下结论**。

---

## 延伸追问

- **Eino 和 LangChain 有什么区别？** → Eino 是 Go 生态、**强类型 + 编译期校验**组件图（Graph / Chain 都是泛型拼装），LangChain 是 Python 的动态链式；Eino 更贴 Go 的工程习惯，代价是灵活度不如动态语言。
- **为什么大模型应用要用「图」而不是顺序链？** → 真实 Agent 有分支、循环、并发子任务与人工介入，顺序链表达不了；Graph 把节点与边显式化，才能做流式输出、中断与恢复。
- **Go 写大模型应用有优势吗？** → 优势在**服务侧**：高并发网关、流式 SSE/WebSocket、常驻内存占用低、与既有 Go 微服务体系无缝；劣势是生态（向量库、工具链）不如 Python 丰富。
- **流式输出怎么实现？** → 底层是 SSE / chunked 传输，服务端逐块下发；⚠️ 客户端断连时的取消传播要做对，否则会持续为没人看的流付费（见 [context.md](../../context.md)）。
- **生产上最容易踩什么？** → 模型/提示词版本变更导致输出漂移（要评测集 + 灰度）、token 成本失控（要限流与预算）、以及超时链路——LLM 调用天然慢，**必须为它单独设超时**，不能沿用普通 RPC 的阈值。

## 关联

- [Kratos框架.md](../微服务/Kratos框架.md) — 同属 Go 应用框架，定位与抽象层次不同
- [依赖注入.md](../../依赖注入.md) — 大模型应用的组件装配同样是 DI 问题
- [服务发现与负载均衡.md](../../../../../04-架构与系统/分布式/服务治理/服务发现与负载均衡.md) — 推理服务上线后的注册与路由
