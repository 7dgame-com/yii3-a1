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
