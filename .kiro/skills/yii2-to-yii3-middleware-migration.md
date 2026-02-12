# Yii2 Behavior → Yii3 PSR-15 中间件迁移

## 功能说明
将 Yii2 的 Controller behavior（CORS、HttpBearerAuth）迁移为 Yii3 的 PSR-15 中间件。

## 核心差异

| Yii2 | Yii3 |
|------|------|
| `Controller::behaviors()` 返回数组 | 独立的 `MiddlewareInterface` 类 |
| `\yii\filters\Cors` | `CorsMiddleware implements MiddlewareInterface` |
| `HttpBearerAuth` behavior | `JwtAuthMiddleware implements MiddlewareInterface` |
| behavior 绑定在 Controller 上 | 中间件可以全局或路由级别应用 |
| `$this->user->identity` | `$request->getAttribute('user')` |

## CORS 中间件

### Yii2 原始写法
```php
public function behaviors() {
    return [
        'cors' => ['class' => \yii\filters\Cors::class],
    ];
}
```

### Yii3 迁移写法
```php
final class CorsMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getMethod() === 'OPTIONS') {
            return $this->addCorsHeaders($this->responseFactory->createResponse(200));
        }
        return $this->addCorsHeaders($handler->handle($request));
    }
}
```
- 注入 `ResponseFactoryInterface`（不是 `new Response()`）
- OPTIONS 预检请求直接返回，不传递给下游
- CORS 头中必须包含 `Access-Control-Expose-Headers` 暴露分页头

## JWT 认证中间件

### Yii2 原始写法
```php
public function behaviors() {
    return [
        'authenticator' => [
            'class' => HttpBearerAuth::class,
            'optional' => ['public', 'checkin'],
        ],
    ];
}
```

### Yii3 迁移写法
两种应用方式：

**方式一：路由级别（推荐）**
```php
// routes.php
Route::get('/v1/server/private')
    ->middleware(JwtAuthMiddleware::class)
    ->action([ServerController::class, 'listPrivate']),
```

**方式二：全局 + 路由白名单**
```php
// 中间件内部判断路径
private array $protectedRoutes = ['/v1/server/private', '/v1/server/group'];
```

### 用户身份传递
```php
// 中间件中注入
$request = $request->withAttribute('user', ['user_id' => $userId]);
return $handler->handle($request);

// Controller 中读取
$user = $request->getAttribute('user');
if ($user === null || !isset($user['user_id'])) {
    return $this->createErrorResponse(401, '...');
}
```

## 错误响应格式
中间件返回的错误必须与 Controller 的错误格式一致（Yii2 兼容格式）：
```php
private function createUnauthorizedResponse(): ResponseInterface
{
    $body = json_encode([
        'name' => 'Unauthorized',
        'message' => 'Your request was made with invalid credentials.',
        'code' => 0,
        'status' => 401,
        'type' => 'yii\\web\\UnauthorizedHttpException',
    ]);
    return $this->responseFactory->createResponse(401)
        ->withHeader('Content-Type', 'application/json')
        ->withBody($this->streamFactory->createStream($body));
}
```

## 注意事项
- Yii3 中间件通过构造函数注入依赖，不是 `Yii::$app->xxx`
- 全局中间件在 `config/web/params.php` 的 `middlewares` 数组中配置
- 路由级中间件在 `routes.php` 中通过 `->middleware()` 链式调用
- 中间件必须在 `config/web/di/middleware.php` 中注册到 DI 容器
