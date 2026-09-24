# I/O 多路复用

> 一句话说明本文件覆盖什么：从系统调用原理到 Go/Java 实践，讲清五种 I/O 模型、select/poll/epoll、LT/ET、Reactor 与高频面试题。
>
> 内容整理自个人学习笔记。Go 侧的网络模型与调度见 [../go/GMP调度.md](../go/GMP调度.md)；零拷贝是另一层的优化，见 [../go/零拷贝.md](../go/零拷贝.md)。

---

## 1. 一句话定位：它解决什么

**I/O 多路复用让一个进程或线程同时监视多个 I/O 通道，只在通道就绪时处理它。**
网络服务的大量连接通常都在等待数据；若采用“每连接一线程”，连接数上升后会遇到 C10K 问题。

| 成本 | 每连接一线程 | I/O 多路复用 |
|---|---|---|
| 内存 | 每个线程需要栈和内核数据结构 | 少量事件循环管理大量连接 |
| 调度 | 线程多导致上下文切换、缓存失效 | 只唤醒负责就绪事件的线程 |
| 同步 | 共享状态需要加锁 | 单事件循环可减少锁竞争 |
| 空闲连接 | 一个线程长期睡眠等待 | 一个等待点收集全部就绪事件 |

多路复用优化“**如何等待许多 fd**”；零拷贝优化“**数据如何搬运**”，两者是不同层面的优化。

---

## 2. 五种 I/O 模型 ⭐

一次输入分两段：① **等待数据就绪**，数据进入内核 socket 缓冲区；② **数据拷贝**，内核将数据复制到用户空间。

| 模型 | 等待数据 | 内核拷贝到用户空间 | 一句话 |
|---|---|---|---|
| 阻塞 I/O | 线程睡眠 | 线程继续阻塞 | 一个 `read` 从等待阻塞到拷贝完成 |
| 非阻塞 I/O | 无数据立即返回 `EAGAIN` | 就绪后由 `read` 同步拷贝 | 应用轮询，可能浪费 CPU |
| I/O 多路复用 | 一次等待多个 fd | 就绪后调用 `read` 同步拷贝 | 一个等待点管理许多连接 |
| 信号驱动 I/O | 就绪时收到 `SIGIO` | 收信号后调用 `read` 同步拷贝 | 等待被通知，拷贝仍同步 |
| 异步 I/O（AIO） | 提交后立即返回 | 内核完成拷贝后通知 | 通知到达时数据已在用户缓冲区 |

### 2.1 各模型一句话展开

- **阻塞 I/O**：最简单，但一个线程同一时刻只能等待一个操作。
- **非阻塞 I/O**：`read` 暂不可完成就返回；若应用忙轮询，大量空检查会消耗 CPU。
- **I/O 多路复用**：线程可阻塞在 `select/poll/epoll_wait`，返回后再处理就绪 fd，仍属同步 I/O。
- **信号驱动 I/O**：通过 `O_ASYNC` 等订阅 `SIGIO`；信号处理复杂，高并发服务中不如 epoll 常见。
- **AIO**：完成通知代表等待和拷贝都已完成，应用无需再调用一次 `read` 搬数据。

```c

// 非阻塞 I/O 的本质：暂时不能读就立即返回，而不是内核替应用完成整个请求。
ssize_t n = read(fd, buf, sizeof(buf));
if (n < 0 && (errno == EAGAIN || errno == EWOULDBLOCK)) {
    /* 稍后重试 */
}
```

⚠️ Linux 传统 AIO 对网络 socket 的生态和易用性较弱；`io_uring` 扩展了异步能力，
但成熟网络服务的主流基线仍是**非阻塞 socket + I/O 多路复用**。

### 2.2 同步/异步与阻塞/非阻塞 ⭐

| 维度 | 关注点 | 区分 |
|---|---|---|
| 同步 / 异步 | I/O 两阶段由谁推进、如何交付结果 | 同步仍由应用调用数据操作；异步由内核完成后通知 |
| 阻塞 / 非阻塞 | 当前调用不能完成时线程是否原地等待 | 阻塞会睡眠；非阻塞立即返回 |

- 阻塞 `read` 是**同步 + 阻塞**；非阻塞 `read` 是**同步 + 非阻塞**。
- `epoll_wait` 可以阻塞，但返回后应用仍要 `read`，所以多路复用仍是**同步 I/O**。
- AIO 提交立即返回，通知到达时数据已复制完成，才是**异步 I/O**。

> ⭐ 记忆：阻塞性看“线程等不等”，同步性看“数据操作最终由谁完成并交付”。

---

## 3. select / poll / epoll 三代演进 ⭐

设监视 fd 总数为 `n`，本轮就绪数为 `k`。

| 维度 | select | poll | epoll |
|---|---|---|---|
| 数据结构 | 位图 `fd_set` | `pollfd` 数组 | epoll 实例；红黑树 + 就绪链表 |
| 最大连接数 | 常受 `FD_SETSIZE=1024` 限制 | 无固定 1024 限制 | 受 fd、内存和系统配置限制 |
| 每次等待复制全量 fd | 是 | 是 | 否，注册关系由内核持久保存 |
| 就绪后遍历全量 fd | 是，扫描 `0..maxfd` | 是，扫描全部数组 | 否，只返回就绪项 |
| 典型复杂度 | `O(n)` | `O(n)` | 等待返回 `O(k)`；`epoll_ctl` 通常 `O(log n)` |
| 触发方式 | LT | LT | LT（默认）/ ET |
| 修改关注集合 | 重建/修改位图 | 修改数组 | `epoll_ctl` 增删改 |

### 3.1 select 的三个缺点

1. **数量限制**：`fd_set` 是固定位图，常见 `FD_SETSIZE` 为 1024。
2. **重复拷贝**：每次调用都要在用户态与内核态之间传递 fd 集合。
3. **全量遍历**：返回后仍需 `O(n)` 检查哪个 fd 就绪；集合还会被改写，下轮要重建。

### 3.2 poll 改进了什么、还剩什么

`poll` 用动态 `pollfd` 数组去掉 1024 的接口级上限，并以 `events/revents` 分离输入和结果。
⚠️ 它每次仍复制整个数组，内核和用户态仍线性扫描；连接多、活跃少时成本依旧随 `n` 增长。

### 3.3 epoll 的三个函数

```c

int epfd = epoll_create1(EPOLL_CLOEXEC);          // 创建实例
int rc = epoll_ctl(epfd, EPOLL_CTL_ADD, fd, &ev); // ADD / MOD / DEL
int n = epoll_wait(epfd, events, maxevents, -1);  // 返回就绪事件
```

- `epoll_create` 创建实例；现代代码优先 `epoll_create1`。
- `epoll_ctl` 持久维护关注集合，避免每轮重新提交全部 fd。
- `epoll_wait` 等待就绪链表；超时为 `-1/0/正数` 分别表示永久等待、立即返回、毫秒超时。

### 3.4 epoll 为什么高效

1. **红黑树管理 fd**：增删改通常 `O(log n)`。
2. **就绪链表记录活跃 fd**：驱动在状态变化时通过回调挂入 ready list。
3. **只返回就绪 fd**：应用处理 `k` 项，不再扫描全部 `n` 项。
4. **关注集合持久化**：等待时不反复复制完整集合，只交付本轮事件。

资料常把第 4 点简称为“通过 mmap 减少拷贝”。严格说，核心是**避免每轮复制全量兴趣集合**；
`epoll_wait` 仍要把就绪事件复制到用户数组，不能理解成完全零拷贝。
⚠️ “epoll 是 `O(1)`”也不严谨：`epoll_ctl` 有树操作，事件交付至少为 `O(k)`。

---

## 4. epoll 的两种触发模式 ⭐

| 维度 | LT（水平触发，默认） | ET（边缘触发） |
|---|---|---|
| 通知条件 | fd 仍就绪就持续通知 | 状态发生变化时通知 |
| 可否只读一部分 | 可以，下轮继续通知 | 不可以，必须读到 `EAGAIN` |
| fd 模式 | 可阻塞，但事件循环仍建议非阻塞 | **必须非阻塞** |
| 优点 | 直观，不易漏事件 | 减少重复通知 |
| 易错点 | 不处理会反复唤醒、忙循环 | 未排空缓冲区可能永久漏事件 |

### 4.1 LT：缓冲区有数据就持续提醒

接收缓冲区有 10 KB 而本轮只读 1 KB，下轮 `epoll_wait` 仍报告可读。
⚠️ LT 也建议使用非阻塞 fd：若数据被并发消费者抢走，阻塞读仍可能卡住事件循环。

### 4.2 ET：只提醒状态变化

ET 必须持续读到 `EAGAIN/EWOULDBLOCK`：

```c

for (;;) {
    ssize_t n = read(fd, buf, sizeof(buf));
    if (n > 0) { handle(buf, n); continue; }
    if (n == 0) { close(fd); break; }
    if (errno == EINTR) continue;
    if (errno == EAGAIN || errno == EWOULDBLOCK) break;
    close(fd); break;
}
```

写操作也要循环到发送缓冲区满；剩余数据放用户态队列并订阅 `EPOLLOUT`，写完立即取消订阅。
> ⚠️ ET 最典型事故：只读一次便返回，缓冲区仍有旧数据，却没有新的边沿，连接像“卡死”。

---

## 5. 典型问题：惊群与 Reactor

### 5.1 惊群（thundering herd）

多个进程/线程同时 `epoll_wait` 同一实例或竞争监听 socket 时，一个事件可能唤醒大量等待者，
最终只有少数获得工作，其余唤醒变成调度与锁竞争。

| 方案 | 原理 |
|---|---|
| Nginx accept 锁 | 同一时刻只让一个 worker 接受新连接 |
| `EPOLLEXCLUSIVE` | 内核对共享监听 fd 排他唤醒，减少无效唤醒 |
| `SO_REUSEPORT` | 每个 worker 独立监听同一端口，由内核分流 |

⚠️ `SO_REUSEPORT` 下每个 worker 有独立监听队列，还要关注负载均衡和重启策略。

### 5.2 Reactor 模式

Reactor 把流程拆成“等待事件 → 分发给 Handler → 执行业务 → 更新关注事件”。

| 模式 | 事件与业务 | 特点/实现 |
|---|---|---|
| 单 Reactor 单线程 | 都在一个线程 | 简单无锁；业务阻塞拖住全局，经典 Redis 接近此模式 |
| 单 Reactor 多线程 | Reactor 分发，线程池执行业务 | I/O 集中，耗时任务并行，结果需安全回到 I/O 线程 |
| 主从 Reactor | 主 Reactor 接连接，从 Reactor 管读写 | Netty `bossGroup/workerGroup` 是典型实现 |

Nginx 更准确地说是“多个 worker 进程各自运行单线程 Reactor”；master 管生命周期而不负责 accept，
阻塞文件任务可交给线程池，因此它不应被机械等同为教科书式主从 Reactor。

---

## 6. Go 的实现：netpoller 与 goroutine

Go runtime 的 netpoller 在 Linux 上使用 epoll：网络 I/O 暂不可完成时挂起 goroutine，
对应 M 不必等待；fd 就绪后 runtime 再把 goroutine 置为可运行。
> ⭐ Go 中可写阻塞式 `Accept/Read/Write`，底层却由非阻塞 fd + 多路复用承接，这降低了并发编程心智负担。

G、M、P 的配合与调度细节见 [Go GMP 调度模型](../go/GMP调度.md)。
⚠️ 普通文件 I/O、部分系统调用和 cgo 可能真的阻塞线程，并非所有阻塞都由 netpoller 接管。

---

## 7. 使用一：Go ⭐

### 7.1 日常优先使用 net 包

```go

ln, err := net.Listen("tcp", ":8080")
if err != nil { log.Fatal(err) }
for {
    conn, err := ln.Accept()
    if err != nil { log.Print(err); continue }
    go handle(conn) // handler 内按阻塞式 Read/Write 编写
}
```

`net` 已封装非阻塞 fd、netpoller、deadline 和资源管理；直接操作 epoll 主要用于理解原理或底层库。

### 7.2 直接调用 epoll：最小 ET TCP 读取服务

仅适用于 Linux。监听 fd 与 `Accept4` 返回的 fd 均设置非阻塞；ET 下 accept/read 都循环到 `EAGAIN`。

```go

package main

import (
    "errors"
    "log"
    "golang.org/x/sys/unix"
)

const epollET uint32 = 1 << 31

func main() {
    lfd, err := unix.Socket(unix.AF_INET,
        unix.SOCK_STREAM|unix.SOCK_NONBLOCK|unix.SOCK_CLOEXEC, 0)
    must(err)
    defer unix.Close(lfd)
    must(unix.SetsockoptInt(lfd, unix.SOL_SOCKET, unix.SO_REUSEADDR, 1))
    must(unix.Bind(lfd, &unix.SockaddrInet4{Port: 8080}))
    must(unix.Listen(lfd, 128))

    epfd, err := unix.EpollCreate1(unix.EPOLL_CLOEXEC)
    must(err)
    defer unix.Close(epfd)
    must(unix.EpollCtl(epfd, unix.EPOLL_CTL_ADD, lfd, &unix.EpollEvent{
        Events: uint32(unix.EPOLLIN) | epollET, Fd: int32(lfd),
    }))

    clients := map[int]struct{}{}
    events := make([]unix.EpollEvent, 128)
    for {
        n, err := unix.EpollWait(epfd, events, -1)
        if errors.Is(err, unix.EINTR) { continue }
        must(err)
        for _, ev := range events[:n] {
            fd := int(ev.Fd)
            if fd == lfd {
                acceptAll(epfd, lfd, clients)
                continue
            }
            if _, ok := clients[fd]; !ok { continue }
            if ev.Events&uint32(unix.EPOLLERR) != 0 || !readAll(fd) {
                closeFD(epfd, fd, clients)
            }
        }
    }
}

func acceptAll(epfd, lfd int, clients map[int]struct{}) {
    for {
        fd, _, err := unix.Accept4(lfd, unix.SOCK_NONBLOCK|unix.SOCK_CLOEXEC)
        if errors.Is(err, unix.EINTR) { continue }
        if errors.Is(err, unix.EAGAIN) || errors.Is(err, unix.EWOULDBLOCK) { return }
        if err != nil { log.Printf("accept: %v", err); return }
        ev := &unix.EpollEvent{
            Events: uint32(unix.EPOLLIN|unix.EPOLLRDHUP) | epollET, Fd: int32(fd),
        }
        if err := unix.EpollCtl(epfd, unix.EPOLL_CTL_ADD, fd, ev); err != nil {
            unix.Close(fd)
            continue
        }
        clients[fd] = struct{}{}
    }
}

func readAll(fd int) bool {
    buf := make([]byte, 4096)
    for {
        n, err := unix.Read(fd, buf)
        if n > 0 { log.Printf("fd=%d read=%d", fd, n); continue }
        if n == 0 && err == nil { return false }
        if errors.Is(err, unix.EINTR) { continue }
        if errors.Is(err, unix.EAGAIN) || errors.Is(err, unix.EWOULDBLOCK) { return true }
        return false
    }
}

func closeFD(epfd, fd int, clients map[int]struct{}) {
    _ = unix.EpollCtl(epfd, unix.EPOLL_CTL_DEL, fd, nil)
    _ = unix.Close(fd)
    delete(clients, fd)
}
func must(err error) { if err != nil { log.Fatal(err) } }
```

```bash

go mod init epoll-demo
go get golang.org/x/sys/unix
go run .
```

### 7.3 为什么优先使用 SetDeadline

`SetDeadline/SetReadDeadline/SetWriteDeadline` 与 runtime 定时器、netpoller 集成，超时会让阻塞式调用返回错误；
它比自行维护 epoll 超时堆更方便、更跨平台。⚠️ deadline 是绝对时间，空闲超时通常要在成功读写后刷新。

---

## 8. 使用二：Java ⭐

### 8.1 NIO 三件套

| 组件 | 作用 | 关系 |
|---|---|---|
| `Channel` | 双向 I/O 通道 | 非阻塞 Channel 注册到 Selector |
| `Buffer` | 保存待读写数据 | Channel 从 Buffer 写出或向其读入 |
| `Selector` | 一个线程等待多个 Channel | 用 `SelectionKey` 管理关注事件和状态 |

写入 Buffer 后用 `flip()` 切到读模式，消费完用 `clear()` 或 `compact()` 回到写模式。

### 8.2 完整可运行的 Selector Echo 服务

```java

import java.io.IOException;
import java.net.InetSocketAddress;
import java.nio.ByteBuffer;
import java.nio.channels.*;
import java.util.Iterator;

public class NioEchoServer {
    public static void main(String[] args) throws IOException {
        int port = args.length == 0 ? 8080 : Integer.parseInt(args[0]);
        try (Selector selector = Selector.open();
             ServerSocketChannel server = ServerSocketChannel.open()) {
            server.configureBlocking(false);
            server.bind(new InetSocketAddress(port));
            server.register(selector, SelectionKey.OP_ACCEPT);
            while (true) {
                selector.select();
                Iterator<SelectionKey> it = selector.selectedKeys().iterator();
                while (it.hasNext()) {
                    SelectionKey key = it.next();
                    it.remove();
                    if (!key.isValid()) continue;
                    try {
                        if (key.isAcceptable()) {
                            acceptAll(server, selector);
                            continue;
                        }
                        if (key.isReadable()) read(key);
                        if (key.isValid() && key.isWritable()) write(key);
                    } catch (IOException e) {
                        close(key);
                    }
                }
            }
        }
    }

    private static void acceptAll(ServerSocketChannel server, Selector selector)
            throws IOException {
        SocketChannel channel;
        while ((channel = server.accept()) != null) {
            channel.configureBlocking(false);
            channel.register(selector, SelectionKey.OP_READ, ByteBuffer.allocate(8192));
        }
    }

    private static void read(SelectionKey key) throws IOException {
        SocketChannel channel = (SocketChannel) key.channel();
        ByteBuffer buffer = (ByteBuffer) key.attachment();
        int n = channel.read(buffer);
        if (n == -1) { close(key); return; }
        if (n > 0) {
            buffer.flip();
            key.interestOps(SelectionKey.OP_WRITE);
        }
    }

    private static void write(SelectionKey key) throws IOException {
        SocketChannel channel = (SocketChannel) key.channel();
        ByteBuffer buffer = (ByteBuffer) key.attachment();
        channel.write(buffer);
        if (!buffer.hasRemaining()) {
            buffer.clear();
            key.interestOps(SelectionKey.OP_READ);
        }
    }

    private static void close(SelectionKey key) {
        key.cancel();
        try { key.channel().close(); } catch (IOException ignored) { }
    }
}
```

```bash

javac NioEchoServer.java
java NioEchoServer 8080
```

示例用 `OP_WRITE` 处理部分写，并在输出完成前暂停读取，形成简单背压。
⚠️ 必须移除已处理的 selected key；真实协议还要处理半包、粘包、解码状态和发送队列上限。

### 8.3 Netty：主从 Reactor

| 概念 | 职责 |
|---|---|
| `bossGroup` | 接收连接，将 Channel 注册给 worker |
| `workerGroup` | 管理连接读写，包含多个 `EventLoop` |
| `EventLoop` | 一个线程管理多个 Channel，串行执行其事件和任务 |
| `ChannelPipeline` | 让事件依次经过编解码和业务 Handler |

一个 Channel 通常固定绑定一个 EventLoop，因此事件天然有序；阻塞业务必须移出 I/O EventLoop。
Netty 采用成熟的 Reactor/NIO，也可用 Linux native epoll transport，而非把 AIO 作为主流传输层：
线程模型、背压和跨平台行为更可控。⚠️ JDK `AsynchronousSocketChannel` 在 Linux 上实际常由线程池承接，使用较少。

---

## 9. 生产实践与坑

### 9.1 fd 与连接上限

每个 socket 占一个 fd；同时检查进程限制、服务管理器配置、系统总量和内存：

```bash

ulimit -n
cat /proc/$PID/limits
sysctl fs.file-max
ss -s
```

仅提高 `ulimit -n` 不够；还要估算收发缓冲区、连接对象、epoll 注册项，并检查 `listen(backlog)` 与 `somaxconn`。

### 9.2 epoll 与普通文件

普通文件从 readiness 视角通常总是就绪，Linux 把它加入 epoll 还常返回 `EPERM`；
epoll 无法表示磁盘何时真正完成。文件 I/O 应使用线程池、AIO 或 `io_uring`。

### 9.3 半开连接、心跳与 CLOSE_WAIT

断网、NAT 状态丢失或对端崩溃可能形成半开连接；组合应用心跳、空闲 deadline、TCP keepalive 和请求超时清理。
`CLOSE_WAIT` 表示对端已发 FIN，而本地应用尚未 close，通常是资源释放路径遗漏或 handler 阻塞。

```bash

ss -antp state close-wait
netstat -antp | grep CLOSE_WAIT
lsof -p "$PID" -a -iTCP
```

排查：定位 PID/fd → 对应请求或 goroutine/线程 → 检查异常分支、连接池归还、响应体关闭 → 观察数量回落。

### 9.4 事件循环规则

- fd 使用非阻塞模式，统一处理 `EINTR`、`EAGAIN/EWOULDBLOCK`；ET 必须 drain。
- 只在有待发送数据时订阅 `EPOLLOUT`，完成后立即取消。
- 限制单连接单轮处理量和发送队列，避免热点连接饥饿与慢客户端耗尽内存。
- 所有退出路径注销事件并关闭 fd；I/O 线程不执行阻塞 DNS、磁盘或长耗时业务。

---

## 10. 面试官会追问什么 ⭐

1. **select、poll、epoll 的区别？** select 有位图/1024 限制，poll 去掉数量限制但仍复制和遍历；epoll 持久注册且只返回就绪项。
2. **epoll 为什么高效？** 红黑树管理兴趣集合、就绪链表收集活跃项；等待处理 `k` 而非扫描 `n`，但并非所有操作都是 `O(1)`。
3. **LT、ET 有何区别？** LT 持续报告就绪；ET 报告状态变化，必须非阻塞并读写到 `EAGAIN`，否则会漏事件。
4. **同步/异步与阻塞/非阻塞如何区分？** 前者看完成责任和通知，后者看当前调用是否让线程等待。
5. **五种 I/O 模型怎么答？** 沿“等待数据、复制数据”两阶段说明阻塞、非阻塞、多路复用、信号驱动和 AIO。
6. **Redis 单线程为什么快？** 内存操作、epoll、高效结构和 pipeline；串行命令减少锁与切换，新版本可用 I/O 线程。
7. **epoll 能用于普通文件吗？** 不适合；普通文件通常总是就绪且常注册失败，不能表示真实磁盘完成。
8. **什么是惊群，如何解决？** 多个等待者被同时唤醒却只有少数获益；可用 accept 锁、`EPOLLEXCLUSIVE`、`SO_REUSEPORT`。
9. **Go 网络代码为何像阻塞？** runtime 用 netpoller 挂起 goroutine 而非长期阻塞 M，就绪后再唤醒。
10. **Reactor 与 Proactor 的区别？** Reactor 通知“可执行 I/O”；Proactor 完成 I/O 后通知“操作已完成”。
