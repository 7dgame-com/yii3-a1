# Y1 请求准入与实际收尾候选

本地源码候选，默认关闭。未部署、未生成稳定控制密钥、未读取生产、未接入实际 ingress/跨主机控制器或 Native continuation。本实现不能单独证明开发环境已经排空或可激活。

`public/index.php` 在创建容器、bootstrap、认证、限流、SN SQL 事务和 token 签发之前，使用固定私控身份登记并 `begin → enter`。gateway 提供 `X-Identity-Admission-Lease` 时只执行单次 `enter`；空值、超长、重复拼接、伪造、过期/重放或归属错误不降级为新 root。只有 coordinator 的认证和原子 enter 可以建立本地上下文，普通公网 header 不能授予权限。入口清除 `$_SERVER` 私控标记，PSR 首 middleware 再剥除 admission/continuation/instance/router/generation headers；保留普通 Authorization 和业务请求。当前源码没有在 BeforeRequest 中读取这些标记的身份监听器，未来增加此类监听器须重新审核。

`AdmissionLeaseScope` 是同步 PHP 请求内部可重入上下文。AuthService、RefreshTokenService、JwtService、SN 事务/登录记录/Redis 限流、登录码解析和 refresh replay 变更均要求 entered lease；开启时直接 CLI 调用这些边界会拒绝。默认关闭不访问私控、不读取控制密钥或构建元数据，原认证/刷新/namespace 逻辑不变。

完成点位于真实 `HttpApplicationRunner::run()` 返回之后，晚于 handler finally、AfterRequest、响应发送、AfterEmit 和 ApplicationShutdown。事件分发器包装也观察 middleware 外层事件异常；runner 在 middleware 已返回后捕获/渲染的发送错误仍标为 unknown。此时检查唯一 DI SQL connection 没有 active transaction，并实际 `session_write_close()`。只有 handler 已返回且边界成功、SQL/Redis/补偿已经同步收尾、PHP 未观察到客户端断开，才发送一次 complete。401/429 等正常错误响应可以完成，500、事件异常、未返回 runner、exit/fatal、未关闭 SQL transaction、客户端 TCP 断开和未知控制回执不能完成。shutdown 回调只报告 unknown；SIGKILL 连回调也不执行，协调器 active 行保留，没有 TTL 自动归零。complete/enter/begin 的未知回执不重试；必要时标记该实例 lost。unknown 没有本适配器的自动清除路径。

控制仅将 HTTP409、精确 `{code}` 且动作白名单内的无副作用拒绝视为 known denial；begin 仅闭闸/未 ready/MAX_INFLIGHT，enter 仅无效 cap/不 active/已进入或错 owner，continue 仅未授权/target/MAX/depth。其它状态（含全部5xx）、日志/操作 unknown、未知或畸形 body 都保留 unknown。begin/continue、enter/validate 会核对 cap 明文的 operation/root/epoch 与本实例或固定目标；解码本身不认证，成功 enter 仍是唯一信任边界。register/status/complete/close-ACK 必需合法状态及唯一匹配 boot/generation 的本实例行；unknown/lost 仅接受精确 `{accepted:true}`，安全上报各至多一次，不重试 complete/enter。完成 ACK 不明会永久 lost，不能被空对象200或伪正常拒绝掩盖。

完成前只读 validate，要求 operation/root/epoch 与 enter 回执相同、`unknown === false` 且 `childrenActive === 0`。continuation 发起前与 ACK 后都核验同一根截止时间；已过期时零 continue RPC，RPC timeout 压到实际剩余时间，晚到/未知 ACK 不返回 header、不恢复 scope、不重试。若本请求发过 continuation，根请求开始的单调时钟就固定了等待截止时间，默认总 1500 ms、25 ms 轮询，配置上限 5000 ms；handler 与 RPC 时间消耗原预算，finish 不重新开始计时，单次 validate RPC 也受剩余时间限制。超时或未知会保留 unknown。未发 continuation 的 root 只做一次 validate，不进行子等待。真实 ingress/browser 的绝对截止时间尚未接入，本候选不能证明用户体验预算满足。

`continuationHeaders()` **仅有合同与 fixture，未接入 IdentityAuthorityClient 业务外呼**。它要求 entered parent、固定 reviewed target 和调用方已有的内部业务认证，向 coordinator 登记 child，再返回固定 lease header 与既有 X-Identity-Internal-Token。公网标记不会复制。Native receiver 的实际接入与验证不在本 diff 中；仅有 child HTTP 响应不能证明 child complete。返回 headers 也不代表随后业务 fetch 自动受该预算限制；业务外呼仍未接入。fixture 的受保护 child 使用本机协议对象，不能当成 Native 已联通。

## 配置合同

| 配置 | 默认/要求 |
|---|---|
| `Y1_ADMISSION_ENABLED` | 严格 `false`（默认）或 `true` |
| `Y1_ADMISSION_CONTROL_URL` | 开启后必填，固定 `http://127.0.0.1:<port>/`，无 user/query/fragment/redirect/proxy；当前只支持同 host |
| `Y1_ADMISSION_KEY_FILE` | 现有、只读打开、非 symlink、单链接、无 group/other 权限的 boot-scoped 原始私控 key，32–4096 bytes；不生成、不推导、不打印 |
| `Y1_ADMISSION_INSTANCE_ID` / `Y1_ADMISSION_BOOT_ID` | 固定 roster identity/该次真实 boot，不能从请求读取 |
| `Y1_ADMISSION_ROUTER_SHA256` | 受审真实入口/数据源清单 SHA-256，64 lowercase hex |
| `Y1_ADMISSION_GENERATION` | 固定 `legacy` 或 `identity` |
| `Y1_ADMISSION_ALLOWED_CHILDREN` | JSON string array，默认 `[]`，只允许固定受审 target，coordinator roster 为最终权限边界 |
| `Y1_ADMISSION_REQUIRES_SESSION_CLOSE` | 严格 boolean 字符串，默认 `false`；收尾实际检查/关闭打开的 PHP session |
| `Y1_ADMISSION_TIMEOUT_MS` | 默认 1000，100–2000，无重试 |
| `Y1_ADMISSION_CHILD_WAIT_MS` | 默认 1500，100–5000，从根开始累计，25 ms poll |

Revision 只来自实际应用根目录 `GIT_COMMIT` 构建文件，40 lowercase hex、非 symlink、单链接且无写入 mode；不接受 runtime env/header revision。已有 CI 在验证 checkout HEAD 等于 github.sha 后写入此文件，Dockerfile 将烘焙文件设为 0444。运行时必须使用受审固定 image digest、保护构建文件/应用挂载并绑定实际 boot。文件和 roster 相等只证明配置一致，不能替代 image provenance、CI attestation、运行镜像/挂载和进程身份的现场核验；拥有运行时 root/写入挂载的操作者仍可替换文件。开启缺少任一必填项会在业务入口之前拒绝，返回无凭据的 503。

## 已验证与未覆盖边界

真实 fixture 使用实际 Yii3 runner/PSR dispatcher/生产 Application DI，真实隔离 MySQL 8、Redis 7、PHP session 文件和 TCP socket。协调器收到 operation_end 时检查 handler finally、SQL commit、Redis 补偿、AfterRequest、AfterEmit、shutdown 与 settled 的顺序。涵盖默认关闭、单次 gateway lease、伪造/重复/跨实例拒绝、active SQL TX、handler/外层事件/发送异常、exit/fatal/SIGKILL/TCP reset、丢 begin/complete 回执以及 parent/child 完成、unknown 与固定截止时间。

这只是同步 HTTP entry 的候选。空闲实例没有常驻 close-ACK 轮询/私控命令，收尾中的请求会用 coordinator 最新 begin/end/epoch ACK；实际进程监督、全部实例/旧 boot 清单、跨 host TLS/认证/互斥、所有公开直连域名和 edge 队列、native receiver、CLI/cron/raw SQL/迁移/清理任务以及其他绕过此入口的 worker 尚未纳入。原始 DB/Redis 客户端没有通用 lease wrapper，新增 writer 必须审查。主连接之外或异步脱离 handler 的写入不在已验证完成证明里。仍需这些边界的真实集成和独立审阅后才能声称排空。

本地运行（只用缓存镜像、全新独立 network/containers、不发布 host 端口，结束只清理由本脚本创建的资源）：

```sh
./tests/manual/run_admission_http_fixture.sh
```

脚本只需要本 Y1 checkout 的已安装 vendor 和缓存的公共 `php:8.4-cli-bookworm` / `node:24-bookworm` / `mysql:8.0` / `redis:7-alpine`。使用网络关闭的 Docker build 编译 pdo_mysql 并复制 Node，生成一次性测试镜像；不依赖私有 xrugc 镜像、superproject sibling checkout 或外部 core 路径。三个一次性容器共享仅本脚本创建的 network namespace；PHP 容器内的只读 hosts 文件将两个固定 fixture aliases 绑定到 127.0.0.1，因此执行与 CI 完全相同的 loopback 检查，host 的 3306/6379 不参与。fixture 只生成临时虚构 key 与随机专用数据库，0600 private key/child 文件，完成后删除；不读取应用 `.env`/JWT/真实账号/云凭据。清理仅作用于脚本保存的 container/network/image ID 或唯一 tag，包含这些 MySQL 容器自己的匿名 volume。

正式 core 五文件以未修改字节冻结在 `tests/Fixtures/admission-protocol/`：coordinator、journal、participant-client、gateway、hold-queue。`manifest.json` 记录 superproject commit `f603930906d55c54fc2c3552ed2f431d05cafe18`、源目录与每文件 SHA-256，和 Native 的 snapshot 来源约定相同。文件由该 commit 的 `git show` 复制，并与受审 upstream working bytes 比对。每次测试先核验准确五文件清单、普通文件（拒 symlink）、固定 provenance 与 SHA；始终 import 本仓库 snapshot，拒绝 `IDENTITY_ADMISSION_CORE_DIR` 外部替换。人工审核 snapshot 更新时应再次对照该 superproject commit；本地可额外传入 `IDENTITY_ADMISSION_UPSTREAM_DIR=/absolute/path/to/driver/identity-dev-admission`，逐字节核对五文件，路径不存在或漂移直接失败。独立 CI 不需要此路径，也没有因此跳过任何测试。SHA 清单证明受审字节一致，不能单独代替部署来源证明。

CI 的 `admission_http_test` 是独立 mandatory job：新 runner、新 MySQL 8/Redis 7 service、Node 24、PHP 8.4 + curl/pdo_mysql/Redis。固定 fixture aliases 只绑定该 runner 的 `127.0.0.1`，预存在时拒绝覆盖；Node runner 再检查所有解析地址都为 loopback。私控 HTTP server 仍只监听 127.0.0.1。`node tests/manual/run_admission_http_ci.mjs` 与 Docker wrapper 使用同一入口，严格要求 TAP 计数 tests/pass 都为 41、fail/cancelled/skipped/todo 都为 0；缺服务、缺扩展、缺 core/manifest、SHA 漂移、少跑或跳过均失败。candidate/build 均依赖此 job 成功。真正 Yii3 Runner/PSR、SQL/Redis 和 TCP fixture 不由 mock 或纯 unit 替代。

10/21 本地验证只证明本地候选，不能作为云部署或现网排空证据；最新冻结的测试计数和日志 SHA 见独立冻结清单。
