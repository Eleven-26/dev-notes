<?php
declare(strict_types=1);

/**
 * php -S 内置服务器的路由脚本：只为了和手写实现打同一条请求做对照。
 */
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if ($path === '/hello') {
    header('Content-Type: text/plain; charset=utf-8');
    echo 'hello';
    return;
}

if ($path === '/echo') {
    header('Content-Type: text/plain; charset=utf-8');
    $body = file_get_contents('php://input');
    printf(
        "method=%s path=%s query=%s cl=%s chunked=%s bytes=%d\n%s",
        $_SERVER['REQUEST_METHOD'] ?? '',
        $path,
        $_SERVER['QUERY_STRING'] ?? '',
        $_SERVER['CONTENT_LENGTH'] ?? '-1',
        isset($_SERVER['HTTP_TRANSFER_ENCODING']) ? 'true' : 'false',
        strlen((string) $body),
        (string) $body
    );
    return;
}

if ($path === '/no-content') {
    http_response_code(204);
    return;
}

if ($path === '/sleep') {
    $ms = isset($_GET['ms']) ? min(3000, (int) $_GET['ms']) : 0;
    usleep($ms * 1000);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'slept ' . $ms . "ms\n";
    return;
}

http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo "404 page not found\n";
