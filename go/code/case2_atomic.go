// 方案二：原子操作 —— 只保证 counter 自增原子，不保证打印顺序
package main

import (
	"fmt"
	"os"
	"sync"
	"sync/atomic"
	"time"
)

const (
	goroutines = 10
	total      = 100
)

func main() {
	withSleep := len(os.Args) > 1 && os.Args[1] == "sleep"

	var (
		counter int64
		wg      sync.WaitGroup
	)

	for i := 0; i < goroutines; i++ {
		wg.Add(1)
		go func() {
			defer wg.Done()
			for {
				n := atomic.AddInt64(&counter, 1) - 1
				if n >= total {
					return
				}
				if withSleep {
					// 模拟业务处理耗时：让取号顺序 == 打印顺序"看起来"成立
					time.Sleep(100 * time.Millisecond)
				}
				fmt.Println(n) // 打印顺序由调度器决定，不受原子操作约束
			}
		}()
	}
	wg.Wait()
}
