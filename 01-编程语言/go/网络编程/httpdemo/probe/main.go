// probe：手工构造 HTTP 请求字节的探针。
// 因为容器里没有 nc，而 curl 又表达不了「分两次发」「用裸 LF」「首部 20 KB」这类场景。
package main

import (
	"bufio"
	"flag"
	"fmt"
	"io"
	"net"
	"os"
	"strconv"
	"strings"
	"time"
)

func show(b []byte) string {
	s := strings.ReplaceAll(string(b), "\r\n", "\\r\\n\n")
	s = strings.ReplaceAll(s, "\r", "\\r")
	// 20 KB 的首部行原样打出来没法看，超过 160 列直接截断
	var out []string
	for _, line := range strings.Split(s, "\n") {
		if len(line) > 160 {
			line = line[:160] + fmt.Sprintf("...(%d bytes)", len(line))
		}
		out = append(out, line)
	}
	return strings.Join(out, "\n")
}

// readResponse 读一条完整响应（到首个空行为首部结束，再按 Content-Length 读体）。
func readResponse(br *bufio.Reader, headOnly bool) ([]byte, error) {
	var out []byte
	cl := -1
	for {
		line, err := br.ReadString('\n')
		if err != nil {
			return out, err
		}
		out = append(out, line...)
		if line == "\r\n" || line == "\n" {
			break
		}
		if len(line) > 15 && strings.HasPrefix(strings.ToLower(line), "content-length:") {
			cl, _ = strconv.Atoi(strings.TrimSpace(line[15:]))
		}
	}
	// ⚠️ HEAD 的响应带 Content-Length 但没有 body，按 CL 读会一直等到超时
	if cl > 0 && !headOnly {
		buf := make([]byte, cl)
		if _, err := io.ReadFull(br, buf); err != nil {
			return out, err
		}
		out = append(out, buf...)
	}
	return out, nil
}

func mustDial(addr string) (net.Conn, *bufio.Reader) {
	c, err := net.DialTimeout("tcp", addr, 3*time.Second)
	if err != nil {
		fmt.Fprintln(os.Stderr, "dial:", err)
		os.Exit(1)
	}
	return c, bufio.NewReaderSize(c, 1<<16)
}

func main() {
	addr := flag.String("addr", "127.0.0.1:9002", "目标地址")
	mode := flag.String("mode", "get", "get|raw|split|huge|chunked|tecl|lf|keepalive|head|pipe|timeout|abs")
	data := flag.String("data", "", "raw/split 模式下的请求原文，支持 \\r\\n 转义")
	splitAt := flag.Int("split", 20, "split 模式下第一次发出的字节数")
	hugeSize := flag.Int("size", 20000, "huge 模式下首部总字节数")
	nreq := flag.Int("n", 3, "keepalive/pipe 模式的请求数")
	delay := flag.Duration("delay", 200*time.Millisecond, "split 模式两次发送的间隔")
	path := flag.String("path", "/hello", "路径")
	flag.Parse()

	var req []byte
	var second []byte
	switch *mode {
	case "get":
		req = []byte("GET " + *path + " HTTP/1.1\r\nHost: probe\r\n\r\n")
	case "head":
		req = []byte("HEAD /hello HTTP/1.1\r\nHost: probe\r\n\r\n")
	case "raw", "split":
		s, err := strconv.Unquote(`"` + *data + `"`)
		if err != nil {
			fmt.Fprintln(os.Stderr, "data unquote:", err)
			os.Exit(1)
		}
		if *mode == "split" && *splitAt < len(s) {
			req, second = []byte(s[:*splitAt]), []byte(s[*splitAt:])
		} else {
			req = []byte(s)
		}
	case "huge":
		// 一个 20 KB 的首部值
		v := strings.Repeat("a", *hugeSize)
		req = []byte("GET /hello HTTP/1.1\r\nHost: probe\r\nX-Big: " + v + "\r\n\r\n")
	case "chunked":
		req = []byte("POST /echo HTTP/1.1\r\nHost: probe\r\nTransfer-Encoding: chunked\r\n\r\n" +
			"5\r\nhello\r\n" + "6\r\n world\r\n" + "0\r\n\r\n")
	case "tecl":
		// Transfer-Encoding 与 Content-Length 同时给：请求走私的经典入口
		req = []byte("POST /echo HTTP/1.1\r\nHost: probe\r\nTransfer-Encoding: chunked\r\nContent-Length: 5\r\n\r\n0\r\n\r\n")
	case "lf":
		// 裸 LF，没有 CR
		req = []byte("GET /hello HTTP/1.1\nHost: probe\n\n")
	case "abs":
		// absolute-form，代理常这么发
		req = []byte("GET http://probe/hello HTTP/1.1\r\nHost: probe\r\n\r\n")
	case "keepalive":
		req = []byte("GET " + *path + " HTTP/1.1\r\nHost: probe\r\n\r\n")
	case "pipe":
		if *data != "" {
			// 用 -data 指定整段字节，一次 Write 出去后再读 n 个响应
			s, err := strconv.Unquote(`"` + *data + `"`)
			if err != nil {
				fmt.Fprintln(os.Stderr, "data unquote:", err)
				os.Exit(1)
			}
			req = []byte(s)
		} else {
			for i := 0; i < *nreq; i++ {
				req = append(req, []byte("GET "+*path+" HTTP/1.1\r\nHost: probe\r\n\r\n")...)
			}
		}
	case "timeout":
		// 一个字节一个字节地发，模拟慢速首部（Slowloris 的形态）
		req = []byte("G")
	default:
		fmt.Fprintln(os.Stderr, "unknown mode:", *mode)
		os.Exit(1)
	}

	c, br := mustDial(*addr)
	defer c.Close()

	fmt.Printf("---- %s request (%d bytes) ----\n%s", *mode, len(req), show(req))
	if len(second) > 0 {
		fmt.Printf("---- second chunk (%d bytes), sent after %v ----\n%s", len(second), *delay, show(second))
	}

	if *mode == "timeout" {
		// 每 800ms 发一个字节，一共 8 个字节，看服务端 ReadHeaderTimeout 何时掐断
		for i := 0; i < 8; i++ {
			if _, err := c.Write([]byte("GET /hello HTTP/1.1\r\n"[i : i+1])); err != nil {
				fmt.Printf("[write stopped at byte %d] %v\n", i, err)
				break
			}
			time.Sleep(800 * time.Millisecond)
		}
		if _, err := c.Write([]byte("ost: probe\r\n\r\n")); err != nil {
			fmt.Printf("[write rest] %v\n", err)
		}
	} else {
		if _, err := c.Write(req); err != nil {
			fmt.Fprintf(os.Stderr, "write: %v\n", err)
			return
		}
		if len(second) > 0 {
			time.Sleep(*delay)
			if _, err := c.Write(second); err != nil {
				fmt.Fprintf(os.Stderr, "write2: %v\n", err)
				return
			}
		}
	}

	rounds := 1
	if *mode == "keepalive" {
		rounds = *nreq
	}
	if *mode == "pipe" {
		rounds = *nreq
	}
	for i := 0; i < rounds; i++ {
		c.SetReadDeadline(time.Now().Add(4 * time.Second))
		resp, err := readResponse(br, *mode == "head")
		fmt.Printf("---- response #%d (%d bytes, err=%v) ----\n%s", i+1, len(resp), err, show(resp))
		if err != nil {
			break
		}
		if *mode == "keepalive" && i+1 < rounds {
			if _, err := c.Write(req); err != nil {
				fmt.Printf("[rewrite #%d] %v\n", i+1, err)
				break
			}
		}
	}
}
