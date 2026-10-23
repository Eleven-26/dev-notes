#!/bin/sh
cd /src || exit 1
echo "================ minihttp /hello"
curl -s -D - -o /dev/null http://127.0.0.1:9002/hello
echo "================ net/http /hello"
curl -s -D - -o /dev/null http://127.0.0.1:9003/hello

echo "================ minihttp /nope"
curl -s -D - http://127.0.0.1:9002/nope
echo "================ net/http /nope"
curl -s -D - http://127.0.0.1:9003/nope

echo "================ minihttp: 裸 LF"
./bin/probe -addr 127.0.0.1:9002 -mode lf
echo "================ net/http: 裸 LF（同一个请求）"
./bin/probe -addr 127.0.0.1:9003 -mode lf

echo "================ minihttp: 20 KB 首部"
./bin/probe -addr 127.0.0.1:9002 -mode huge -size 20000
echo "================ net/http: 20 KB 首部（同一个请求）"
./bin/probe -addr 127.0.0.1:9003 -mode huge -size 20000

echo "================ net/http: TE+CL 同给"
./bin/probe -addr 127.0.0.1:9003 -mode tecl
echo "================ net/http: chunked"
./bin/probe -addr 127.0.0.1:9003 -mode chunked
echo "================ net/http: 半包"
./bin/probe -addr 127.0.0.1:9003 -mode split -split 20 -data "GET /hello HTTP/1.1\r\nHost: probe\r\n\r\n"
