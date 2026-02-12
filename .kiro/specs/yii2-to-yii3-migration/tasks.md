# Implementation Plan: Yii2 到 Yii3 REST API 迁移

## Overview

基于 Yii3 app-api 模板从零搭建项目，逐步实现数据模型、服务层、中间件、控制器，最终完成与 Yii2 版本 1:1 功能对等的 REST API。每个任务增量构建，确保无孤立代码。

## Tasks

- [x] 1. 项目骨架与基础配置
  - [x] 1.1 初始化 Yii3 项目结构
    - 创建 composer.json，引入 yiisoft/app-api 核心依赖：yiisoft/yii-http, yiisoft/router, yiisoft/di, yiisoft/config, yiisoft/db-mysql, yiisoft/active-record, yiisoft/cache, yiisoft/log, yiisoft/yii-middleware
    - 创建 public/index.php 入口文件
    - 创建 config/ 目录结构（common/di/, common/params.php, web/di/, web/params.php, web/routes.php, params.php）
    - 创建 src/ 目录结构（Controller/, Model/, Search/, Service/, Middleware/, Policy/）
    - 创建 .env.example 包含所有环境变量（MYSQL_HOST, MYSQL_DB, MYSQL_USER, MYSQL_PASS, REDIS_HOST, REDIS_PORT, REDIS_DB, JWT_KEY）
    - _Requirements: 1.1, 1.2, 1.3, 1.4, 1.5_

  - [x] 1.2 配置 DI 容器和数据库连接
    - 创建 config/common/di/db.php：通过环境变量配置 MySQL 连接（yiisoft/db Connection）
    - 创建 config/common/di/redis.php：通过环境变量配置 Predis\Client
    - 创建 config/common/di/cache.php：配置 yiisoft/cache 使用 Redis 后端
    - 创建 config/common/params.php：设置时区 Asia/Shanghai 和应用参数
    - _Requirements: 1.2, 1.3, 1.4_

  - [x] 1.3 配置中间件管道
    - 创建 config/web/di/middleware.php：注册中间件到 DI 容器
    - 在应用配置中设置中间件管道顺序：ErrorHandler → CorsMiddleware → Router
    - _Requirements: 1.1, 2.1_

- [x] 2. CORS 和认证中间件
  - [x] 2.1 实现 CorsMiddleware
    - 创建 src/Middleware/CorsMiddleware.php，实现 PSR-15 MiddlewareInterface
    - 对所有请求添加 Access-Control-Allow-Origin: *, Access-Control-Allow-Methods, Access-Control-Allow-Headers 头
    - OPTIONS 预检请求直接返回 200
    - _Requirements: 2.1, 2.2, 2.3_

  - [x] 2.2 CorsMiddleware 属性测试
    - **Property 1: CORS 响应头完整性**
    - **Validates: Requirements 2.1, 2.2**

  - [x] 2.3 实现 JwtService
    - 创建 src/Service/JwtService.php
    - 使用 lcobucci/jwt v5，HS256 签名，密钥从 JWT_KEY 环境变量指定的文件读取
    - 实现 generateToken(userId): string, parseToken(token): ?array, validateToken(token): bool
    - Token 有效期 3 小时
    - 创建 config/common/di/jwt.php 注册 JwtService 到 DI 容器
    - _Requirements: 3.1, 3.6_

  - [x] 2.4 JwtService 属性测试
    - **Property 6: JWT HS256 签名不变量**
    - **Validates: Requirements 3.6**

  - [x] 2.5 实现 JwtAuthMiddleware
    - 创建 src/Middleware/JwtAuthMiddleware.php，实现 PSR-15 MiddlewareInterface
    - 从 Authorization: Bearer <token> 头提取并验证 JWT
    - 受保护路由无有效 token 返回 401
    - 将解析出的 user identity 注入 request attribute
    - _Requirements: 9.1, 9.2_

  - [x] 2.6 JwtAuthMiddleware 属性测试
    - **Property 4: 无效认证返回 401**
    - **Validates: Requirements 3.3, 3.4, 9.1, 9.2**

- [x] 3. Checkpoint - 中间件基础验证
  - Ensure all tests pass, ask the user if questions arise.

- [x] 4. 数据模型层
  - [x] 4.1 实现核心 ActiveRecord 模型
    - 创建 src/Model/User.php：字段 username, password_hash, auth_key, nickname, created_at, updated_at；实现 validatePassword 方法
    - 创建 src/Model/Verse.php：字段 name, description, uuid, data, image_id, author_id, version；定义关联 author, image, metas, managers, verseCode, properties, tags
    - 创建 src/Model/Snapshot.php：字段 verse_id, uuid, code, data, metas, resources, managers, created_by；定义关联 verse, createdBy；实现 JsonSerializable 包含 extraFields（name, description, image, author_id, author）
    - 创建 src/Model/Meta.php：字段 data, events, uuid, image_id, author_id, prefab；实现 afterFind 数据升级逻辑
    - 创建 src/Model/File.php：字段 md5, type, url, filename, key, size, user_id；实现 URL 过滤逻辑（IP 替换）
    - _Requirements: 8.1, 8.2, 8.3, 8.4, 8.5, 8.7_

  - [x] 4.2 User 密码验证属性测试
    - **Property 14: User 密码验证 round-trip**
    - **Validates: Requirements 8.1**

  - [x] 4.3 Meta 数据升级幂等性属性测试
    - **Property 16: Meta 数据升级幂等性**
    - **Validates: Requirements 8.5**

  - [x] 4.4 Snapshot extraFields 属性测试
    - **Property 15: Snapshot extraFields 完整性**
    - **Validates: Requirements 8.4**

  - [x] 4.5 实现关联和辅助模型
    - 创建 src/Model/Resource.php, Tags.php, VerseTags.php, Property.php, VerseProperty.php
    - 创建 src/Model/Group.php, GroupUser.php, GroupVerse.php
    - 创建 src/Model/Manager.php, Code.php, VerseCode.php, MetaCode.php
    - 创建 src/Model/UserLinked.php, Watermark.php, Phototype.php
    - 在 Verse 模型中实现 TimestampBehavior（beforeInsert/beforeUpdate 自动设置 created_at, updated_at）和 BlameableBehavior（自动设置 created_by, updated_by）
    - _Requirements: 8.8, 8.9_

  - [x] 4.6 实现搜索模型
    - 创建 src/Search/SnapshotSearch.php：实现 searchPublic, searchCheckin, searchPrivate, searchGroup, applyTagFilter 方法
    - 创建 src/Search/VerseSearch.php, TagsSearch.php, GroupSearch.php
    - searchPublic：通过 verse_property + property(key='public') 关联查询
    - searchCheckin：通过 verse_property + property(key='checkin') 关联查询
    - searchPrivate：按 author_id 过滤
    - searchGroup：通过 group_user + group_verse 关联查询
    - applyTagFilter：通过 verse_tags 关联按 tag ID 过滤
    - _Requirements: 8.10, 4.2, 4.3, 4.4, 4.5, 4.9_

- [x] 5. 服务层
  - [x] 5.1 实现 RefreshTokenService
    - 创建 src/Serv  - [ ]* 5.2 RefreshToken round-trip 属性测试
    - **Property 3
    : RefreshToken round-trip**
    - **Validates: Requirements 3.2, 3.7**

  - [x] 5.3 实现 AuthService
    - 创建 src/Service/AuthService.php
    - 注入 JwtService, RefreshTokenService, ActiveRecord 查询能力
    - 实现 login(username, password): array — 验证凭据，生成 accessToken + refreshToken
    - 实现 refresh(refreshToken): array — 验证旧 token，删除旧 token，生成新令牌对
    - 实现 keyToToken(key): array — 通过 UserLinked 查找用户，生成令牌
    - _Requirements: 3.1, 3.2, 3.5_

  - [x] 5.4 实现 PaginationService
    - 创建 src/Service/PaginationService.php
    - 实现 paginate(query, page, pageSize): PaginatedResult
    - 实现 applyHeaders(response, result): ResponseInterface — 添加 X-Pagination-Current-Page, X-Pagination-Page-Count, X-Pagination-Per-Page, X-Pagination-Total-Count 头
    - 创建 PaginatedResult 值对象
    - _Requirements: 4.8, 10.2_

  - [x] 5.5 分页正确性属性测试
    - **Property 11: 分页正确性**
    - **Validates: Requirements 4.8, 10.2**

  - [x] 5.6 实现 SnapshotQueryService
    - 创建 src/Service/SnapshotQueryService.php
    - 注入 SnapshotSearch, PaginationService, CacheInterface
    - 实现 findPublic, findCheckin, findPrivate, findGroup, findById, findByVerseId, findTags 方法
    - 查询结果通过 yiisoft/cache 缓存 30 秒
    - _Requirements: 4.2, 4.3, 4.4, 4.5, 4.6, 4.7, 4.10_

  - [x] 5.7 实现 HealthCheckService
    - 创建 src/Service/HealthCheckService.php
    - 检查 MySQL 连接状态和响应时间
    - 检查 Redis 连接状态和响应时间
    - 返回 HealthResult（status: healthy/unhealthy, services 详情）
    - _Requirements: 6.1, 6.2, 6.3, 6.4_

  - [x] 5.8 健康检查状态属性测试
    - **Property 13: 健康检查状态一致性**
    - **Validates: Requirements 6.3, 6.4**

  - [x] 5.9 实现 VersePolicy
    - 创建 src/Policy/VersePolicy.php
    - 实现 canView(user, verse): bool, canUpdate(user, verse): bool, canDelete(user, verse): bool
    - 基于 author_id 判断所有权
    - _Requirements: 9.3_

  - [x] 5.10 VersePolicy 属性测试
    - **Property 17: VersePolicy 权限正确性**
    - **Validates: Requirements 9.3**

- [x] 6. Checkpoint - 模型和服务层验证
  - Ensure all tests pass, ask the user if questions arise.

- [x] 7. V1 控制器
  - [x] 7.1 实现 V1 AuthController
    - 创建 src/Controller/V1/AuthController.php
    - 注入 AuthService
    - POST /v1/auth/login：从请求体获取 username/password，调用 AuthService::login，返回 JSON {accessToken, refreshToken}
    - POST /v1/auth/refresh：从请求体获取 refreshToken，调用 AuthService::refresh
    - POST /v1/auth/key-to-token：从请求体获取 key，调用 AuthService::keyToToken
    - 错误时返回 401 + {status, message} 格式
    - _Requirements: 3.1, 3.2, 3.3, 3.4, 3.5, 10.3_

  - [x] 7.2 实现 V1 ServerController
    - 创建 src/Controller/V1/ServerController.php
    - 注入 SnapshotQueryService, PaginationService
    - GET /v1/server/test：返回测试响应
    - GET /v1/server/public：调用 findPublic，支持 pageSize 和 tags 参数
    - GET /v1/server/checkin：调用 findCheckin，支持 pageSize 和 tags 参数
    - GET /v1/server/private：从 request attribute 获取当前用户，调用 findPrivate
    - GET /v1/server/group：从 request attribute 获取当前用户，调用 findGroup
    - GET /v1/server/tags：调用 findTags
    - GET /v1/server/snapshot：根据 id 或 verse_id 参数调用 findById/findByVerseId
    - 所有列表接口通过 PaginationService 添加分页头
    - _Requirements: 4.1, 4.2, 4.3, 4.4, 4.5, 4.6, 4.7, 4.8, 4.9_

  - [x] 7.3 V1 ServerController 场景查询属性测试
    - **Property 7: 场景查询过滤正确性 — public/checkin**
    - **Property 8: 场景查询过滤正确性 — private**
    - **Property 9: 场景查询过滤正确性 — group**
    - **Property 10: 标签查询类型过滤**
    - **Property 12: 标签过滤正确性**
    - **Validates: Requirements 4.2, 4.3, 4.4, 4.5, 4.6, 4.9, 5.1, 5.2, 5.3, 5.4, 5.6**

- [x] 8. V2 控制器
  - [x] 8.1 实现 V2 SnapshotController
    - 创建 src/Controller/V2/SnapshotController.php
    - GET /v2/snapshots：根据 scope 参数（public/checkin/group/private）调用对应的 SnapshotQueryService 方法
    - GET /v2/snapshots/{id}：调用 findById
    - scope=group 和 scope=private 需要认证（通过路由中间件配置）
    - _Requirements: 5.1, 5.2, 5.3, 5.4, 5.5_

  - [x] 8.2 实现 V2 TagController 和 SystemController
    - 创建 src/Controller/V2/TagController.php：GET /v2/tags 返回标签列表
    - 创建 src/Controller/V2/SystemController.php：GET/HEAD /v2/system 调用 HealthCheckService
    - _Requirements: 5.6, 5.7_

- [x] 9. 健康检查和 Swagger 控制器
  - [x] 9.1 实现 HealthController
    - 创建 src/Controller/HealthController.php
    - GET /health：调用 HealthCheckService::check，返回状态和各服务响应时间
    - 正常返回 200，异常返回 503
    - _Requirements: 6.1, 6.2, 6.3, 6.4_

  - [x] 9.2 实现 SwaggerController
    - 创建 src/Controller/SwaggerController.php
    - GET /swagger：返回 Swagger UI HTML（HTTP Basic Auth 保护）
    - GET /swagger/json-schema：使用 zircote/swagger-php 扫描代码注解生成 OpenAPI JSON
    - _Requirements: 7.1, 7.2, 7.3_

- [x] 10. 路由配置和错误处理
  - [x] 10.1 配置完整路由表
    - 更新 config/web/routes.php，注册所有 V1、V2、Health、Swagger 路由
    - 为需要认证的路由配置 JwtAuthMiddleware
    - 确保路由路径与 Yii2 版本完全一致
    - _Requirements: 1.5, 9.1_

  - [x] 10.2 实现全局错误处理
    - 创建自定义 ErrorHandler，确保所有异常转换为 JSON 格式 {status, message}
    - 生产环境不暴露堆栈信息
    - _Requirements: 10.1, 10.3_

  - [x] 10.3 错误响应格式属性测试
    - **Property 18: 错误响应格式一致性**
    - **Validates: Requirements 10.3**

  - [x] 10.4 模型序列化属性测试
    - **Property 19: 模型序列化字段一致性**
    - **Validates: Requirements 10.4**

- [x] 11. Checkpoint - 全功能验证
  - Ensure all tests pass, ask the user if questions arise.

- [x] 12. Docker 部署配置
  - [x] 12.1 创建 Docker 配置
    - 创建 Dockerfile：基于 PHP 8.5 FPM 镜像，安装 pdo_mysql, redis 扩展，composer install
    - 创建 docker-compose.yml：定义 app（PHP）、mysql、redis 三个服务
    - 所有外部服务配置通过环境变量注入
    - _Requirements: 11.1, 11.2, 11.3_

- [x] 13. Final checkpoint - 完整集成验证
  - Ensure all tests pass, ask the user if questions arise.

## Notes

- Tasks marked with `*` are optional and can be skipped for faster MVP
- Each task references specific requirements for traceability
- Checkpoints ensure incremental validation
- Property tests validate universal correctness properties
- Unit tests validate specific examples and edge cases
- Yii3 ActiveRecord 不支持静态 find() 方法，所有查询通过 ActiveQuery 实例或 ActiveRecordFactory
- RefreshToken 从 Yii2 Redis ActiveRecord 迁移为 Predis 直接操作
- 分页头需要手动实现以保持与 Yii2 X-Pagination 格式兼容
