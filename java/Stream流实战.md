# Java Stream 流实战

> 取列、取多列、List 转 Map、分组、累加、排序、去重等高频场景的可复制代码，以及 toMap 的重复 key 坑
>
> 内容整理自个人学习笔记。原文件名为 `lambda表达式.md`，但内容实为 **Stream API 实战**，已改名归位。

---

## 一、取一列

```java

// 单层 list：取字段 → 过滤空 → 去重 → 收集
List<Item> list = new ArrayList<>();
List<String> ids = list.stream()
    .map(Item::getId)
    .filter(id -> id != null && !id.isEmpty())
    .distinct()
    .collect(Collectors.toList());

// 双层 list：用 flatMap 把嵌套结构拍平
List<List<Item>> nestedItemList = new ArrayList<>();
List<String> ids = nestedItemList.stream()
    .flatMap(innerList -> innerList.stream().map(Item::getId))
    .collect(Collectors.toList());
```

> ⭐ `map` 是**一对一**转换，`flatMap` 是**一对多后拍平**——处理嵌套集合必须用 `flatMap`。

## 二、取多列（转成 DTO）

```java

List<BillNoticeDTO> billNotices = new ArrayList<>();
List<Item> itemList = billNotices.stream()
    .map(dto -> new Item(dto.getId(), dto.getCode()))
    .collect(Collectors.toList());
```

## 三、List 转 Map

### 3.1 取两列组成 Map

```java

// 取两列返回一个 map
Map<String, Integer> codeToIdMap = userList.stream()
    .collect(Collectors.toMap(User::getCode, User::getId));
```

### 3.2 用列表字段作为 key

```java

// id 相同时会抛 IllegalStateException
Map<String, User> userMap = userList.stream()
    .collect(Collectors.toMap(User::getId, user -> user));

// 或
Map<String, User> userMap = userList.stream()
    .collect(Collectors.toMap(User::getId, Function.identity()));

// 有重复 id 时——取第一个出现的用户对象
Map<String, User> userMap = userList.stream()
    .collect(Collectors.toMap(User::getId, user -> user, (user1, user2) -> user1));

// 取最后一个出现的用户对象
Map<String, User> userMap = userList.stream()
    .collect(Collectors.toMap(User::getId, user -> user, (user1, user2) -> user2));
```

> ⚠️ **`Collectors.toMap` 遇到重复 key 会抛 `IllegalStateException: Duplicate key`**，
> 只要不能保证 key 唯一，就**必须**传第三个参数（合并函数）来明确保留策略。

### 3.3 自定义组合 key

```java

// 创建 Map<String, Item>：用字符串拼接做 key
Map<String, Item> mapByIds = items.stream()
    .collect(Collectors.toMap(
        item -> item.getRenterId() + "_" + item.getContractId(),
        Function.identity()));

// 或（等价，用 StringBuilder 拼接）
Map<String, Item> mapByIds = items.stream()
    .collect(Collectors.toMap(
        item -> {
            StringBuilder builder = new StringBuilder();
            builder.append(item.getRenterId()).append("_").append(item.getContractId());
            return builder.toString();
        },
        Function.identity()));

// 创建 Map<String, List<Item>>：同 key 的归到一组
Map<String, List<Item>> mapByIdStrings = items.stream()
    .collect(Collectors.groupingBy(
        item -> {
            StringBuilder sb = new StringBuilder();
            sb.append(item.getRenterId()).append("_").append(item.getContractId());
            return sb.toString();
        }));

// 创建 Map<List<String>, List<Item>>：key 本身是 List
Map<List<String>, List<Item>> mapByIdLists = items.stream()
    .collect(Collectors.groupingBy(
        item -> {
            List<String> ids = new ArrayList<>();
            ids.add(item.getRenterId());
            ids.add(item.getContractId());
            return ids;
        }));
```

> **注**：`Function.identity()` 是一个预定义的函数，它接受一个输入参数并返回该参数本身（等价于 `t -> t`）。

> 💡 **用 `toMap` 还是 `groupingBy`？**
> `toMap` 要求 key 唯一（重复需显式处理），`groupingBy` 天然支持一个 key 对应多个元素，返回 `Map<K, List<V>>`。

### 3.4 Map 转回 List

```java

// 按字段分组，得到 Map<String, List<User>>
Map<String, List<User>> userMap = userList.stream()
    .collect(Collectors.groupingBy(User::getId));
```

## 四、累加

```java

// BigDecimal 类型（金额计算必须用 BigDecimal，避免精度丢失）
BigDecimal totalAmount = items.stream()
    .map(Item::getAmount)
    .filter(Objects::nonNull)
    .reduce(BigDecimal.ZERO, BigDecimal::add);

// int 类型
int totalAmount = items.stream()
    .filter(Objects::nonNull)
    .mapToInt(item -> item.getAmount() != null ? item.getAmount() : 0)
    .sum();
```

> ⭐ 金额累加用 `reduce(BigDecimal.ZERO, BigDecimal::add)`，**初始值不能省**，否则空集合会返回 `Optional.empty`。

## 五、排序

```java

// 单字段升序
List<Item> sortedList = items.stream()
    .sorted(Comparator.comparing(Item::getName))
    .collect(Collectors.toList());
```

**多字段与降序**：

```java

// 先按 age 升序，再按 name 降序
items.stream()
    .sorted(Comparator.comparing(Item::getAge)
        .thenComparing(Item::getName, Comparator.reverseOrder()));

// 直接原地排序（更省一次拷贝，推荐）
items.sort(Comparator.comparing(Item::getName));
```

## 六、去重

### 6.1 单列去重，返回单列

```java

// 返回单列，去重、去空
public static List<String> extractUniqueNonEmptyNames(List<User> userList) {
    return userList.stream()
        // 过滤掉 name 为空或空白的用户
        .filter(user -> user.getName() != null && !user.getName().trim().isEmpty())
        // 映射到 name 属性
        .map(User::getName)
        // 去重
        .distinct()
        .collect(Collectors.toList());
}
```

### 6.2 按字段去重，返回原对象

```java

// 单列去重，返回原数据：以 name 为键，遇到相同 name 保留第一个出现的 User
public static List<User> removeDuplicatesByName(List<User> userList) {
    return userList.stream()
        .collect(Collectors.collectingAndThen(
            Collectors.toMap(User::getName, Function.identity(), (u1, u2) -> u1),
            map -> new ArrayList<>(map.values())   // 将 Map 的 values 转回 List
        ));
}

// 组合 key 去重，返回原数据
public static List<User> removeDuplicatesByNameAndPhone(List<User> userList) {
    return userList.stream()
        .collect(Collectors.collectingAndThen(
            Collectors.toMap(
                user -> user.getName() + "#" + user.getPhone(),  // 用 # 分隔，确保组合唯一
                Function.identity(),
                (u1, u2) -> u1),
            map -> new ArrayList<>(map.values())
        ));
}
```

> ⭐ **`distinct()` 只能按对象整体去重**（依赖 `equals`/`hashCode`），想按**某个字段**去重就用
> `collectingAndThen(toMap(字段, identity(), (u1,u2)->u1), m -> new ArrayList<>(m.values()))` 这个套路。
> 注意：`toMap` 默认返回 `HashMap`，**结果顺序不保证与源列表一致**；需要保序就显式传 `LinkedHashMap::new`。

## 七、易踩的坑

| 坑 | 说明 | 规避 |
|---|---|---|
| **`toMap` 重复 key 抛异常** | `IllegalStateException: Duplicate key` | 传第三个合并函数参数 |
| **Stream 不能复用** | 一个 Stream 被消费后再次使用会抛 `IllegalStateException: stream has already been operated upon or closed` | 每次重新 `list.stream()` |
| **`distinct()` 按字段去重无效** | 只按整体对象比较 | 用 `collectingAndThen` + `toMap` 套路 |
| **`sorted()` 后忘了 `collect`** | `sorted` 是中间操作，不收集则不会执行 | 终端操作 `collect` 到新 List |
| **在流中修改源集合** | 可能抛 `ConcurrentModificationException` | 先收集结果，再操作原集合 |
| **`parallelStream()` 滥用** | 小数据量反而更慢，且共享可变状态时不安全 | 数据量大且无共享状态时再用 |
| **空集合 `.get(0)`** | 返回 null 或越界 | 用 `findFirst().orElse(null)`、`Optional` 兜底 |
