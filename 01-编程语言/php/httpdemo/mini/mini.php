#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * 手写 HTTP/1.1 服务端：单进程常驻 + 非阻塞 socket + stream_select 事件循环。
 * 没有 fork、没有线程，所以「解析到一半」的状态必须挂在连接对象上跨 read 保留。
 */
require __DIR__ . '/../lib/http.php';

final class Conn
{
    /** @var resource */
    public $sock;
    public string $peer;
    public int $id;
    public string $buf = '';
    public string $out = '';
    public string $state = 'head'; // head | body | chunked | done
    public int $need = 0;
    public array $req = [];
    public bool $closeAfterWrite = false;
    public bool $expired = false;
    public float $deadline = 0.0;
    public int $reqs = 0;
}

$opt = [
    'addr' => '127.0.0.1:9002',
    'maxhdr' => MAX_HEADER,
    'drain' => true,
    'log' => true,
];
for ($i = 1; $i < $argc; $i++) {
    switch ($argv[$i]) {
        case '-addr':
            $opt['addr'] = $argv[++$i];
            break;
        case '-maxhdr':
            $opt['maxhdr'] = (int) $argv[++$i];
            break;
        case '-drain':
            $opt['drain'] = $argv[++$i] !== 'false';
            break;
        case '-log':
            $opt['log'] = $argv[++$i] !== 'false';
            break;
        default:
            fwrite(STDERR, "usage: php mini.php [-addr host:port] [-maxhdr N] [-drain true|false] [-log true|false]\n");
            exit(2);
    }
}

/**
 * 从缓冲区里解一块完整的 chunked body。
 * 返回 null = 还没收全；$consumed = -1 = 格式非法。
 */
function try_chunked(string $buf, int &$consumed): ?string
{
    $consumed = 0;
    $body = '';
    $p = 0;
    $len = strlen($buf);
    while (true) {
        $eol = strpos($buf, "\r\n", $p);
        if ($eol === false) {
            return null;
        }
        $line = substr($buf, $p, $eol - $p);
        $semi = strpos($line, ';');
        if ($semi !== false) {
            $line = substr($line, 0, $semi);
        }
        $line = trim($line);
        if ($line === '' || !preg_match('/^[0-9a-fA-F]+$/', $line)) {
            $consumed = -1;
            return null;
        }
        $size = (int) hexdec($line);
        $p = $eol + 2;
        if ($size === 0) {
            $end = strpos($buf, "\r\n", $p);
            if ($end === false) {
                return null;
            }
            $consumed = $end + 2;
            return $body;
        }
        if ($len < $p + $size + 2) {
            return null;
        }
        $body .= substr($buf, $p, $size);
        $p += $size;
        if (substr($buf, $p, 2) !== "\r\n") {
            $consumed = -1;
            return null;
        }
        $p += 2;
    }
}

function send_error(Conn $c, int $status, string $msg): void
{
    $err = ['proto' => 'HTTP/1.1', 'method' => 'GET'];
    $c->out .= build_response($err, $status, $msg . "\n", false);
    $c->closeAfterWrite = true;
    $c->state = 'done';
    if ($GLOBALS['opt']['log']) {
        log_line(sprintf('conn#%d -> %d %s', $c->id, $status, $msg));
    }
}

function dispatch(Conn $c, array $req, string $body): void
{
    global $opt;
    $resp = route($req, $body);
    $keep = !$req['close'];
    $c->out .= build_response($req, $resp[0], $resp[1], $keep);
    $c->reqs++;
    if ($opt['log']) {
        log_line(sprintf(
            'conn#%d req#%d %s %s %s cl=%d chunked=%s close=%s hdrBytes=%d',
            $c->id,
            $c->reqs,
            $req['method'],
            $req['path'],
            $req['proto'],
            $req['cl'],
            $req['chunked'] ? 'true' : 'false',
            $req['close'] ? 'true' : 'false',
            $req['hdrBytes']
        ));
        log_line(sprintf(
            'conn#%d req#%d %s %s -> %d (%d B)',
            $c->id,
            $c->reqs,
            $req['method'],
            $req['path'],
            $resp[0],
            strlen($resp[1])
        ));
    }
    if (!$keep) {
        $c->closeAfterWrite = true;
        $c->state = 'done';
        return;
    }
    $c->state = 'head';
    $c->deadline = microtime(true) + READ_HEADER_TO;
}

function pump(Conn $c): void
{
    global $opt;
    while (true) {
        if ($c->state === 'head') {
            $end = strpos($c->buf, "\r\n\r\n");
            // 严格模式：首部区只认 CRLF。判据是「每一个 \n 的前一字节必须是 \r」，
            // 而不是找 "\n\n"——后者漏得掉夹在首部中间的裸 LF。
            $limit = $end === false ? strlen($c->buf) : $end + 4;
            $eol = strpos($c->buf, "\n");
            while ($eol !== false && $eol < $limit) {
                if ($eol === 0 || $c->buf[$eol - 1] !== "\r") {
                    send_error($c, 400, 'bare LF line ending');
                    return;
                }
                $eol = strpos($c->buf, "\n", $eol + 1);
            }
            if ($end === false) {
                if (strlen($c->buf) > $opt['maxhdr']) {
                    send_error($c, 431, 'header section too large');
                }
                return;
            }
            if ($end + 4 > $opt['maxhdr']) {
                // 总量闸门：一万行小首部也在这里被拦住
                send_error($c, 431, 'header section too large');
                return;
            }
            $head = substr($c->buf, 0, $end + 4);
            $c->buf = substr($c->buf, $end + 4);
            $lines = explode("\r\n", substr($head, 0, -4));
            try {
                $req = parse_request_head($lines, strlen($head));
            } catch (HttpError $e) {
                send_error($c, $e->status, $e->getMessage());
                return;
            }
            if (!$opt['drain']) {
                // 故意不消费 body：下一轮解析会从 body 的第一个字节开始
                $req['cl'] = 0;
                $req['chunked'] = false;
            }
            $c->req = $req;
            if ($req['chunked']) {
                $c->state = 'chunked';
            } elseif ($req['cl'] > 0) {
                $c->state = 'body';
                $c->need = $req['cl'];
            } else {
                dispatch($c, $req, '');
                if ($c->state === 'done') {
                    return;
                }
            }
            continue;
        }

        if ($c->state === 'body') {
            if (strlen($c->buf) < $c->need) {
                return;
            }
            $body = substr($c->buf, 0, $c->need);
            $c->buf = substr($c->buf, $c->need);
            dispatch($c, $c->req, $body);
            if ($c->state === 'done') {
                return;
            }
            continue;
        }

        if ($c->state === 'chunked') {
            $consumed = 0;
            $body = try_chunked($c->buf, $consumed);
            if ($consumed === -1) {
                send_error($c, 400, 'malformed chunked body');
                return;
            }
            if ($body === null) {
                return;
            }
            $c->buf = substr($c->buf, $consumed);
            dispatch($c, $c->req, $body);
            if ($c->state === 'done') {
                return;
            }
            continue;
        }

        return;
    }
}

function close_conn(Conn $c, array &$conns): void
{
    @fclose($c->sock);
    unset($conns[$c->id]);
}

$srv = @stream_socket_server('tcp://' . $opt['addr'], $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN);
if ($srv === false) {
    fwrite(STDERR, "listen {$opt['addr']} failed: {$errstr} ({$errno})\n");
    exit(1);
}
stream_set_blocking($srv, false);

// php:8.0-cli 默认不带 pcntl，这里只能有则用、无则退回默认的信号行为
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGINT, function () {
        exit(0);
    });
    pcntl_signal(SIGTERM, function () {
        exit(0);
    });
}

log_line(sprintf('mini-php listening on %s pid=%d', $opt['addr'], getmypid()));

$conns = [];
$nextId = 0;

while (true) {
    $read = [$srv];
    $write = [];
    $bySock = [];
    foreach ($conns as $c) {
        $read[] = $c->sock;
        $bySock[(int) $c->sock] = $c->id;
        if ($c->out !== '') {
            $write[] = $c->sock;
        }
    }

    $wait = 1.0;
    foreach ($conns as $c) {
        if ($c->closeAfterWrite || $c->state === 'done') {
            continue;
        }
        $left = $c->deadline - microtime(true);
        $wait = min($wait, max(0.01, $left));
    }
    $sec = (int) floor($wait);
    $usec = (int) (($wait - $sec) * 1000000);
    $except = null;
    $n = @stream_select($read, $write, $except, $sec, $usec);
    if ($n === false) {
        continue;
    }

    if ($n > 0 && in_array($srv, $read, true)) {
        while (true) {
            $sock = @stream_socket_accept($srv, 0, $peer);
            if ($sock === false) {
                break;
            }
            stream_set_blocking($sock, false);
            $c = new Conn();
            $c->sock = $sock;
            $c->peer = $peer;
            $c->id = ++$nextId;
            $c->deadline = microtime(true) + READ_HEADER_TO;
            $conns[$c->id] = $c;
            if ($opt['log']) {
                log_line(sprintf('conn#%d accept from %s', $c->id, $peer));
            }
        }
    }

    foreach ($read as $sock) {
        if ($sock === $srv) {
            continue;
        }
        $id = $bySock[(int) $sock] ?? null;
        if ($id === null || !isset($conns[$id])) {
            continue;
        }
        $c = $conns[$id];
        $data = @fread($c->sock, 65536);
        if ($data === false) {
            close_conn($c, $conns);
            continue;
        }
        if ($data === '') {
            if (feof($c->sock)) {
                close_conn($c, $conns);
            }
            continue;
        }
        $c->buf .= $data;
        pump($c);
    }

    foreach ($write as $sock) {
        $id = $bySock[(int) $sock] ?? null;
        if ($id === null || !isset($conns[$id])) {
            continue;
        }
        $c = $conns[$id];
        if ($c->out === '') {
            continue;
        }
        $written = @fwrite($c->sock, $c->out);
        if ($written === false) {
            close_conn($c, $conns);
            continue;
        }
        if ($written > 0) {
            $c->out = substr($c->out, $written);
        }
    }

    // 到期：回 408 再关；已经要关的连接不重复判
    $now = microtime(true);
    foreach ($conns as $c) {
        if ($c->closeAfterWrite || $c->state === 'done' || $c->expired) {
            continue;
        }
        if ($now > $c->deadline) {
            $c->expired = true;
            send_error($c, 408, 'timeout while reading headers');
        }
    }

    foreach ($conns as $c) {
        if ($c->closeAfterWrite && $c->out === '') {
            close_conn($c, $conns);
        }
    }
}
