// 方案三：channel 令牌 —— 缓冲区大小 1 当信号量，用完必须归还
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
	token := make(chan struct{}, 1) // 缓冲区 = 1，同一时刻只有一个协程持有令牌
	var (
		counter int
		wg      sync.WaitGroup
	)

	token <- struct{}{} // 放入初始令牌，否则所有协程一开始就阻塞

	for i := 0; i < goroutines; i++ {
		wg.Add(1)
		go func() {
			defer wg.Done()
			for {
				<-token // 抢令牌，抢不到就阻塞
				if counter >= total {
					token <- struct{}{} // 退出前归还，否则其他协程永远阻塞
					return
				}
				fmt.Println(counter)
				counter++
				token <- struct{}{} // 用完立即归还，交给下一个协程
			}
		}()
	}
	wg.Wait()
}
