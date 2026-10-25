#!/bin/sh
# 协议行为实验：三种定界、两道首部闸门、keep-alive、pipelining、半包、慢速首部
set -u
cd "$(dirname "$0")"
mkdir -p logs

# 上一轮异常中断可能留下监听进程，先清掉（只认 php 起的两个服务端）
stop_stale() {
    for p in /proc/[0-9]*; do
        pid=${p#/proc/}
        [ "$(cat "$p/comm" 2>/dev/null)" = "php" ] || continue
        if grep -qaE '(mini|router)\.php' "$p/cmdline" 2>/dev/null; then
            kill "$pid" 2>/dev/null
        fi
    done
    sleep 0.3
}

stop_stale
php mini/mini.php -addr 127.0.0.1:9002 > logs/mini.log 2>&1 &
MINI=$!
sleep 1

for m in get head http10 http10ka chunked tecl lf pipe close; do
    echo "########## ${m}"
    php probe/probe.php -addr 127.0.0.1:9002 -mode "${m}"
done

echo "########## huge: 单行 12 KB（单行闸门，总长没超 16 KB）"
php probe/probe.php -addr 127.0.0.1:9002 -mode huge -size 12000

echo "########## many: 2000 行小首部（总量闸门）"
php probe/probe.php -addr 127.0.0.1:9002 -mode many -n 2000

echo "########## longuri: 9000 字节 request-target（请求行超长）"
php probe/probe.php -addr 127.0.0.1:9002 -mode longuri -size 9000

echo "########## split: 20 / 16 字节两次发（半包）"
php probe/probe.php -addr 127.0.0.1:9002 -mode split -split 20

echo "########## slow header: 1 byte / 800ms（绝对截止时间）"
php probe/probe.php -addr 127.0.0.1:9002 -mode timeout

kill "${MINI}" 2>/dev/null || true
wait 2>/dev/null

echo "########## mini server log (tail)"
tail -n 30 logs/mini.log
