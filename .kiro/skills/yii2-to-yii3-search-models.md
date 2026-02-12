# Yii2 → Yii3 搜索模型迁移

## 功能说明
将 Yii2 的搜索模型（继承 Model，使用 `ActiveDataProvider`）迁移为 Yii3 的纯服务类，直接构建 `ActiveQuery`。

## 核心差异

| Yii2 | Yii3 |
|------|------|
| `class SnapshotSearch extends Snapshot` | `class SnapshotSearch`（纯类，不继承） |
| `$this->load($params)` 加载参数 | 方法参数直接传入 |
| 返回 `ActiveDataProvider` | 返回 `ActiveQuery` |
| `Model::find()` 静态方法 | `new ActiveQuery(Snapshot::class)` |

## Yii2 原始写法
```php
class SnapshotSearch extends Snapshot {
    public function search($params) {
        $query = Snapshot::find()->alias('snapshot');
        $dataProvider = new ActiveDataProvider(['query' => $query]);
        $this->load($params);
        // ... 添加条件
        return $dataProvider;
    }
}
```

## Yii3 迁移写法
```php
class SnapshotSearch {
    public function searchPublic(array $params = []): ActiveQuery {
        $query = (new ActiveQuery(Snapshot::class))->alias('snapshot');

        $query->innerJoin('verse', '{{verse}}.[[id]] = {{snapshot}}.[[verse_id]]')
            ->innerJoin('verse_property', '{{verse_property}}.[[verse_id]] = {{verse}}.[[id]]')
            ->innerJoin('property', '{{property}}.[[id]] = {{verse_property}}.[[property_id]]')
            ->andWhere(['property.key' => 'public'])
            ->orderBy(['snapshot.id' => SORT_DESC]);

        if (!empty($params['tags'])) {
            $this->applyTagFilter($query, $params['tags']);
        }
        return $query;
    }
}
```

## ActiveQuery 用法差异

### 创建查询
```php
// Yii2
$query = Snapshot::find();
$query = Snapshot::find()->where(['id' => 1])->one();

// Yii3
$query = new ActiveQuery(Snapshot::class);
$query = (new ActiveQuery(Snapshot::class))->where(['id' => 1])->one();
```

### 别名
```php
// Yii2
$query->alias('t');

// Yii3（相同）
$query->alias('snapshot');
```

### JOIN 语法
```php
// Yii2 和 Yii3 相同
$query->innerJoin('verse', '{{verse}}.[[id]] = {{snapshot}}.[[verse_id]]');
```

### viaTable（多对多关联）
```php
// Yii2 和 Yii3 相同
$this->hasMany(Tags::class, ['id' => 'tags_id'])
    ->viaTable('verse_tags', ['verse_id' => 'id']);
```

## 标签过滤
```php
public function applyTagFilter(ActiveQuery $query, string|array $tagIds): ActiveQuery {
    if (is_string($tagIds)) {
        $tagIds = array_filter(
            array_map('intval', explode(',', $tagIds)),
            fn(int $id) => $id > 0,
        );
    }
    if (empty($tagIds)) return $query;

    $query->innerJoin('verse_tags', '{{verse_tags}}.[[verse_id]] = {{verse}}.[[id]]')
        ->andWhere(['verse_tags.tags_id' => $tagIds]);
    return $query;
}
```

## 服务层编排
搜索模型只负责构建 Query，分页和缓存由 `SnapshotQueryService` 编排：
```php
class SnapshotQueryService {
    public function findPublic(array $params): PaginatedResult {
        return $this->cache->getOrSet($cacheKey, fn() =>
            $this->paginationService->paginate(
                $this->snapshotSearch->searchPublic($params),
                $page, $pageSize,
            ),
            30, // 缓存 30 秒
        );
    }
}
```

## 注意事项
- Yii3 的 `ActiveQuery` 没有 `getJoins()` 公开方法（需要检查是否已 join 时用反射或自行跟踪）
- `$query->count()` 返回 string，需要 `(int)` 转换
- 搜索模型不需要注册到 DI 容器（无状态，可直接 new 或自动注入）
