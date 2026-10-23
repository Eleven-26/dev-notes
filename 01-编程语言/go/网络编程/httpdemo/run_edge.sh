#!/bin/sh
cd /src || exit 1
P=./bin/probe

echo "########## A. net/http 的首部上限到底在哪：1.1 MB 首部"
$P -addr 127.0.0.1:9003 -mode huge -size 1100000

echo ""
echo "########## B. minihttp: HTTP/1.0，不给 Connection"
$P -addr 127.0.0.1:9002 -mode raw -data "GET /hello HTTP/1.0\r\nHost: probe\r\n\r\n"

echo ""
echo "########## C. minihttp: HTTP/1.0 + Connection: keep-alive"
$P -addr 127.0.0.1:9002 -mode raw -data "GET /hello HTTP/1.0\r\nHost: probe\r\nConnection: keep-alive\r\n\r\n"

echo ""
echo "########## D. minihttp: HTTP/1.1 + Connection: close"
$P -addr 127.0.0.1:9002 -mode raw -data "GET /hello HTTP/1.1\r\nHost: probe\r\nConnection: close\r\n\r\n"

echo ""
echo "########## E. 正常排空（:9002）：同一连接先 POST 带 body，再 GET"
$P -addr 127.0.0.1:9002 -mode pipe -n 2 -data "POST /hello HTTP/1.1\r\nHost: probe\r\nContent-Length: 5\r\n\r\nhelloGET /hello HTTP/1.1\r\nHost: probe\r\n\r\n"

echo ""
echo "########## F. 不排空（:9005 -drain=false）：完全相同的字节"
$P -addr 127.0.0.1:9005 -mode pipe -n 2 -data "POST /hello HTTP/1.1\r\nHost: probe\r\nContent-Length: 5\r\n\r\nhelloGET /hello HTTP/1.1\r\nHost: probe\r\n\r\n"
