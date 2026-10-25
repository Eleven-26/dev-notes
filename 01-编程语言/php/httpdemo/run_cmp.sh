#!/bin/sh
# 同一批请求同时打给 mini 与 php -S，逐项看两边选了哪条边
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
php mini/mini.php -addr 127.0.0.1:9002 -log false > logs/mini-cmp.log 2>&1 &
MINI=$!
php -S 127.0.0.1:9003 std/router.php > logs/std.log 2>&1 &
STD=$!
sleep 1

for m in get head http10 http10ka chunked tecl lf; do
    echo "########## ${m}"
    echo "---- mini ----"
    php probe/probe.php -addr 127.0.0.1:9002 -mode "${m}" | sed -n '/response/,$p'
    echo "---- php -S ----"
    php probe/probe.php -addr 127.0.0.1:9003 -mode "${m}" | sed -n '/response/,$p'
done

echo "########## 单行 12 KB 首部"
echo "---- mini ----"
php probe/probe.php -addr 127.0.0.1:9002 -mode huge -size 12000 | sed -n '/response/,$p'
echo "---- php -S ----"
php probe/probe.php -addr 127.0.0.1:9003 -mode huge -size 12000 | sed -n '/response/,$p'

echo "########## 单行 20 KB 首部"
echo "---- mini ----"
php probe/probe.php -addr 127.0.0.1:9002 -mode huge -size 20000 | sed -n '/response/,$p'
echo "---- php -S ----"
php probe/probe.php -addr 127.0.0.1:9003 -mode huge -size 20000 | sed -n '/response/,$p'

echo "########## 2000 行小首部（34.9 KB）"
echo "---- mini ----"
php probe/probe.php -addr 127.0.0.1:9002 -mode many -n 2000 | sed -n '/response/,$p'
echo "---- php -S ----"
php probe/probe.php -addr 127.0.0.1:9003 -mode many -n 2000 | sed -n '/response/,$p'

echo "########## php -S 的首部边界：81920 与 81921 字节"
for n in 81920 81921; do
    printf 'size=%-7s ' $n
    php probe/probe.php -addr 127.0.0.1:9003 -mode huge -size $n | sed -n '/response/p'
done

echo "########## 1.1 MB 单行首部（mini 16 KB 上限）"
echo "---- mini ----"
php probe/probe.php -addr 127.0.0.1:9002 -mode huge -size 1100000 | sed -n '/response/,$p' | head -3
echo "---- php -S ----"
php probe/probe.php -addr 127.0.0.1:9003 -mode huge -size 1100000 | sed -n '/response/,$p' | head -3

echo "########## 404 与 204"
for uri in /nope /no-content; do
    echo "---- mini ${uri} ----"
    php probe/probe.php -addr 127.0.0.1:9002 -mode raw -data "GET ${uri} HTTP/1.1\r\nHost: probe\r\n\r\n" | sed -n '/response/,$p'
    echo "---- php -S ${uri} ----"
    php probe/probe.php -addr 127.0.0.1:9003 -mode raw -data "GET ${uri} HTTP/1.1\r\nHost: probe\r\n\r\n" | sed -n '/response/,$p'
done

kill "${MINI}" "${STD}" 2>/dev/null || true
wait 2>/dev/null
