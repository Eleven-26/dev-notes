#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * 手工构造请求字节的探针：容器里没有 nc，curl 又表达不了
 * 「分两次发」「只发裸 LF」「首部 20 KB」「一次发三个」这些形状。
 */
$opt = [
    'addr' => '127.0.0.1:9002',
    'mode' => 'get',
    'size' => 20000,
    'split' => 20,
    'n' => 3,
    'data' => null,
    'idle' => 0.3,
    'delay' => 2.0,
];

for ($i = 1; $i < $argc; $i++) {
    switch ($argv[$i]) {
        case '-addr':
            $opt['addr'] = $argv[++$i];
            break;
        case '-mode':
            $opt['mode'] = $argv[++$i];
            break;
        case '-size':
            $opt['size'] = (int) $argv[++$i];
            break;
        case '-split':
            $opt['split'] = (int) $argv[++$i];
            break;
        case '-n':
            $opt['n'] = (int) $argv[++$i];
            break;
        case '-data':
            $opt['data'] = stripcslashes($argv[++$i]);
            break;
        case '-idle':
            $opt['idle'] = (float) $argv[++$i];
            break;
        case '-delay':
            $opt['delay'] = (float) $argv[++$i];
            break;
        default:
            fwrite(STDERR, "unknown option {$argv[$i]}\n");
            exit(2);
    }
}

function connect(string $addr)
{
    $s = @stream_socket_client('tcp://' . $addr, $errno, $errstr, 2);
    if ($s === false) {
        fwrite(STDERR, "connect {$addr} failed: {$errstr} ({$errno})\n");
        exit(1);
    }
    return $s;
}

/** 阻塞 socket 上 fwrite 可能只写一部分，大报文要循环写完。 */
function write_all($s, string $data): int
{
    $off = 0;
    $len = strlen($data);
    while ($off < $len) {
        $w = @fwrite($s, substr($data, $off));
        if ($w === false || $w === 0) {
            break;
        }
        $off += $w;
    }
    return $off;
}

/** 读到「一段时间没有新字节」为止，记下期间是否收到 EOF。 */
function read_idle($s, float $idle): array
{
    stream_set_timeout($s, (int) floor($idle), (int) (($idle - floor($idle)) * 1000000));
    $buf = '';
    $last = microtime(true);
    $closed = false;
    while (true) {
        $chunk = fread($s, 65536);
        if ($chunk !== false && $chunk !== '') {
            $buf .= $chunk;
            $last = microtime(true);
            continue;
        }
        if (feof($s)) {
            $closed = true;
            break;
        }
        if (microtime(true) - $last >= $idle) {
            break;
        }
    }
    return [$buf, $closed];
}

/** 按 Content-Length 精确读完一个响应，专供计时用。 */
function read_one_response($s): string
{
    stream_set_timeout($s, 2);
    $buf = '';
    $p = false;
    while ($p === false) {
        $chunk = fread($s, 65536);
        if ($chunk === false || $chunk === '') {
            return $buf;
        }
        $buf .= $chunk;
        $p = strpos($buf, "\r\n\r\n");
    }
    $head = substr($buf, 0, (int) $p);
    $len = 0;
    if (preg_match('/^Content-Length:\s*(\d+)/mi', $head, $m)) {
        $len = (int) $m[1];
    }
    $have = strlen($buf) - ((int) $p + 4);
    while ($have < $len) {
        $chunk = fread($s, $len - $have);
        if ($chunk === false || $chunk === '') {
            break;
        }
        $buf .= $chunk;
        $have += strlen($chunk);
    }
    return $buf;
}

function dump(string $label, string $req, string $resp, bool $closed): void
{
    printf("---- %s (%d bytes) ----\n", $label, strlen($req));
    printf("---- response (%d bytes, closed=%s) ----\n", strlen($resp), $closed ? 'true' : 'false');
    echo $resp;
    if ($resp !== '' && substr($resp, -1) !== "\n") {
        echo "\n";
    }
}

$get = "GET /hello HTTP/1.1\r\nHost: probe\r\n\r\n";

switch ($opt['mode']) {
    case 'get':
        $req = $get;
        break;
    case 'head':
        $req = "HEAD /hello HTTP/1.1\r\nHost: probe\r\n\r\n";
        break;
    case 'http10':
        $req = "GET /hello HTTP/1.0\r\n\r\n";
        break;
    case 'http10ka':
        $req = "GET /hello HTTP/1.0\r\nConnection: keep-alive\r\n\r\n";
        break;
    case 'chunked':
        $req = "POST /echo HTTP/1.1\r\nHost: probe\r\nTransfer-Encoding: chunked\r\n\r\n"
            . "5\r\nhello\r\n6\r\n world\r\n0\r\n\r\n";
        break;
    case 'tecl':
        $req = "POST /echo HTTP/1.1\r\nHost: probe\r\nContent-Length: 5\r\n"
            . "Transfer-Encoding: chunked\r\n\r\n5\r\nhello\r\n0\r\n\r\n";
        break;
    case 'lf':
        $req = "GET /hello HTTP/1.1\nHost: probe\n\n";
        break;
    case 'huge':
        $head = "GET /hello HTTP/1.1\r\nHost: probe\r\nX-Big: ";
        $tail = "\r\n\r\n";
        $req = $head . str_repeat('a', max(0, $opt['size'] - strlen($head) - strlen($tail))) . $tail;
        break;
    case 'many':
        $req = "GET /hello HTTP/1.1\r\nHost: probe\r\n";
        for ($i = 0; $i < $opt['n']; $i++) {
            $req .= sprintf("X-Pad-%d: aaaa\r\n", $i);
        }
        $req .= "\r\n";
        break;
    case 'longuri':
        $req = 'GET /' . str_repeat('a', max(0, $opt['size'])) . " HTTP/1.1\r\nHost: probe\r\n\r\n";
        break;
    case 'pipe':
        $req = str_repeat($get, $opt['n']);
        break;
    case 'drain':
        $req = "POST /hello HTTP/1.1\r\nHost: probe\r\nContent-Length: 5\r\n\r\nhello" . $get;
        break;
    case 'close':
        $req = "GET /hello HTTP/1.1\r\nHost: probe\r\nConnection: close\r\n\r\n";
        break;
    case 'split':
    case 'raw':
        $req = $opt['data'] ?? $get;
        break;
    case 'timeout':
    case 'seq':
    case 'timed':
    case 'hold':
    case 'slowhold':
        $req = '';
        break;
    default:
        fwrite(STDERR, "mode: get|head|http10|http10ka|chunked|tecl|lf|huge|many|longuri|pipe|drain|close|split|raw|timed|timeout|seq|hold|slowhold\n");
        exit(2);
}

if ($opt['mode'] === 'timeout') {
    $s = connect($opt['addr']);
    set_error_handler(function ($no, $str) {
        echo "[php] {$str}\n";
        return true;
    });
    $partial = "GET /hello HTTP/1.1\r\nHost: probe\r\nX-Slow: ";
    fwrite($s, $partial[0]);
    $sent = 1;
    for ($i = 0; $i < 9; $i++) {
        usleep(800000);
        $w = @fwrite($s, $partial[$sent]);
        if ($w === false) {
            echo "[php] write failed at byte {$sent}\n";
            break;
        }
        $sent++;
    }
    // 滴完之后把首部补完：绝对截止时间已经到点的服务端此时已经关了连接
    @fwrite($s, substr($partial, $sent) . "\r\n\r\n");
    [$resp, $closed] = read_idle($s, max(0.5, $opt['idle']));
    dump('slow header: 1 byte / 800ms', $partial . "\r\n\r\n", $resp, $closed);
    exit(0);
}

if ($opt['mode'] === 'seq') {
    $t0 = microtime(true);
    $last = '';
    for ($i = 0; $i < $opt['n']; $i++) {
        $s = connect($opt['addr']);
        fwrite($s, $get);
        $last = read_one_response($s);
        fclose($s);
    }
    $el = microtime(true) - $t0;
    printf("---- seq: %d new connections in %.3f s, avg %.3f ms, %.1f conn/s ----\n", $opt['n'], $el, $el * 1000 / $opt['n'], $opt['n'] / $el);
    printf("---- last response: %d bytes ----\n", strlen($last));
    exit(0);
}

if ($opt['mode'] === 'timed') {
    $s = connect($opt['addr']);
    stream_set_timeout($s, 10);
    $t0 = microtime(true);
    fwrite($s, $get);
    $buf = '';
    while (strpos($buf, "\r\n\r\n") === false) {
        $chunk = fread($s, 65536);
        if ($chunk === false || $chunk === '') {
            break;
        }
        $buf .= $chunk;
    }
    printf("---- timed: response head in %.1f ms (%d bytes) ----\n", (microtime(true) - $t0) * 1000, strlen($buf));
    exit(0);
}

if ($opt['mode'] === 'hold') {
    $socks = [];
    for ($i = 0; $i < $opt['n']; $i++) {
        $s = connect($opt['addr']);
        fwrite($s, $get);
        read_one_response($s);
        $socks[] = $s;
    }
    printf("---- hold: %d connections established, holding %.1f s ----\n", $opt['n'], $opt['delay']);
    sleep((int) $opt['delay']);
    foreach ($socks as $s) {
        fclose($s);
    }
    echo "---- hold: done ----\n";
    exit(0);
}

if ($opt['mode'] === 'slowhold') {
    $s = connect($opt['addr']);
    fwrite($s, "GET /hello HTTP/1.1\r\nHost: probe\r\n");
    printf("---- slowhold: partial header sent, holding %.1f s ----\n", $opt['delay']);
    usleep((int) ($opt['delay'] * 1000000));
    fwrite($s, "\r\n");
    [$resp, $closed] = read_idle($s, $opt['idle']);
    printf("---- slowhold: response %d bytes, closed=%s ----\n", strlen($resp), $closed ? 'true' : 'false');
    exit(0);
}

$s = connect($opt['addr']);
if ($opt['mode'] === 'split') {
    echo '---- first write: ' . write_all($s, substr($req, 0, $opt['split'])) . " bytes ----\n";
    usleep(200000);
    echo '---- second write: ' . write_all($s, substr($req, $opt['split'])) . " bytes ----\n";
} else {
    write_all($s, $req);
}
[$resp, $closed] = read_idle($s, $opt['idle']);
dump($opt['mode'] . ' request', $req, $resp, $closed);
