#!/bin/sh
# 压测：mini 分别走 keep-alive 与逐请求重连；php -S 只能逐请求重连（它恒回 Connection: close）
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
php mini/mini.php -addr 127.0.0.1:9002 -log false > logs/bench-mini.log 2>&1 &
MINI=$!
php -S 127.0.0.1:9003 std/router.php > logs/bench-std.log 2>&1 &
STD=$!
sleep 1

for CONC in 8 4; do
    for ROUND in 1 2 3 4 5; do
        echo "---- conc=${CONC} round=${ROUND} ----"
        printf 'mini-keepalive  '
        php bench/bench.php -addr 127.0.0.1:9002 -conns "${CONC}" -requests 1000 -mode keepalive
        printf 'mini-close      '
        php bench/bench.php -addr 127.0.0.1:9002 -conns "${CONC}" -requests 500 -mode close
        printf 'php-S-close     '
        php bench/bench.php -addr 127.0.0.1:9003 -conns "${CONC}" -requests 200 -mode close
    done
done

kill "${MINI}" "${STD}" 2>/dev/null || true
wait 2>/dev/null
