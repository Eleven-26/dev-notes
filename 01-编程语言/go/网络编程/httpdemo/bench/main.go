// bench：极简 keep-alive 压测客户端，用来对照 minihttp 与 net/http 的吞吐。
package main

import (
	"bufio"
	"flag"
	"fmt"
	"io"
	"net"
	"sort"
	"strconv"
	"strings"
	"sync"
	"sync/atomic"
	"time"
)

func main() {
	addr := flag.String("addr", "127.0.0.1:9002", "目标地址")
	conc := flag.Int("c", 8, "并发连接数")
	total := flag.Int("n", 20000, "总请求数")
	path := flag.String("path", "/hello", "请求路径")
	flag.Parse()

	per := *total / *conc
	if per == 0 {
		per = 1
	}
	var wg sync.WaitGroup
	var errs atomic.Int64
	lats := make([][]time.Duration, *conc)

	start := time.Now()
	for i := 0; i < *conc; i++ {
		wg.Add(1)
		go func(i int) {
			defer wg.Done()
			lats[i] = make([]time.Duration, 0, per)
			c, err := net.DialTimeout("tcp", *addr, 3*time.Second)
			if err != nil {
				errs.Add(int64(per))
				return
			}
			defer c.Close()
			br := bufio.NewReaderSize(c, 8192)
			bw := bufio.NewWriterSize(c, 4096)
			for j := 0; j < per; j++ {
				t0 := time.Now()
				fmt.Fprintf(bw, "GET %s HTTP/1.1\r\nHost: bench\r\n\r\n", *path)
				if err := bw.Flush(); err != nil {
					errs.Add(int64(per - j))
					return
				}
				cl, ok := -1, true
				for {
					line, err := br.ReadString('\n')
					if err != nil {
						ok = false
						break
					}
					if line == "\r\n" {
						break
					}
					if len(line) > 15 && strings.HasPrefix(strings.ToLower(line), "content-length:") {
						cl, _ = strconv.Atoi(strings.TrimSpace(line[15:]))
					}
				}
				if !ok {
					errs.Add(int64(per - j))
					return
				}
				if cl > 0 {
					if _, err := io.CopyN(io.Discard, br, int64(cl)); err != nil {
						errs.Add(int64(per - j))
						return
					}
				}
				lats[i] = append(lats[i], time.Since(t0))
			}
		}(i)
	}
	wg.Wait()
	elapsed := time.Since(start)

	all := make([]time.Duration, 0, per*(*conc))
	for _, s := range lats {
		all = append(all, s...)
	}
	sort.Slice(all, func(a, b int) bool { return all[a] < all[b] })
	pick := func(q float64) time.Duration {
		if len(all) == 0 {
			return 0
		}
		i := int(float64(len(all)) * q)
		if i >= len(all) {
			i = len(all) - 1
		}
		return all[i]
	}
	n := len(all)
	fmt.Printf("addr=%s c=%d n=%d path=%s\n", *addr, *conc, n, *path)
	fmt.Printf("elapsed=%v ok=%d err=%d qps=%.0f\n", elapsed.Round(time.Millisecond), n, errs.Load(), float64(n)/elapsed.Seconds())
	if n > 0 {
		fmt.Printf("p50=%v p90=%v p99=%v max=%v\n",
			pick(0.50).Round(time.Microsecond), pick(0.90).Round(time.Microsecond),
			pick(0.99).Round(time.Microsecond), all[n-1].Round(time.Microsecond))
	}
}
