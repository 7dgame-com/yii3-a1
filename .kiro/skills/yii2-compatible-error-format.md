# Yii2 兼容错误响应格式

## 功能说明
所有 API 错误响应统一使用 Yii2 格式：`{name, message, code, status, type}`，确保前端无需修改即可从 Yii2 迁移到 Yii3。

## 错误响应结构
```json
{
    "name": "Bad Request",
    "message": "具体错误信息",
    "code": 0,
    "status": 400,
    "type": "yii\\web\\BadRequestHttpException"
}
```

## 实现位置
1. `src/ErrorHandler/ApiErrorRenderer.php` — 全局异常渲染（ErrorCatcher 中间件捕获的未处理异常）
2. 各 Controller 的 `createErrorResponse()` — 业务逻辑主动返回的错误
3. `src/Middleware/JwtAuthMiddleware.php` — 401 认证错误
4. `src/Controller/SwaggerController.php` — Swagger 认证错误

## HTTP 状态码到 Yii2 类型映射
| Status | name | type |
|--------|------|------|
| 400 | Bad Request | `yii\web\BadRequestHttpException` |
| 401 | Unauthorized | `yii\web\UnauthorizedHttpException` |
| 403 | Forbidden | `yii\web\ForbiddenHttpException` |
| 404 | Not Found | `yii\web\NotFoundHttpException` |
| 500 | Internal Server Error | `yii\web\ServerErrorHttpException` |

## 注意事项
- `code` 字段始终为 `0`（匹配 Yii2 默认行为）
- 500 错误在生产模式下使用通用消息，不暴露内部细节
- debug 模式（`renderVerbose`）额外包含 `file`、`line`、`trace` 字段
- 新增 Controller 时，复制 `createErrorResponse()` 方法保持格式一致
