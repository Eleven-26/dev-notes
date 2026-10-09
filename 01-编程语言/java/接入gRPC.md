# Java 接入 gRPC

> 不用 protoc 的 Java gRPC 接入：手写 `MethodDescriptor` + 自定义 `Marshaller`、四种通信模式、拦截器 / metadata / deadline / 状态码，以及与 Go 侧的逐行对照。
>
> 内容整理自个人学习笔记，实测基于本机 Docker Desktop（容器 `eclipse-temurin:17-jdk` 内 java 17.0.20.1 + grpc-java 1.60.0；对照组是容器 `golang:1.26-alpine` 内 go1.26.8 + grpc-go v1.84.0），原始输出见 `.workbuddy/tmp/exp/grpclab/out/`。素材见 [素材清单](../../素材清单.md)。

---

## 一、Java 侧要用哪些依赖？能不能不用 protoc？

**本节要点**：五个 grpc 包加 guava 与 **failureaccess**（后者少一个就 `NoClassDefFoundError`）；Java 侧 protoc 内置 java 输出，不用像 Go 那样再装插件；「能不能不用 protoc」的答案是能——方法名只是一个字符串，`MethodDescriptor.Marshaller` 想怎么编解码自己写。

### 1.1 依赖清单

本实验台把 11 个 jar 放在 `javalib/` 下，容器里用 `javac -cp` / `java -cp` 直接拼 classpath（`CP=$(ls javalib/*.jar | tr '\n' ':')`）。清单与各自的作用：

| jar | 作用 |
|---|---|
| `grpc-api-1.60.0.jar` | 纯 API：`Channel` / `MethodDescriptor` / `Metadata` / `Status`，不带传输实现 |
| `grpc-core-1.60.0.jar` | 核心实现：拦截器框架、`ManagedChannel` 的装配 |
| `grpc-stub-1.60.0.jar` | 存根层：`StreamObserver` 与 `ClientCalls` / `ServerCalls` 的静态方法 |
| `grpc-netty-shaded-1.60.0.jar` | 传输层：Netty **带 shade（包名重定位）** 的版本，免得与业务自己引的 Netty 打架 |
| `grpc-util-1.60.0.jar` | 工具类，含 `Forwarding*` 系列包装类 |
| `guava-33.0.0-jre.jar` | `grpc-core` 的强制依赖 |
| `failureaccess-1.0.2.jar` | ⭐ guava 的伴生包，**必须一起拉** |
| `perfmark-api-0.27.0.jar` | `grpc-core` 的传递依赖（内部埋点） |
| `error_prone_annotations-2.23.0.jar` | 编译期注解，guava 的传递依赖 |
| `jspecify-0.3.0.jar` | 空值注解（JSpecify），guava 的传递依赖 |
| `jsr305-3.0.2.jar` | 空值注解（JSR-305），guava 的传递依赖 |

⭐ **`guava` 与 `failureaccess` 必须一起拉**。本实验第一次只放了 guava，一启动就是：

```text
NoClassDefFoundError: com/google/common/util/concurrent/internal/InternalFutureFailureAccess
```

`InternalFutureFailureAccess` 住在 `failureaccess-1.0.2.jar` 里（guava 把它拆成了独立包），guava 的 `AbstractFuture` 会引用它。⚠️ 手拼 classpath 而不是走 Maven 时最常漏这一个——**只在运行时炸，编译期一点提示都没有**。

### 1.2 换成 Maven 坐标

```xml
<dependency>
  <groupId>io.grpc</groupId>
  <artifactId>grpc-netty-shaded</artifactId>
  <version>1.60.0</version>
</dependency>
<dependency>
  <groupId>io.grpc</groupId>
  <artifactId>grpc-stub</artifactId>
  <version>1.60.0</version>
</dependency>
<dependency>
  <groupId>io.grpc</groupId>
  <artifactId>grpc-util</artifactId>
  <version>1.60.0</version>
</dependency>
```

三点：① 用 **`grpc-netty-shaded`** 而不是 `grpc-netty`，免得和业务自己引的 Netty 版本互相覆盖；② `grpc-api` / `grpc-core` / `guava` / `failureaccess` 都是**传递依赖**，Maven 会自动拉全（这正是上一条坑在 Maven 工程里少见的理由）；③ 用 protoc 生成代码时才需要 `grpc-protobuf` / `grpc-stub` 带的生成注解，本实验不用。

### 1.3 Java 侧的 protoc 内置 java 输出

protoc 自带 java 输出，出 stub 的那部分是插件 `protoc-gen-grpc-java`：

```bash
protoc --java_out=gen/java --grpc-java_out=gen/java api/order/v1/order.proto
```

⚠️ 对照 Go 侧：Go 必须另外装 `protoc-gen-go` 与 `protoc-gen-go-grpc` 两个插件并加进 PATH；Java 只需要这一个插件，消息类由 protoc 自己出。这个「装几个插件」的差异在 [数据序列化.md](../../02-计算机基础/网络/数据序列化.md) 第一节的格式对照里也被点到，Go 侧的完整流程见 [不使用protoc的gRPC.md](../go/网络编程/不使用protoc的gRPC.md)。

「能不能不用 protoc」的答案是**能**，只是两门语言换掉的是**不同层的对象**：Go 换 codec（注册 `jsonCodec` + 手写 `ServiceDesc`），Java 换 `Marshaller`（第二节）。

---

## 二、不用 protoc 怎么手写 MethodDescriptor 与 Marshaller？

**本节要点**：`MethodDescriptor.Marshaller<String>` 就能把 JSON 文本当载荷——`stream()` 管出、`parse()` 管进；`method(name, type)` 把「全名 + 类型 + 两个 marshaller」拼成一个描述符；服务端用 `ServerServiceDefinition.builder("demo.Echo").addMethod(...)` 装配。

### 2.1 自定义 `Marshaller`：把 JSON 文本当载荷

```java
static final class JsonMarshaller implements MethodDescriptor.Marshaller<String> {
  @Override
  public InputStream stream(String value) {
    return new ByteArrayInputStream(value.getBytes(StandardCharsets.UTF_8));
  }

  @Override
  public String parse(InputStream stream) {
    try {
      return new String(stream.readAllBytes(), StandardCharsets.UTF_8);
    } catch (IOException e) {
      throw new RuntimeException(e);
    }
  }
}
```

对照 Go 的 codec：Go 的 codec 一个接口同时管两个方向（`Marshal` / `Unmarshal` / `Name` 三个方法），Java 把方向拆成**出**（`stream`）与**进**（`parse`）两个方法，并把消息类型写在泛型参数上——**类型由 `Marshaller<String>` 固定，不再像 `Invoke` 的 `any` 那样自由**。想换成别的格式（比如直接传 Java 对象），改的是这两个方法里的字节处理，`addMethod` 那一行不动。

### 2.2 `method(name, type)`：拼一个 `MethodDescriptor`

```java
static MethodDescriptor<String, String> method(String name, MethodDescriptor.MethodType type) {
  return MethodDescriptor.<String, String>newBuilder()
      .setType(type)
      .setFullMethodName(MethodDescriptor.generateFullMethodName("demo.Echo", name))
      .setRequestMarshaller(new JsonMarshaller())
      .setResponseMarshaller(new JsonMarshaller())
      .build();
}
```

四个方法各建一个描述符（服务名固定成 `demo.Echo`，与 Go 侧 `svc.ServiceName` 是同一个字符串）：

```java
static final MethodDescriptor<String, String> UNARY =
    method("Unary", MethodDescriptor.MethodType.UNARY);
static final MethodDescriptor<String, String> SERVER_STREAM =
    method("ServerStream", MethodDescriptor.MethodType.SERVER_STREAMING);
static final MethodDescriptor<String, String> CLIENT_STREAM =
    method("ClientStream", MethodDescriptor.MethodType.CLIENT_STREAMING);
static final MethodDescriptor<String, String> BIDI =
    method("BiDi", MethodDescriptor.MethodType.BIDI_STREAMING);
```

`generateFullMethodName("demo.Echo", "Unary")` 得到 `demo.Echo/Unary`，加上前导斜杠就是对外的 `:path /demo.Echo/Unary`——与 Go 侧 `svc.Path("Unary")` 拼出来的是**同一个字符串**。

⚠️ 一处理念差异：Go 的 `StreamDesc` 用 `ServerStreams` / `ClientStreams` **两个布尔位**组合出四种流；Java 的 `MethodType` 直接**枚举四个值**（`UNARY` / `SERVER_STREAMING` / `CLIENT_STREAMING` / `BIDI_STREAMING`）。Go 那边写错方向位会在运行期报错，Java 这边写错枚举值则表现为客户端与服务端对不上。

### 2.3 服务端装配：`ServerServiceDefinition.builder`

```java
ServerServiceDefinition base =
    ServerServiceDefinition.builder("demo.Echo")
        .addMethod(UNARY, ServerCalls.asyncUnaryCall(/* 四个模式的完整实现见第三节 */))
        .build();
```

与 Go 的 `grpc.ServiceDesc` 有个**本质差异**：Go 的 `HandlerType` 是接口类型，`RegisterService` 会**用反射校验**实现有没有实现该接口，写错直接 panic；Java 的 `ServerServiceDefinition` 只登记「方法描述符 → 处理器」的映射，**没有接口校验这一步**——方法名写错不会在注册时报错，只会在调用时表现为 `UNIMPLEMENTED`。所以 Java 侧更要靠**服务名与方法名集中在一处常量**（本实验就是上面那四个 `static final` 加一处 `builder("demo.Echo")`）。

---

## 三、四种通信模式在 Java 侧怎么写？

**本节要点**：服务端四条入口是 `ServerCalls` 的四个静态方法，客户端四条入口是 `ClientCalls` 的四个静态方法；⚠️ 两个流式的客户端方法收的是 **`ClientCall`**，不是「channel + method + options」。

### 3.1 服务端：一元与服务端流

`ServerCalls.asyncUnaryCall` 收一个 `(String, StreamObserver<String>)` 的双参 lambda；`asyncServerStreamingCall` 的 lambda 只收一个 `StreamObserver`（请求是单个），服务端用它 `onNext` 多条：

```java
    ServerServiceDefinition base =
        ServerServiceDefinition.builder("demo.Echo")
            .addMethod(
                UNARY,
                ServerCalls.asyncUnaryCall(
                    (String req, StreamObserver<String> obs) -> {
                      stUnary.incrementAndGet();
                      if (req.contains("\"delay_ms\":300")) {
                        try {
                          Thread.sleep(300);
                        } catch (InterruptedException ignored) {
                          Thread.currentThread().interrupt();
                        }
                      }
                      if (req.contains("\"body\":\"notfound\"")) {
                        obs.onError(
                            Status.NOT_FOUND
                                .withDescription("no such key: 42")
                                .asRuntimeException());
                        return;
                      }
                      obs.onNext("{\"seq\":1,\"body\":\"echo:" + req + "\"}");
                      obs.onCompleted();
                    }))
            .addMethod(
                SERVER_STREAM,
                ServerCalls.asyncServerStreamingCall(
                    (String req, StreamObserver<String> obs) -> {
                      stStreams.incrementAndGet();
                      for (int i = 1; i <= 5; i++) {
                        try {
                          Thread.sleep(15);
                        } catch (InterruptedException ignored) {
                          Thread.currentThread().interrupt();
                        }
                        stMsgsOut.incrementAndGet();
                        obs.onNext("{\"seq\":" + i + ",\"body\":\"srv-" + i + "\"}");
                      }
                      obs.onCompleted();
                    }))
```

两个细节：`asyncUnaryCall` 的 lambda 里直接 `onError(Status...asRuntimeException())` 就返回了业务错误码，这条正是第四节 4.3 里客户端拿到 `NOT_FOUND` 的来源；`Thread.sleep(15)` 与服务端流那 5 条消息就是 `firstByte` / `total` 两个读数的来历。

### 3.2 服务端：客户端流与双向流

`asyncClientStreamingCall` 收一个「入参是响应 observer、返回一个请求 observer」的 lambda；`asyncBidiStreamingCall` 要一个 `ServerCalls.BidiStreamingMethod` 实例（`invoke` 的签名与前者一致）：

```java
            .addMethod(
                CLIENT_STREAM,
                ServerCalls.asyncClientStreamingCall(
                    (StreamObserver<String> obs) -> {
                      stStreams.incrementAndGet();
                      return new StreamObserver<String>() {
                        int got = 0;

                        @Override
                        public void onNext(String value) {
                          got++;
                          stMsgsIn.incrementAndGet();
                        }

                        @Override
                        public void onError(Throwable t) {}

                        @Override
                        public void onCompleted() {
                          stMsgsOut.incrementAndGet();
                          obs.onNext("{\"body\":\"server got " + got + " msgs\"}");
                          obs.onCompleted();
                        }
                      };
                    }))
            .addMethod(
                BIDI,
                ServerCalls.asyncBidiStreamingCall(
                    new ServerCalls.BidiStreamingMethod<String, String>() {
                      int i = 0;

                      @Override
                      public StreamObserver<String> invoke(StreamObserver<String> obs) {
                        stStreams.incrementAndGet();
                        return new StreamObserver<String>() {
                          @Override
                          public void onNext(String value) {
                            i++;
                            stMsgsIn.incrementAndGet();
                            stMsgsOut.incrementAndGet();
                            obs.onNext("{\"seq\":" + i + ",\"body\":\"ack:" + value + "\"}");
                          }

                          @Override
                          public void onError(Throwable t) {}

                          @Override
                          public void onCompleted() {
                            obs.onCompleted();
                          }
                        };
                      }
                    }))
```

⚠️ 方向体现在**谁返回 observer**：客户端流是「响应 observer 当入参、请求 observer 当返回值」；双向流的 `invoke` 签名与它完全一致（入参响应 observer、返回请求 observer），只是一个写成 lambda、一个写成匿名类。**回调式与 Go 的流式 `for { RecvMsg }` 循环是同一件事的两种写法**——Go 用阻塞循环，Java 用 `StreamObserver` 回调，所以 Java 侧必须自己用 `CountDownLatch` 等结束（见 3.4）。

### 3.3 客户端：一元与服务端流

```java
    String unary = ClientCalls.blockingUnaryCall(channel, UNARY, CallOptions.DEFAULT, "{\"body\":\"hello\"}");
    System.out.println("unary: resp=" + unary + " cost=" + ms(tu));

    long t0 = System.nanoTime();
    Iterator<String> it =
        ClientCalls.blockingServerStreamingCall(
            channel, SERVER_STREAM, CallOptions.DEFAULT, "{\"n\":5}");
    int got = 0;
    String firstAt = "";
    String last = "";
    while (it.hasNext()) {
      String m = it.next();
      if (got == 0) {
        firstAt = ms(t0);
      }
      got++;
      last = m;
    }
    System.out.println(
        "server-stream: recv=" + got + " firstByte=" + firstAt + " total=" + ms(t0) + " last=" + last);
```

这两个是**阻塞式**：`blockingUnaryCall` 一路等到响应；`blockingServerStreamingCall` 返回一个 `Iterator<String>`，`hasNext()` 阻塞到收齐为止（拿到 `EOF` 就结束，不抛异常）。⚠️ 与 Go 侧 `for { RecvMsg }` 拿到 `io.EOF` 才退出是同一个模型，差别只是 Go 的 `EOF` 是 `error`、Java 的 `Iterator.hasNext()` 返回 `false`。

### 3.4 客户端：客户端流与双向流

⚠️ 这两个方法收的第一个参数是 **`ClientCall`**（由 `channel.newCall(method, options)` 造出来），不是「channel + method + options + observer」四件套：

```java
    final String[] sum = new String[1];
    CountDownLatch done1 = new CountDownLatch(1);
    StreamObserver<String> reqObs =
        ClientCalls.asyncClientStreamingCall(
            channel.newCall(CLIENT_STREAM, CallOptions.DEFAULT),
            new StreamObserver<String>() {
              @Override
              public void onNext(String value) {
                sum[0] = value;
              }

              @Override
              public void onError(Throwable t) {
                done1.countDown();
              }

              @Override
              public void onCompleted() {
                done1.countDown();
              }
            });
    for (int i = 1; i <= 5; i++) {
      reqObs.onNext("{\"seq\":" + i + ",\"body\":\"c" + i + "\"}");
    }
    reqObs.onCompleted();
    await(done1);

    final List<String> acks = new ArrayList<>();
    CountDownLatch done2 = new CountDownLatch(1);
    StreamObserver<String> bidiReq =
        ClientCalls.asyncBidiStreamingCall(
            channel.newCall(BIDI, CallOptions.DEFAULT),
            new StreamObserver<String>() {
              @Override
              public void onNext(String value) {
                acks.add(value);
              }

              @Override
              public void onError(Throwable t) {
                done2.countDown();
              }

              @Override
              public void onCompleted() {
                done2.countDown();
              }
            });
    for (int i = 1; i <= 3; i++) {
      bidiReq.onNext("{\"body\":\"m" + i + "\"}");
    }
    bidiReq.onCompleted();
    await(done2);
```

三个要点：① 返回的 `StreamObserver` 是**写**请求的把手，`onCompleted()` 相当于 Go 的 `CloseSend()`；② 结束时机只能在 observer 的 `onCompleted` / `onError` 里，所以配 `CountDownLatch`（本实验的 `await` 给 10 秒上限）；③ 收结果用「回调往里塞」而不是 `Iterator`——想同步等就在这里阻塞。

### 3.5 实测输出

`out/java-modes.txt` 每次跑三段（`javac` 一次、`java` 连跑三轮），下面是第 1 轮的完整输出：

```text
### run 1 modes
env: java=17.0.20.1 grpc-java=1.60.0
unary: resp={"seq":1,"body":"echo:{"body":"hello"}"} cost=874ms
server-stream: recv=5 firstByte=60ms total=108ms last={"seq":5,"body":"srv-5"}
client-stream: sent=5 resp={"body":"server got 5 msgs"}
bidi: sent=3 recv=3 firstAck={"seq":1,"body":"ack:{"body":"m1"}"}
server stats: streams=3 msgsIn=8 msgsOut=9 unaryCalls=1
```

逐行说明：

- `env:` 那行是 `ManagedChannel` 包元数据里读出的 grpc-java 版本，与 `System.getProperty("java.version")` 并列，用来自证「跑的是哪个版本」；
- `unary`：`resp` 是服务端 `"echo:" + req` 拼出来的 JSON **文本**（所以引号会嵌套，看起来有点怪，但字段结构对得上）；`cost=874ms` 是首调，含义见第五节；
- `server-stream`：`recv=5` 与服务端 `for (i = 1; i <= 5)` 对应；`firstByte=60ms` 是第一条消息到达的耗时（服务端每条之间睡 15 ms，5 条约 75 ms，加建连与调度就是 `total=108ms`）；`last={"seq":5,"body":"srv-5"}` 与 Go 侧的 `last.body=srv-5` 是同一条；
- `client-stream`：`sent=5` 与服务端 `got` 对上，`resp={"body":"server got 5 msgs"}` 是那条汇总回包；
- `bidi`：`sent=3 recv=3`，`firstAck` 里嵌套的 `m1` 说明「发 `m1` 收 `ack:m1`」的顺序成立；
- `server stats`：三个计数由服务端全局 `AtomicInteger` 计数得来（3 条流、收 8 = 双向 3 加客户端流 5、发 9 = 服务端流 5 加客户端流 1 加双向 3），一元另记 `unaryCalls=1`。

三轮的时序读数（只断言趋势，取区间）：

| 项 | run 1 | run 2 | run 3 | 区间 |
|---|---|---|---|---|
| 一元 `cost` | 874ms | 1433ms | 827ms | 827~1433ms |
| 服务端流 `firstByte` | 60ms | 54ms | 44ms | 44~60ms |
| 服务端流 `total` | 108ms | 113ms | 96ms | 96~113ms |

⚠️ 一元那个 `827~1433ms` 是**首调**的一次性成本（JIT 预热 + Netty 建连），把它当成 gRPC 的性能结论会得出完全错误的结论——见第五节与第六节 ⑥。

---

## 四、拦截器、metadata、deadline、状态码在 Java 侧怎么写？

**本节要点**：客户端拦截器包 `ClientCall`、在 `onClose` 里拿状态码；服务端拦截器包 `ServerCall`，header 与 trailer 都靠**覆写包装类的 `sendHeaders(Metadata)` 与 `close(Status, Metadata)`**；deadline 走 `CallOptions.withDeadlineAfter`，状态码走 `Status.X.withDescription(...).asRuntimeException()`，metadata 的 key 用 `Metadata.Key.of(name, ASCII_STRING_MARSHALLER)`。

### 4.1 客户端拦截器：包 `ClientCall`，在 `onClose` 拿状态码

```java
    ClientInterceptor cliIc =
        new ClientInterceptor() {
          @Override
          public <ReqT, RespT> ClientCall<ReqT, RespT> interceptCall(
              MethodDescriptor<ReqT, RespT> m, CallOptions o, Channel next) {
            cliLog.add("in " + m.getFullMethodName());
            return new ForwardingClientCall.SimpleForwardingClientCall<ReqT, RespT>(
                next.newCall(m, o)) {
              @Override
              public void start(Listener<RespT> listener, Metadata headers) {
                super.start(
                    new ForwardingClientCallListener.SimpleForwardingClientCallListener<RespT>(
                        listener) {
                      @Override
                      public void onClose(Status status, Metadata trailers) {
                        cliLog.add("out code=" + status.getCode());
                        super.onClose(status, trailers);
                      }
                    },
                    headers);
              }
            };
          }
        };
    Channel intercepted = ClientInterceptors.intercept(channel, cliIc);
```

⚠️ 要**包两层**：`ForwardingClientCall` 改「调用本身」，`ForwardingClientCallListener` 才能挂到结束事件上。`onClose` 是「这次调用的结果」（状态码 + trailer），正好对应 Go 侧 `UnaryInvoker` 里 `err` 那个位置。

### 4.2 服务端拦截器：header 与 trailer 靠覆写

```java
            final int mdCount = mdCount(headers, TRACE_ID);
            // header / trailer 都靠包装 ServerCall 注入；Go 那边是 SetHeader / SetTrailer。
            // 注意：不能在拦截器里直接 call.sendHeaders()，否则 handler 首次 onNext 会撞
            // "sendHeaders has already been called"（实测踩过）。
            ServerCall<ReqT, RespT> wrapped =
                new ForwardingServerCall.SimpleForwardingServerCall<ReqT, RespT>(call) {
                  @Override
                  public void sendHeaders(Metadata responseHeaders) {
                    responseHeaders.put(HANDLER, "unary");
                    responseHeaders.put(MD_COUNT, String.valueOf(mdCount));
                    super.sendHeaders(responseHeaders);
                  }

                  @Override
                  public void close(Status status, Metadata trailers) {
                    srvLog.add("out " + call.getMethodDescriptor().getFullMethodName()
                        + " code=" + status.getCode());
                    trailers.put(TRAILER_NOTE, "set-in-server-interceptor");
                    super.close(status, trailers);
                  }
                };
            return next.startCall(wrapped, headers);
```

⚠️ 这里是与 Go 差得最远的一处对照：

| 目标 | Go | Java |
|---|---|---|
| 响应头 | `grpc.SetHeader(ctx, metadata.Pairs(...))` | 覆写包装 `ServerCall` 的 `sendHeaders(Metadata)` |
| 响应 trailer | `grpc.SetTrailer(ctx, metadata.Pairs(...))` | 覆写包装 `ServerCall` 的 `close(Status, Metadata)` |
| 出参类型 | `ctx` 穿针引线 | 包装 `ServerCall` 对象往下传 |

Go 是把 ctx 当「侧信道」用（`SetHeader` / `SetTrailer` 从 ctx 里取到流），Java 没有这个侧信道，只能**把 `ServerCall` 包一层再交给下游**——所以 `next.startCall(wrapped, headers)` 这一句不能少，少了包装就等于没做。

### 4.3 deadline 与状态码

```java
    try {
      ClientCalls.blockingUnaryCall(
          intercepted,
          UNARY,
          CallOptions.DEFAULT.withDeadlineAfter(60, TimeUnit.MILLISECONDS),
          "{\"body\":\"slow\",\"delay_ms\":300}");
      dl = "OK";
    } catch (StatusRuntimeException e) {
      dl = e.getStatus().getCode().toString();
    }
```

```java
    try {
      ClientCalls.blockingUnaryCall(intercepted, UNARY, CallOptions.DEFAULT, "{\"body\":\"notfound\"}");
      System.out.println("status: code=OK");
    } catch (StatusRuntimeException e) {
      System.out.println(
          "status: code="
              + e.getStatus().getCode()
              + " desc="
              + e.getStatus().getDescription()
              + " isNotFound="
              + (e.getStatus().getCode() == Status.Code.NOT_FOUND));
    }
```

两条约定：① deadline 用 `CallOptions.DEFAULT.withDeadlineAfter(时长, 单位)`，传进 `ClientCalls` 的第三个参数——等价于 Go 的 `context.WithTimeout`；② 业务错误码用 `Status.NOT_FOUND.withDescription("...").asRuntimeException()`（服务端那边 `obs.onError(...)`），客户端**按 `Status.Code` 判定**而不是比字符串，`getDescription()` 只用于给人看。

### 4.4 metadata 的读写

key 必须用 `Metadata.Key.of(name, marshaller)` 建出来，示例值用 `ASCII_STRING_MARSHALLER`：

```java
  static final Metadata.Key<String> TRACE_ID =
      Metadata.Key.of("x-trace-id", Metadata.ASCII_STRING_MARSHALLER);
  static final Metadata.Key<String> HANDLER =
      Metadata.Key.of("x-handler", Metadata.ASCII_STRING_MARSHALLER);
  static final Metadata.Key<String> MD_COUNT =
      Metadata.Key.of("x-md-count", Metadata.ASCII_STRING_MARSHALLER);
  static final Metadata.Key<String> TRAILER_NOTE =
      Metadata.Key.of("x-trailer-note", Metadata.ASCII_STRING_MARSHALLER);
```

客户端发请求 metadata、收响应 header 与 trailer：

```java
    ClientCall<String, String> call = intercepted.newCall(UNARY, CallOptions.DEFAULT);
    Metadata reqMd = new Metadata();
    reqMd.put(TRACE_ID, "t-123");
    call.start(
        new ClientCall.Listener<String>() {
          @Override
          public void onHeaders(Metadata headers) {
            hdr[0] = headers;
          }

          @Override
          public void onMessage(String message) {
            body[0] = message;
          }

          @Override
          public void onClose(Status status, Metadata trailers) {
            st[0] = status;
            tlr[0] = trailers;
            done.countDown();
          }
        },
        reqMd);
    call.sendMessage("{\"body\":\"md\"}");
    call.halfClose();
    call.request(1);
```

⚠️ 三个容易踩的点：① **`onHeaders` 是响应头、`onClose` 的第二个参数是 trailer**，两者是不同的回调，写反了就会拿到 null；② `sendMessage` 之后必须 `halfClose()`（等价 Go 的 `CloseSend()`），再 `request(1)` 声明要收一条——不 `request` 在部分传输实现下会一直等不到消息；③ 服务端读请求头用 `headers.get(TRACE_ID)`，**键不存在时 `getAll` 返回 `null` 而不是空集合**（见第六节 ②）。

### 4.5 实测输出

`out/java-base.txt` 全文（`java` 连跑三轮，此处原样贴出）：

```text
### run 1 base
env: java=17.0.20.1 grpc-java=1.60.0
interceptor cli: [in demo.Echo/Unary, out code=OK]
interceptor srv: [in demo.Echo/Unary, out demo.Echo/Unary code=OK]
interceptor unary reply: {"seq":1,"body":"echo:{"body":"x"}"}
deadline: client 60ms vs handler 300ms -> code=DEADLINE_EXCEEDED cost=70ms
deadline: 同请求 2s deadline -> code=OK body={"seq":1,"body":"echo:{"body":"slow","delay_ms":300}"}
metadata: code=OK respHeader.x-handler=unary respHeader.x-md-count=1 respTrailer.x-trailer-note=set-in-server-interceptor body={"seq":1,"body":"echo:{"body":"md"}"}
status: code=NOT_FOUND desc=no such key: 42 isNotFound=true
### run 2 base
env: java=17.0.20.1 grpc-java=1.60.0
interceptor cli: [in demo.Echo/Unary, out code=OK]
interceptor srv: [in demo.Echo/Unary, out demo.Echo/Unary code=OK]
interceptor unary reply: {"seq":1,"body":"echo:{"body":"x"}"}
deadline: client 60ms vs handler 300ms -> code=DEADLINE_EXCEEDED cost=81ms
deadline: 同请求 2s deadline -> code=OK body={"seq":1,"body":"echo:{"body":"slow","delay_ms":300}"}
metadata: code=OK respHeader.x-handler=unary respHeader.x-md-count=1 respTrailer.x-trailer-note=set-in-server-interceptor body={"seq":1,"body":"echo:{"body":"md"}"}
status: code=NOT_FOUND desc=no such key: 42 isNotFound=true
### run 3 base
env: java=17.0.20.1 grpc-java=1.60.0
interceptor cli: [in demo.Echo/Unary, out code=OK]
interceptor srv: [in demo.Echo/Unary, out demo.Echo/Unary code=OK]
interceptor unary reply: {"seq":1,"body":"echo:{"body":"x"}"}
deadline: client 60ms vs handler 300ms -> code=DEADLINE_EXCEEDED cost=67ms
deadline: 同请求 2s deadline -> code=OK body={"seq":1,"body":"echo:{"body":"slow","delay_ms":300}"}
metadata: code=OK respHeader.x-handler=unary respHeader.x-md-count=1 respTrailer.x-trailer-note=set-in-server-interceptor body={"seq":1,"body":"echo:{"body":"md"}"}
status: code=NOT_FOUND desc=no such key: 42 isNotFound=true
```

三轮之间**只有 deadline 的 `cost` 在动**（70 / 81 / 67 ms，区间 67~81ms），其余 6 行逐字节相同——这就是「确定性事实精确比对、时序数字标区间」的口径。⚠️ 两边都只记了**这一次**调用：Go 在第一次 `Invoke` 之后立刻打印快照，Java 也在第一次 `blockingUnaryCall` 之后立刻打印——后面的 deadline / metadata / 状态码几次调用同样穿过这层拦截器，只是打印时机在前，没被记进这一行。

---

## 五、与 Go 侧逐行对照的实测读数

**本节要点**：把 Go 的 `out/modes.txt` / `out/base.txt` 与 Java 的 `out/java-modes.txt` / `out/java-base.txt` 并排看，**确定性的东西（计数、状态码、metadata 键值）两边完全一致，只有时间和枚举名大小写是语言差异**。

### 5.1 四种通信模式对照

| 行 | Go（`out/modes.txt`） | Java（`out/java-modes.txt`） | 判定 |
|---|---|---|---|
| 一元 | `unary: resp.seq=1 resp.body=echo:hello err=<nil> cost=4ms` | `unary: resp={"seq":1,"body":"echo:{"body":"hello"}"} cost=874ms` | **等价**：两边都是「seq=1 + echo:hello」；Java 的 `resp` 是 JSON 文本所以引号嵌套，Go 侧打印的是结构体字段 |
| 服务端流 | `server-stream: sent(n=5) recv=5 firstByte=17ms total=81ms endErr=EOF last.body=srv-5` | `server-stream: recv=5 firstByte=60ms total=108ms last={"seq":5,"body":"srv-5"}` | **一致**：`recv=5` 与末条 `srv-5` 相同；Go 多打了结束错误 `EOF`（Java 侧 `Iterator` 结束不产错误） |
| 客户端流 | `client-stream: sent=5 msgs/10 bytes resp.seq=5 resp.body="server got 5 msgs / 10 bytes"` | `client-stream: sent=5 resp={"body":"server got 5 msgs"}` | **等价**：都是 5 条；Go 的回包多带一个字节数，Java 的回包只有条数——**是两边 handler 写的内容不同，不是协议差异** |
| 双向流 | `bidi: sent=3 recv=3 firstAck=ack:m1 endErr=EOF` | `bidi: sent=3 recv=3 firstAck={"seq":1,"body":"ack:{"body":"m1"}"}` | **一致**：3 发 3 收、首份 ack 都对应 `m1` |
| 服务端统计 | `server stats: streams=3 msgsIn=8 msgsOut=9 unaryCalls=1` | `server stats: streams=3 msgsIn=8 msgsOut=9 unaryCalls=1` | ⭐ **完全相同**，逐字节一致 |

⭐ **`server stats: streams=3 msgsIn=8 msgsOut=9 unaryCalls=1` 两边完全相同**，这条最有价值：它证明两边跑的是**同一套调用序列与同一套计数口径**（3 条流、收 8、发 9、一元 1 次），而不是「两边各自实现了一个差不多的 demo」。

### 5.2 基础能力对照

| 行 | Go（`out/base.txt`） | Java（`out/java-base.txt`） | 判定 |
|---|---|---|---|
| 客户端拦截器 | `interceptor cli: [unary-in /demo.Echo/Unary unary-out err=<nil>]` | `interceptor cli: [in demo.Echo/Unary, out code=OK]` | **等价**：都是进 / 出各记一行；Go 拿到的是 `err`，Java 从 `onClose` 拿状态码 |
| 服务端拦截器 | `interceptor srv: [unary-in /demo.Echo/Unary unary-out err=<nil>]` | `interceptor srv: [in demo.Echo/Unary, out demo.Echo/Unary code=OK]` | **等价**：Java 侧在 `close` 里多记一次方法名 |
| deadline | `deadline: client 60ms vs handler 300ms -> code=DeadlineExceeded cost=60ms` | `deadline: client 60ms vs handler 300ms -> code=DEADLINE_EXCEEDED cost=70ms` | **一致**：同一个 code，枚举名大写是语言差异；`cost` 都在 60~81ms 量级（都远小于 300ms 的 handler 睡眠） |
| deadline 放宽 | `deadline: 同请求 2s deadline -> code=OK body=echo:slow` | `deadline: 同请求 2s deadline -> code=OK body={"seq":1,"body":"echo:{"body":"slow","delay_ms":300}"}` | **一致**：同一个请求给足预算就成功 |
| metadata | `respHeader.x-handler=[unary] respHeader.x-md-count=[1] respTrailer.x-trailer-note=[set-in-unary-handler]` | `respHeader.x-handler=unary respHeader.x-md-count=1 respTrailer.x-trailer-note=set-in-server-interceptor` | **一致**：⭐ `x-md-count` **两边都是 1**，说明请求头里的 `x-trace-id` 服务端确实看得见；trailer 的**值**不同只是两边代码里写的常量不同 |
| 状态码 | `status: code=NotFound msg="no such key: 42" codeIsNotFound=true` | `status: code=NOT_FOUND desc=no such key: 42 isNotFound=true` | **一致**：描述文字逐字相同 |

### 5.3 时序差异：JVM 预热，不是协议

⭐ 两处需要单独说清：

- **一元首调**：Java 是 `827~1433ms`（三轮实测 874 / 1433 / 827，实验台汇总口径记作 `751~1433ms`），Go 是 `2~4ms`。差了两个数量级，但**原因是 JVM 预热（类加载 + JIT 编译 + Netty 建连）的一次性成本，不是协议差异**——同一个进程里的第二批调用就没有这个成本了。
- **稳态流式**：Java 首字节 `44~60ms`、总 `96~113ms`（实验台汇总口径 `44~75ms` / `96~129ms`），Go 首字节 `16~17ms`、总 `79~81ms`。服务端每条之间睡 15 ms，5 条约 75 ms 是**两边共同的时间下限**，所以差值被压在几十毫秒量级。

⚠️ 结论只有一条：**拿一元首调的数字对比两门语言的 gRPC 性能是错的**。要比也得比稳态（进程内第二批及以后）或把 JVM 预热排除掉再比。

---

## 六、Java 侧最容易踩的六个坑

**本节要点**：六个坑都来自本实验台真实踩过的现象，每题给「现象 → 根因 → 解法」。

1. **不能在 `ServerInterceptor` 里直接 `call.sendHeaders(...)`**。现象：拦截器里加了这行之后，handler 首次 `onNext` 抛 `IllegalStateException: sendHeaders has already been called`。根因：`sendHeaders` 是**一次性的**，它在第一次回包时由框架触发；拦截器抢先调用，handler 再回包就撞上。解法：**覆写包装类的 `sendHeaders(Metadata)`**，在别人的调用里加头（第四节 4.2 的写法）——这也是 `ForwardingServerCall` 存在的意义。

2. **`Metadata.getAll(key)` 在键不存在时返回 `null`**（不是空集合）。现象：`headers.getAll(TRACE_ID).size()` 直接 `NullPointerException`。本实验的 `mdCount()` 就是为此加了判空：

   ```java
   static <T> int mdCount(Metadata md, Metadata.Key<T> key) {
     Iterable<T> all = md.getAll(key);
     if (all == null) {
       return 0;
     }
     int n = 0;
     for (T ignored : all) {
       n++;
     }
     return n;
   }
   ```

   ⚠️ 这与 Go 的 `md.Get("x-trace-id")` 返回 `[]string{}`（长度为 0）**行为不同**，从 Go 迁过来最容易被这条绊倒。判空之外还要注意：`get` 只取最后一个值，要计数量必须走 `getAll`。

3. **`ClientCalls.asyncClientStreamingCall` / `asyncBidiStreamingCall` 收的是 `ClientCall`**。现象：按记忆里的四件套写成 `(channel, method, options, observer)`，编译直接报错（参数类型不匹配）。正确形态是：

   ```java
   ClientCalls.asyncClientStreamingCall(
       channel.newCall(CLIENT_STREAM, CallOptions.DEFAULT),
       new StreamObserver<String>() { /* … */ });
   ```

   根因：这两个方法签名里的第一个参数是 `Call`（`ClientCall` 接口），options 是在 `newCall` 那一步给进去的。⚠️ 一元与服务端流的两个 `blocking*` 方法才是「channel + method + options + request」的形态，**四件套与两件套要分清**。

4. **缺 `failureaccess` 会 `NoClassDefFoundError`**。现象：只放 guava 不放 `failureaccess-1.0.2.jar`，JVM 起不来：

   ```text
   NoClassDefFoundError: com/google/common/util/concurrent/internal/InternalFutureFailureAccess
   ```

   根因：guava 把 `InternalFutureFailureAccess` 拆到了独立的 `failureaccess` 工件里，而 grpc-core 的 `AbstractFuture` 会引用它。解法：手拼 classpath 时把它一起 `ls` 进去（本实验就是 `ls javalib/*.jar | tr '\n' ':'`）；走 Maven 时它是 guava 的传递依赖，一般不用管。⚠️ 这个坑**编译期不报**，只在运行时炸。

5. **在 `ServerInterceptor` 里给请求头加默认值，不传下去下游看不到**。现象：拦截器里 `headers.put(SOME_KEY, "default")`，handler 里 `metadata.FromIncomingContext` 找不到这个键。根因：拦截器拿到的 `headers` 只是一个对象引用，**必须把它作为参数交给 `next.startCall(...)`** 才会成为下游看到的那份：

   ```java
   return next.startCall(wrapped, headers);   // headers 要原样传下去
   ```

   要「加默认值再往下传」，就先 `put` 到 `headers` 上再交给 `startCall`（或者同样用包装的方式注入），**不能只在本地改一改然后 `return next.startCall(call, new Metadata())`**。

6. **一元首调慢得离谱是 JIT + 建连，别把首调数字当性能结论**。现象：同一个一元调用，首调 `827~1433ms`，而稳态的服务端流首字节只有 `44~60ms`（详见 5.3）。根因：JVM 类加载与分层编译要等第一批调用跑热，Netty 还要建立连接与 HTTP/2 握手（SETTINGS 往返）。解法：**压测前先预热**（跑一轮丢弃），或直接读稳态数字；报告里绝不要把首调与稳态数字混着用。

---

## 延伸追问

- **为什么可以不用 protoc？** → 方法名只是一个字符串 `demo.Echo/Unary`，载荷只是字节；`MethodDescriptor` 把「全名 + 类型 + 两个 `Marshaller`」组装起来就够了。protoc 与生成代码的作用是**自动生成这段组装**，不是协议的要求。
- **`Marshaller` 的出与进为什么是两个方法？** → `stream()` 把消息对象转成 `InputStream`（发送方向），`parse()` 把 `InputStream` 转回对象（接收方向）。Go 的 codec 用 `Marshal` / `Unmarshal` 表达同一对方向。
- **Java 侧没有 `ServiceDesc` 吗？** → 有等价物但没有接口校验：`ServerServiceDefinition.builder(服务名).addMethod(描述符, 处理器)`。Go 的 `HandlerType` 会反射校验实现类型、写错立刻 panic；Java 写错方法名只会在调用时表现为 `UNIMPLEMENTED`（2.3）。
- **header 与 trailer 为什么非得包一层 `ServerCall`？** → Go 有 `ctx` 这个侧信道（`grpc.SetHeader` / `grpc.SetTrailer` 从 ctx 取到流），Java 没有，只能把 `ServerCall` 包起来，在 `sendHeaders` / `close` 被调用时插进去（4.2 的对照表）。
- **两个流式的客户端方法为什么收 `ClientCall`？** → options 在 `channel.newCall(method, options)` 那一步就定下来了，方法本身只再收一个响应 observer；一元与服务端流那对 `blocking*` 方法才是把 options 当参数传的形态（第六节 ③）。
- **`Status.Code` 与 Go 的 `codes` 是一一对应的吗？** → 是，都是 gRPC 规范里那 16 个 code，只是命名风格不同（`NotFound` ↔ `NOT_FOUND`、`DeadlineExceeded` ↔ `DEADLINE_EXCEEDED`）。所以判据统一写成「按 code 判定，不按描述文字判定」。
- **metadata 的 key 在 Java 侧有什么约束？** → 必须用 `Metadata.Key.of(name, marshaller)` 建；名字里只能有小写字母、数字、`-`、`_`、`.`，用 `ASCII_STRING_MARSHALLER` 表示值是 ASCII 字符串。二进制值要用 `BINARY_BYTE_MARSHALLER` 并给键名加 `-bin` 后缀。
- **为什么 Java 侧要数「流跑在同一条连接上」？** → 与 Go 侧同一个理由：`server stats` 里的计数是服务端**全局**的，它只能证明「一共收了几条流」，证明不了「这些流跑在同一条 TCP 上」。连接复用这层证据在 Go 侧已经数过（见 [实战示例.md](../../03-数据与中间件/中间件/RPC框架/gRPC/实战示例.md) 第四节 ⑨ 与 [不使用protoc的gRPC.md](../go/网络编程/不使用protoc的gRPC.md) 第五节）；Java 侧本实验**未单独数连接**，只对照了服务端统计。

---

## 关联

- [不使用protoc的gRPC.md](../go/网络编程/不使用protoc的gRPC.md) — Go 侧同主题实证：`encoding.RegisterCodec`、手写 `ServiceDesc`、metadata 通道、连接复用；两篇是同一命题的两种语言落点
- [接入Jaeger.md](接入Jaeger.md) — 本目录另一份分册手册（Java 接链路追踪），体例与鉴权口径可互相参照
- [接入Skywalking.md](接入Skywalking.md) — 同一目录的 Java 分册，讲字节码增强那条路线
- [README.md](README.md) — Java 目录导读（本篇的定位与依赖关系）
- [基础概念.md](../../03-数据与中间件/中间件/RPC框架/gRPC/基础概念.md) — gRPC 是什么、与 HTTP/2 的关系（本体篇）
- [四种通信模式.md](../../03-数据与中间件/中间件/RPC框架/gRPC/四种通信模式.md) — 四种模式的定义与适用（本篇给的是 Java 侧的落地写法）
- [实战示例.md](../../03-数据与中间件/中间件/RPC框架/gRPC/实战示例.md) — 该主题的本体篇：工程布局、`.proto` 审查、上线前十条
- [HTTP与gRPC.md](../../02-计算机基础/网络/HTTP与gRPC.md) — 那篇的「使用二：Java」讲的是 JDK `HttpClient` / OkHttp 的**连接池与超时参数**，本篇讲的是 **gRPC 存根层**（`MethodDescriptor` / `StreamObserver` / 拦截器），两者在 Java 侧一个管通用 HTTP 客户端、一个管 gRPC 调用栈，不重叠
- [数据序列化.md](../../02-计算机基础/网络/数据序列化.md) — Protobuf 与 JSON 的格式选型、体积与耗时读数，以及「Go 要装几个 protoc 插件」的对照
> 反向引用（本篇被下列文档引到）：[RPC框架选型对比.md](../../03-数据与中间件/中间件/RPC框架/RPC框架选型对比.md)、[生态与网关.md](../../03-数据与中间件/中间件/RPC框架/gRPC/生态与网关.md)
