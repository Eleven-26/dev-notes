// 方案一：互斥锁 —— 临界区覆盖"取号 + 打印"，顺序有保证
package main

import (
	"fmt"
	"sync"
)

const (
	goroutines = 10
	total      = 100
)

func main() {
	var (
		mu      sync.Mutex
		counter int
		wg      sync.WaitGroup
	)

	for i := 0; i < goroutines; i++ {
		wg.Add(1)
		go func() {
			defer wg.Done()
			for {
				mu.Lock()
				if counter >= total {
					mu.Unlock()
					return
				}
				fmt.Println(counter) // 取号和打印在同一个临界区内
				counter++
				mu.Unlock()
			}
		}()
	}
	wg.Wait()
}
