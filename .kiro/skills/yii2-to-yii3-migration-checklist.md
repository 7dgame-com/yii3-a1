# Yii2 → Yii3 迁移总体检查清单

## 功能说明
从 Yii2 迁移到 Yii3 的完整检查清单，涵盖所有需要迁移的组件和常见陷阱。适用于任何 Yii2 REST API 项目。

## 迁移顺序（推荐）
1. 项目骨架 + DI 配置 → 参考 `yii3-project-bootstrap.md`
2. 中间件（CORS、Auth）→ 参考 `yii2-to-yii3-middleware-migration.md`
3. 数据模型 → 参考 `yii3-activerecord-models.md`
4. 搜索模型 → 参考 `yii2-to-yii3-search-models.md`
5. 服务层（JWT、分页、缓存）→ 参考对应 skill
6. 控制器 → 参考 `yii2-to-yii3-controller-migration.md`
7. 错误处理 → 参考 `yii2-compatible-error-format.md`
8. Docker 部署 + 并行测试 → 参考 `yii2-yii3-docker-side-by-side-testing.md`

## 组件迁移映射

| Yii2 组件 | Yii3 替代 | Skill 文件 |
|-----------|----------|-----------|
| `\yii\filters\Cors` | `CorsMiddleware` (PSR-15) | middleware-migration |
| `HttpBearerAuth` | `JwtAuthMiddleware` (PSR-15) | middleware-migration |
| `bizley/jwt` | `lcobucci/jwt` v5 直接使用 | jwt-auth |
| Redis ActiveRecord | `predis/predis` 直接操作 | jwt-auth |
| `ActiveDataProvider` | `PaginationService` + `PaginatedResult` | pagination |
| `Model::find()` | `new ActiveQuery(Model::class)` | search-models |
| `SearchModel extends Model` | 纯服务类 | search-models |
| `Controller::behaviors()` | 路由级 `->middleware()` | controller-migration |
| `Yii::$app->request` | `ServerRequestInterface` | controller-migration |
| `Yii::$app->response` | `ResponseInterface` | controller-migration |
| `Yii::$app->user` | `$request->getAttribute('user')` | controller-migration |
| `fields()` / `extraFields()` | `jsonSerialize()` + `toExpandedArray()` | snapshot-expand-mechanism |
| `TimestampBehavior` | 手动在 `insert()`/`update()` 中实现 | activerecord-models |
| `BlameableBehavior` | 手动 `touchBlame()` 方法 | activerecord-models |
| `urlManager` | `yiisoft/router` + `routes.php` | project-bootstrap |
| `ErrorHandler` | `ErrorCatcher` 中间件 + `ApiErrorRenderer` | error-format |

## 最容易踩的坑（按频率排序）

### 1. `$_ENV` 为空
PHP 默认 `variables_order` 不包含 `E`，Docker 中必须设置：
```ini
variables_order = EGPCS
```

### 2. ActiveRecord 动态属性
PHP 8.2+ 弃用动态属性，所有 DB 列必须声明为 `public` 属性。

### 3. `ConnectionProvider::set()` 未调用
必须在 `bootstrap-web` 中调用，否则所有 ActiveRecord 查询失败。

### 4. PSR-17 工厂未注册
Yii3 不自动注册 `ResponseFactoryInterface` 等，需要手动映射到 `httpsoft/http-message`。

### 5. `relationQuery()` 未覆盖
Yii3 通过 `relationQuery()` 发现关系，仅定义 `getXxx()` 不够。

### 6. `RequestBodyParser` 缺失
不在中间件管道中的话，`$request->getParsedBody()` 返回 null。

### 7. `environment` 参数
`HttpApplicationRunner` 的 `environment` 传 `'dev'` 会找 `config/dev/` 目录，通常应传 `null`。

### 8. config-plugin 的 `params-web`
不配置的话，web DI 文件中 `$params` 为空数组。

### 9. Controller 方法名
`public`、`private` 是 PHP 保留字，不能作为方法名，需要改为 `listPublic`、`listPrivate`。

### 10. `CurrentRoute` 是 final 类
测试时不能直接 mock，需要用反射设置内部状态。

## 依赖包对照

### Yii2 → Yii3 核心包
```
yii2                    → yiisoft/yii-http + yiisoft/di + yiisoft/config
yii2-redis              → predis/predis
bizley/jwt              → lcobucci/jwt
yii2 urlManager         → yiisoft/router + yiisoft/router-fastroute
yii2 ErrorHandler       → yiisoft/error-handler
yii2 ActiveRecord       → yiisoft/active-record + yiisoft/db + yiisoft/db-mysql
yii2 Cache              → yiisoft/cache + yiisoft/cache-redis
yii2 Log                → yiisoft/log + yiisoft/log-target-file
```

### 新增必需包（Yii2 不需要）
```
httpsoft/http-message    — PSR-17 HTTP 工厂实现
yiisoft/yii-runner-http  — HTTP 应用运行器
yiisoft/request-body-parser — POST body 解析
yiisoft/yii-event        — 事件系统
yiisoft/injector         — 方法注入
vlucas/phpdotenv         — .env 文件加载
```

## 测试策略
- 单元测试：PHPUnit 10+，mock 外部依赖
- 属性测试：Eris（`giorgiosironi/eris`），验证通用正确性属性
- 集成测试：Docker 并行运行 Yii2 和 Yii3，逐端点对比响应
