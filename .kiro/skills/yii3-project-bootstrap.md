# Yii3 项目引导与 DI 配置

## 功能说明
从零搭建 Yii3 REST API 项目的完整引导流程，包括入口文件、DI 容器配置、中间件管道、路由注册。

## 项目骨架

### 入口文件 `public/index.php`
```php
$runner = (new HttpApplicationRunner(
    rootPath: dirname(__DIR__),
    debug: (bool) ($_ENV['YII_DEBUG'] ?? false),
    checkEvents: false,
    environment: null,  // 重要：不要传 'dev'/'prod'，否则会找不到对应配置组
))->run();
```
- `environment` 必须为 `null`，除非你在 `config/` 下创建了对应环境的子目录
- 使用 `vlucas/phpdotenv` 加载 `.env` 文件

### composer.json config-plugin 配置
```json
"config-plugin": {
    "params": ["params.php", "common/params.php", "web/params.php"],
    "params-web": ["$params"],
    "common": "common/*.php",
    "web": ["$common", "web/*.php"],
    "di": ["common/di/*.php"],
    "di-web": ["$di", "web/di/*.php"],
    "routes": "web/routes.php",
    "bootstrap-web": "web/bootstrap.php"
}
```
- `params-web` 必须存在，否则 web DI 文件中的 `$params` 变量为空
- `bootstrap-web` 用于在请求处理前执行初始化逻辑（如 `ConnectionProvider::set()`）

## DI 配置清单

### 必须注册的服务（缺一不可）

| 文件 | 注册内容 | 说明 |
|------|---------|------|
| `common/di/db.php` | `ConnectionInterface` | MySQL 连接，需要 `SchemaCache` |
| `common/di/redis.php` | `Predis\Client` | Redis 客户端 |
| `common/di/cache.php` | `CacheInterface` + `YiiCacheInterface` | PSR-16 + Yii Cache 双层 |
| `common/di/http.php` | PSR-17 工厂 | `ResponseFactoryInterface`, `StreamFactoryInterface` 等 |
| `common/di/logger.php` | `LoggerInterface` | Yii3 内部依赖 |
| `common/di/jwt.php` | `JwtService` | JWT 令牌服务 |
| `common/di/services.php` | 业务服务 | `SnapshotQueryService`, `HealthCheckService` 等 |
| `web/di/application.php` | `Application` | 中间件管道 |
| `web/di/router.php` | `RouteCollectionInterface` | 路由注册 |
| `web/di/error-handler.php` | `ErrorHandler` + `ErrorCatcher` | 错误处理 |
| `web/di/middleware.php` | 中间件类 | CORS, JWT Auth |

### PSR-17 HTTP 工厂（最容易遗漏）
Yii3 不自动注册 PSR-17 工厂，必须手动映射：
```php
ResponseFactoryInterface::class => ResponseFactory::class,
StreamFactoryInterface::class => StreamFactory::class,
ServerRequestFactoryInterface::class => ServerRequestFactory::class,
// ... 共 6 个接口
```
推荐使用 `httpsoft/http-message` 包。

### Cache 双层结构
```php
// PSR-16 SimpleCache（底层 Redis 实现）
CacheInterface::class => fn(RedisClient $client) => new RedisCache($client),
// Yii Cache（上层封装，提供 getOrSet 等便捷方法）
YiiCacheInterface::class => fn(CacheInterface $handler) => new Cache($handler),
```

### DB 连接需要 SchemaCache
```php
ConnectionInterface::class => function (CacheInterface $psrCache) {
    $driver = new Driver($dsn, $user, $pass);
    $schemaCache = new SchemaCache($psrCache);
    return new Connection($driver, $schemaCache);
};
```

## 中间件管道顺序
```php
// config/web/params.php
'middlewares' => [
    ErrorCatcher::class,      // 1. 捕获所有异常
    CorsMiddleware::class,    // 2. CORS 头
    RequestBodyParser::class, // 3. 解析 POST body
    Router::class,            // 4. 路由分发
],
```
- `ErrorCatcher` 必须在最外层
- `RequestBodyParser` 在 `Router` 之前，否则 POST 请求体为空

## Bootstrap（ActiveRecord 必需）
```php
// config/web/bootstrap.php
return [
    static function (ContainerInterface $container): void {
        ConnectionProvider::set($container->get(ConnectionInterface::class));
    },
];
```
不调用 `ConnectionProvider::set()` 的话，所有 ActiveRecord 查询会报 "Connection not set" 错误。

## 环境变量
Dockerfile 中必须设置 `variables_order = EGPCS`，否则 `$_ENV` 为空：
```dockerfile
RUN echo "variables_order = EGPCS" > /usr/local/etc/php/conf.d/env.ini
```

## 常见坑
1. `config/params.php` 不能与 `config/common/params.php` 有重复 key，否则合并时覆盖
2. 路由文件中 `$params` 变量来自 config-plugin 合并，确保 `params-web` 配置正确
3. `Application` 的中间件管道通过 `$params['middlewares']` 注入，不是硬编码
