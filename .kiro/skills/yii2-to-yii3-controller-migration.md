# Yii2 → Yii3 Controller 迁移

## 功能说明
将 Yii2 的 REST Controller（继承 `ActiveController` 或 `Controller`）迁移为 Yii3 的纯 PHP 类，使用 PSR-7 请求/响应。

## 核心差异

| Yii2 | Yii3 |
|------|------|
| `extends \yii\rest\ActiveController` | 纯 PHP 类，无继承 |
| `Yii::$app->request->get('id')` | `$request->getQueryParams()['id']` |
| `Yii::$app->request->post()` | `$request->getParsedBody()` |
| `Yii::$app->user->identity` | `$request->getAttribute('user')` |
| `return $data;`（自动序列化） | 手动 `json_encode` + 构建 Response |
| `throw new NotFoundHttpException()` | 返回错误 Response 或抛 RuntimeException |

## Controller 模板
```php
final class ServerController
{
    public function __construct(
        private readonly SnapshotQueryService $snapshotQueryService,
        private readonly PaginationService $paginationService,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {}

    public function listPublic(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        $result = $this->snapshotQueryService->findPublic($params);
        return $this->createPaginatedJsonResponse($result);
    }

    private function createJsonResponse(mixed $data, int $statusCode = 200): ResponseInterface
    {
        $json = json_encode($data, JSON_THROW_ON_ERROR);
        $stream = $this->streamFactory->createStream($json);
        return $this->responseFactory->createResponse($statusCode)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($stream);
    }
}
```

## 路由参数获取

### Query 参数（GET）
```php
$params = $request->getQueryParams();
$id = $params['id'] ?? null;
```

### 路径参数（如 `/v2/snapshots/{id}`）
```php
// 注入 CurrentRoute
public function __construct(private readonly CurrentRoute $currentRoute) {}

public function view(ServerRequestInterface $request): ResponseInterface {
    $id = $this->currentRoute->getArgument('id');
}
```
注意：`CurrentRoute` 是 `final` 类，测试时需要用反射 mock。

### POST Body
```php
$body = $request->getParsedBody();
$username = $body['username'] ?? '';
```
需要 `RequestBodyParser` 中间件在管道中（否则 `getParsedBody()` 返回 null）。

## 认证用户获取
```php
$user = $request->getAttribute('user');
if ($user === null || !isset($user['user_id'])) {
    return $this->createErrorResponse(401, 'Your request was made with invalid credentials.');
}
$userId = (int) $user['user_id'];
```

## 错误响应（Yii2 兼容格式）
每个 Controller 都需要一个 `createErrorResponse` 方法：
```php
private function createErrorResponse(int $statusCode, string $message): ResponseInterface
{
    $nameMap = [400 => 'Bad Request', 401 => 'Unauthorized', 403 => 'Forbidden', 404 => 'Not Found'];
    $typeMap = [400 => 'yii\\web\\BadRequestHttpException', ...];

    return $this->createJsonResponse([
        'name' => $nameMap[$statusCode] ?? 'Error',
        'message' => $message,
        'code' => 0,
        'status' => $statusCode,
        'type' => $typeMap[$statusCode] ?? 'yii\\web\\HttpException',
    ], $statusCode);
}
```

## 路由注册
```php
// config/web/routes.php
Route::get('/v1/server/public')
    ->action([ServerController::class, 'listPublic'])
    ->name('v1.server.public'),

// 需要认证的路由
Route::get('/v1/server/private')
    ->middleware(JwtAuthMiddleware::class)
    ->action([ServerController::class, 'listPrivate']),
```

## 注意事项
- Yii3 Controller 不自动序列化返回值，必须手动构建 PSR-7 Response
- 依赖通过构造函数注入，不是 `Yii::$app->xxx`
- `ResponseFactoryInterface` 和 `StreamFactoryInterface` 是必需依赖
- 方法名不能用 PHP 保留字（如 `public`、`private`），需要改名（如 `listPublic`、`listPrivate`）
