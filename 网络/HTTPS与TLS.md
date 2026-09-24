# HTTPS 与 TLS

> 一句话说明：本篇讲清 **TLS 握手怎么走**（1.2 全流程 / 1.3 变化 / 会话复用）、证书在握手现场怎么用、如何抓包排障，以及 **Go 与 Java 的落地**（含 mTLS 双向认证）。
>
> 内容整理自个人学习笔记。加密算法与数字证书的基础概念见 [数字证书与PKI.md](../安全/数字证书与PKI.md)、[加密算法.md](../安全/加密算法.md)。

## 一、一句话定位：HTTPS 解决什么
HTTP 全程明文，链路上任何一台设备都能看到并改写内容。TLS 解决的是**三大风险**：

| 风险 | 含义 | TLS 的对策 |
|---|---|---|
| **窃听**（机密性） | 中间人读到明文 | 握手后走**对称加密 + AEAD** |
| **篡改**（完整性） | 改了包而对方发现不了 | AEAD 认证标签 + 握手 transcript 摘要 |
| **冒充**（身份认证） | 连到了假服务器 | **X.509 证书 + CA 验签 + 域名校验** |

⚠️ **高频澄清：HTTPS 不是新协议**。分层是「应用层 HTTP / 安全层 TLS / 传输层 TCP / 网络层 IP」，TLS 是夹在中间的一层：**HTTPS = HTTP over TLS**，HTTP 报文原封不动塞进 TLS 记录层再交给 TCP（同理还有 gRPC over TLS、数据库连接加密）。

握手的前提是 **TCP 三次握手已完成**（见 [TCP三次握手.md](tcp/TCP三次握手.md)）——TLS 的 RTT 是**叠加在 TCP 之上**的额外开销，这也是后面为什么要压缩握手往返。另外，HTTPS 默认**只认证服务器**（单向认证），要认证客户端必须上 **mTLS**（第八、九节）。

## 二、TLS 与 SSL 的关系
SSL 由 Netscape 发明，**SSL 3.0（1996）是最后一版**，之后改名交给 IETF 标准化，这条线就是 TLS：

| 版本 | 状态 | 说明 |
|---|---|---|
| SSL 2.0 / 3.0 | ⚠️ 已全面废弃 | POODLE 等攻击，主流实现一律禁用 |
| TLS 1.0（1999）/ 1.1（2006） | ⚠️ 已废弃 | **2020 年起 Chrome/Firefox/Safari/Edge 全部禁用**，PCI-DSS 也不再认可 |
| TLS 1.2（2008） | 兼容底线 | 支持 AEAD，但 2-RTT、套件多、历史包袱重 |
| TLS 1.3（2018, RFC 8446） | ⭐ 推荐 | 1-RTT、0-RTT、**强制前向安全**、套件大精简 |

口语里的「SSL 证书」「SSL 握手」其实几乎都是 TLS，只是名字沿用下来了。

## 三、TLS 1.2 完整握手 ⭐
### 3.1 前提与目标
- 前提：**TCP 三次握手已完成**，双方在 TCP 之上开始 TLS 握手。目标：① 协商一套密码参数；② **安全地**协商出一个只有双方知道的对称会话密钥。
- 手段：**用非对称（证书 + 密钥交换）保护密钥协商，之后的数据全部走对称加密**。

### 3.2 两次往返的完整流程
```
Client                                              Server
  |--- ① ClientHello ----------------------------------->|   RTT 1
  |<-- ② ServerHello ------------------------------------|
  |<-- ③ Certificate ------------------------------------|
  |<-- ④ ServerKeyExchange（仅 ECDHE/DHE）---------------|
  |<-- ⑤ CertificateRequest（仅 mTLS）-------------------|
  |<-- ⑥ ServerHelloDone -------------------------------|
  |--- ⑦ ClientKeyExchange ----------------------------->|   RTT 2
  |--- ⑧ Certificate + CertificateVerify（仅 mTLS）----->|
  |--- ⑨ ChangeCipherSpec ----------------------------->|
  |--- ⑩ Finished（已加密）----------------------------->|
  |<-- ⑪ ChangeCipherSpec -------------------------------|
  |<-- ⑫ Finished（已加密）------------------------------|
  |<========= ⑬ Application Data（对称加密）===========>|
```

1. **ClientHello**：支持的 TLS 版本、**Client Random**（32 字节随机数）、**密码套件列表**、压缩方法、扩展（**SNI** 域名、ALPN 如 h2/http1.1、supported_groups 曲线列表、signature_algorithms、session_ticket 等）。⚠️ 明文发送，**SNI 里的域名是可见的**。
2. **ServerHello**：选定版本与**一个**密码套件，回 **Server Random** 与 session_id；若复用会话则走第五节。
3. **Certificate**：服务端发**证书链**（自身证书 + 中间 CA 证书）。
4. **ServerKeyExchange**：**仅 ECDHE/DHE 时出现**，含 DH 参数与**服务端临时公钥**，并用**证书私钥签名**证明来源；RSA 密钥交换方式下没有这条。
5. **CertificateRequest**（可选，仅 mTLS）：要求客户端也提供证书。**ServerHelloDone** 表示「我这边的握手消息发完了」。
6. **ClientKeyExchange**：核心步骤，见 3.3。mTLS 下客户端还要发 **Certificate + CertificateVerify**——用自己的私钥对**前面所有握手 transcript 签名**，这才是真「证明你持有私钥」。
7. **ChangeCipherSpec**：**不是握手消息**，是独立协议类型的一句「我从下一条开始加密了」，本身不参与密钥协商。
8. **Finished**：第一条被加密的消息，内容是**此前所有握手消息的 PRF 摘要**，同时校验「双方算出的密钥一致」与「握手未被篡改」。
9. 服务端同样回 ChangeCipherSpec + Finished；之后所有应用数据走**对称加密 + AEAD**，每条记录带显式 nonce，乱序 / 重放 / 丢记录都会被 AEAD 发现。（1.2 时代有过 False Start 这类「发完 Finished 就先发数据」的小优化，未被广泛采纳，真正的解法是 TLS 1.3。）

### 3.3 密钥交换：RSA vs ECDHE ⭐
`ClientKeyExchange` 里发什么，取决于密钥交换算法：

| 维度 | **RSA 密钥交换** | **ECDHE（推荐）** |
|---|---|---|
| 客户端做什么 | 生成 48 字节 Pre-Master Secret，用**证书里的公钥加密**发送 | 生成**临时**密钥对，把自己的**临时公钥**明文发送 |
| 服务端做什么 | 用私钥解密得到 Pre-Master Secret | 用临时私钥算 `ECDH(client_pub, server_priv)` 得到共享秘密 |
| 私钥泄露的后果 | ⚠️ **致命**：历史流量可被回溯解密 | 临时私钥不落盘、用完即弃，历史会话依旧安全 |
| 前向安全 PFS | ❌ 无 | ⭐ ✅ 有 |
| TLS 1.3 | ❌ 已删除 | ✅ 唯一方式 |

> ⭐ **前向安全（PFS）**：一次长期私钥泄露不应危及**以往**会话。ECDHE 的 E 就是 **Ephemeral（临时）**——每次握手新生成密钥对，长期私钥**只用来签名、不用来加密流量**。这也是现在几乎所有 1.2 站点都用 `ECDHE_xxx` 套件的原因。

### 3.4 证书是怎么验证的
证书概念见 [数字证书与PKI.md](../安全/数字证书与PKI.md)，这里只讲握手现场客户端做的事：

1. **逐级验签**：用上级 CA 公钥解开本级证书签名并比对摘要，一路向上到**本机信任库里的根证书**；
2. **有效期**：当前时间须在 `notBefore` ~ `notAfter` 之间；
3. **域名匹配**：⚠️ **只看 `SAN`（SubjectAltName）扩展**，`CN` 字段早已被主流实现废弃；
4. **吊销与用途**：查吊销状态（CRL 列表臃肿滞后，或 OCSP 在线查询，现代实践是服务端做 **OCSP Stapling**）；EKU 须含 `serverAuth`，Basic Constraints 决定它能否当中级 CA。

任一步失败即中断握手：浏览器报「您的连接不是私密连接」，Go 客户端报 `x509: certificate signed by unknown authority` 之类。

### 3.5 主密钥是怎么推导出来的
```text
Pre-Master Secret (PMS)：RSA = 客户端生成的 48 字节随机数；ECDHE = ECDH(client_priv, server_pub) 共享秘密
      │ PRF(PMS, "master secret", ClientRandom + ServerRandom) → 48 字节
      ▼
Master Secret
      │ PRF(MasterSecret, "key expansion", ServerRandom + ClientRandom) → 密钥块
      ▼
Session Keys: client_write_key / server_write_key
              client_write_MAC_key / server_write_MAC_key（非 AEAD 套件才用）
              client_write_IV / server_write_IV（AEAD 的 nonce 盐）
```

- **收发方向各用一把密钥**，避免单密钥在双向流量下出现 nonce / IV 复用问题；
- **两个随机数都参与推导**，任一方随机数变了密钥就不同，这是防重放的关键设计；
- 现代套件都是 **AEAD**（AES-GCM / ChaCha20-Poly1305），加密即认证，不再需要额外 MAC 密钥；
- ⚠️ 链式设计的效果：**任何握手消息被篡改，双方算出的 Finished 摘要就对不上，握手立刻失败**。

## 四、TLS 1.3 的变化 ⭐
### 4.1 1-RTT 握手：客户端先「赌」密钥交换参数
省掉一个 RTT 的核心手段是 **客户端在 ClientHello 里直接带上 `key_share`（自己的临时公钥）**，把原本第二轮才做的事提前：

```
Client                                                Server
  |--- ① ClientHello                                       |
  |       + supported_versions: 1.3                        |
  |       + key_share: X25519 公钥  ← 提前交东西           | ← 往返开始
  |<-- ② ServerHello + key_share（服务端公钥）--------------|
  |       ★ 此刻双方已算出 handshake traffic secrets         |
  |       ★ 之后所有握手消息全部加密                         |
  |<-- ③ [加密] EncryptedExtensions（ALPN 等）--------------|
  |<-- ④ [加密] Certificate（服务器证书也被加密了⭐）--------|
  |<-- ⑤ [加密] CertificateVerify（私钥签名 transcript）-----|
  |<-- ⑥ [加密] Finished ----------------------------------|
  |--- ⑦ [加密] Finished + Application Data -------------->| ← 握手完成
```

> ⭐ 注意 ④：**1.2 里服务器证书是明文广播的**（谁抓包都能看到你在访问哪个域名）；**1.3 里证书也被加密**，只有合法客户端能看到，直接缓解了证书嗅探与流量指纹。
>
> ⚠️ **赌错了怎么办**：服务端不支持客户端 `key_share` 里的曲线时回 **HelloRetryRequest** 要求换参数，握手退化成 2-RTT。所以服务端务必支持 `X25519` 和 `P-256`。

### 4.2 0-RTT（early data）与 ⚠️ 重放攻击
**原理**：客户端凭上次会话留下的 **PSK**，在第一个 flight 就带上 `early_data` 扩展并把应用数据直接发出去——**握手没走完请求已到达**，延迟等同明文 HTTP。

⚠️ **风险：0-RTT 数据不具备唯一性保证，天然可被重放**——攻击者录下这串 early data 重发，服务器会当成新请求处理。

| 安全属性 | TLS 1.3 完整握手 | 0-RTT |
|---|---|---|
| 机密性 / 完整性 | ✅ | ✅ |
| 前向安全 | ✅ | 部分（取决于 PSK 来源） |
| **防重放** | ✅ | ⚠️ **没有，需应用层兜底** |

正确做法：**只放幂等请求**（GET 静态资源），不下单 / 转账 / 登录；服务端用 single-use ticket 或一次性 nonce；应用层加 token 或时间窗；很多反向代理（如 Nginx `ssl_early_data`）默认关闭。

### 4.3 其他重要变化
- **彻底删除**：RSA 密钥交换、静态 DH、RC4 / 3DES / AES-CBC、MD5 / SHA-1 签名、TLS 压缩、重协商；
- **强制前向安全**：密钥交换只剩 DHE / ECDHE；
- **套件大精简 + 交换与认证分离**：套件名不再描述密钥交换且只留 AEAD，密钥交换参数走 `supported_groups` 扩展、签名算法走 `signature_algorithms` 扩展。1.2 是 `TLS_ECDHE_RSA_WITH_AES_128_GCM_SHA256` 一大串，1.3 只剩 `TLS_AES_128_GCM_SHA256`、`TLS_CHACHA20_POLY1305_SHA256` 等五个；
- **密钥推导改用 HKDF**，并拆成 early / handshake / master 多级流量密钥；
- ⚠️ **版本协商走 `supported_versions` 扩展**，记录层版本号固定写兼容用的 legacy 值 `0x0303`，所以**不能靠记录层版本判断是不是 1.3**。

### 4.4 TLS 1.2 vs 1.3 对比表
| 维度 | TLS 1.2 | TLS 1.3 |
|---|---|---|
| 首次握手延迟 | 2-RTT | ⭐ **1-RTT**（复用可 0-RTT） |
| 会话复用机制 | Session ID / Session Ticket | ⭐ PSK + 0-RTT early data |
| RSA 密钥交换 | 支持 | ❌ 删除 |
| 前向安全 | 取决于套件（可选） | ⭐ **强制** |
| 对称算法 | CBC / GCM / 流密码均有 | 仅 AEAD |
| 服务器证书 | 明文发送 | ⭐ **加密发送** |
| 密码套件数量 / 密钥推导 | 上百个 / TLS PRF | 5 个左右 / **HKDF** |

## 五、会话复用
每次完整握手都要做非对称运算（尤其 RSA 私钥解密极耗 CPU），连接量大时开销可观。思路是**协商一次、多次使用**。

### 5.1 Session ID vs Session Ticket
| 维度 | Session ID | Session Ticket |
|---|---|---|
| 谁有状态 | ⭐ **服务端**存会话参数，按 session_id 索引 | 服务端**无状态**：把会话参数**加密后交客户端保管** |
| 客户端怎么做 | 带上上次服务端给的 session_id | 在 `session_ticket` 扩展里带上这张票 |
| 多机 / 负载均衡 | ⚠️ 须命中同一节点，或共享 Redis 缓存 | ⭐ 天然支持横向扩展，任意节点都能解票 |
| 握手开销 | 复用成功 = 1-RTT | 同左 |

Session Ticket 因为无状态、利于横向扩容，是主流 CDN / 负载均衡的默认选择。

### 5.2 TLS 1.3 的 PSK / 0-RTT
1.3 把上面两者统一成 **PSK**：**外部 PSK**（预置共享密钥，IoT / 内部服务）与**恢复 PSK**（由上次握手的 `resumption_master_secret` 推导，即新一代 Session Ticket）。客户端在 ClientHello 带 `pre_shared_key` + `early_data` 扩展，服务端接受后，**Finished 之前的 application data 就是 early data**。

### 5.3 ⚠️ Session Ticket 的密钥轮换
Ticket 是用**服务端持有的 ticket key 加密**后发给客户端的，于是：

- ⚠️ **ticket key 长期不变 ⇒ 前向安全被削弱**：拿到它就解开所有用它加密的票，进而解出留存的流量。Nginx / Apache 默认启动时生成一次 ticket key 并长期沿用；**集群各节点 key 不一致**时还会出现「节点 A 给的票，落到节点 B 解不开 → 悄悄降级成完整握手」，表现为**突发的握手延迟抖动**，很难察觉。

正确做法：集群**共享同一份** ticket key（Nginx `ssl_session_ticket_key`，可配多份平滑轮转）、**定期轮换（建议 ≤ 24h）**；追求更强前向安全可直接 `ssl_session_tickets off`。

## 六、证书：握手视角下的要点
### 6.1 X.509 结构速览
| 字段 | 作用 |
|---|---|
| Version / Serial Number | 通常为 v3；序列号在 CA 内唯一，吊销与 CT 定位都靠它 |
| Issuer / Subject | 签发者 DN / 持有者 DN |
| Validity | `notBefore` / `notAfter`，⭐ **90 天是当前主流上限** |
| Signature Algorithm | 签发算法，如 `sha256WithRSAEncryption`、`ecdsa-with-SHA256` |
| Subject Public Key Info | 持有者公钥 + 算法 |
| **Extensions** | `SAN`（⭐ 域名校验**只认它**）、`Basic Constraints`（是否 CA）、`Key Usage` / `EKU`（serverAuth / clientAuth）、`CRL Distribution Points`、`AIA`（OCSP 地址）、`SCT`（证书透明度） |
| Signature | ⭐ **上级 CA 对本证书内容的签名**，验签就是验这一项 |

### 6.2 信任链、自签与 Let's Encrypt
- **信任链** = leaf（站点证书）→ intermediate（中间 CA）→ root（根 CA，预置在信任库）。服务端必须在 Certificate 消息里发出**除根以外的完整链**。
- **自签名证书**：不在任何信任库里 → 客户端必须显式配置信任（见第八、九节的 `RootCAs` / TrustStore），否则报错；仅用于内网与开发环境。
- **Let's Encrypt + ACME**：**HTTP-01**（放 token 到 `/.well-known/acme-challenge/`，需 80 端口可达）、**DNS-01**（写 `_acme-challenge` TXT 记录，**支持通配符证书**，无公网 80 时首选）、TLS-ALPN-01。**有效期 90 天，必须自动续期**（certbot / acme.sh / cert-manager）。
- **证书固定（Certificate Pinning）**：把服务器的公钥 / 证书哈希硬编码进 App（Android `network-security-config`、iOS Trust Evaluation、OkHttp `CertificatePinner`、Go `VerifyPeerCertificate`），只接受这一个。优点是抗「恶意 / 被攻破的 CA 签发了假证书」；⚠️ 风险是**证书轮换或到期而 App 未更新 ⇒ 大面积不可用**，且用户无法自助修复。现代实践更推荐依赖 **CT（证书透明度）** + 受控 CA 列表，或至少配置**备用 pin**。

### 6.3 ⚠️ 常见故障速查
| 现象 | 常见原因 |
|---|---|
| `unknown authority` / `unable to get local issuer certificate` | ⚠️ **服务端证书链不完整（漏了中间证书）**；或客户端信任库太老（旧 JDK cacerts 缺新根） |
| `hostname mismatch` | 证书缺少对应 **SAN**（CN 已废弃）；或 IP / 泛域名没匹配上 |
| `certificate is not yet valid` | ⚠️ **系统时间不对**（时钟漂移、容器没同步 NTP 最常见） |
| `certificate has expired` | 忘了续期——生产事故 Top 1，请配自动续期 + 到期告警 |
| 拿到的证书不是自己的 | **SNI 未匹配**：同 IP 多域名，客户端没带 SNI 或服务端默认虚拟主机配错 |
| 只在部分网络下握手失败 | 中间透明代理 / 老 WAF / 负载均衡固件不支持 TLS 1.3（见第十节） |

## 七、抓包与排障
### 7.1 Wireshark 看握手
| 显示过滤器 | 看什么 |
|---|---|
| `tls.handshake.type == 1` | ClientHello（版本、套件列表、SNI、ALPN） |
| `tls.handshake.type == 2` | ServerHello（最终选定的套件） |
| `tls.handshake.type == 11` | Certificate（能看到完整链） |
| `tls.handshake.type == 14` | ServerHelloDone（**仅 1.2 有**）；`== 4` 是 NewSessionTicket |
| `tls.handshake.extensions_server_name` | SNI 明文域名 |
| `tls.alert_message` | ⭐ Alert，握手失败的错误在此（如 `40 handshake_failure`、`42 bad_certificate`） |

> ⚠️ 抓 TLS 1.3 时会发现 ServerHello 之后**全是 Application Data 密文**，看不到 Certificate / Finished——没有密钥，这是正常的（见 4.1），不是抓包坏了。

### 7.2 用 SSLKEYLOGFILE 解密 HTTPS 流量 ⭐
浏览器（Chrome / Firefox）以及 curl、Go、OpenSSL 等支持 NSS keylog 的程序，会把每个会话的密钥材料写进环境变量 `SSLKEYLOGFILE` 指向的文件；Wireshark 读该文件即可**解密本机流量**。

```bash
export SSLKEYLOGFILE=/tmp/sslkeys.log       # Linux/macOS，之后启动浏览器或 curl
$env:SSLKEYLOGFILE = 'C:\tmp\sslkeys.log'   # Windows PowerShell
curl https://example.com >/dev/null
```

然后 **Wireshark → Preferences → Protocols → TLS → (Pre)-Master-Secret log filename** 填同一路径，再过滤 `http` 就能看到明文请求。⚠️ 只有**参与握手、能导出密钥的那一端**才能解密，截获别人的流量照样解不开；该文件泄漏等于泄密，用完即删，**不要提交到 Git**。

### 7.3 openssl / curl 常用命令
```bash
# 看握手全过程：证书链、协商出的协议与套件、是否走了会话复用
openssl s_client -connect example.com:443 -servername example.com -showcerts
openssl s_client -connect example.com:443 -tls1_2     # 指定版本探测，能连上即支持
openssl s_client -connect example.com:443 -tls1_3
openssl s_client -connect example.com:443 -cipher ECDHE-RSA-AES128-GCM-SHA256  # 指定套件探测
nmap --script ssl-enum-ciphers -p 443 example.com     # 枚举支持的全部协议与套件
curl -vI https://example.com                          # 看握手细节
curl --tlsv1.3 -o /dev/null -sS -w '%{http_version} %{tls_version}\n' https://example.com
# 只看有效期 / SAN（到期监控的基础命令）
echo | openssl s_client -connect example.com:443 -servername example.com 2>/dev/null \
     | openssl x509 -noout -dates -subject -ext subjectAltName
```
（-servername 一定要带上，否则多域名站点会取到默认虚拟主机的证书，现象就是「域名不匹配」。）
## 八、使用一：Go ⭐
### 8.1 起一个 HTTPS 服务
最简写法是 `http.ListenAndServeTLS(":443", "fullchain.pem", "server.key", nil)`；生产要自己控 TLS 参数就用 `http.Server` + `TLSConfig`：

```go
srv := &http.Server{
	Addr:              ":443", // ReadHeaderTimeout 务必设，防慢速攻击
	Handler:           mux,
	ReadHeaderTimeout: 5 * time.Second,
	TLSConfig: &tls.Config{
		MinVersion: tls.VersionTLS12,
		// CipherSuites 留空即用 Go 的安全默认值（已剔除 CBC/3DES）；显式指定请务必自查
		CipherSuites: []uint16{
			tls.TLS_ECDHE_ECDSA_WITH_AES_128_GCM_SHA256,
			tls.TLS_ECDHE_RSA_WITH_AES_128_GCM_SHA256,
			tls.TLS_ECDHE_RSA_WITH_AES_256_GCM_SHA384,
			tls.TLS_ECDHE_ECDSA_WITH_CHACHA20_POLY1305,
		},
		CurvePreferences: []tls.CurveID{tls.X25519, tls.CurveP256}, // X25519 最快，放前面
	},
}
log.Fatal(srv.ListenAndServeTLS("fullchain.pem", "server.key"))
```

> ⚠️ 证书文件必须是 **fullchain**（服务器证书在前、中间证书在后），否则就是 6.3 里的「链不完整」。Go 1.18 起 `PreferServerCipherSuites` 已失效并标记废弃（服务端始终按自身偏好选择），不要再依赖它。

### 8.2 mTLS 双向认证 ⭐
**服务端**要求并校验客户端证书：

```go
pem, _ := os.ReadFile("ca.crt")                       // 签发客户端证书的 CA
pool := x509.NewCertPool()
pool.AppendCertsFromPEM(pem)
srv := &http.Server{
	Addr:    ":8443",
	Handler: mux,
	TLSConfig: &tls.Config{
		MinVersion: tls.VersionTLS13,
		ClientAuth: tls.RequireAndVerifyClientCert, // ⭐ 必须提供且必须过 CA 校验
		ClientCAs: pool,
		// 可选：链已被 Go 校过，这里再做业务级白名单（CN / SPIFFE ID / 自定义 OID）
		VerifyPeerCertificate: func(raw [][]byte, chains [][]*x509.Certificate) error {
			if len(chains) == 0 || len(chains[0]) == 0 {
				return errors.New("empty verified chain")
			}
			if cn := chains[0][0].Subject.CommonName; cn != "payment-client" {
				return fmt.Errorf("client CN %q not allowed", cn)
			}
			return nil
		},
	},
}
log.Fatal(srv.ListenAndServeTLS("fullchain.pem", "server.key"))
```

**客户端**加载自己的证书发起请求：

```go
cert, err := tls.LoadX509KeyPair("client.crt", "client.key") // err 记得判断
client := &http.Client{
	Timeout: 10 * time.Second,
	Transport: &http.Transport{TLSClientConfig: &tls.Config{
		Certificates: []tls.Certificate{cert},
		MinVersion:   tls.VersionTLS12,
	}},
}
resp, err := client.Post("https://api.internal:8443/pay", "application/json", body)
```

> ⚠️ `ClientAuth` 取值别搞混：`NoClientCert`（默认不要求）、`RequestClientCert`（要但不校验，**几乎没有安全意义**）、`RequireAnyClientCert`（要且必须有，但不校验签发者）、⭐ **`RequireAndVerifyClientCert`（要求并用 ClientCAs 完整校验，生产用这个）**、`VerifyClientCertIfGiven`。
> ⚠️ **`RequireAndVerifyClientCert` 不会查 CRL / OCSP**，要吊销客户端得自己在 `VerifyPeerCertificate` 里比对序列号黑名单，或改用短期证书 + 自动轮换。

### 8.3 客户端自定义 tls.Config（含自签 CA）
```go
caPEM, _ := os.ReadFile("ca.crt")              // 自签 / 内部 CA 根证书
rootCAs, _ := x509.SystemCertPool()            // ⭐ 先取系统信任库，别用空池直接覆盖
if rootCAs == nil {
	rootCAs = x509.NewCertPool()
}
rootCAs.AppendCertsFromPEM(caPEM)
cfg := &tls.Config{
	RootCAs:    rootCAs,
	MinVersion: tls.VersionTLS12,
	ServerName: "api.internal",                // 通过 IP 访问时必须显式指定证书里的 SAN 名
	// ⚠️ 生产禁用：InsecureSkipVerify: true
}
client := &http.Client{Transport: &http.Transport{TLSClientConfig: cfg}}
```

> ⚠️ **`InsecureSkipVerify: true` 同时跳过证书校验与域名校验，等于放任中间人冒充**。自签证书的正确做法是上面的 `RootCAs`，而不是跳过校验——安全扫描工具会直接把前者判为高危。

### 8.4 `tls.Config` 常用字段
| 字段 | 作用 |
|---|---|
| `MinVersion` / `MaxVersion` | 限制协议版本（建议 `MinVersion: tls.VersionTLS12`） |
| `CipherSuites` | 套件白名单，**留空即用 Go 的安全默认值**（推荐留空） |
| `RootCAs` / `ClientCAs` | 客户端 / 服务端使用的信任根池 |
| `Certificates` / `GetCertificate` | 本端密钥对；多域名站点用后者按 SNI 动态返回 |
| `InsecureSkipVerify` | ⚠️ **生产禁用**（会同时跳过证书与域名校验） |

## 九、使用二：Java ⭐
### 9.1 先分清 KeyStore 与 TrustStore ⭐
| 名称 | 装什么 | 谁用 |
|---|---|---|
| **KeyStore** | **自己的**私钥 + 证书链 | 由 `KeyManager` 读取 → **向对端出示证书**（服务端出示服务证书 / mTLS 时客户端出示客户端证书） |
| **TrustStore** | **信任的** CA 根 / 中间证书 | 由 `TrustManager` 读取 → **校验对端证书** |

JSSE 四个核心类：`SSLContext`（配置入口，由它产出 `SSLSocketFactory` / `SSLServerSocketFactory`）、`KeyManagerFactory`（KeyStore → `KeyManager[]`，管本地凭证）、`TrustManagerFactory`（TrustStore → `TrustManager[]`，管信任判断）、`SSLEngine`（Netty 等异步场景使用）。

> JVM 默认 TrustStore 是 `$JAVA_HOME/lib/security/cacerts`（密码 `changeit`）。⚠️ 老 JDK 的 cacerts 缺新根（如 Let's Encrypt 的 ISRG Root X1），会报 `PKIX path building failed`，需升级 JDK 或用 `-Djavax.net.ssl.trustStore` 指定。

### 9.2 初始化 SSLContext
```java
public static SSLContext create(Path keyStore, char[] ksPass,
                                Path trustStore, char[] tsPass) throws Exception {
    KeyStore ks = KeyStore.getInstance("PKCS12");            // 1. 自己的凭证
    try (InputStream in = Files.newInputStream(keyStore)) {
        ks.load(in, ksPass);
    }
    KeyManagerFactory kmf = KeyManagerFactory.getInstance(KeyManagerFactory.getDefaultAlgorithm());
    kmf.init(ks, ksPass);
    KeyStore ts = KeyStore.getInstance("PKCS12");            // 2. 信任的 CA
    try (InputStream in = Files.newInputStream(trustStore)) {
        ts.load(in, tsPass);
    }
    TrustManagerFactory tmf = TrustManagerFactory.getInstance(TrustManagerFactory.getDefaultAlgorithm());
    tmf.init(ts);
    SSLContext ctx = SSLContext.getInstance("TLS");          // 3. 组装，由实现协商最高可用版本
    ctx.init(kmf.getKeyManagers(), tmf.getTrustManagers(), new SecureRandom());
    return ctx;
}
```

### 9.3 客户端怎么用 ⭐
**① JDK 原生 `HttpsURLConnection`**

```java
char[] pw = "changeit".toCharArray();
SSLContext ctx = SslContextFactory.create(Path.of("client.p12"), pw, Path.of("truststore.p12"), pw);
HttpsURLConnection conn = (HttpsURLConnection)
        new URL("https://api.internal:8443/ping").openConnection();
conn.setSSLSocketFactory(ctx.getSocketFactory());   // ⭐ 换成我们自己的
conn.setRequestMethod("GET");
conn.setConnectTimeout(5000);
conn.setReadTimeout(5000);
try (var in = conn.getInputStream()) {
    System.out.println(new String(in.readAllBytes(), StandardCharsets.UTF_8));
}
```

**② OkHttp**（`sslSocketFactory` 两个参数缺一不可）

```java
TrustManagerFactory tmf =
        TrustManagerFactory.getInstance(TrustManagerFactory.getDefaultAlgorithm());
tmf.init((KeyStore) null);                          // 传 null = 用 JDK 默认信任库
X509TrustManager tm = (X509TrustManager) tmf.getTrustManagers()[0];
OkHttpClient client = new OkHttpClient.Builder()
        .sslSocketFactory(ctx.getSocketFactory(), tm)
        .build();
```

**③ Apache HttpClient 5**

```java
CloseableHttpClient httpclient = HttpClients.custom()
        .setConnectionManager(PoolingHttpClientConnectionManagerBuilder.create()
                .setSSLSocketFactory(new SSLConnectionSocketFactory(ctx))
                .build())
        .build();
```

> ⚠️ **绝不要在生产使用「信任所有证书」的 TrustManager**（网上流传的 `checkServerTrusted(...) {}` 空实现）——它让 HTTPS 退化成「只加密不认证」，中间人拿自签证书就能解密全部流量，Android / iOS 与合规扫描也会直接判定不通过；自签场景的正确做法是把内部 CA 加进 TrustStore。另外用 IP 访问 HTTPS 会因主机名校验失败，应使用证书里的 SAN 名作为 hostname，而**不是**把 `HostnameVerifier` 覆盖成总是通过。

### 9.4 Spring Boot 开启 HTTPS
```yaml
server:
  port: 8443
  ssl:
    enabled: true
    key-store: classpath:server.p12      # 也支持 file: 绝对路径
    key-store-type: PKCS12
    key-store-password: changeit
    key-alias: myapp
    protocol: TLS
    enabled-protocols: TLSv1.3,TLSv1.2  # ⚠️ 显式关掉 1.0/1.1
    ciphers: TLS_AES_128_GCM_SHA256,TLS_ECDHE_RSA_WITH_AES_128_GCM_SHA256  # 可选白名单
    # ---- mTLS 双向认证，需要时才打开 ----
    client-auth: need                    # none / want（可选）/ need（强制）
    trust-store: classpath:truststore.p12
    trust-store-password: changeit
```

HTTP → HTTPS 的重定向建议放在网关 / Nginx，或给 Tomcat 追加一个明文连接器做跳转。

```bash
# 生成自签证书（务必带 SAN，CN 已不被信任）
keytool -genkeypair -alias myapp -keyalg RSA -keysize 2048 -validity 365 \
        -storetype PKCS12 -keystore server.p12 -storepass changeit \
        -dname "CN=localhost,O=Dev" -ext "SAN=dns:localhost,ip:127.0.0.1"
# 导出证书给客户端 → 客户端导入信任 → 排障：查看证书链详情（有效期、SAN、签发者）
keytool -exportcert -alias myapp -keystore server.p12 -storepass changeit -file server.crt
keytool -importcert -alias myapp -file server.crt -keystore truststore.p12 \
        -storetype PKCS12 -storepass changeit -noprompt
keytool -list -v -keystore server.p12 -storepass changeit
```
（`-ext "SAN=..."` 必须写：现代 JDK 与浏览器**只认 SAN**，`CN` 匹配已废弃，少了它就报 `No subject alternative names present`。）

## 十、生产实践与坑
### 10.1 全站 HTTPS 与 HSTS
全站 HTTPS + HTTP 301 跳转，再加 **HSTS**：`Strict-Transport-Security: max-age=31536000; includeSubDomains`。⭐ 浏览器会**记住**该域名只走 HTTPS，省掉一次跳转 RTT，还能挡 SSL Stripping 降级攻击。⚠️ `preload`（提交进浏览器内置列表）基本**不可回退**：一旦上线，某个子域名暂时没 HTTPS 就彻底打不开，务必确认全站（含所有子域）就绪再加。

**混合内容（mixed content）**：HTTPS 页面里加载 HTTP 子资源（图片 / js / iframe）即 mixed content，浏览器会**拦截**其中的脚本与 XHR 类资源。排查看控制台 Console 警告，修复办法是全站 HTTPS，或加 `Content-Security-Policy: upgrade-insecure-requests` 自动升级。

### 10.2 性能开销
| 项 | 说明 |
|---|---|
| 握手 CPU | 大头是**非对称运算**。**ECDHE（尤其 X25519）比 RSA 快得多**，RSA-2048 私钥运算在突发连接时会明显吃 CPU；也可在 LB / CDN 做 TLS 终结 |
| 减少握手次数 | 会话复用（Ticket / PSK）、长连接 keep-alive、HSTS 免跳转 |
| 协议版本 | ⭐ TLS 1.3 少一次 RTT；必要时用 0-RTT（注意重放）；HTTP/2 多路复用让多条请求复用一条 TLS 连接 |
| 对称加密 | AES-GCM 有 **AES-NI** 硬件加速几乎免费；移动端无加速时 `ChaCha20-Poly1305` 反而更快 |

**OCSP Stapling**：客户端自己跑去 CA 查 OCSP 既慢、又泄漏用户访问行为，还容易因查询超时拖垮握手。改成服务端定期取自己的 OCSP 响应，握手时「订」在 Certificate 消息后面一起发：

```nginx
ssl_stapling on;                                 # 服务端代取 OCSP 响应，握手时一起发出
ssl_stapling_verify on;
ssl_trusted_certificate /path/to/chain.pem;      # 含中间证书，用于验证 OCSP 响应
resolver 8.8.8.8 valid=300s;
```

### 10.3 证书到期监控与 ⚠️ 中间设备
- **到期是生产事故第一名**（Let's Encrypt 只有 90 天）：用 certbot / acme.sh 定时任务，K8s 上用 **cert-manager**。
- 监控两手抓：① Prometheus `blackbox_exporter`（`probe_ssl_earliest_cert_expiry`）或 `ssl_exporter`；② 服务启动时自打印剩余天数并在 `/health` 暴露。告警阈值建议 **30 天 / 7 天两级**。⚠️ 别忘了整套链路：**CDN、负载均衡、Ingress、内网自签 CA** 都要管。
- ⚠️ **中间设备导致握手失败**：企业出口代理、老 WAF、部分负载均衡固件**解析不了 TLS 1.3 的新记录格式与扩展**，只有部分网络用户受影响；排查用 `openssl s_client -tls1_2` 与 `-tls1_3` 对比，短期可针对该来源降级。带 SNI 拦截的透明代理会签发自己的证书，只能靠客户端信任库 / 证书固定发现。
- ⚠️ HTTP/3 over QUIC 走 **UDP 443**，不少防火墙直接丢 UDP，表现为「网站时而打不开」；MTU / 分片问题（DF 位 + ICMP 被禁的 PMTUD 黑洞）会让 TLS 记录变大后偶发连接挂起。

## 十一、面试官会追问什么
1. **HTTPS 握手过程？和 TCP 握手什么关系？** → TCP 三次握手先建立连接；TLS 在 TCP 之上再走：ClientHello → ServerHello → Certificate →（ECDHE 时）ServerKeyExchange → ServerHelloDone → ClientKeyExchange → 双方 ChangeCipherSpec + Finished，之后对称加密传数据。**TLS 的 RTT 是叠加在 TCP 之上的额外开销**。
2. **HTTPS 是不是一个新协议？** → ⚠️ **不是**。它在 TCP 与 HTTP 之间插了一层 TLS，HTTP 报文原封不动交给 TLS 加密后再交 TCP，即 HTTP over TLS（同理还有 gRPC over TLS）。
3. **TLS 1.2 与 1.3 最大的区别？** → ① 2-RTT → **1-RTT**（客户端提前在 ClientHello 带 `key_share`）；② **删除 RSA / 静态 DH，强制前向安全**；③ 套件精简到 5 个、只留 AEAD、交换与认证分离；④ **Hello 之后立刻加密，服务器证书也被加密**（1.2 里是明文）；⑤ PRF → **HKDF**。
4. **为什么都用 ECDHE，不能用 RSA 密钥交换？** → RSA 方式下客户端把 Pre-Master Secret 用**服务器证书公钥**加密发送，**长期私钥一泄露，留存的历史流量可被全部解密，没有前向安全**。ECDHE 每次握手生成临时密钥对、用完即弃，长期私钥只签名不加密流量。
5. **证书是怎么验证的？** → ① **逐级验签**到本机信任库根证书；② 校验**有效期**；③ 校验 **SAN 域名**（CN 已废弃）；④ 查**吊销**（CRL / OCSP，生产用 Stapling）；⑤ 校验用途 EKU。任一步失败即中断。
6. **0-RTT 有什么风险？怎么用才安全？** → ⚠️ **重放攻击**：early data 不具备唯一性，录下来重发服务端照收。限定**幂等请求**、服务端 single-use ticket / 一次性 nonce、应用层加 token。**TLS 1.3 的 0-RTT 不提供防重放保证**。
7. **Session ID 和 Session Ticket 区别？** → Session ID **服务端有状态**（多机需共享缓存或命中同节点）；Ticket 服务端**无状态**，把加密后的会话参数交客户端，天然支持横向扩展。⚠️ Ticket 加密密钥必须**集群共享 + 定期轮换**，否则既削弱前向安全，又会出现「跨节点解不开票、默默降级为完整握手」的延迟抖动。
8. **抓包为什么看不到 HTTPS 内容？** → 握手后数据走**对称加密**，密钥只有协商双方持有，抓到的只是 TLS 记录密文。想解密只能：① 配 `SSLKEYLOGFILE` 让**本端**导出会话密钥给 Wireshark；② 在代理上做 MITM 并让客户端信任其根证书。
9. **为什么不能只用对称或非对称？** → 对称**快但密钥分发难**（不可信信道里怎么先让双方拿到同一把密钥）；非对称**解决分发但慢**，还依赖证书解决「公钥是谁的」。所以是 ⭐ **用非对称安全协商出对称密钥，再用对称加密海量数据**——这正是 TLS 的做法。
10. **HTTPS 一定安全吗？** → ⚠️ 不一定，它只保护**传输链路**：① 用了 `InsecureSkipVerify` / 信任所有证书的 TrustManager → 中间人可冒充；② 配了弱套件或 TLS 1.0/1.1；③ 私钥泄漏、CA 被攻破；④ 数据在**进入 TLS 之前和解密之后仍是明文**（日志、CDN 边缘、服务端内存）；⑤ HTTPS 拦不住 XSS / SQL 注入等应用层攻击。
11. **ChangeCipherSpec 和 Finished 分别做什么？** → ChangeCipherSpec 是**独立协议**的一条信号：「我下面开始加密了」，本身不参与密钥协商；Finished 是**第一条加密消息**，内容是此前**全部握手消息的摘要**，用来校验「双方算出的密钥一致」+「握手没被篡改」，是抗降级攻击的关键一环。
12. **单向认证和双向认证（mTLS）差在哪？什么时候用？** → 单向只客户端验服务器证书（浏览网页）；双向还要求**服务器验客户端证书**。用于**服务间调用、内网 API、零信任 / 服务网格、支付类金融接口**——把「我知道你的密码」升级为「你持有这张证书对应的私钥」。

## 关联

- [../安全/数字证书与PKI.md](../安全/数字证书与PKI.md) — 证书与信任链的基础概念（单一来源）
- [../安全/加密算法.md](../安全/加密算法.md) — 握手协商的套件从哪来
- [HTTP与gRPC.md](HTTP与gRPC.md) — h2 的事实前提是 TLS 与 ALPN
- [tcp/TCP三次握手.md](tcp/TCP三次握手.md) — TLS 握手的 RTT 叠加在 TCP 之上
