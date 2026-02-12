# Yii2 → Yii3 分页迁移

## 功能说明
Yii3 没有内置 REST 分页器（Yii2 的 `ActiveDataProvider` + `Serializer`），需要手动实现分页逻辑和 X-Pagination 响应头。

## Yii2 原始方式
```php
// Yii2 自动处理分页
$dataProvider = new ActiveDataProvider([
    'query' => $query,
    'pagination' => ['pageSize' => $pageSize],
]);
return $dataProvider; // Serializer 自动添加分页头
```

## Yii3 迁移方式

### PaginatedResult 值对象
```php
final class PaginatedResult {
    public function __construct(
        public readonly array $items,
        public readonly int $totalCount,
        public readonly int $pageCount,
        public readonly int $currentPage,
        public readonly int $perPage,
    ) {}
}
```

### PaginationService
```php
final class PaginationService {
    public function paginate(ActiveQuery $query, int $page, int $pageSize): PaginatedResult {
        $page = max(1, $page);
        $pageSize = max(1, $pageSize);
        $totalCount = (int) $query->count();
        $pageCount = $totalCount > 0 ? (int) ceil($totalCount / $pageSize) : 0;

        if ($pageCount > 0 && $page > $pageCount) {
            $page = $pageCount;
        }

        $items = $query->offset(($page - 1) * $pageSize)->limit($pageSize)->all();
        return new PaginatedResult($items, $totalCount, $pageCount, $page, $pageSize);
    }

    public function applyHeaders(ResponseInterface $response, PaginatedResult $result): ResponseInterface {
        return $response
            ->withHeader('X-Pagination-Current-Page', (string) $result->currentPage)
            ->withHeader('X-Pagination-Page-Count', (string) $result->pageCount)
            ->withHeader('X-Pagination-Per-Page', (string) $result->perPage)
            ->withHeader('X-Pagination-Total-Count', (string) $result->totalCount);
    }
}
```

### Controller 中使用
```php
$result = $this->snapshotQueryService->findPublic($params);
$response = $this->createJsonResponse($result->items);
return $this->paginationService->applyHeaders($response, $result);
```

## 与 Yii2 的兼容性
- 响应头名称完全一致：`X-Pagination-Current-Page`, `X-Pagination-Page-Count`, `X-Pagination-Per-Page`, `X-Pagination-Total-Count`
- 默认 pageSize=15（匹配 Yii2 默认值）
- page 参数从 1 开始（匹配 Yii2）
- CORS 中间件必须在 `Access-Control-Expose-Headers` 中暴露这些头，否则前端跨域请求读不到

## 注意事项
- `$query->count()` 会执行一次 SQL COUNT 查询，然后 `->all()` 再执行一次 SELECT 查询
- 分页参数从 `$request->getQueryParams()` 获取，key 为 `page` 和 `pageSize`
- 缓存时要把 page 和 pageSize 纳入 cache key
