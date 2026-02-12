# Requirements Document: Yii2 到 Yii3 REST API 迁移

## Introduction

将现有的 mrpp-api Yii2 REST API 项目完整迁移到 Yii3 框架。该项目是一个元宇宙场景管理平台（MrPP - Mixed Reality Platform），提供场景、快照、标签、用户认证等 REST API 服务。迁移要求所有 API 端点、请求/响应格式、业务逻辑保持 1:1 一致，同时采用 Yii3 的现代架构（PSR 标准、依赖注入、中间件）。

## Glossary

- **API_Gateway**: Yii3 应用的 HTTP 入口，基于 yiisoft/yii-http 和 PSR-15 中间件栈
- **Router**: 基于 yiisoft/router 的路由组件，替代 Yii2 的 urlManager
- **Auth_Middleware**: JWT Bearer Token 认证中间件，替代 Yii2 的 authenticator behavior
- **CORS_Middleware**: 跨域资源共享中间件，替代 Yii2 的 CORS behavior
- **ActiveRecord_Layer**: 基于 yiisoft/active-record + yiisoft/db 的数据访问层
- **Redis_Client**: 基于 yiisoft/cache-redis 或 predis/predis 的 Redis 客户端
- **JWT_Service**: JWT 令牌生成与验证服务，基于 lcobucci/jwt
- **DI_Container**: Yii3 依赖注入容器（yiisoft/di）
- **Snapshot**: 场景快照，包含场景的某一时刻状态数据
- **Verse**: 场景实体，包含名称、描述、UUID、数据等
- **Meta**: 元数据实体，描述场景中的对象属性
- **RefreshToken**: 用于刷新 accessToken 的令牌，存储在 Redis 中
- **Tag**: 分类标签，用于场景分类和过滤
- **Property**: 场景属性（如 public、checkin），用于标记场景类型
- **Pagination_Header**: HTTP 响应头中的分页信息（X-Pagination-*）

## Requirements

### Requirement 1: 项目基础架构

**User Story:** 作为开发者，我需要一个基于 Yii3 框架的项目骨架，以便在现代 PHP 架构上运行 REST API 服务。

#### Acceptance Criteria

1. THE API_Gateway SHALL 基于 Yii3 框架（yiisoft/app-api 模板）提供 HTTP 服务入口
2. THE DI_Container SHALL 管理所有服务的依赖注入，包括数据库连接、Redis 客户端、JWT 服务
3. THE API_Gateway SHALL 通过环境变量读取配置（MYSQL_HOST, MYSQL_DB, MYSQL_USER, MYSQL_PASS, REDIS_HOST, REDIS_PORT, REDIS_DB, JWT_KEY）
4. THE API_Gateway SHALL 将默认时区设置为 Asia/Shanghai
5. THE Router SHALL 支持 RESTful 风格的 URL 路由，启用 pretty URL 和 strict parsing

### Requirement 2: CORS 支持

**User Story:** 作为前端开发者，我需要 API 支持跨域请求，以便从任意域名的前端应用访问 API。

#### Acceptance Criteria

1. THE CORS_Middleware SHALL 允许来自所有来源（Origin: *）的跨域请求
2. THE CORS_Middleware SHALL 在所有 API 响应中包含正确的 CORS 头（Access-Control-Allow-Origin, Access-Control-Allow-Methods, Access-Control-Allow-Headers）
3. WHEN 收到 OPTIONS 预检请求时, THE CORS_Middleware SHALL 返回 200 状态码和正确的 CORS 头

### Requirement 3: JWT 认证系统

**User Story:** 作为 API 用户，我需要通过用户名密码登录获取 JWT 令牌，以便安全地访问受保护的 API 端点。

#### Acceptance Criteria

1. WHEN 用户提交正确的用户名和密码到 POST /v1/auth/login 时, THE JWT_Service SHALL 生成一个有效期为 3 小时的 HS256 签名 accessToken 和一个 refreshToken，并返回给用户
2. WHEN 用户提交有效的 refreshToken 到 POST /v1/auth/refresh 时, THE JWT_Service SHALL 生成新的 accessToken 和 refreshToken，删除旧的 refreshToken，并返回新令牌
3. WHEN 用户提交无效的用户名或密码时, THE Auth_Middleware SHALL 返回 401 状态码和错误信息
4. WHEN 用户提交过期或无效的 refreshToken 时, THE JWT_Service SHALL 返回 401 状态码和错误信息
5. WHEN 用户提交有效的关联 key 到 POST /v1/auth/key-to-token 时, THE JWT_Service SHALL 通过 UserLinked 表查找对应用户，生成 accessToken 和 refreshToken 并返回
6. THE JWT_Service SHALL 使用从 JWT_KEY 环境变量指定的文件路径读取的密钥进行 HS256 签名
7. THE Redis_Client SHALL 存储 RefreshToken 记录，包含 user_id 和 token key

### Requirement 4: 场景服务 V1 API

**User Story:** 作为 API 用户，我需要查询公开、打卡、私有和组内场景列表，以便在客户端展示不同类型的场景。

#### Acceptance Criteria

1. WHEN 请求 GET /v1/server/test 时, THE Router SHALL 返回测试响应
2. WHEN 请求 GET /v1/server/public 时, THE ActiveRecord_Layer SHALL 查询 property 表中 key='public' 关联的场景快照列表并返回
3. WHEN 请求 GET /v1/server/checkin 时, THE ActiveRecord_Layer SHALL 查询 property 表中 key='checkin' 关联的场景快照列表并返回
4. WHEN 已认证用户请求 GET /v1/server/private 时, THE ActiveRecord_Layer SHALL 查询当前用户（author_id）的私有场景快照列表并返回
5. WHEN 已认证用户请求 GET /v1/server/group 时, THE ActiveRecord_Layer SHALL 通过 group_user 和 group_verse 关联查询当前用户所在群组的场景快照列表并返回
6. WHEN 请求 GET /v1/server/tags 时, THE ActiveRecord_Layer SHALL 查询 type='Classify' 的标签列表并返回
7. WHEN 请求 GET /v1/server/snapshot 并提供 id 或 verse_id 参数时, THE ActiveRecord_Layer SHALL 返回对应的单个快照详情
8. WHEN 请求包含 pageSize 参数时, THE API_Gateway SHALL 按指定页大小进行分页，并在响应头中包含 X-Pagination 分页信息
9. WHEN 请求包含 tags 参数（逗号分隔的 tag ID）时, THE ActiveRecord_Layer SHALL 按标签过滤查询结果
10. THE Redis_Client SHALL 对场景查询结果缓存 30 秒

### Requirement 5: V2 API 模块

**User Story:** 作为 API 用户，我需要使用统一的 V2 快照接口，以便通过 scope 参数灵活查询不同类型的快照。

#### Acceptance Criteria

1. WHEN 请求 GET /v2/snapshots 并提供 scope=public 参数时, THE ActiveRecord_Layer SHALL 返回公开场景快照列表
2. WHEN 请求 GET /v2/snapshots 并提供 scope=checkin 参数时, THE ActiveRecord_Layer SHALL 返回打卡场景快照列表
3. WHEN 请求 GET /v2/snapshots 并提供 scope=group 参数时, THE ActiveRecord_Layer SHALL 返回当前用户群组内的场景快照列表
4. WHEN 请求 GET /v2/snapshots 并提供 scope=private 参数时, THE ActiveRecord_Layer SHALL 返回当前用户的私有场景快照列表
5. WHEN 请求 GET /v2/snapshots/{id} 时, THE ActiveRecord_Layer SHALL 返回指定 ID 的快照详情
6. WHEN 请求 GET /v2/tags 时, THE ActiveRecord_Layer SHALL 返回标签列表（只读）
7. WHEN 请求 GET 或 HEAD /v2/system 时, THE API_Gateway SHALL 返回系统健康状态

### Requirement 6: 健康检查

**User Story:** 作为运维人员，我需要健康检查端点，以便监控 API 服务及其依赖的数据库和 Redis 的运行状态。

#### Acceptance Criteria

1. WHEN 请求 GET /health 时, THE API_Gateway SHALL 检查 MySQL 数据库连接状态并记录响应时间
2. WHEN 请求 GET /health 时, THE API_Gateway SHALL 检查 Redis 连接状态并记录响应时间
3. WHEN 所有依赖服务正常时, THE API_Gateway SHALL 返回 200 状态码和 "healthy" 状态
4. WHEN 任一依赖服务异常时, THE API_Gateway SHALL 返回 503 状态码和 "unhealthy" 状态，并包含各服务的详细状态

### Requirement 7: Swagger 文档

**User Story:** 作为 API 使用者，我需要 Swagger 文档界面，以便查看和测试 API 接口。

#### Acceptance Criteria

1. WHEN 请求 GET /swagger 时, THE API_Gateway SHALL 返回 Swagger UI 界面
2. THE API_Gateway SHALL 通过 HTTP Basic Auth 保护 Swagger UI 界面的访问
3. WHEN 请求 GET /swagger/json-schema 时, THE API_Gateway SHALL 基于代码注解生成并返回 OpenAPI JSON Schema

### Requirement 8: 数据模型层

**User Story:** 作为开发者，我需要完整的数据模型层，以便通过 ActiveRecord 模式操作数据库中的所有业务实体。

#### Acceptance Criteria

1. THE ActiveRecord_Layer SHALL 提供 User 模型，包含 username, password_hash, auth_key, nickname, created_at, updated_at 字段，并支持密码验证
2. THE ActiveRecord_Layer SHALL 提供 Verse 模型，包含 name, description, uuid, data, image_id, author_id, version 字段，并关联 author(User), image(File), metas(Meta), managers(Manager), verseCode(VerseCode), properties(Property)
3. THE ActiveRecord_Layer SHALL 提供 Snapshot 模型，包含 verse_id, uuid, code, data, metas, resources, managers, created_by 字段，并关联 verse(Verse), createdBy(User)
4. WHEN 查询 Snapshot 的 extraFields 时, THE ActiveRecord_Layer SHALL 从关联的 Verse 获取 name, description, image, author_id, author 信息
5. THE ActiveRecord_Layer SHALL 提供 Meta 模型，包含 data, events, uuid, image_id, author_id, prefab 字段，并在 afterFind 中执行旧数据格式升级逻辑
6. THE ActiveRecord_Layer SHALL 提供 Resource 模型，包含 name, type, file_id, image_id, uuid, info 字段
7. THE ActiveRecord_Layer SHALL 提供 File 模型，包含 md5, type, url, filename, key, size, user_id 字段，并实现 URL 过滤逻辑（IP 替换）
8. THE ActiveRecord_Layer SHALL 提供 Tags, VerseTags, Property, VerseProperty, Group, GroupUser, GroupVerse, Manager, Code, VerseCode, MetaCode, UserLinked, Watermark, Phototype 模型
9. THE ActiveRecord_Layer SHALL 在 Verse 模型上实现 TimestampBehavior（自动维护 created_at, updated_at）和 BlameableBehavior（自动设置 created_by, updated_by）
10. THE ActiveRecord_Layer SHALL 提供 SnapshotSearch, VerseSearch, TagsSearch, GroupSearch 搜索模型，支持 searchCheckin, searchPublic, searchPrivate, searchGroup, applyTagFilter 方法

### Requirement 9: 权限控制

**User Story:** 作为系统管理员，我需要基于策略的权限控制，以便确保用户只能访问和操作自己有权限的场景。

#### Acceptance Criteria

1. THE Auth_Middleware SHALL 对需要认证的端点（/v1/server/private, /v1/server/group, /v2/snapshots?scope=private, /v2/snapshots?scope=group）验证 JWT Bearer Token
2. WHEN 请求缺少或包含无效的 JWT Token 时, THE Auth_Middleware SHALL 返回 401 状态码
3. THE API_Gateway SHALL 实现 VersePolicy 权限策略，提供 canView, canUpdate, canDelete 方法来控制场景级别的访问权限

### Requirement 10: 响应格式兼容

**User Story:** 作为前端开发者，我需要 Yii3 API 的响应格式与 Yii2 版本完全一致，以便前端代码无需修改即可对接新 API。

#### Acceptance Criteria

1. THE API_Gateway SHALL 以 JSON 格式返回所有 API 响应
2. THE API_Gateway SHALL 在分页响应中包含与 Yii2 一致的 X-Pagination-Current-Page, X-Pagination-Page-Count, X-Pagination-Per-Page, X-Pagination-Total-Count 响应头
3. WHEN 发生错误时, THE API_Gateway SHALL 返回与 Yii2 一致的错误响应格式（包含 status, message 字段）
4. THE ActiveRecord_Layer SHALL 确保模型的 JSON 序列化输出字段和格式与 Yii2 版本一致（包括 fields 和 extraFields 定义）

### Requirement 11: Docker 部署

**User Story:** 作为运维人员，我需要 Docker 化的部署方案，以便快速部署和扩展 API 服务。

#### Acceptance Criteria

1. THE API_Gateway SHALL 提供 Dockerfile 用于构建生产环境镜像
2. THE API_Gateway SHALL 提供 docker-compose.yml 用于本地开发环境，包含 PHP 应用、MySQL、Redis 服务
3. THE API_Gateway SHALL 通过环境变量注入所有外部服务配置，不硬编码任何连接信息
