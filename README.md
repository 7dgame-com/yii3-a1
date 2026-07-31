# MrPP API

Mixed Reality Platform REST API — 基于 Yii3 框架构建的元宇宙场景管理平台后端服务。

提供场景（Verse）、快照（Snapshot）、标签（Tags）、用户认证等 RESTful API，支持 JWT 认证、Redis 缓存、Swagger 文档。

## 技术栈

- PHP 8.1+
- Yii3 (yiisoft/yii-http, yiisoft/router, yiisoft/active-record, yiisoft/di)
- MySQL 8.0
- Redis 7
- JWT 认证 (lcobucci/jwt v5)
- PSR-7 / PSR-15 / PSR-11 标准
- Docker

## 快速开始

### 环境要求

- Docker & Docker Compose
- 或 PHP 8.1+、Composer、MySQL、Redis

### Docker 启动（推荐）

```bash
docker compose up --build
```

服务启动后访问 `http://localhost:8080`。

`.env.example` 默认通过 `http://host.docker.internal:8093` 访问宿主机上运行的白牌
插件；Compose 为 Linux Docker 显式配置了 `host-gateway`。生产环境不要沿用该地址，
应将 `WHITELABEL_SERVICE_URL` 设置为 A1 容器可解析的内部服务 DNS，并把 A1 与白牌
插件接入受控的共享容器网络。

### 本地开发

```bash
cp .env.example .env
# 编辑 .env 配置数据库、Redis、JWT 密钥路径

composer install

# 导入数据库
mysql -u root mrpp < docker/init.sql

# 启动开发服务器
php -S 0.0.0.0:8080 -t public
```

### 生产运行说明

仓库中的 `php -S` 仅用于本地开发，它是单 worker 服务，不承担生产并发与抗滥用
能力。生产应使用多 worker PHP-FPM（或等价应用服务器）并置于反向代理之后；反向
代理需对公开白牌查询接口配置限流、请求体/响应体上限和短连接超时，A1 到插件的
上游连接也应保持短超时。

## 环境变量

| 变量 | 说明 | 默认值 |
|------|------|--------|
| `MYSQL_HOST` | MySQL 主机 | `localhost` |
| `MYSQL_DB` | 数据库名 | `mrpp` |
| `MYSQL_USER` | 数据库用户 | `root` |
| `MYSQL_PASS` | 数据库密码 | - |
| `REDIS_HOST` | Redis 主机 | `127.0.0.1` |
| `REDIS_PORT` | Redis 端口 | `6379` |
| `REDIS_DB` | Redis 数据库编号 | `0` |
| `JWT_KEY` | JWT 密钥文件路径 | - |
| `WHITELABEL_SERVICE_URL` | 白牌插件后端的固定内部地址 | 本地 Docker：`http://host.docker.internal:8093` |
| `WHITELABEL_INTERNAL_TOKEN` | A1 与白牌插件共享的内部鉴权令牌（至少 32 字符） | - |

`WHITELABEL_SERVICE_URL` 必须是部署时提供的固定 `http` 或 `https` 地址，
不能包含用户信息、查询串或 fragment。服务不会使用客户端请求中的 host 或 URL
构造上游地址，也不会跟随重定向。内部令牌去除首尾空白后必须至少 32 字符，且不能
包含控制字符。两个白牌变量缺失或无效时，公开接口安全地返回 `503 Service
Unavailable`。

## API 端点

### 认证 (V1)

| 方法 | 路径 | 说明 |
|------|------|------|
| POST | `/v1/auth/login` | 用户名密码登录，返回 accessToken + refreshToken |
| POST | `/v1/auth/refresh` | 刷新令牌 |
| POST | `/v1/auth/key-to-token` | 关联 key 换取令牌 |

### 场景服务 (V1)

| 方法 | 路径 | 认证 | 说明 |
|------|------|------|------|
| GET | `/v1/server/test` | 否 | 测试端点 |
| GET | `/v1/server/public` | 否 | 公开场景列表 |
| GET | `/v1/server/checkin` | 否 | 打卡场景列表 |
| GET | `/v1/server/private` | 是 | 当前用户私有场景 |
| GET | `/v1/server/group` | 是 | 当前用户群组场景 |
| GET | `/v1/server/tags` | 否 | 标签列表 |
| GET | `/v1/server/snapshot` | 否 | 快照详情（?id= 或 ?verse_id=） |

### 白牌配置 (V1)

| 方法 | 路径 | 认证 | 说明 |
|------|------|------|------|
| GET | `/v1/white-label-configs?o={organizationId}&d={domainId}` | 否 | 获取一对已启用的组织级与域名级 Unity 白牌配置 |

`o` 和 `d` 都必须是无前导零、且不超过 JavaScript 最大安全整数的正十进制整数；
缺失或格式错误返回 `400 Bad Request`。二维码内容就是完整的生产 HTTPS URL，例如：

```text
https://a1.example.com/v1/white-label-configs?o=12&d=34
```

Unity 应只接受 `https` 且 host 位于应用内置 A1 allowlist 的二维码 URL，避免二维码
把客户端导向任意服务。A1 自身仍只使用部署配置中的固定插件内部地址。

A1 使用固定内部地址调用白牌插件：

```http
GET /internal/v1/white-label-configs/resolve?o=12&d=34
X-Internal-Token: <WHITELABEL_INTERNAL_TOKEN>
Accept: application/json
```

成功体固定为三个顶层命名空间，组织和域名配置彼此独立：

```json
{
  "version": 1,
  "organization": {
    "id": 12,
    "name": "academy",
    "title": "Academy",
    "revision": 4,
    "schemaVersion": 1,
    "config": {}
  },
  "domain": {
    "id": 34,
    "configKey": "dev.xrugc.com",
    "revision": 7,
    "schemaVersion": 1,
    "config": {
      "name": "dev.xrugc.com",
      "description": "XR UGC Dev",
      "is_active": true,
      "fallback_domain": null,
      "default_config": {},
      "configs": {}
    }
  }
}
```

`domain.configKey` 是主前端静态域名配置键/域名族（例如 `dev.xrugc.com`），不是
扫码时的精确请求 host；它必须等于 `domain.config.name`。`organization.name`、
`organization.title`、域名配置固定结构、两侧的 `id`、`revision`、
`schemaVersion` 与对象类型都会在 A1 信任边界校验。
`domain.config.fallback_domain` 只作为格式兼容元数据透传；A1 不按它递归请求另一份
配置。插件必须下发已经包含 Unity 所需有效内容的自包含快照，Unity 也不应把该字段
当作新的网络地址。
成功体最多 1 MiB；Guzzle 在收到响应头时就中止声明超限的 `Content-Length`，没有
长度或长度不可信时，网关仍以 1 MiB + 1 字节的有界流读取执行最终上限。

`200` 与 `304` 都返回语法有效的 `ETag` 和安全的 `Cache-Control`：

```http
ETag: "wl-o12-r4-d34-r7-a2"
Cache-Control: private, max-age=60
```

客户端传入的合法 `If-None-Match` 会转发给插件。成功响应的 JSON、`ETag` 和
`Cache-Control` 会按白名单原样返回；浏览器可通过全局 CORS 的
`Access-Control-Expose-Headers` 读取 `ETag`。JSON 以 `organization` 与 `domain`
两个独立命名空间承载组织级和域名级配置。只有上游 `304` 的 ETag 与客户端条件头
弱匹配时才返回 `304`。

任一配置不存在、已停用或两个 ID 不是有效组合时，插件使用 JSON
`error.code=NOT_FOUND`，A1 才统一返回 `404`。伪 404、缺失或畸形缓存头、上游超时、
鉴权失败、非 JSON 响应和其他协议异常统一返回 `503`。
A1 不会转发客户端的 `Authorization` 或其他请求头，白牌 JSON 中也不得存放密钥
或令牌。

### 快照服务 (V2)

| 方法 | 路径 | 说明 |
|------|------|------|
| GET | `/v2/snapshots` | 快照列表（?scope=public\|checkin\|private\|group） |
| GET | `/v2/snapshots/{id}` | 快照详情 |
| GET | `/v2/tags` | 标签列表 |
| GET/HEAD | `/v2/system` | 系统状态 |

### 其他

| 方法 | 路径 | 说明 |
|------|------|------|
| GET | `/health` | 健康检查（MySQL + Redis） |
| GET | `/swagger` | Swagger UI（需 Basic Auth） |
| GET | `/swagger/json-schema` | OpenAPI JSON Schema |

## 分页

列表接口支持 `page` 和 `pageSize` 参数，响应头包含：

- `X-Pagination-Current-Page`
- `X-Pagination-Page-Count`
- `X-Pagination-Per-Page`
- `X-Pagination-Total-Count`

## 项目结构

```
├── config/             # 配置（DI 容器、路由、参数）
├── docker/             # Docker 相关文件与测试 SQL
├── public/index.php    # HTTP 入口
├── src/
│   ├── Controller/     # V1/V2 控制器
│   ├── ErrorHandler/   # 错误响应渲染
│   ├── Middleware/      # CORS、JWT 认证中间件
│   ├── Model/          # ActiveRecord 模型
│   ├── Policy/         # 权限策略
│   ├── Search/         # 搜索/查询构建器
│   └── Service/        # 业务服务层
├── tests/
│   ├── Unit/           # 单元测试
│   └── Property/       # 属性测试 (Eris)
└── runtime/logs/
```

## 测试

```bash
# 运行全部测试
composer test

# 仅单元测试
./vendor/bin/phpunit --testsuite Unit

# 仅属性测试
./vendor/bin/phpunit --testsuite Property
```

## 并行对比测试

支持 Yii2 与 Yii3 版本并行运行，对比 API 响应一致性：

```bash
docker compose -f docker-compose.test.yml up --build
# Yii3: http://localhost:8080
# Yii2: http://localhost:8081

# 运行对比脚本
bash docker/test-api-compare.sh
```

## 许可证

本项目基于 [GPL-3.0](https://www.gnu.org/licenses/gpl-3.0.html) 许可证发布。
