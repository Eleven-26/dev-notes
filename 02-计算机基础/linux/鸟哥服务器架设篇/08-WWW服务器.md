# WWW服务器

> HTTP 服务基础、Apache 与 Nginx 的配置与虚拟主机、HTTPS 证书配置、
> 访问控制与日志分析。
>
> 内容整理自个人学习笔记。**基础框架**参考鸟哥官网《服务器架设篇 - RockyLinux 9》
> 与《鸟哥的 Linux 私房菜：服务器架设篇（第三版修订）》相关章节的读后整理；Nginx 与证书自动化基于官方文档独立整理。

---

## 一、HTTP 服务的基础是什么？

**本节要点**：一次请求就是「客户端发请求行 + 头 + 体，服务器回状态行 + 头 + 体」；**静态**内容直接读文件，**动态**内容交给后端程序。

| 状态码段 | 含义 | 典型 |
|----------|------|------|
| 2xx | 成功 | `200 OK`、`206`（范围请求） |
| 3xx | 重定向 | `301`（永久）、`302`（临时） |
| 4xx | 客户端错误 | `403`（拒绝）、`404`（找不到）、`429`（限流） |
| 5xx | 服务端错误 | `502`（上游坏）、`503`（不可用）、`504`（上游超时） |

```bash
curl -sSI https://example.com | head        # 只看响应头
curl -sS -o /dev/null -w "%{http_code} %{time_total}s\n" https://example.com/
```

⭐ 静态内容（HTML / 图片 / 视频）由 Web 服务器直接返回；动态内容（PHP / Java / Go）经 **FastCGI / 反向代理**转给后端。

---

## 二、Apache 怎么配？

**本节要点**：Apache 用「主配置 + 虚拟主机 + `.htaccess` + 模块（动态加载）」组织配置；`httpd -t` 校验、`systemctl reload` 生效。

```text
# /etc/httpd/conf.d/vhost.conf（基于域名的虚拟主机）
<VirtualHost *:80>
  ServerName  web01.example.com
  DocumentRoot /srv/web/web01
  <Directory /srv/web/web01>
    Options -Indexes
    AllowOverride None
    Require all granted
  </Directory>
  ErrorLog  /var/log/httpd/web01-error.log
  CustomLog /var/log/httpd/web01-access.log combined
</VirtualHost>
```

```bash
sudo dnf install -y httpd
sudo systemctl enable --now httpd
sudo httpd -t                        # 语法检查
sudo apachectl -M | grep rewrite     # 看已加载模块
sudo systemctl reload httpd
```

- **虚拟主机三种**：基于域名（`ServerName`）、基于端口（`*:8080`）、基于 IP；生产最常见是**基于域名**；
- **`.htaccess`** 是目录级配置：方便但**每次请求都要读盘、有性能开销**，生产建议 `AllowOverride None` 并把规则写进主配置；
- **模块管理**：`LoadModule` 动态加载（如 `mod_rewrite`、`mod_ssl`）。

---

## 三、Nginx 怎么配？

**本节要点**：Nginx 用 `server` / `location` 组织，**事件驱动**、高并发下内存占用低；`location` 的匹配优先级是配置正确与否的关键。

```text
# /etc/nginx/conf.d/web01.conf
server {
  listen 80;
  server_name web01.example.com;
  root /srv/web/web01;

  location / {
    try_files $uri $uri/ =404;
  }
  location /api/ {
    proxy_pass http://10.0.10.30:8080/;
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
  }
}
```

| `location` 写法 | 匹配规则 | 优先级 |
|-----------------|----------|--------|
| `= /path` | 精确匹配 | 最高 |
| `/path`（前缀） | 前缀匹配，取最长 | 中 |
| `^~ /path` | 前缀匹配且不再走正则 | 高于普通前缀 |
| `~ \.php$` | 正则（区分大小写） | 高于前缀 |
| `~* \.jpg$` | 正则（不区分大小写） | 同上 |

```bash
sudo nginx -t                        # 语法检查
sudo systemctl reload nginx
```

⚠️ 反向代理要带上 `Host` / `X-Real-IP` / `X-Forwarded-For`，否则后端拿到的是代理的地址；怎么取真实 IP 见 [客户端真实IP与可信代理.md](../../网络/客户端真实IP与可信代理.md)。

---

## 四、HTTPS 怎么配、证书怎么来？

**本节要点**：HTTPS = HTTP over TLS；证书可用 Let\u2019s Encrypt 免费签发并**自动续期**（90 天有效期），或用自签证书做内网测试。

```bash
# ① 用 certbot 申请并自动配置（Nginx）
sudo dnf install -y certbot python3-certbot-nginx
sudo certbot --nginx -d web01.example.com

# ② 验证自动续期（certbot 会装 systemd timer）
sudo certbot renew --dry-run
systemctl list-timers | grep certbot

```

```text
# Nginx 的 HTTPS 要点
server {
  listen 443 ssl http2;
  ssl_certificate     /etc/letsencrypt/live/web01.example.com/fullchain.pem;
  ssl_certificate_key /etc/letsencrypt/live/web01.example.com/privkey.pem;
  ssl_protocols TLSv1.2 TLSv1.3;      # ⚠️ 禁用过老的 TLS 1.0 / 1.1
  add_header Strict-Transport-Security "max-age=31536000" always;   # HSTS
}
```

⚠️ **自签证书**浏览器会告警，仅用于内网 / 测试；对外必须用受信 CA 的证书。证书链要完整（`fullchain.pem`），只传叶子证书会导致部分客户端校验失败。

---

## 五、日志与分析

**本节要点**：`access_log` 记录每个请求、`error_log` 记录问题；日志会无限增长，**必须切割 + 归档**。

```bash
tail -f /var/log/nginx/access.log
tail -f /var/log/nginx/error.log

# 统计 TOP 来源 IP 与状态码分布
awk '{print $1}' /var/log/nginx/access.log | sort | uniq -c | sort -rn | head
awk '{print $9}' /var/log/nginx/access.log | sort | uniq -c | sort -rn | head

# 找 5xx
grep " 5[0-9][0-9] " /var/log/nginx/access.log | tail
```

- Nginx 通过 `logrotate` 的 `USR1` 信号重开日志（`create` 模式）；
- Apache 用 `rotatelogs` 或 logrotate；
- ⚠️ 切割后若进程不重开文件，日志会继续写进被 rename 的旧文件、空间不释放——这是「打了日志但 `df` 不降」的常见原因。

---

## 使用：用 Nginx 跑一个站点 + 反代后端

**本节要点**：静态站点 + `/api` 反代到后端 + HTTPS 的一条完整流程。

```bash
# ① 装并放行
sudo dnf install -y nginx
sudo firewall-cmd --add-service=http --add-service=https --permanent && sudo firewall-cmd --reload

# ② 静态根目录（注意 SELinux 上下文见 02 篇）
sudo mkdir -p /srv/web/web01 && echo "hello" | sudo tee /srv/web/web01/index.html
sudo chown -R nginx:nginx /srv/web/web01
sudo semanage fcontext -a -t httpd_sys_content_t "/srv/web(/.*)?" && sudo restorecon -Rv /srv/web

# ③ 写 server 配置（上文的模板）后
sudo nginx -t && sudo systemctl enable --now nginx

# ④ 验证
curl -sS -o /dev/null -w "static:%{http_code}\n" http://127.0.0.1/
curl -sS -o /dev/null -w "api:%{http_code}\n" http://127.0.0.1/api/
sudo tail -5 /var/log/nginx/access.log
```

判据：`nginx -t` 通过；`curl` 静态返回 `200`；`/api/` 反代到后端（后端日志能看到带 `X-Forwarded-For` 的请求）；`access.log` 有新行。

---

## 延伸追问

- **Apache 和 Nginx 怎么选？**
  → **Nginx**：事件驱动、静态 / 反向代理 / 高并发场景内存占用低，现代前端 + 后端分离架构的首选；**Apache**：模块生态老练，`.htaccess` 与目录级配置灵活，传统 PHP 应用、需要大量第三方模块时仍有优势。⭐ 也常见「Nginx 做前端反代 + Apache / 应用服务器做后端」的组合。
- **虚拟主机有哪几种方式？**
  → 三种：**基于域名**（同一个 IP:80，按 `Host` 头区分，最常用）、**基于端口**（同一 IP 不同端口）、**基于 IP**（每站一个 IP）。现代 HTTPS 下主流是基于域名 + SNI，一个 IP 可承载大量证书不同的站点。
- **为什么 HTTPS 证书要自动续期？**
  → 因为 Let\u2019s Encrypt 等免费证书有效期只有 **90 天**（设计上鼓励自动化），手工续期迟早会忘——到期那天站点直接证书失效告警。`certbot` 安装后自带 systemd timer / cron 自动续期，务必用 `certbot renew --dry-run` 验证续期链路是通的。

---

## 关联

- [HTTP与gRPC.md](../../网络/HTTP与gRPC.md) — HTTP 语义与 gRPC 的对照
- [HTTPS与TLS.md](../../网络/HTTPS与TLS.md) — 证书与 TLS 握手原理
- [反向代理原理与实现.md](../../网络/反向代理原理与实现.md) — 反向代理的原理
- [客户端真实IP与可信代理.md](../../网络/客户端真实IP与可信代理.md) — 反代下取真实 IP
- [02-主机安全与SELinux.md](02-主机安全与SELinux.md) — 站点目录的 SELinux 上下文
- [README.md](README.md) — 本目录导读：版本坐标与阅读顺序
> 反向引用（本篇被下列文档引到）：[10-邮件服务器.md](10-邮件服务器.md)、[11-容器化衔接.md](11-容器化衔接.md)
