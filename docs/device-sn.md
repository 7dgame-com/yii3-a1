# Unity / Rokid SN 认证

更新于 2026-09-29。**本次改造仅在本地实现，尚未部署开发或生产。** 此文描述 y1
（本仓库）的设备认证接口，不代表对应线上地址已提供这些新路由。

网页 `sn-management` 插件仍由主 API 管理发码、停用/恢复、查看与导出。y1 负责 Unity 的
SN 激活、登录、刷新和退出，复用本服务现有用户名密码登录的 HS256 JWT 与 Redis 刷新
会话体系；不调用主 API 或 identity 签发 Token，不复制 SN 或账号绑定。

## 存储与设备约束

y1 读取同环境主 API 的权威 `device_sn`、`user`、`auth_assignment`，激活审计复用
`audit_log`。不新增认证数据库表，不访问旧 `device` 表，不保存第二份 SN 授权。
SN 仅用于计算 SHA-256 后查询 `device_sn.sn_hash`；y1 不解密完整码，无需
`DEVICE_SN_KEYS` 或 `DEVICE_SN_ACTIVE_KEY_ID`。

- SN 规范化时忽略 ASCII 空白、连字符及大小写，然后严格接受 16 个字符。
  字符集为 `0123456789ABCDEFGHJKMNPQRSTVWXYZ`；展示为四组四位。32 位及其他长度拒绝，
  不自动替换 I/L/O/U，不截断历史码。主 API 可以继续留档查看历史 32 位码，这不改变 y1 输入限制。
- UUID 去除首尾空白并转小写，匹配 `[a-z0-9][a-z0-9._:-]{0,254}`，原始输入最多 255 字节。
  客户端必须使用稳定的设备标识；它不是硬件防克隆证明。
- 一 SN 对应一个账号和一个 UUID，同账号多设备使用不同 SN。生成时 UUID 和激活时间均为空；
  首次激活在事务内写入两者，行锁防止一 SN 被两台设备占用，唯一索引防止多码占用同一 UUID。
  半绑定记录拒绝，不自动覆盖；同一对 SN + UUID 重复激活幂等。
- 只有 `status=10` 且没有 root/admin/manager 角色的账号可使用 SN 会话。
- 绑定事务在 Token 签发之前提交。签发失败或响应丢失后，仍可用同一对凭据重试，不能换 UUID。

## 路由与协议

以下路由均位于 **y1**，请求使用 JSON。SN 激活、登录不需要旧 Bearer，也不接受客户端
指定账号或来源字段；账号及来源由 SN 绑定决定。响应禁止缓存。

| 方法和路径 | 请求体 | 行为 |
|---|---|---|
| POST `/v1/auth/sn-activate` | `{sn,uuid}` | 首次绑定并返回 y1 Token；相同绑定幂等 |
| POST `/v1/auth/sn-login` | `{sn,uuid}` | 已激活设备登录；未激活返回409 |
| POST `/v1/auth/refresh` | `{refreshToken}` | 沿用兼容刷新入口，SN 刷新必须持续保留来源 |
| POST `/v2/auth/refresh-token` | `{refreshToken}` | 严格刷新入口，同样核验 SN，不回退为登录码 |
| POST `/v1/auth/logout` | `{refreshToken}` | 删除该 y1 刷新凭据，保留 SN 和设备绑定 |

激活、登录返回 HTTP200：

```json
{
  "success": true,
  "message": "login",
  "nickname": "<账号昵称>",
  "token": {
    "accessToken": "<y1 JWT>",
    "expires": "2026-09-29 16:00:00",
    "refreshToken": "<y1 refresh credential>"
  },
  "user": {"id": 123, "username": "<账号名>", "nickname": "<账号昵称>", "fixture": false}
}
```

`token` 与本服务普通用户名密码登录使用相同字段。`accessToken` 为 HS256 JWT，携带
`uid`、`iat`、`exp`；SN 会话额外携带 `auth_method=device_sn` 和整数 `device_sn_id`。
Access 最长 10800 秒。`expires` 为 `yyyy-MM-dd HH:mm:ss`、时区 `Asia/Shanghai`；
客户端可解析 JWT 的 `exp` 做排程，解析不代表验签或权限校验。

刷新仍返回 `success/message/token`，客户端须同时替换两个 Token。两种刷新路径都核验
当前绑定、账号和 SN 状态，轮换后仍含同一来源，不能退化为普通会话或登录码兑换。
SN 刷新凭据使用可识别的来源前缀及带来源的 Redis 记录；缺少或损坏来源拒绝。
普通历史刷新记录仍按原格式兼容。客户端把刷新凭据当不透明字符串，不依赖长度或前缀。

退出成功返回 `{success:true,message:"logout"}`。退出只撤销对应刷新凭据，不解除绑定，
也不立即撤销已经签发的 Access。客户端先清理本地会话并拒绝迟到响应，再尝试退出请求；
是否保留 SN 由明确的本地退出操作决定。再次以有效 SN + UUID 登录仍可获取新会话。

| 状态 | 意义及客户端处理 |
|---|---|
| 400 | 格式不正确，修改输入 |
| 401 | 凭据、账号或 SN 授权无效；停止自动循环，提示联系管理员 |
| 409 | 未激活、绑定冲突或并发冲突；仅可有限重试同一对凭据，不得换绑 |
| 429 | 遵循 `Retry-After`；缺失时使用有限退避 |
| 503 | 权威存储、限流或认证不可用；有限指数退避，不改走其他 issuer |

SN 激活/登录限流为每 IP 600 次/分钟、每 SN 和每 UUID 各 30 次/分钟。Redis 使用原子
滑动窗口，键仅含摘要；多个节点共享同一逻辑库。Redis 不可用时拒绝认证。代理部署须核对
实际请求地址来源，不能仅凭客户端可伪造的转发头分桶。不要记录 SN、请求/响应 Token 或密钥。

## 停用、删除和旧会话

普通停用 SN 会立即拒绝之后开始的新激活、登录和刷新，已经签发的 Access 可用至自然到期，
最长 3 小时。恢复只恢复原账号/UUID 绑定。已经通过授权检查的在途请求可能完成。

主 API 的增量迁移 `m260928_150000_preserve_revoked_device_sn` 令 `user_id` 可空、外键
`ON DELETE SET NULL`，并保存仅用于留档的 `original_user_id`。删除账号后留下不可恢复墓碑，
SN、UUID 与原审计继续保留；同名或同 ID 重建账号也不会复活凭据。
y1 的新登录、两种刷新及每次 Access 校验都要求当前 `sn.user_id=uid`，NULL 永不授权。
Access 校验忽略普通 SN 停用的 enabled 位，但不忽略墓碑、账号停用、升权或损坏绑定。
`original_user_id` 绝不能成为授权回退。被作废的 UUID 保持唯一占用，没有换机/解绑接口。

Unity 不能用 SN 会话换取不携带来源的凭据。主 API 的旧 SN 激活、登录 action 在配套版本
返回410，即使使用 Yii 默认路由也不会发证。原主 API SN 会话仍可按原限制刷新，但不能
拿该 Refresh Token 到 y1 兑换；Unity 应重新用原 SN + UUID 登录以获得 y1 会话。
y1 HS256 与网页主 API/identity 的 EC 会话不互换。

## 配置与部署前置条件

- `MYSQL_HOST/MYSQL_DB/MYSQL_USER/MYSQL_PASS`：与网页管理使用同一权威主库；不能从
  异步副本、独立演示库或另一环境读取 SN。所有绑定写入都在该主库完成。
- `REDIS_HOST/REDIS_PORT/REDIS_DB`：同环境、所有 y1 节点使用同一 Redis 逻辑库，保存
  会话和共享限流状态。开发和生产分离，不能直接沿用 `.env.example` 默认值上线。
- `JWT_KEY`：沿用 y1 现有 HS256 密钥文件，各 y1 节点一致；不是主 API/identity 的 EC key。
- y1 无需 AES keyring、identity 内部签发 Token 或 `AUTH_PROVIDER` 切换。网页主 API 的
  AES 与既有 identity 配置保留原用途，不通过客户端传给 y1。

既有文档的地址为开发 `https://y1.d.xrteeth.com`、生产 `https://y1.xrteeth.com`，
本次新接口部署状态尚未核验。不要将主 API 域名、Portainer 控制台或插件 iframe URL 当作 y1。

部署前先核对共享表和上述环境；账号删除墓碑迁移由主 API 的迁移工具在共享库执行一次，
y1 不重复建表。迁移和滚动升级期间暂停账号删除与 SN 分发，防止旧 CASCADE 或旧 writer
丢失历史。更新全部 y1 节点及配套主 API 后，在开发环境完成实际验收，再按发布流程推进。
本地测试或历史主 API 发布记录不能替代本次 y1 的开发环境功能验收。

## 验收范围

至少验证网页主 API 分发→y1 激活→启动登录→v1/v2 刷新→退出，来源与账号保持一致；
同对幂等、同 SN 换 UUID/同 UUID 换 SN 冲突、32 位输入拒绝、暂停 SN 的旧 Access 宽限、
删除账号及重建同 ID 后旧 Access/刷新拒绝、UUID 仍占用、存储/限流故障拒绝、并发激活/刷新，
以及 y1 普通用户名密码登录、二维码登录和主 API 历史 SN 会话不受破坏。
双节点需验证交叉登录/刷新、停用和删除同步生效；主 API 旧激活/登录必须为410且无写入。
真机 UUID、休眠唤醒与完整3小时自然到期另行实测，不能从模拟用例推定已通过。
