# Dubbo

> 占位目录：Dubbo 的笔记暂未整理，下列为待补主题。
> 在此之前，横向对比（Dubbo 与 gRPC / Thrift / Spring Cloud 的取舍、以及「契合语言」维度）见
> [RPC框架选型对比.md](../RPC框架选型对比.md)；gRPC 侧已做深，见 [gRPC/README.md](../gRPC/README.md)。

---

## 待补主题

- [ ] `协议与序列化.md`（2.x 的私有 TCP 协议 + Hessian2 vs 3.x 的 Triple / HTTP/2 / Protobuf）
- [ ] `服务治理.md`（注册发现、路由与权重、灰度、动态配置、限流熔断、集群容错策略）
- [ ] `接入.md`（Java 侧：注解 / XML 两种写法；⭐ 接入代码属语言分册，落地时按 [RPC框架选型对比.md](../RPC框架选型对比.md) 的口径拆到 `01-编程语言/java/`）

> ⚠️ 写之前先按仓库口径 `grep` 全仓，确认没有与既有篇目重复的内容
> （`RPC框架选型对比.md` 已有对比表与决策线，零件篇**不重复讲选型**）。

## 关联

- [RPC框架选型对比.md](../RPC框架选型对比.md) — 本目录总纲（Dubbo 在其中占一节）
- [README.md](../gRPC/README.md) — gRPC 目录导读（Dubbo 的主要对照对象）
- [Nacos.md](../../注册与配置中心/Nacos.md) — Dubbo 常用的注册与配置中心
- [素材清单.md](../../../../素材清单.md) — 素材来源登记
