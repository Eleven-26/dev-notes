#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * 只读一次 + 十六进制 dump：看清「一次 Read 拿到的是什么」。
 */
$addr = $argv[1] ?? '127.0.0.1:9001';

function hexdump(string $s): string
{
    $out = '';
    $len = strlen($s);
    for ($off = 0; $off < $len; $off += 16) {
        $chunk = substr($s, $off, 16);
        $hex = '';
        for ($i = 0; $i < 16; $i++) {
            $hex .= $i < strlen($chunk) ? sprintf('%02x ', ord($chunk[$i])) : '   ';
            if ($i === 7) {
                $hex .= ' ';
            }
        }
        $asc = '';
        for ($i = 0; $i < strlen($chunk); $i++) {
            $o = ord($chunk[$i]);
            $asc .= ($o >= 32 && $o < 127) ? $chunk[$i] : '.';
        }
        $out .= sprintf("%08x  %s |%s|\n", $off, $hex, $asc);
    }
    return $out;
}

$srv = @stream_socket_server('tcp://' . $addr, $errno, $errstr);
if ($srv === false) {
    fwrite(STDERR, "listen {$addr} failed: {$errstr} ({$errno})\n");
    exit(1);
}
fwrite(STDERR, "raw listening on {$addr}\n");

for ($i = 1; $i <= 3; $i++) {
    $c = @stream_socket_accept($srv, 30, $peer);
    if ($c === false) {
        break;
    }
    $buf = fread($c, 65536);
    printf("===== conn#%d from %s  read=%d =====\n", $i, $peer, strlen((string) $buf));
    echo hexdump((string) $buf);
    fclose($c);
}
