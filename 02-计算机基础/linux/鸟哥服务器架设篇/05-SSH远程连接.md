# SSH远程连接

> 密钥认证与 SSHD 配置、端口转发（本地 / 远程 / 动态）、rsync 同步；
> SSH 是服务器管理的入口，也是异地备援的基础。
>
> 内容整理自个人学习笔记。**基础框架**参考鸟哥官网《服务器架设篇 - RockyLinux 9》
> 相关章节（SSH 服务）的读后整理；ssh / rsync 的现代用法基于官方文档独立整理。

---

## 一、SSH 基础：密码还是密钥？

**本节要点**：密钥认证不做「密码过网络」这件事——私钥不出本机、用**挑战-响应**证明身份，也因此能做免密与自动化。

```bash
# ① 本机生成密钥对（ed25519 更现代、更短）
ssh-keygen -t ed25519 -C "deploy@laptop"

# ② 把公钥装到服务器（推荐 ssh-copy-id）
ssh-copy-id deploy@10.0.10.20

# ③ 免密验证
ssh deploy@10.0.10.20 "hostname"
```

| 文件 | 在哪 | 作用 | 权限 |
|------|------|------|------|
| `~/.ssh/id_ed25519` | 客户端 | **私钥**（绝不外传） | 600 |
| `~/.ssh/id_ed25519.pub` | 客户端 | 公钥，拷到服务器 | 644 |
| `~/.ssh/authorized_keys` | 服务器 | 允许登录的公钥列表 | 600 |

⚠️ 权限不对 SSH 会**拒绝使用密钥**（`Permissions 0644 ... too open`）——`.ssh` 目录 700、私钥 600、authorized_keys 600。

---

## 二、SSHD 服务端怎么加固？

**本节要点**：`/etc/ssh/sshd_config` 里最该改的四项——**禁密码、禁 root 直登、限用户 / 来源、可换端口**；改完先 `sshd -t` 校验再重启。

```text
Port 2222                       # 非标准端口（减少自动化扫描噪音，不是安全屏障）
PermitRootLogin no              # 禁止 root 直接登录
PasswordAuthentication no       # ⭐ 只允许密钥登录
PubkeyAuthentication yes
AllowUsers deploy admin         # 只允许这些用户
AllowTcpForwarding no           # 不需要隧道就关掉
MaxAuthTries 3                  # 最大认证尝试次数
ClientAliveInterval 300         # 空闲断开
```

```bash
sudo sshd -t                              # ⚠️ 改完先校验语法，别直接重启把自己关在门外
sudo systemctl restart sshd
sudo journalctl -u sshd -n 20 --no-pager
```

⚠️ **改远端 sshd 配置时**：先另开一个 SSH 会话验证新配置能登录，再关掉当前会话——否则配错了就永久失联，只能走控制台。

---

## 三、端口转发：给未加密的服务套壳

**本节要点**：SSH 隧道把流量**加密**后从 SSH 连接里穿过；三种模式按「谁连谁」区分。

| 模式 | 命令 | 场景 |
|------|------|------|
| 本地转发 `-L` | `ssh -L 8080:db.internal:3306 jump` | 把远端内网服务映射到本地端口 |
| 远程转发 `-R` | `ssh -R 9000:localhost:3000 jump` | 把本地服务暴露给远端 |
| 动态转发 `-D` | `ssh -D 1080 jump` | 建一个 SOCKS 代理，按需转发 |

```bash
# 本地转发：本地 13306 → 经跳板机 → 访问内网 MySQL 3306
ssh -N -L 13306:10.0.10.30:3306 deploy@jump.example.com
# 之后本地连 127.0.0.1:13306 就等于连到内网 MySQL

# 动态转发：本地 1080 起一个 SOCKS5 代理
ssh -N -D 1080 deploy@jump.example.com
```

⚠️ `-N` 表示「不执行远程命令，只做转发」；配合 `-f` 转后台。生产更推荐用 `autossh` 或 systemd unit 保活。

---

## 四、rsync 走 SSH 做同步

**本节要点**：`rsync` 默认就通过 SSH 传输，是「异地备援 + 增量同步」的常用组合；先 `--dry-run` 再执行。

```bash
# 本地 → 远端（增量）
rsync -avz --delete /srv/app/ deploy@10.0.10.20:/backup/app/

# 先干跑，看会传/删什么
rsync -avz --dry-run --delete /srv/app/ deploy@10.0.10.20:/backup/app/

# 指定端口与密钥
rsync -avz -e "ssh -p 2222 -i ~/.ssh/id_ed25519" /data/ deploy@10.0.10.20:/data/

# 限速（避免占满带宽）
rsync -avz --bwlimit=5000 /data/ deploy@10.0.10.20:/data/
```

⚠️ **源路径结尾的 `/`**：`rsync -a src/ dst/` 同步内容；`rsync -a src dst/` 会在 `dst` 下建 `src`。`--delete` 会删目标端多余文件，务必先 `--dry-run` 确认。

---

## 使用：给一台新服务器配密钥登录并加固

**本节要点**：先确保密钥能登，再关密码——顺序错了就会把自己锁在外面。

```bash
# ① 生成并上传公钥
ssh-keygen -t ed25519 -C "deploy@laptop"
ssh-copy-id deploy@10.0.10.20

# ② 验证密钥可登（关键一步，必须在关密码前成功）
ssh -o PreferredAuthentications=publickey deploy@10.0.10.20 "echo key-login-ok"

# ③ 加固 sshd（另开会话验证后再退出）
sudo sed -i "s/^#\?PasswordAuthentication .*/PasswordAuthentication no/" /etc/ssh/sshd_config
sudo sed -i "s/^#\?PermitRootLogin .*/PermitRootLogin no/" /etc/ssh/sshd_config
sudo sshd -t && sudo systemctl reload sshd

# ④ 复核：密码登录应被拒
ssh -o PreferredAuthentications=password deploy@10.0.10.20 true || echo "password login rejected"
```

判据：`PreferredAuthentications=publickey` 能登入；`password` 方式被拒；`sshd -t` 无语法错误；加固后原会话仍可操作（说明没把自己关在外面）。

---

## 延伸追问

- **密钥登录为什么更安全？**
  → 密码会在认证时被传（或哈希后传）到服务端，可被暴力猜、被弱口令攻破，且多机复用时一处泄露处处可用；密钥认证里**私钥从不离开本机**，服务端只有公钥，攻击者拿不到私钥就无法冒充。再配上 `Passphrase` + `ssh-agent` 兼顾安全与便利。
- **SSH 隧道和 VPN 的区别？**
  → SSH 隧道是**基于连接的、细粒度**的转发（某个端口 / 某个 SOCKS 代理），按需建立、无需额外服务端；VPN 是**网络层的整机隧道**（所有流量走 VPN 虚拟网卡），覆盖范围大但需要专门的 VPN 服务端与配置。临时救急用 SSH 隧道，长期 / 全局用 VPN。
- **怎么防止 SSH 暴力破解？**
  → 三层：① **关密码登录**（`PasswordAuthentication no`）——这一步就基本终结了暴力破解；② 限制来源（`AllowUsers`、防火墙只放行管理网段）与失败次数（`MaxAuthTries`）；③ 上 `fail2ban` 自动封禁高频失败 IP。⚠️ 换非标准端口只能减少扫描噪音，**不是**安全措施。

---

## 关联

- [02-主机安全与SELinux.md](02-主机安全与SELinux.md) — 安全基线与失败登录检测
- [09-备份与恢复.md](../鸟哥基础学习篇/09-备份与恢复.md) — rsync 备份策略
- [常用命令.md](../常用命令.md) — 查端口、看连接的命令速查
- [04-防火墙与NAT.md](04-防火墙与NAT.md) — 只对管理网段放行 SSH
- [README.md](README.md) — 本目录导读：版本坐标与阅读顺序
> 反向引用（本篇被下列文档引到）：[09-文件共享服务.md](09-文件共享服务.md)、[10-邮件服务器.md](10-邮件服务器.md)
