// minihttp：从零手写 HTTP/1.1 服务端。
//
// 硬约束：不 import net/http（也不 import net/url、net/textproto）。
// 只用 net、bufio、io、strconv、strings、time 这些「更底层」的包。
// 目的不是造轮子，而是把标准库替你挡掉的东西一件件摊开。
package main

import (
	"bufio"
	"errors"
	"flag"
	"fmt"
	"io"
	"log"
	"net"
	"os"
	"strconv"
	"strings"
	"sync/atomic"
	"time"
)

const (
	maxLineBytes     = 8 << 10 // 单行（请求行 / 单个首部行）上限
	readHeaderTO     = 5 * time.Second
	writeTimeout     = 10 * time.Second
	statusLineFormat = "%s %d %s\r\n"
	// ⚠️ 这里连 http.TimeFormat 都不能用，字面量照抄 RFC 7231 的 IMF-fixdate
	dateFormat = "Mon, 02 Jan 2006 15:04:05 GMT"
)

// noDrain 打开后故意不读也不排空请求体，用来演示 keep-alive 下的解析错位。
var noDrain bool

var (
	errBadRequest        = errors.New("malformed HTTP request")
	errHeaderTooLarge    = errors.New("request header section too large")
	errHeaderLineTooLong = errors.New("single header line too long")
	errURITooLong        = errors.New("request line too long")
	errLineTooLong       = errors.New("line too long")
)

// ---------- 请求结构 ----------

type Request struct {
	Method        string
	Target        string // 原始 request-target（可能是 origin-form 或 absolute-form）
	Path          string // target 去掉 ?query 的部分
	Query         string
	Proto         string
	ProtoMajor    int
	ProtoMinor    int
	Header        map[string][]string // key 统一小写，同名首部 append 而不是覆盖
	Body          io.Reader
	ContentLength int64 // -1 表示 chunked（长度未知）
	Chunked       bool
	Close         bool // 本次响应后是否关连接
	HeaderBytes   int  // 首部区实际消耗的字节数
}

func (r *Request) Get(key string) string {
	if v := r.Header[strings.ToLower(key)]; len(v) > 0 {
		return v[0]
	}
	return ""
}

// HasToken 判断逗号分隔的首部值里有没有某个 token。
// ⚠️ Connection 的值可能是 "keep-alive, Upgrade" 这种列表，不能整串比较。
func (r *Request) HasToken(key, token string) bool {
	for _, v := range r.Header[strings.ToLower(key)] {
		for _, part := range strings.Split(v, ",") {
			if strings.EqualFold(strings.TrimSpace(part), token) {
				return true
			}
		}
	}
	return false
}

// ---------- 逐行读 ----------

// readLine 读一行并以 CRLF 结尾。返回内容不含 CRLF。
// ⚠️ bufio.ReadSlice 返回的切片指向内部缓冲，下次读即失效 —— 必须立刻转 string 复制一份。
func readLine(br *bufio.Reader) (string, error) {
	line, err := br.ReadSlice('\n')
	if errors.Is(err, bufio.ErrBufferFull) {
		return "", errLineTooLong
	}
	if err != nil {
		return "", err
	}
	n := len(line)
	if n < 2 || line[n-2] != '\r' {
		// 严格模式：只认 CRLF。裸 LF 在这里被判为畸形请求。
		return "", errBadRequest
	}
	return string(line[:n-2]), nil
}

func isToken(s string) bool {
	if s == "" {
		return false
	}
	for i := 0; i < len(s); i++ {
		c := s[i]
		switch {
		case c >= 'a' && c <= 'z', c >= 'A' && c <= 'Z', c >= '0' && c <= '9':
		case strings.IndexByte("!#$%&'*+-.^_`|~", c) >= 0:
		default:
			return false
		}
	}
	return true
}

// ---------- 解析请求行 + 首部 ----------

func parseRequest(br *bufio.Reader, maxHeader int) (*Request, error) {
	line, err := readLine(br)
	if errors.Is(err, errLineTooLong) {
		return nil, errURITooLong
	}
	if err != nil {
		return nil, err
	}
	// 请求行：method SP request-target SP HTTP-version
	parts := strings.SplitN(line, " ", 3)
	if len(parts) != 3 {
		return nil, errBadRequest
	}
	req := &Request{
		Method:        parts[0],
		Target:        parts[1],
		Proto:         parts[2],
		Header:        make(map[string][]string),
		ContentLength: -1,
	}
	if !isToken(req.Method) {
		return nil, errBadRequest
	}
	if !strings.HasPrefix(req.Proto, "HTTP/") {
		return nil, errBadRequest
	}
	if _, err := fmt.Sscanf(req.Proto, "HTTP/%d.%d", &req.ProtoMajor, &req.ProtoMinor); err != nil {
		return nil, errBadRequest
	}
	if req.ProtoMajor != 1 {
		return nil, errBadRequest // 本实现只做 HTTP/1.x
	}

	// target 拆 path / query。absolute-form（http://host/path）也要能认。
	target := req.Target
	if i := strings.Index(target, "://"); i >= 0 {
		rest := target[i+3:]
		if j := strings.IndexByte(rest, '/'); j >= 0 {
			target = rest[j:]
		} else {
			target = "/"
		}
	}
	if k := strings.IndexByte(target, '?'); k >= 0 {
		req.Path, req.Query = target[:k], target[k+1:]
	} else {
		req.Path = target
	}

	// 首部区
	consumed := len(line) + 2
	for {
		line, err := readLine(br)
		if errors.Is(err, errLineTooLong) {
			return nil, errHeaderLineTooLong
		}
		if err != nil {
			return nil, err
		}
		consumed += len(line) + 2
		if consumed > maxHeader {
			return nil, errHeaderTooLarge
		}
		if line == "" {
			break
		}
		i := strings.IndexByte(line, ':')
		if i <= 0 {
			return nil, errBadRequest
		}
		name := line[:i]
		if !isToken(name) {
			return nil, errBadRequest // 名字与冒号之间有空格会落在这里
		}
		val := strings.Trim(line[i+1:], " \t")
		key := strings.ToLower(name)
		req.Header[key] = append(req.Header[key], val)
	}
	req.HeaderBytes = consumed

	// 连接语义：1.1 默认持久，1.0 默认关闭
	if req.ProtoMinor >= 1 {
		req.Close = req.HasToken("Connection", "close")
	} else {
		req.Close = !req.HasToken("Connection", "keep-alive")
	}

	// body 定界。⚠️ 顺序有讲究：「两者同给」必须在解析 CL 之前判掉。
	// 第一版把这段写在 CL 解析之前却用 req.ContentLength 判断，
	// 那时它还是初始值 -1，于是 TE+CL 的走私样例被当成正常 chunked 请求放过去了。
	hasTE := len(req.Header["transfer-encoding"]) > 0
	hasCL := len(req.Header["content-length"]) > 0
	if hasTE && hasCL {
		// 两者同给是请求走私的经典入口，RFC 7230 §3.3.3 要求当错误处理
		return nil, errBadRequest
	}
	if te := req.Header["transfer-encoding"]; hasTE {
		last := strings.ToLower(strings.TrimSpace(te[len(te)-1]))
		if last != "chunked" {
			return nil, errBadRequest
		}
		req.Chunked = true
		req.ContentLength = -1
		req.Body = newChunkedReader(br)
		return req, nil
	}

	if cls := req.Header["content-length"]; len(cls) > 0 {
		for _, v := range cls[1:] {
			if v != cls[0] { // 多个 CL 且值不同 → 拒绝
				return nil, errBadRequest
			}
		}
		n, err := strconv.ParseInt(cls[0], 10, 64)
		if err != nil || n < 0 {
			return nil, errBadRequest
		}
		req.ContentLength = n
		req.Body = io.LimitReader(br, n)
		return req, nil
	}

	// 既无 TE 也无 CL：请求没有 body
	req.ContentLength = 0
	req.Body = strings.NewReader("")
	return req, nil
}

// ---------- chunked 解码 ----------

type chunkedReader struct {
	br     *bufio.Reader
	remain int64
	done   bool
	chunks int
}

func newChunkedReader(br *bufio.Reader) *chunkedReader { return &chunkedReader{br: br} }

func (c *chunkedReader) Read(p []byte) (int, error) {
	if c.done {
		return 0, io.EOF
	}
	if c.remain == 0 {
		line, err := readLine(c.br)
		if err != nil {
			return 0, err
		}
		// chunk-size 是十六进制，后面可能跟 ";name=value" 扩展
		if i := strings.IndexByte(line, ';'); i >= 0 {
			line = line[:i]
		}
		size, err := strconv.ParseInt(strings.TrimSpace(line), 16, 64)
		if err != nil || size < 0 {
			return 0, errBadRequest
		}
		if size == 0 {
			// 末块：吃掉 trailer 首部直到空行
			for {
				l, err := readLine(c.br)
				if err != nil {
					return 0, err
				}
				if l == "" {
					break
				}
			}
			c.done = true
			return 0, io.EOF
		}
		c.remain = size
		c.chunks++
	}
	if int64(len(p)) > c.remain {
		p = p[:c.remain]
	}
	n, err := c.br.Read(p)
	c.remain -= int64(n)
	if c.remain == 0 {
		// ⚠️ 每块数据后面还有一个 CRLF，漏吃会让下一块尺寸解析错位
		if _, e := readLine(c.br); e != nil && e != io.EOF {
			return n, e
		}
	}
	return n, err
}

// ---------- 写响应 ----------

type response struct {
	status int
	reason string
	body   []byte
	ctype  string
	extra  [][2]string
}

func writeResponse(bw *bufio.Writer, r *Request, resp *response, keepAlive bool) error {
	headOnly := r.Method == "HEAD"
	// ⚠️ 首部必须在第一次 Write 之前全部拼好；先写 body 再补首部是无效的
	fmt.Fprintf(bw, statusLineFormat, r.Proto, resp.status, resp.reason)
	fmt.Fprintf(bw, "Date: %s\r\n", time.Now().UTC().Format(dateFormat))
	if resp.ctype != "" {
		fmt.Fprintf(bw, "Content-Type: %s\r\n", resp.ctype)
	}
	fmt.Fprintf(bw, "Content-Length: %d\r\n", len(resp.body))
	for _, kv := range resp.extra {
		fmt.Fprintf(bw, "%s: %s\r\n", kv[0], kv[1])
	}
	// ⚠️ 关连接要显式告知，客户端才会放弃复用
	if keepAlive {
		io.WriteString(bw, "Connection: keep-alive\r\n")
	} else {
		io.WriteString(bw, "Connection: close\r\n")
	}
	io.WriteString(bw, "\r\n")
	if !headOnly {
		bw.Write(resp.body)
	}
	// ⚠️ bufio.Writer 不 Flush，响应就一直卡在用户态缓冲里
	return bw.Flush()
}

func notFound(r *Request) *response {
	return &response{
		status: 404, reason: "Not Found",
		ctype: "text/plain; charset=utf-8",
		body:  []byte("404 page not found\n"),
	}
}

// ---------- 路由 ----------

func route(r *Request, body []byte) *response {
	switch r.Path {
	case "/hello":
		return &response{
			status: 200, reason: "OK",
			ctype: "text/plain; charset=utf-8",
			body:  []byte("hello\n"),
		}
	case "/echo":
		// 把解析出来的东西原样回显，用于验证 chunked / CL 解对了没有
		s := fmt.Sprintf("method=%s path=%s query=%s cl=%d chunked=%v bytes=%d\n%s",
			r.Method, r.Path, r.Query, r.ContentLength, r.Chunked, len(body), body)
		return &response{
			status: 200, reason: "OK",
			ctype: "text/plain; charset=utf-8",
			body:  []byte(s),
		}
	case "/sleep":
		time.Sleep(2 * time.Second)
		return &response{status: 200, reason: "OK", ctype: "text/plain", body: []byte("slept\n")}
	}
	return notFound(r)
}

// ---------- 连接循环 ----------

var connSeq, reqSeq atomic.Int64

func serveConn(c net.Conn, maxHeader int, verbose, accessLog bool) {
	defer c.Close()
	id := connSeq.Add(1)
	br := bufio.NewReaderSize(c, maxLineBytes)
	bw := bufio.NewWriterSize(c, 4096)

	for n := 1; ; n++ {
		c.SetReadDeadline(time.Now().Add(readHeaderTO))
		req, err := parseRequest(br, maxHeader)
		if err != nil {
			status, reason := 400, "Bad Request"
			switch {
			case errors.Is(err, errHeaderLineTooLong), errors.Is(err, errHeaderTooLarge):
				status, reason = 431, "Request Header Fields Too Large"
			case errors.Is(err, errURITooLong):
				status, reason = 414, "URI Too Long"
			case errors.Is(err, os.ErrDeadlineExceeded):
				status, reason = 408, "Request Timeout"
				log.Printf("conn#%d req#%d %v", id, n, err)
				writeBare(bw, status, reason)
				return
			case errors.Is(err, io.EOF):
				return // 对端正常关闭，不打日志
			}
			log.Printf("conn#%d req#%d parse error: %v", id, n, err)
			writeBare(bw, status, reason)
			return
		}
		c.SetReadDeadline(time.Time{})
		c.SetWriteDeadline(time.Now().Add(writeTimeout))

		if verbose {
			log.Printf("conn#%d req#%d %s %s %s cl=%d chunked=%v close=%v hdrBytes=%d",
				id, n, req.Method, req.Target, req.Proto,
				req.ContentLength, req.Chunked, req.Close, req.HeaderBytes)
		}

		// 读 body。handler 不读时也必须排空，否则下一个请求从残字节开始解析。
		var body []byte
		var rerr error
		if noDrain && req.Path != "/echo" {
			// 故意什么都不做：既不读也不排空。请求体留在 br 里，下一个请求会串味。
		} else {
			body, rerr = io.ReadAll(req.Body)
		}
		if rerr != nil {
			log.Printf("conn#%d req#%d body error: %v", id, n, rerr)
			writeBare(bw, 400, "Bad Request")
			return
		}
		reqSeq.Add(1)

		resp := route(req, body)
		keepAlive := !req.Close
		if err := writeResponse(bw, req, resp, keepAlive); err != nil {
			log.Printf("conn#%d req#%d write error: %v", id, n, err)
			return
		}
		if accessLog {
			log.Printf("conn#%d req#%d %s %s -> %d (%d B)", id, n, req.Method, req.Path, resp.status, len(resp.body))
		}
		if !keepAlive {
			return
		}
	}
}

func writeBare(bw *bufio.Writer, status int, reason string) {
	fmt.Fprintf(bw, "HTTP/1.1 %d %s\r\n", status, reason)
	fmt.Fprintf(bw, "Date: %s\r\n", time.Now().UTC().Format(dateFormat))
	io.WriteString(bw, "Content-Type: text/plain; charset=utf-8\r\n")
	io.WriteString(bw, "Content-Length: 0\r\nConnection: close\r\n\r\n")
	bw.Flush()
}

func main() {
	addr := flag.String("addr", ":9002", "监听地址")
	maxHeader := flag.Int("maxhdr", 16<<10, "首部区字节上限")
	verbose := flag.Bool("v", true, "打印每个请求的解析结果")
	accessLog := flag.Bool("log", true, "打印访问日志")
	drain := flag.Bool("drain", true, "读完并排空请求体；置 false 演示串味")
	flag.Parse()
	noDrain = !*drain

	ln, err := net.Listen("tcp", *addr)
	if err != nil {
		fmt.Fprintln(os.Stderr, "listen:", err)
		os.Exit(1)
	}
	log.Printf("minihttp listening on %s (maxHeaderBytes=%d)  [no net/http]", ln.Addr(), *maxHeader)
	for {
		c, err := ln.Accept()
		if err != nil {
			log.Println("accept:", err)
			return
		}
		go serveConn(c, *maxHeader, *verbose, *accessLog)
	}
}
