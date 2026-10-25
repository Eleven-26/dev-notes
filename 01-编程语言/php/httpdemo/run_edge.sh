#!/bin/sh
# 两个对照实验：① 不排空请求体会把下一个请求行拼坏；② 事件循环与 php -S 的并发隔离差异
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
php mini/mini.php -addr 127.0.0.1:9002 > logs/edge-drain.log 2>&1 &
D1=$!
php mini/mini.php -addr 127.0.0.1:9005 -drain false > logs/edge-nodrain.log 2>&1 &
D2=$!
sleep 1

echo "########## 同一个字节序列：POST body(5) + 紧接一个完整 GET"
echo "---- drain=true (9002) ----"
php probe/probe.php -addr 127.0.0.1:9002 -mode drain
echo "---- drain=false (9005) ----"
php probe/probe.php -addr 127.0.0.1:9005 -mode drain
echo "---- drain=true 服务端日志 ----"
tail -n 8 logs/edge-drain.log
echo "---- drain=false 服务端日志 ----"
tail -n 8 logs/edge-nodrain.log
kill "${D1}" "${D2}" 2>/dev/null || true
wait 2>/dev/null

php mini/mini.php -addr 127.0.0.1:9010 -log false > logs/edge-conc.log 2>&1 &
M1=$!
php -S 127.0.0.1:9003 std/router.php > logs/edge-conc-std.log 2>&1 &
S1=$!
sleep 1

for addr in 127.0.0.1:9010 127.0.0.1:9003; do
    echo "########## ${addr}：一个慢客户端只发半个首部并挂住 2 秒"
    php probe/probe.php -addr "${addr}" -mode slowhold -delay 2 > logs/slowhold.out 2>&1 &
    HOLD=$!
    sleep 0.5
    php probe/probe.php -addr "${addr}" -mode timed
    wait "${HOLD}"
    cat logs/slowhold.out

    echo "########## ${addr}：一个 handler 里 sleep 1.5 秒"
    php probe/probe.php -addr "${addr}" -mode raw -idle 2 -data "GET /sleep?ms=1500 HTTP/1.1\r\nHost: probe\r\n\r\n" > logs/sleep.out 2>&1 &
    SLOW=$!
    sleep 0.2
    php probe/probe.php -addr "${addr}" -mode timed
    wait "${SLOW}"
    tail -n 2 logs/sleep.out
done

echo "########## 只对 mini：先挂 50 条空闲 keep-alive 连接，再发一个正常请求"
php probe/probe.php -addr 127.0.0.1:9010 -mode hold -n 50 -delay 2 > logs/hold50.out 2>&1 &
HOLD50=$!
sleep 0.5
php probe/probe.php -addr 127.0.0.1:9010 -mode timed
wait "${HOLD50}"
cat logs/hold50.out

kill "${M1}" "${S1}" 2>/dev/null || true
wait 2>/dev/null
