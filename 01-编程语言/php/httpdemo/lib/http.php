<?php
declare(strict_types=1);

/**
 * 手写 HTTP/1.1 的共用层：报文头解析、三种定界、响应拼装、路由。
 * 服务端与探针共用同一份判断，线上看到的行为就是这里写的行为。
 */

const MAX_HEADER = 16384;
const MAX_LINE = 8192;
const READ_HEADER_TO = 5;

final class HttpError extends Exception
{
    public int $status;

    public function __construct(int $status, string $message)
    {
        $this->status = $status;
        parent::__construct($message);
    }
}

final class ConnEof extends Exception
{
}

function is_token(string $s): bool
{
    return (bool) preg_match('/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/', $s);
}

function reason_phrase(int $status): string
{
    switch ($status) {
        case 200:
            return 'OK';
        case 204:
            return 'No Content';
        case 304:
            return 'Not Modified';
        case 400:
            return 'Bad Request';
        case 404:
            return 'Not Found';
        case 408:
            return 'Request Timeout';
        case 413:
            return 'Payload Too Large';
        case 414:
            return 'URI Too Long';
        case 431:
            return 'Request Header Fields Too Large';
        default:
            return 'Internal Server Error';
    }
}

function has_token(array $headers, string $name, string $token): bool
{
    foreach ($headers[$name] ?? [] as $value) {
        foreach (explode(',', $value) as $item) {
            if (strcasecmp(trim($item), $token) === 0) {
                return true;
            }
        }
    }
    return false;
}

/**
 * 把「首部区」（请求行 + 首部行，不含结尾空行）解析成请求数组。
 * 所有畸形都在这里被判掉，调用方只需把 HttpError->status 回给客户端。
 */
function parse_request_head(array $lines, int $hdrBytes): array
{
    if ($lines === []) {
        throw new HttpError(400, 'empty request');
    }

    // 请求行：method SP request-target SP HTTP-version，必须是单个空格
    $requestLine = $lines[0];
    if (strlen($requestLine) > MAX_LINE) {
        throw new HttpError(414, 'request line too long');
    }
    $parts = explode(' ', $requestLine);
    if (count($parts) !== 3 || in_array('', $parts, true)) {
        throw new HttpError(400, 'malformed request line');
    }
    [$method, $target, $proto] = $parts;
    if (!is_token($method)) {
        throw new HttpError(400, 'method is not a token');
    }
    if (!preg_match('#^HTTP/(\d)\.(\d)$#', $proto, $m)) {
        throw new HttpError(400, 'malformed HTTP version');
    }
    $major = (int) $m[1];
    $minor = (int) $m[2];
    if ($major !== 1) {
        throw new HttpError(400, 'only HTTP/1.x is supported');
    }

    // request-target 三种形态：origin-form / absolute-form / asterisk-form
    $path = $target;
    $query = '';
    $schemeAt = strpos($path, '://');
    if ($schemeAt !== false) {
        $rest = substr($path, $schemeAt + 3);
        $slash = strpos($rest, '/');
        $path = $slash === false ? '/' : substr($rest, $slash);
    }
    $q = strpos($path, '?');
    if ($q !== false) {
        $query = substr($path, $q + 1);
        $path = substr($path, 0, $q);
    }

    $headers = [];
    foreach (array_slice($lines, 1) as $line) {
        if (strlen($line) > MAX_LINE) {
            throw new HttpError(431, 'header line too long');
        }
        if ($line === '') {
            throw new HttpError(400, 'unexpected empty header line');
        }
        if ($line[0] === ' ' || $line[0] === "\t") {
            // obs-fold：RFC 9112 明确废弃，这里按「拒绝」这条路走
            throw new HttpError(400, 'obsolete line folding');
        }
        $colon = strpos($line, ':');
        if ($colon === false || $colon === 0) {
            throw new HttpError(400, 'header without colon');
        }
        $name = substr($line, 0, $colon);
        if (!is_token($name)) {
            // 名字与冒号之间多了空格会落在这里
            throw new HttpError(400, 'header name is not a token');
        }
        $value = trim(substr($line, $colon + 1), " \t");
        $headers[strtolower($name)][] = $value;
    }

    // 请求体定界：TE 与 CL 同时出现必须直接拒绝，且这条判断要在解析 CL 之前
    $hasTE = isset($headers['transfer-encoding']);
    $hasCL = isset($headers['content-length']);
    if ($hasTE && $hasCL) {
        throw new HttpError(400, 'Transfer-Encoding with Content-Length');
    }

    $chunked = false;
    $contentLength = -1;
    if ($hasTE) {
        $te = strtolower(trim(implode(',', $headers['transfer-encoding'])));
        if ($te !== 'chunked') {
            throw new HttpError(400, 'unsupported transfer-encoding');
        }
        $chunked = true;
    }
    if ($hasCL) {
        $values = $headers['content-length'];
        foreach (array_slice($values, 1) as $value) {
            if ($value !== $values[0]) {
                throw new HttpError(400, 'conflicting Content-Length');
            }
        }
        if (!preg_match('/^\d+$/', $values[0])) {
            throw new HttpError(400, 'Content-Length is not a decimal number');
        }
        $contentLength = (int) $values[0];
    }

    // HTTP/1.1 默认持久，HTTP/1.0 默认关闭；Connection 的值是逗号列表
    $close = $minor >= 1
        ? has_token($headers, 'connection', 'close')
        : !has_token($headers, 'connection', 'keep-alive');

    return [
        'method' => $method,
        'target' => $target,
        'proto' => $proto,
        'major' => $major,
        'minor' => $minor,
        'path' => $path,
        'query' => $query,
        'headers' => $headers,
        'chunked' => $chunked,
        'cl' => $contentLength,
        'close' => $close,
        'hdrBytes' => $hdrBytes,
    ];
}

/** 五件套 + 三个「不写 body」的特例。 */
function build_response(array $req, int $status, string $body, bool $keepAlive): string
{
    $headOnly = $req['method'] === 'HEAD' || $status === 204 || $status === 304;
    $out = sprintf("%s %d %s\r\n", $req['proto'], $status, reason_phrase($status));
    $out .= 'Date: ' . gmdate('D, d M Y H:i:s') . " GMT\r\n";
    $out .= "Content-Type: text/plain; charset=utf-8\r\n";
    $out .= 'Content-Length: ' . strlen($body) . "\r\n";
    $out .= $keepAlive ? "Connection: keep-alive\r\n" : "Connection: close\r\n";
    $out .= "\r\n";
    if (!$headOnly) {
        $out .= $body;
    }
    return $out;
}

/** 路由：只有三个端点，够实验用。 */
function route(array $req, string $body): array
{
    switch ($req['path']) {
        case '/hello':
            return [200, 'hello'];
        case '/echo':
            return [200, sprintf(
                "method=%s path=%s query=%s cl=%d chunked=%s bytes=%d\n%s",
                $req['method'],
                $req['path'],
                $req['query'],
                $req['cl'],
                $req['chunked'] ? 'true' : 'false',
                strlen($body),
                $body
            )];
        case '/no-content':
            return [204, ''];
        case '/not-modified':
            return [304, ''];
        case '/sleep':
            // 只为验证「handler 里阻塞会拖住整个事件循环」，最多 3 秒
            $ms = 0;
            if (preg_match('/(?:^|&)ms=(\d+)/', $req['query'], $m)) {
                $ms = min(3000, (int) $m[1]);
            }
            usleep($ms * 1000);
            return [200, 'slept ' . $ms . "ms\n"];
        default:
            return [404, "404 page not found\n"];
    }
}

function log_line(string $msg): void
{
    fwrite(STDERR, $msg . "\n");
}
