#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * 压测客户端：一个进程用 stream_select 同时驱动 K 条连接。
 * -mode keepalive 复用连接（响应以 Content-Length 定界）；
 * -mode close 每条连接只发一个请求，响应以 EOF 定界——php -S 恒回 Connection: close，只能这么打。
 * 客户端与服务端同机同容器，读数只能看趋势，不能当排名。
 */
$opt = ['addr' => '127.0.0.1:9002', 'conns' => 4, 'requests' => 2000, 'mode' => 'keepalive'];
for ($i = 1; $i < $argc; $i++) {
    switch ($argv[$i]) {
        case '-addr':
            $opt['addr'] = $argv[++$i];
            break;
        case '-conns':
            $opt['conns'] = (int) $argv[++$i];
            break;
        case '-requests':
            $opt['requests'] = (int) $argv[++$i];
            break;
        case '-mode':
            $opt['mode'] = $argv[++$i];
            break;
        default:
            fwrite(STDERR, "usage: php bench.php [-addr host:port] [-conns N] [-requests N] [-mode keepalive|close]\n");
            exit(2);
    }
}

// 小请求 + 小响应最容易被 Nagle 与延迟 ACK 咬住，客户端侧先关掉
$ctx = stream_context_create(['socket' => ['tcp_nodelay' => true]]);

function newconn(string $addr, $ctx)
{
    $s = @stream_socket_client('tcp://' . $addr, $errno, $errstr, 5, STREAM_CLIENT_CONNECT, $ctx);
    if ($s === false) {
        fwrite(STDERR, "connect failed: {$errstr}\n");
        exit(1);
    }
    stream_set_blocking($s, false);
    return $s;
}

$req = $opt['mode'] === 'close'
    ? "GET /hello HTTP/1.1\r\nHost: bench\r\nConnection: close\r\n\r\n"
    : "GET /hello HTTP/1.1\r\nHost: bench\r\n\r\n";

$conns = [];
for ($i = 0; $i < $opt['conns']; $i++) {
    $conns[] = ['sock' => newconn($opt['addr'], $ctx), 'buf' => '', 'done' => 0, 'want' => true];
}

$remaining = $opt['conns'] * $opt['requests'];
$t0 = microtime(true);
while ($remaining > 0) {
    $read = [];
    $write = [];
    $map = [];
    foreach ($conns as $k => $c) {
        if ($c['done'] >= $opt['requests']) {
            continue;
        }
        $read[] = $c['sock'];
        $map[(int) $c['sock']] = $k;
        if ($c['want']) {
            $write[] = $c['sock'];
        }
    }
    if ($read === []) {
        break;
    }
    $except = null;
    $n = @stream_select($read, $write, $except, 5);
    if ($n === false || $n === 0) {
        fwrite(STDERR, "select timeout with {$remaining} requests left\n");
        break;
    }

    foreach ($write as $sock) {
        $k = $map[(int) $sock];
        $w = @fwrite($sock, $req);
        if ($w === strlen($req)) {
            $conns[$k]['want'] = false;
        } elseif ($w === false || $w === 0) {
            // 对端已经关了（比如拿 keepalive 模式打 php -S），别在这里空转
            fwrite(STDERR, "write failed on conn {$k} in {$opt['mode']} mode\n");
            $remaining -= $opt['requests'] - $conns[$k]['done'];
            $conns[$k]['want'] = false;
            $conns[$k]['done'] = $opt['requests'];
        }
    }

    foreach ($read as $sock) {
        $k = $map[(int) $sock];
        $chunk = @fread($sock, 65536);
        if ($chunk === false || $chunk === '') {
            // close 模式：响应体以 EOF 定界，读到 EOF 才算收完一个
            if ($opt['mode'] === 'close' && feof($sock)) {
                if ($conns[$k]['buf'] !== '') {
                    $conns[$k]['buf'] = '';
                    $conns[$k]['done']++;
                    $remaining--;
                }
                if ($conns[$k]['done'] < $opt['requests']) {
                    @fclose($sock);
                    $conns[$k]['sock'] = newconn($opt['addr'], $ctx);
                    $conns[$k]['want'] = true;
                }
            }
            continue;
        }
        if ($opt['mode'] === 'close') {
            $conns[$k]['buf'] .= $chunk;
            continue;
        }
        $conns[$k]['buf'] .= $chunk;
        while (($p = strpos($conns[$k]['buf'], "\r\n\r\n")) !== false) {
            $len = 0;
            if (preg_match('/^Content-Length:\s*(\d+)/mi', substr($conns[$k]['buf'], 0, $p), $m)) {
                $len = (int) $m[1];
            }
            $total = $p + 4 + $len;
            if (strlen($conns[$k]['buf']) < $total) {
                break;
            }
            $conns[$k]['buf'] = substr($conns[$k]['buf'], $total);
            $conns[$k]['done']++;
            $remaining--;
            if ($conns[$k]['done'] < $opt['requests']) {
                $conns[$k]['want'] = true;
            }
        }
    }
}

$elapsed = microtime(true) - $t0;
$done = $opt['conns'] * $opt['requests'] - max(0, $remaining);
printf("%-9s conns=%d requests=%d elapsed=%.3f s qps=%.0f\n", $opt['mode'], $opt['conns'], $done, $elapsed, $elapsed > 0 ? $done / $elapsed : 0);
