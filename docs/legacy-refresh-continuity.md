# Y1 旧刷新响应连续性（候选，默认关闭）

当前完整图增量契约为超级项目的 `legacy-refresh-graph-v1`，另带真实 SQL `legacy-refresh-accounts-v1` 快照；`securityChangedAtMs` 由已核 UTC 触发器的 ledger 时间提供，不能从现在推测。独立 preflight 采集器不生成最终 drain 证明，不能直接导入或充当上线 ready。下面早期 `y1-refresh-continuity-v1` 字段清单保留为历史设计说明，不能代替新严格 importer 的实际 schema。

Identity 内部桥接固定 v1 / v2 来源端点；Y1 原缓存实际共享同一 canonical `{token,nickname,user}`，其 scope 为 `y1-refresh-pair`，没有伪造原端点或重建原 JWT。客户端严格校验 `legacyResponse` 的版本、消费方、scope、owner 和 access/refresh pair，再按原 v1/v2 外观返回原昵称、资料及展示期限。另行标准化的 `authTokens.expires` 由已验证 JWT exp 提供，不替换旧 body。客户端仅接受最多256字节、无 C0/DEL 的 opaque 幂等请求编号，不从公开参数接受消费方或端点覆盖。控制器、真实 HTTP、禁用本地依赖的旅程测试覆盖该桥接；源码和测试完成并不代表线上已启用。

本功能仅为旧 Y1 发行者增加原子轮换与原响应重放，不代表 Identity 已可切换。跨 PHP 改密/禁用撤销、真实请求排空、最终同步及 Native importer 未验收时，`IDENTITY_INDEPENDENT_READY` 必须保持 false。

`Y1_REFRESH_REPLAY_ENABLED=false` 保持现有 legacy 实现。启用需要运维显式交接稳定32字节服务端密钥 `Y1_REFRESH_REPLAY_KEY`，使用无填充 base64url；不生成生产密钥，不从 JWT 或其他秘密派生。测试仅在隔离 fixture 内生成随机密钥，不输出密钥。所有 Y1 实例使用同一环境的同一密钥；不允许部署时自动更换。

现有 `refresh_token:<raw>` 的 string/DSN JSON 和30天 SETEX 策略不变。新增 namespace `y1:refresh-replay:v1:` 保存 SHA256 credential 索引、family、撤销 epoch 和 AES-256-GCM 响应。Redis 必须是当前实现支持的单实例/同 slot 数据源；不得误连其他环境。

credential metadata/tombstone 和用户 epoch 不自动过期，保留原 `expires_at_ms` 强制拒绝过期凭据，防止遗忘类型后降级扫码。family 按最新真实凭据原期限过期；response 最长30秒。此兼容存储须纳入内存容量观测，只有 Native 完整导入、旧发行者退役且分类/撤销证据已持久保留后才可审核清理；本候选没有自动 GC。真实 refresh key 的 TTL 从不因此延长。

## Native 导出契约：必须单独实现与审核，不能交给现有 session importer

版本名固定为 `y1-refresh-continuity-v1`。离线 manifest 必须严格拒绝未知字段、重复/冲突 credential、未知 source、非原 TTL、不可解密或不匹配的响应，使用新建0600文件；不能通过公开 API 输出。现有 `legacy-session-import` 的 Y1 schema 禁止 revoked/replaced family，不能读取此契约。

顶层固定字段：

- `version: "y1-refresh-continuity-v1"`。
- `capturedAtMs`: Redis 时间，安全整数毫秒。
- `source`: `{application:"yii3-a1",sourceRevision:<40 lowercase hex>,redisDatabase:1,keyPrefix:"refresh_token:",metadataPrefix:"y1:refresh-replay:v1:",fixedTtlSeconds:2592000,expiryPolicy:"setex-on-create-only",namespaceWritersReviewed:true}`；开发 DB1 是本次迁移限定，不能推断其他环境。
- `credentials`: 每条 `{credentialHash,userId,familyId,accountFingerprint,accountEpoch,userEpoch,authContext,issuedAtMs,expiresAtMs,state,successorHash,rotatedAtMs,revokedAtMs}`。hash 均为64位小写 SHA256；familyId 为32位小写随机hex；state 仅 active/rotated/revoked；rotated 必须 successorHash 与真实 rotatedAtMs；revoked 必须 revokedAtMs，并保留已有 rotatedAtMs；active 三者为 null。authContext 只能 `{}` 或 `{auth_method:"device_sn",device_sn_id:<positive safe int>}`。
- `replays`: 每条 `{predecessorHash,successorHash,userId,familyId,accountFingerprint,accountEpoch,userEpoch,requestHash,createdAtMs,expiresAtMs,response}`。requestHash 为 null 或 SHA256；expiresAtMs 不超过 createdAtMs+30000，也不超过 predecessor/successor 原有效期；原无key或不同key只可在 createdAtMs+5000 之前重放。不能从 capturedAtMs 重新延长。
- `revocations`: 每个相关账号 `{userId,userEpoch,revokedAtMs}`，读取同次 Redis snapshot 的 `user:<id>:epoch` 与 `user:<id>:revoked-at-ms`，epoch=0 时 revokedAtMs 为 null，否则必须保留真实撤销时间。credential epoch 小于账号当前 epoch 时不可 active；不能只读取旧 metadata 声称的 epoch。Native 私库 revocation cutoff 只能单调前进。
- `response`: 解密后的 `{token:{accessToken,expires,refreshToken},nickname,user:{id,username,nickname,fixture:false}}`，字段及类型严格限制；refreshToken 的 hash 必须 successorHash，user.id 必须 userId。响应 token 属敏感数据，只写受保护离线文件，不写日志/报告。现有 encrypted response 先按 Y1 密钥/AAD验证，Native importer 再按其私有密钥重加密；不得直接信任 JSON 声称的关系。

`accountFingerprint = SHA256(UTF8(JSON.stringify(["yii-ar-account-v1", passwordHashOrNull, authKeyOrNull])))`，与 Identity 现有 `legacyRefreshAccountFingerprint` 一致。`accountEpoch` 是源 MySQL `identity_legacy_security_versions.epoch` 的严格正十进制字符串（1..18446744073709551615），禁止转 JSON number；与 Redis 用于会话撤销的 `userEpoch` 分开。AES-256-GCM blob 为 `base64url(iv12 || tag16 || ciphertext)` 无填充；AAD 是 UTF8 JSON 数组 `["y1-refresh-response-v1", predecessorHash, successorHash, userId, familyId, userEpoch, accountFingerprint, accountEpoch]`，不转义 slash/Unicode。服务端稳定 key 只能私下交接，不进入导出源/日志。

导出必须在真实全 writer 排空后原子读取关联 Redis 状态及剩余有效期，保留 consumed predecessor tombstone、完整 family 和撤销 epoch，不能只扫描仍存在的 refresh_token keys。有效期来自 Redis TIME/PTTL/PEXPIRETIME 与已有 metadata，不能从当前时间猜 issuance。所有 family 的账号安全指纹及 accountEpoch 必须与已迁入私库的源账号安全快照一致，SN 需独立现时业务授权校验。

Native 需在 staging fence 内同事务导入 predecessor→successor、稳定 family、撤销及原响应；可以复用现有 sessions/replays 表，但必须严格区分此响应版本，不能把完整 token 响应冒充 IssuedIdentitySession。重放前锁账号，检查 active/安全指纹/accountEpoch/撤销 cutoff/后继仍活跃，返回原 token。重复导入不得重置已消费、撤销、lifetime、response 或 replay 截止时间。导入关系/响应能力完成之前，本候选不可用于最终切换。

## 本地轮换语义与上线前提

- 有同一稳定 Idempotency-Key 时最多30秒重放原响应；无key旧客户端5秒。不同接口维持各自响应外观，token pair 相同。
- 原子操作保存 successor、关系及加密响应后才删除 predecessor；失败或响应丢失可安全重试，不先删后建。
- logout 任一已知 predecessor/successor 撤销整个 family；用户撤销推进 epoch，阻止 scan 与 refresh 竞态。重放不恢复已撤销后继。
- 准备/提交/重放都在账号行锁下检查 active、安全指纹、源 SQL accountEpoch 及 Redis 撤销 epoch；新发行凭据绑定 fingerprint + accountEpoch。现存凭据须先审查并 backfill 元数据，不延长原 refresh TTL。
- 所有旧发行节点先升级，feature 仍关闭；回填工具默认 dry-run，apply 必须精确审核 manifest 的 SHA256；源值、账号指纹、凭据集合、元数据状态变化则拒绝。回填完成并排除旧版本 writer 后才可启用。
- Feature 开启前必须先审核应用源库安全版本表及 user INSERT/UPDATE/DELETE 触发器，使密码、auth_key、status 的真实变更（包括 raw SQL/批量入口）在同一事务中推进 accountEpoch；账号行锁后读取 ledger，缺失/无效则503。原值改回、禁用重启、删除重建也不能复活旧凭据。源 DDL 不由本功能/回填工具自行创建。
- PHP/A1 共享 namespace 的退出/刷新互通及 Native accountEpoch 导入未验收前，不能宣称账号生命周期或全链路会话连续性验收通过。
- `login-code`、`key-to-token` 和 v1 旧扫码 fallback 仍为60秒内多设备读取兑换。已知 consumed/revoked refresh 不能降级扫码。
- 此候选不改变已经签发的旧 access JWT 的既有有效期策略；Native 接管后的撤权/禁用/退出行为另需集成验收。

## 元数据精确审核与应用

工具不创建 schema、发行 token、改变 Identity authority 或运行 Docker。默认仅产生新建0600审核文件（不含原 refresh token、密码 hash 或服务密钥），输出数量及文件 SHA256：

```sh
php scripts/refresh-replay-backfill.php --out=/protected/new-y1-review.json
php scripts/refresh-replay-backfill.php --apply --review=/protected/new-y1-review.json --reviewed-sha256=<已审核文件的64位小写SHA256>
```

apply 要求同一凭据集合、值哈希、metadata 哈希、账号指纹、accountEpoch、Redis epoch、来源和原绝对到期时间；先全量 preflight，再逐行锁账号并 Lua CAS；完成后再次检查集合。源变化会拒绝，不自动重试、不回滚已写的安全元数据；部分应用需重新生成审核文件。全程不写原 refresh key，不改变其 TTL。工具没有证明所有 writer 已关闸，成功报告始终 `independentReady:false`。

共享 Redis 以只读 classifier 隔离 Yii2 Redis ActiveRecord 的真实存储树：`refresh_token:s:id` 是规范十进制 string counter，`refresh_token:a:<规范正十进制id>` 是 hash，`refresh_token` 是主键 list。依据 YiiRedisAR `insert()` / `buildKey()`：自动编号写 counter，RPUSH 单主键，HMSET 只写非 null 属性。hash 必须有匹配的 `id`、正整数 `user_id` 和 `key`；额外属性只接受当前 PHP 模型的 `session_id,user_agent,ip,created_at,expires_at,revoked_at,auth_method,device_sn_id`。可缺省或为空的历史属性、永久 key 和任意原始属性字节只用于辨识命名空间；不据此猜测 plain/hash 编码、发行者、有效期或迁移资格。

审核文件版本为 `y1-refresh-metadata-review-v2`，以 `siblingInventorySha256` 绑定兄弟树的 key/type、原始属性或 list 字节及永久/到期类别。apply 前后重新扫描并比较摘要；未知 key、类型、属性或非法 Y1 普通/SN/metadata 形状使预检拒绝，预检失败不写任何 metadata。兄弟 counter、hash、list 及 TTL 从不被回填或撤销改动。撤销先全量只读预检，再一次 Lua 比较全部目标 Y1 string 值后删除；任一目标类型或所有者在预检后变化，整批不删除。feature 开启的用户撤销仍先在账号锁下推进 epoch；即使随后因未知 namespace 返回503，旧 family 已保守失效，物理凭据及兄弟树保持原样。

审核文件另绑定 MySQL host/database/DSN 哈希与 Redis host/port/DB，跨目标应用会拒绝。不能用开发数据的审核文件去应用另一套同数据副本。

全部节点升级且 feature 关闭后仍会发行无 metadata 的旧凭据；因此正常流量下的 dry-run/backfill 只是提前准备。最终启用必须使用真实准入屏障，完成最终 exact review/apply、验证无旧发行节点，再统一启用同一 generation 后放行。没有该屏障与预算实测不能声称无体验影响。误启用后遇到未回填的已存在 refresh 返回存储暂不可用（不按凭据失效401清除会话，也不降级扫码）；正常切换不能把此防线当作用户体验方案。

本机定向测试使用明确的 disposable Redis/MySQL network，普通 unit 新用例拒绝未知 Redis；真实 MySQL 测试必须显式设置 fixture 环境并拒绝已有 user 表。示例测试入口：`vendor/bin/phpunit tests/Unit/Service/LegacyRefreshReplayTest.php` 和 `vendor/bin/phpunit tests/Integration/LegacyRefreshReplayMysqlTest.php`，fixture 的 host/DB guard 见测试源码；不要改指向开发或生产库。
