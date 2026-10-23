// rawdump：不做任何解析，只把 TCP 上收到的原始字节 dump 出来。
// 目的是先看清「一个 HTTP 请求在线上到底长什么样」，再决定解析器要处理哪些形态。
package main

import (
	"flag"
	"fmt"
	"io"
	"net"
	"os"
	"strings"
)

func hexdump(b []byte) string {
	var sb strings.Builder
	for off := 0; off < len(b); off += 16 {
		end := min(off+16, len(b))
		chunk := b[off:end]
		sb.WriteString(fmt.Sprintf("%08x  ", off))
		for i := 0; i < 16; i++ {
			if i < len(chunk) {
				sb.WriteString(fmt.Sprintf("%02x ", chunk[i]))
			} else {
				sb.WriteString("   ")
			}
			if i == 7 {
				sb.WriteString(" ")
			}
		}
		sb.WriteString(" |")
		for _, c := range chunk {
			if c >= 0x20 && c < 0x7f {
				sb.WriteByte(c)
			} else {
				sb.WriteByte('.')
			}
		}
		sb.WriteString("|\n")
	}
	return sb.String()
}

func main() {
	addr := flag.String("addr", ":9001", "监听地址")
	flag.Parse()

	ln, err := net.Listen("tcp", *addr)
	if err != nil {
		fmt.Fprintln(os.Stderr, "listen:", err)
		os.Exit(1)
	}
	fmt.Printf("rawdump listening on %s\n", ln.Addr())

	n := 0
	for {
		c, err := ln.Accept()
		if err != nil {
			fmt.Fprintln(os.Stderr, "accept:", err)
			return
		}
		n++
		connID := n
		go func(c net.Conn) {
			defer c.Close()
			buf := make([]byte, 16384)
			// 只读一次：看 TCP 交付的第一批字节是什么样
			rn, rerr := c.Read(buf)
			fmt.Printf("\n===== conn#%d from %s  read=%d err=%v =====\n",
				connID, c.RemoteAddr(), rn, rerr)
			fmt.Print(hexdump(buf[:rn]))
			// 回一个最小合法响应，好让 curl 正常退出
			io.WriteString(c, "HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: close\r\n\r\nok")
		}(c)
	}
}
