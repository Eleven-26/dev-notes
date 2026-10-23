#!/bin/sh
cd /src || exit 1
P=./bin/probe
A=127.0.0.1:9002

echo "########## 1. GET /hello  (最简成功路径)"
$P -addr $A -mode get

echo ""
echo "########## 2. POST /echo  Content-Length: 5"
$P -addr $A -mode raw -data "POST /echo HTTP/1.1\r\nHost: probe\r\nContent-Length: 5\r\n\r\nhello"

echo ""
echo "########## 3. POST /echo  Transfer-Encoding: chunked  (5+6 两块)"
$P -addr $A -mode chunked

echo ""
echo "########## 4. Transfer-Encoding 与 Content-Length 同时给"
$P -addr $A -mode tecl

echo ""
echo "########## 5. 裸 LF（没有 CR）"
$P -addr $A -mode lf

echo ""
echo "########## 6. 20 KB 首部（默认上限 16 KB）"
$P -addr $A -mode huge -size 20000

echo ""
echo "########## 7. HEAD /hello"
$P -addr $A -mode head

echo ""
echo "########## 8. keep-alive：同一连接依次发 3 个请求"
$P -addr $A -mode keepalive -n 3

echo ""
echo "########## 9. pipelining：一次 Write 发 3 个请求"
$P -addr $A -mode pipe -n 3

echo ""
echo "########## 10. absolute-form  request-target"
$P -addr $A -mode abs

echo ""
echo "########## 11. 不存在的路径"
$P -addr $A -mode get -path /nope

echo ""
echo "########## 12. 半包：同一个请求分两次 Write（间隔 200ms）"
$P -addr $A -mode split -split 20 -data "GET /hello HTTP/1.1\r\nHost: probe\r\n\r\n"

echo ""
echo "########## 13. 慢速首部：每 800ms 发 1 字节（ReadHeaderTimeout=5s）"
$P -addr $A -mode timeout
