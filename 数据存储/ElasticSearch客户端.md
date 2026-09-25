# Elasticsearch 客户端使用（Go / Java）

> Go 与 Java 官方客户端的接入方式、版本对应关系，以及两套调用形态的差异。
>
> 内容整理自个人学习笔记；参考《Elasticsearch 数据搜索与分析实战》（王深湛）。
> 查询 DSL 与 mapping 见 [ElasticSearch应用与DSL.md](ElasticSearch应用与DSL.md)；原理见 [ElasticSearch.md](ElasticSearch.md)。

## 使用方法

客户端与 ES 版本必须匹配，见下表。
### 版本对应关系

| ES 版本 | Java 客户端 | Go 客户端 | 关键说明 |
| --- | --- | --- | --- |
| 5.x ~ 6.x | `TransportClient`（已淘汰） | `olivere/elastic/v6` | 旧版 |
| 7.0 ~ 7.14 | `RestHighLevelClient` | `olivere/elastic/v7` | |
| 7.15 ~ 7.17 | `RestHighLevelClient`（7.15 起**弃用**） | `go-elasticsearch/v7` | 7.16 起官方推荐新客户端 |
| 8.x | `co.elastic.clients:elasticsearch-java` 8.x | `go-elasticsearch/v8` | ⚠️ 8.x 默认开启 HTTPS + 认证 |

⚠️ 匹配原则：客户端主版本 = ES 服务端主版本。跨主版本一般能通，但存在 API 不兼容与弃用告警。
### Go 客户端

安装官方客户端，⭐ 社区客户端 `github.com/olivere/elastic` 已停止维护，新项目请直接使用官方客户端：
```bash
go get github.com/elastic/go-elasticsearch/v8@latest
```
#### 建立客户端
```go
// 依赖: github.com/elastic/go-elasticsearch/v8
// 另需: crypto/tls、net/http、encoding/json、bytes、strings、time、fmt、log
func newClient() *elasticsearch.Client {
	cfg := elasticsearch.Config{
		Addresses: []string{"https://localhost:9200"},
		Username:  "elastic",
		Password:  "changeme",
		Transport: &http.Transport{
			// ⚠️ 仅本地自签证书调试使用，生产环境应配置正确的 CA
			TLSClientConfig: &tls.Config{InsecureSkipVerify: true},
		},
	}
	es, err := elasticsearch.NewClient(cfg)
	if err != nil {
		log.Fatalf("创建客户端失败: %s", err)
	}
	return es
}
```
#### 创建索引（含 mapping）
```go
res, err := es.Indices.Create("my_index",
	es.Indices.Create.WithBody(strings.NewReader(`{
		"settings": { "number_of_shards": 5, "number_of_replicas": 1 },
		"mappings": { "properties": {
			"title":     { "type": "text" },
			"userid":    { "type": "keyword" },
			"visittime": { "type": "date", "format": "yyyy-MM-dd HH:mm:ss||epoch_millis" }
		} } }`)),
)
if err != nil {
	log.Fatalf("创建索引失败: %s", err)
}
defer res.Body.Close()
```
#### 单条写入 / 查询 / 更新 / 删除
```go
// 写入
doc := map[string]any{
	"title":     "Go 与 Elasticsearch",
	"userid":    "u001",
	"visittime": time.Now().Format("2006-01-02 15:04:05"),
}
body, _ := json.Marshal(doc)
res, err := es.Index("my_index", bytes.NewReader(body),
	es.Index.WithDocumentID("1"),
	es.Index.WithRefresh("true"), // 立即刷新便于测试；生产环境批量写不要加
)
defer res.Body.Close()

res, err = es.Get("my_index", "1") // 按 ID 查询
res, err = es.Update("my_index", "1", // 部分更新
	strings.NewReader(`{"doc": {"title": "更新后的标题"}}`))
res, err = es.Delete("my_index", "1") // 删除
```
#### 批量写入 Bulk

⭐ 生产环境必须使用 Bulk。请求体是 NDJSON：**每行一个 JSON，行尾必须换行**，由「动作行 + 文档行」成对组成，最后一行也要有换行。
```go
var buf bytes.Buffer
enc := json.NewEncoder(&buf)
enc.SetEscapeHTML(false) // 避免 URL/HTML 字符被转义

write := func(v any) {
	_ = enc.Encode(v) // Encode 自动补行尾换行符，符合 NDJSON 要求
}

write(map[string]any{"index": map[string]any{"_index": "my_index", "_id": "1"}})
write(map[string]any{"title": "文档一", "userid": "u001"})
write(map[string]any{"index": map[string]any{"_index": "my_index", "_id": "2"}})
write(map[string]any{"title": "文档二", "userid": "u002"})

res, err := es.Bulk(bytes.NewReader(buf.Bytes()))
defer res.Body.Close()
// ⚠️ 即使 HTTP 200，也要逐条检查 items[*].index.status / error
```
#### 查询（bool + term/match/range，含高亮与分页）
```go
query := map[string]any{
	"from": 0, "size": 10,
	"query": map[string]any{"bool": map[string]any{
		"must": []any{map[string]any{"match": map[string]any{"title": "elasticsearch"}}},
		"filter": []any{
			map[string]any{"term": map[string]any{"userid": "u001"}},
			map[string]any{"range": map[string]any{
				"visittime": map[string]any{"gte": "2024-01-01 00:00:00"}}},
		},
	}},
	"highlight": map[string]any{"fields": map[string]any{"title": map[string]any{}}},
}
body, _ := json.Marshal(query)

res, err := es.Search(es.Search.WithIndex("my_index"),
	es.Search.WithBody(bytes.NewReader(body)))
defer res.Body.Close()

var r map[string]any
_ = json.NewDecoder(res.Body).Decode(&r)
for _, h := range r["hits"].(map[string]any)["hits"].([]any) {
	m := h.(map[string]any)
	fmt.Println(m["_id"], m["_source"], m["highlight"])
}
```
### Java 客户端

8.x 使用官方 Java API Client `co.elastic.clients:elasticsearch-java`。Maven 依赖：
```xml
<dependency>
  <groupId>co.elastic.clients</groupId>
  <artifactId>elasticsearch-java</artifactId>
  <version>8.13.4</version>
</dependency>
<!-- 客户端依赖 HTTP 层与 JSON 库，需显式引入 -->
<dependency>
  <groupId>org.apache.httpcomponents.client5</groupId>
  <artifactId>httpclient5</artifactId>
  <version>5.3.1</version>
</dependency>
<dependency>
  <groupId>com.fasterxml.jackson.core</groupId>
  <artifactId>jackson-databind</artifactId>
  <version>2.17.0</version>
</dependency>
```
⚠️ `RestHighLevelClient` 自 7.15 起被标记为**弃用**，7.16+ 官方推荐迁移到 `elasticsearch-java`。新项目不要再写 `RestHighLevelClient`。
#### 建立 ElasticsearchClient
```java
// 依赖: co.elastic.clients:elasticsearch-java + org.apache.httpcomponents.client5:httpclient5
// 需 import: ElasticsearchClient、JacksonJsonpMapper、RestClientTransport、RestClient 等
BasicCredentialsProvider creds = new BasicCredentialsProvider();
creds.setCredentials(AuthScope.ANY, new UsernamePasswordCredentials("elastic", "changeme"));

RestClient restClient = RestClient.builder(new HttpHost("localhost", 9200, "https"))
        .setHttpClientConfigCallback(hc -> hc.setDefaultCredentialsProvider(creds))
        .build();

// ⚠️ 8.x 默认开启 TLS 与认证，自签证书需额外配置 SSLContext；生产环境务必启用 HTTPS
ElasticsearchTransport transport = new RestClientTransport(restClient, new JacksonJsonpMapper());
ElasticsearchClient client = new ElasticsearchClient(transport);
```
#### 索引创建 / 文档 CRUD
```java
// 创建索引（含 mapping）
client.indices().create(c -> c
        .index("my_index")
        .settings(s -> s.numberOfShards("5").numberOfReplicas("1"))
        .mappings(m -> m
                .properties("title", p -> p.text(t -> t))
                .properties("userid", p -> p.keyword(k -> k))
                .properties("visittime", p -> p.date(d ->
                        d.format("yyyy-MM-dd HH:mm:ss||epoch_millis")))));

Map<String, Object> doc = Map.of(
        "title", "Java 与 Elasticsearch",
        "userid", "u001",
        "visittime", "2024-01-01 10:00:00");

client.index(i -> i.index("my_index").id("1").document(doc)); // 写入

GetResponse<Map> got = client.get(g -> g.index("my_index").id("1"), Map.class); // 查询
Map<?, ?> source = got.source();

client.update(u -> u.index("my_index").id("1") // 部分更新
        .doc(Map.of("title", "更新后的标题")), Map.class);

client.delete(d -> d.index("my_index").id("1")); // 删除
```
#### bulk 批量写入
```java
List<BulkOperation> ops = new ArrayList<>();
ops.add(BulkOperation.of(b -> b.index(i -> i.id("1").document(doc))));
ops.add(BulkOperation.of(b -> b.index(i -> i.id("2").document(doc))));

BulkResponse bulk = client.bulk(b -> b.index("my_index").operations(ops));
if (bulk.errors()) { // ⚠️ 需逐条检查错误
    bulk.items().stream()
            .filter(it -> it.error() != null)
            .forEach(it -> System.err.println("写入失败: " + it.error().reason()));
}
```
#### search 查询
```java
// import co.elastic.clients.json.JsonData;
SearchResponse<Map> resp = client.search(s -> s
        .index("my_index")
        .from(0)
        .size(10)
        .query(q -> q.bool(b -> b
                .must(m -> m.match(mt -> mt.field("title").query("elasticsearch")))
                .filter(f -> f.term(t -> t.field("userid").value("u001")))
                .filter(f -> f.range(r -> r.field("visittime")
                        .gte(JsonData.of("2024-01-01 00:00:00"))))))
        .highlight(h -> h.fields("title", hf -> hf)),
        Map.class);

long total = resp.hits().total() == null ? 0 : resp.hits().total().value();
resp.hits().hits().forEach(h -> {
    System.out.println(h.id() + " -> " + h.source());
    System.out.println("高亮: " + h.highlight());
});
```
⭐ 小结：Go 与 Java 客户端的调用形态不同——Go 偏 REST + JSON 拼装，Java 偏 builder 强类型——但底层是同一套 REST API，**查询 DSL 与 mapping 完全通用**。

---

---

## 面试官会追问什么

### 一、Go 和 Java 客户端的 DSL 通用吗？
**通用**。DSL 与 mapping 是**服务端协议层**的东西，客户端只负责"把请求发出去、把响应解析回来"。
差异在**拼装方式**：Java 的 `QueryBuilders` 是强类型 builder，字段名写错编译期就报；Go 侧多是 `map[string]any` / 带 tag 的结构体 + `json.Marshal`，
**写错字段名只有运行时（或服务端返回 400）才发现** —— 所以 Go 侧更依赖集成测试来兜底。

### 二、为什么客户端版本要和集群版本对齐？
客户端与集群之间是 **REST API 契约**：新版客户端可能带上旧集群不认识的参数，旧客户端可能读不懂新版本新增的响应字段。
官方对 7.x / 8.x 之间给过明确的兼容矩阵，**跨大版本升级前必须先查矩阵**，不要凭"应该兼容"上手。
（这也是为什么第三节开头先列「版本对应关系」——它决定了你 index 里能用哪些字段类型。）

### 三、每个请求 new 一个客户端行不行？
**不行**。客户端内部持有**连接池**（HTTP 长连接复用，Java 侧还有嗅探与节点健康检查），
每请求 new 一个 = 每请求重建一次连接池：TCP 握手、连接数暴涨、节点列表重复拉取，压测时先崩的往往是 ES 的接入层而不是查询本身。
标准做法：**进程内单例**，生命周期交给容器（见 [../go/工程实践/依赖注入.md](../go/工程实践/依赖注入.md)）。

### 四、客户端的超时该怎么设？
要**分开设连接超时与请求超时**，请求超时还要大于服务端 slowlog 的阈值（否则日志里看不到慢查询就已经被客户端掐断了）。
⚠️ 最关键的一条：**不要用无限等待**。ES 在段合并或长 GC 时会长时间无响应，客户端不给超时，业务线程池会被一起拖垮 ——
超时后配合重试，但要注意**写请求不能盲目重试**（可能是"已写入但响应丢了"），需要幂等键或版本号保护。

## 关联

- [ElasticSearch.md](ElasticSearch.md) — 客户端背后的倒排索引与段机制
- [ElasticSearch应用与DSL.md](ElasticSearch应用与DSL.md) — 客户端要发的查询与 mapping
- [../中间件/消息队列/Kafka.md](../中间件/消息队列/Kafka.md) — 消费 CDC 后写入 ES 的常见链路
- [../go/工程实践/依赖注入.md](../go/工程实践/依赖注入.md) — 客户端单例该怎么交给容器管
