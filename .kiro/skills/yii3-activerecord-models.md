# Yii3 ActiveRecord Models

## 功能说明
所有 `src/Model/` 下的 ActiveRecord 模型已适配 Yii3 + PHP 8.2+，解决动态属性弃用问题，并与数据库 schema 完全对齐。

## 关键设计决策

### 1. 公共属性声明
每个模型必须声明与数据库列对应的 `public` 属性，否则 Yii3 的 `populateProperty()` 会触发 PHP 8.2 动态属性弃用错误。属性类型必须与 Yii3 DB 驱动返回的类型兼容：
- `int` 列 → `public int $col = 0;` 或 `public ?int $col = null;`
- `varchar/text` 列 → `public ?string $col = null;`
- `datetime` 列 → `public \DateTimeImmutable|string|null $col = null;`（Yii3 MySQL 驱动返回 `DateTimeImmutable`）

### 2. 关系定义
Yii3 ActiveRecord 通过 `relationQuery()` 方法发现关系，而非 Yii2 的 `getXxx()` 魔术方法。需要同时定义两者：
```php
public function getVerse(): ActiveQuery {
    return $this->hasOne(Verse::class, ['id' => 'verse_id']);
}
public function relationQuery(string $name): ActiveQuery {
    return match ($name) {
        'verse' => $this->hasOne(Verse::class, ['id' => 'verse_id']),
        default => parent::relationQuery($name),
    };
}
```

### 3. Snapshot 的 fields/extraFields 机制
匹配 Yii2 行为：`jsonSerialize()` 返回空数组（对应 `fields()=[]`），通过 `toExpandedArray($fields)` 支持 `?expand=` 参数。

### 4. Verse 的 BlameableBehavior
使用 `author_id`/`updater_id`（匹配 Yii2 配置），而非 `created_by`/`updated_by`。

## 模型与数据库列映射
所有模型属性严格对应 `docker/init.sql` 中的表定义。参考该文件获取完整 schema。

## 依赖
- `yiisoft/active-record` ^1.0
- `yiisoft/db-mysql` ^2.0（datetime 列返回 `DateTimeImmutable`）
- `ConnectionProvider::set()` 必须在 bootstrap 阶段调用（见 `config/web/bootstrap.php`）

## 注意事项
- 新增模型时必须声明所有数据库列为 public 属性
- datetime 类型必须使用 `\DateTimeImmutable|string|null` 联合类型
- 序列化 datetime 字段时需检查类型并格式化：`$val instanceof \DateTimeImmutable ? $val->format('Y-m-d H:i:s') : $val`
