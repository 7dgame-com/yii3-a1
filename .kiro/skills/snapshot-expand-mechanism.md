# Snapshot Expand 机制

## 功能说明
复刻 Yii2 REST Serializer 的 `fields()`/`extraFields()` + `?expand=` 行为。Snapshot 列表和详情默认返回空对象，通过 `?expand=field1,field2` 参数选择性返回字段。

## Yii2 原始行为
- `Snapshot::fields()` 返回 `[]`（空），所以默认序列化为 `[]`
- `Snapshot::extraFields()` 定义了 `id, name, description, image, author_id, author, uuid, verse_id, code, data, metas, resources, managers`
- 其中 `name`, `description`, `image`, `author_id`, `author` 来自关联的 Verse 模型

## Yii3 实现
- `Snapshot::jsonSerialize()` → 返回 `[]`（匹配 Yii2 `fields()=[]`）
- `Snapshot::toExpandedArray(array $fields)` → 按请求的字段名返回对应值
- `Snapshot::toFullArray()` → 返回所有 extraFields（用于 `findById` 等内部调用）
- Controller 通过 `parseExpand($request)` 解析 `?expand=` 参数

## 使用示例
```
GET /v1/server/public                    → [[]]  (空对象数组)
GET /v1/server/public?expand=id,name     → [{"id":1,"name":"Test"}]
GET /v1/server/snapshot?id=1             → []    (空对象)
GET /v1/server/snapshot?id=1&expand=id,data → {"id":1,"data":"..."}
```

## 涉及文件
- `src/Model/Snapshot.php` — jsonSerialize / toExpandedArray / toFullArray / getExtraFieldsMap
- `src/Controller/V1/ServerController.php` — parseExpand / createPaginatedSnapshotResponse
- `src/Controller/V2/SnapshotController.php` — 同上
- `src/Service/SnapshotQueryService.php` — findSnapshotModel / findSnapshotModelByVerseId

## 注意事项
- Verse 关联通过 `$this->relation('verse')` 懒加载，需要 `relationQuery()` 正确定义
- `created_at` 字段需要 DateTimeImmutable → string 转换
