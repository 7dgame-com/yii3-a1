# Yii2 vs Yii3 Docker 并行测试

## 功能说明
使用 Docker Compose 同时运行 Yii2 和 Yii3 版本，共享同一个 MySQL 和 Redis，逐个端点对比 API 响应确保 1:1 一致。

## 架构
```
┌─────────────┐  ┌─────────────┐
│  Yii3 :8080 │  │  Yii2 :8081 │
└──────┬──────┘  └──────┬──────┘
       │                │
       └───────┬────────┘
               │
       ┌───────┴───────┐
       │  MySQL :3307   │
       │  Redis :6380   │
       └───────────────┘
```

## docker-compose.test.yml 关键配置

### Yii3 服务
```yaml
yii3-app:
  build: .
  ports: ["8080:8080"]
  environment:
    MYSQL_HOST: mysql
    MYSQL_DB: mrpp
    MYSQL_USER: mrpp
    MYSQL_PASS: mrpp_secret
    REDIS_HOST: redis
```

### Yii2 服务
```yaml
yii2-app:
  build:
    context: /tmp/mrpp-api-yii2          # Yii2 源码目录
    dockerfile: ${PWD}/docker/yii2/Dockerfile  # 自定义 Dockerfile
  ports: ["8081:80"]
  environment:
    MYSQL_HOST: mysql
    MYSQL_USERNAME: mrpp                  # 注意：Yii2 用 USERNAME 不是 USER
    MYSQL_PASSWORD: mrpp_secret
```

### Yii2 Dockerfile 注意事项
```dockerfile
FROM php:8.2-apache    # Yii2 不支持 PHP 8.5，用 8.2
RUN a2enmod rewrite    # Apache URL 重写
```

## 对比测试方法
```bash
# 启动所有服务
docker compose -f docker-compose.test.yml up --build

# 对比端点
curl -s http://localhost:8080/v1/server/test | python3 -m json.tool
curl -s http://localhost:8081/v1/server/test | python3 -m json.tool

# 对比分页头
curl -sI http://localhost:8080/v1/server/public
curl -sI http://localhost:8081/v1/server/public

# 对比 expand 参数
curl -s "http://localhost:8080/v1/server/public?expand=id,name"
curl -s "http://localhost:8081/v1/server/public?expand=id,name"
```

## 在容器内运行测试
```bash
# PHP 未安装在宿主机时，在 Yii3 容器内运行 PHPUnit
docker compose -f docker-compose.test.yml exec yii3-app php vendor/bin/phpunit

# 复制修改后的文件到容器（不重建镜像）
docker compose -f docker-compose.test.yml cp src/Model/Snapshot.php yii3-app:/app/src/Model/Snapshot.php
```

## 常见差异排查清单
1. JSON 字段顺序 — Yii2 和 Yii3 可能不同，用 `json_decode` 后比较
2. 空值表示 — `null` vs 缺失字段 vs 空字符串
3. 数字类型 — Yii2 可能返回字符串 `"1"`，Yii3 返回整数 `1`
4. 日期格式 — 确保都是 `Y-m-d H:i:s`
5. 分页头 — 名称和值必须完全一致
6. 错误响应 — Yii2 返回 `{name, message, code, status, type}`

## 注意事项
- 两个应用共享同一个 MySQL 数据库，测试数据通过 `docker/init.sql` 初始化
- 修改 init.sql 后需要删除 volume 重建：`docker compose -f docker-compose.test.yml down -v`
- Yii2 的某些 V2 端点可能有 bug（如缺少 `use Yii;`），这是原始代码问题
