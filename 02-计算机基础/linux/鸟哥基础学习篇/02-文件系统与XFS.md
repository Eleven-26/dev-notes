# 文件系统与XFS

> ext4 / XFS 的差异、格式化与挂载、XFS 扩容与备份还原、inode 与目录、
> fsck / xfs_repair 的边界；第四版重点新增 XFS 实操。
>
> 内容整理自个人学习笔记。**基础框架**参考《鸟哥的 Linux 私房菜：基础学习篇（第四版）》
> 相关章节（Linux 文件系统与目录、磁盘配额与进阶文件系统管理）的读后整理；inode / Page Cache 原理见 [文件系统与IO.md](../文件系统与IO.md)。

---

## 一、ext4 与 XFS 的差异在哪？

**本节要点**：两者都能用，差异集中在「日志能力、扩容缩容、面向的场景」——CentOS 7 起默认 XFS，Ubuntu 仍默认 ext4。

| 维度 | ext4 | XFS |
|------|------|-----|
| 日志 | 有序日志（可关） | 元数据日志 |
| 快照 | ❌ | ❌（靠 LVM 快照） |
| 数据校验和 | ❌（仅元数据） | ❌（元数据校验和，数据靠上层） |
| 在线扩容 | ✅ `resize2fs` | ✅ `xfs_growfs` |
| 缩容 | ✅（卸载后 `resize2fs`） | ⚠️ **不支持** |
| 定位 | 通用、稳、可缩容 | 大文件、高并发、数据库 |

⭐ 一句话：**要能缩容选 ext4，要大文件与并发选 XFS**。CentOS / RHEL 系默认 XFS 是因为它在多线程大文件 I/O 上表现更好。

---

## 二、格式化与挂载怎么做？

**本节要点**：`mkfs.xfs` / `mkfs.ext4` 建文件系统，挂载选项决定读写行为——`noatime`、`discard` 是常被忽略的两个。

```bash
sudo mkfs.xfs /dev/vg_data/lv_data          # 建 XFS
sudo mkfs.ext4 /dev/vg_data/lv_data         # 建 ext4

# 挂载：临时
sudo mount -t xfs -o noatime /dev/vg_data/lv_data /data
# 挂载：写入 fstab（第六字段对 XFS 忽略 fsck 顺序）
# UUID=xxxx  /data  xfs  defaults,noatime  0  0
```

| 选项 | 作用 | 什么时候用 |
|------|------|-----------|
| `defaults` | 等价 `rw,suid,dev,exec,auto,nouser,async` | 缺省 |
| `noatime` | 读时不更新访问时间 | 日志 / 数据库类高频读，减少写 |
| `discard` | 删除即 TRIM | SSD；⚠️ 高并发下不推荐，改 `fstrim -av` 定期跑 |
| `nofail` | 挂不上不阻塞启动 | 外挂数据盘 |

⚠️ `mkfs.xfs` 会**拒绝在已有文件系统上执行**（防止误格式化），确认要重做得加 `-f`。

---

## 三、XFS 怎么扩容？缩容又怎么办？

**本节要点**：XFS 只能长不能缩；扩容是「先扩 LV，再 `xfs_growfs`」，缩容只能「备份 → 重建 → 回灌」。

```bash
# 前提：LV 已经扩过（lvextend），XFS 才能跟着长
sudo lvextend -L +50G /dev/vg_data/lv_data
sudo xfs_growfs /data            # 若文件系统是 ext4，改用 resize2fs
xfs_info /data                   # 看 block size / 是否在 online 扩容
```

⚠️ **XFS 不支持缩容**（这是设计取舍，不是缺失）：它的空间分配靠 **B+ 树 + AG（分配组）**，数据块可以散布在任意 AG 里，缩容要么无法判断哪些块在用、要么代价极高。真要变小，走「备份 → 建更小的文件系统 → 回灌」。

---

## 四、XFS 怎么备份还原？

**本节要点**：XFS 自带 `xfsdump` / `xfsrestore`，支持**增量**备份，但**只能在同一文件系统类型上还原**——跨文件系统用 `tar` / `rsync`。

```bash
sudo xfsdump -l 0 -f /backup/root.dump /          # 全量（level 0）
sudo xfsdump -l 1 -f /backup/root.incr /          # 增量（基于上次）
sudo xfsrestore -f /backup/root.dump /restore_dir # 还原到指定目录
```

| 维度 | xfsdump / xfsrestore | tar / rsync |
|------|----------------------|-------------|
| 增量 | ✅ `-l 0/1/…` | ❌（tar 需自己算差异） |
| 跨文件系统 | ❌ 只认 XFS | ✅ |
| 保留 ACL / 扩展属性 | ✅ | tar 需 `--acls --xattrs` |
| 典型用途 | XFS 分区的一致性快照级备份 | 通用打包、跨机同步 |

⚠️ `xfsdump` **不能**备份一个正在被写入的文件系统的「一致视图」——要一致性，先打 LVM 快照再 dump 快照。

---

## 五、inode 与目录是怎么组织的？

**本节要点**：inode 存元数据（不含文件名与内容），目录是「文件名 → inode 号」的映射表；XFS 的 inode 是**动态分配**的。

### 5.1 inode 存什么

| inode 存什么 | inode 不存什么 |
|--------------|----------------|
| 权限、属主属组、大小、时间戳（atime/mtime/ctime） | ❌ 文件名 |
| 数据块指针、链接计数、文件类型 | ❌ 文件内容本身 |

### 5.2 目录是「名字 → inode」的表

- 打开 `/data/a.txt`：内核逐级解析目录项，最终拿到 `a.txt` 的 inode 号；
- **硬链接**：多个文件名指向同一个 inode（`ln`），`links` 计数 +1；
- **软链接**：一个独立文件，内容是目标路径（`ln -s`），可以是相对 / 绝对路径，甚至可以悬空。

### 5.3 XFS 的动态 inode

- ext4 在建文件系统时**预分配固定数量的 inode**，用满就报 `No space left on device`（哪怕还有空间）；
- XFS **动态分配** inode：按需从空闲空间里划，理论上不会「inode 耗尽而空间充足」。

⚠️ 但 XFS 有个相关坑：inode 密集的小文件场景会消耗「**inode chunk**」与 AG 空间，`df -i` 的读数意义与 ext4 不同。

---

## 六、文件系统检查与修复什么时候能做？

**本节要点**：`fsck` / `xfs_repair` 只能在**卸载状态**下跑；已挂载的根分区只能靠重启时的自动检查。

```bash
sudo umount /data
sudo xfs_repair /dev/vg_data/lv_data      # XFS 专用
sudo fsck.ext4 -f /dev/vg_data/lv_data    # ext4 专用
```

- XFS 有**日志**，正常关机后一般不需要 `xfs_repair`；日志重放会在挂载时自动完成；
- ⚠️ `xfs_repair -L` **强制清空日志**是危险操作，只在明确知道日志损坏、且已备份时用；
- ext4 的 `fsck` 修复前会交互确认，`-y` 全自动（脚本里慎用）；
- ⚠️ **不要对已挂载的文件系统跑修复工具**，会造成二次损坏。

---

## 使用：建一个 XFS 数据盘并验证

**本节要点**：从格式化到验证的一条龙命令，附带「空间不还」的常见现象观察。

```bash
# ① 格式化
sudo mkfs.xfs -f /dev/vg_data/lv_data

# ② 挂载
sudo mkdir -p /data && sudo mount /dev/vg_data/lv_data /data

# ③ 看空间与 inode
df -h /data
df -i /data              # XFS 的 inode 计数是动态的
xfs_info /data

# ④ 扩容（需先 lvextend 扩 LV）
sudo xfs_growfs /data

# ⑤ 删了大文件但空间不还？先看有没有进程还拿着它
sudo lsof +L1 | head
```

判据：`df -h` 容量变化符合预期、`xfs_info` 的 `data blocks` 增长、删除文件后若空间不还则用 `lsof +L1` 找到仍持有 fd 的进程。

---

## 延伸追问

- **XFS 为什么不能缩容？**
  → 它是**设计取舍**：XFS 把空间切成多个 AG（分配组），数据块可散布在任意 AG，要缩容必须先确定「将裁掉的区域里没有在用数据」，而 XFS 没有为此保留廉价的反向索引，代价极高，官方就此不做。需要缩容就选 ext4，或重建 + 回灌。
- **xfsdump 能增量备份吗？**
  → 能，用 `-l 0/1/2…` 标级别，`xfsdump` 自己维护「上次 dump 到哪」的记录（在 `/var/lib/xfsdump/inventory`）。⚠️ 但它只认 XFS，且还原目标也应是 XFS；跨文件系统一律用 `tar` / `rsync`。
- **inode 耗尽了怎么办？**
  → 先 `df -i` 确认。ext4 下是**建文件系统时定的**，无法在线增加，只能找出小文件集中的目录（`find -xdev -type f | wc -l` 逐目录统计）清理，或重建文件系统并调大 `-i`（每多少字节一个 inode）。XFS 动态分配，一般不会耗尽，但小文件过多会吃 AG 空间。
- **`noatime` 到底省了什么？**
  → 省掉「每次读都写回 inode 的 atime」，对高频读的日志 / 数据库文件能显著减少写。现代替代是 `relatime`（默认）：只在 atime 早于 mtime 或超过 24h 时更新，兼顾省写与 atime 语义。

---

## 关联

- [文件系统与IO.md](../文件系统与IO.md) — VFS、inode、从 write 到落盘的 I/O 栈（原理层）
- [01-安装与磁盘分区.md](01-安装与磁盘分区.md) — LVM 扩容与 fstab（本节的上一环）
- [09-备份与恢复.md](09-备份与恢复.md) — xfsdump 与 tar / rsync 的取舍全景
- [内存管理.md](../内存管理.md) — Page Cache 与「删了不还空间」的另一半原因
- [README.md](README.md) — 本目录导读：版本坐标与阅读顺序
> 反向引用（本篇被下列文档引到）：[08-软件包管理.md](08-软件包管理.md)
