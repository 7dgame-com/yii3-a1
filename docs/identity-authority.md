# Independent Identity compatibility

`IDENTITY_DATA_SOURCE=legacy` is the default and preserves the current y1
authentication implementation. The remote develop authentication endpoints,
including SN and `/v2/auth/*`, remain available.

After the account, role, session and code imports have been reconciled, configure:

```dotenv
IDENTITY_DATA_SOURCE=identity
IDENTITY_INDEPENDENT_READY=true
IDENTITY_AUTH_BASE_URL=http://identity-native-develop:8086
IDENTITY_TOKEN_ISSUANCE_INTERNAL_API_TOKEN=<auth scoped service token>
IDENTITY_IAM_INTERNAL_API_TOKEN=<IAM scoped service token>
UNITY_DEV_LOGIN_FIXTURE_ENABLED=false
```

The scoped tokens fall back to `IDENTITY_INTERNAL_API_TOKEN` when empty. The
Identity and PHP API deployments must use the matching scoped values. Readiness
is an operator decision after the prerequisites below; it is not established by
setting the flag. No credentials belong in source control or image build args.

In identity mode, password login, refresh, logout, QR exchange and JWT validation
use Identity. HTTP errors, a missing endpoint and transport failures never retry
a local credential reader or issuer. JWT verification reads current private
account status and roles. The old signing key and Redis refresh store are not
used, and local JWT generation, local refresh operations and local login-code
lookups are rejected. A transport failure on a protected resource returns 503.

The existing response envelopes are retained. Token fields remain
`accessToken`, `expires`, `refreshToken`; `expires` uses the original Shanghai
`Y-m-d H:i:s` representation of Identity's actual expiry. V1 refresh omits `user`;
strict V2 refresh returns the safe client user. V1 key-to-token-with-url omits a
missing URL; strict V2 login-code returns `url: null`. No password hash or auth key
is exposed in the new user projection.

## Internal contracts

The auth scoped token protects these Identity endpoints:

- `POST /internal/yii3/refresh`, body
  `{refreshToken, allowLoginCode}`. V1 passes `true`, preserving bare codes,
  `web_<code>` and URL wrappers. V2 passes `false`. Identity must first check any
  existing refresh hash, including expired, revoked and consumed records. Such a
  record always follows refresh rotation and can never fall through to a code.
- `POST /internal/yii3/login-code`, body `{key, operation: "redeem"|"context"}`.
  Redeem returns `{authTokens, subject, frontendDomain?}`; context returns only
  `{frontendDomain}` and never creates a session.
- `POST /internal/auth/issue-user-token`, body
  `{legacyUserId, auth_method: "device_sn", device_sn_id}`. Identity rechecks the
  private account, inherited roles and its separately configured device source.

`authTokens` is Identity's existing flattened `AuthTokenResponse.token` object.
The subject has numeric `id`, `username`, `nickname`, `status`, inherited
`roles`, and, for SN sessions, the verified pair `auth_method`/`device_sn_id`.
`Idempotency-Key` and `User-Agent` are forwarded with bounded, newline-free
values. QR redemption must ignore refresh idempotency when creating separate
device sessions: one active code remains reusable for exactly 60 seconds.

The IAM scoped token protects existing
`POST /internal/auth/verify-access-token` and
`GET /internal/identity/subjects/:id`. The latter supplies current SN account
eligibility. `frontendDomain` is trusted imported code metadata, validated as a
lowercase hostname or IP, and affects only the response URL. It does not alter
the JWT audience or authorize another host.

## Cutover prerequisites

1. Match the actual running y1 image to its `GIT_COMMIT` and source files; the
   old CI's mutable `develop` tag is not sufficient provenance.
2. Import all current y1 refresh sessions while writers are drained. Their
   Redis namespace is `refresh_token:*`; ordinary values are scalar user IDs,
   while `dsn_` values are JSON
   `{version:1,user_id,auth_method:"device_sn",device_sn_id}`. Both use 30-day
   `SETEX`, and neither format stores an issue timestamp or family ID. Exact
   Redis expiry and reviewed issuer evidence are required; do not invent an
   issue date, lose SN source, or treat this namespace as Yii's different
   `refresh_token:a:*` namespace. The imported sessions must retain their
   original absolute expiry. Do not wait 30 days and silently invalidate them.
3. Configure reviewed, bounded trust for existing y1 HS256 access tokens in
   Identity. They contain `uid`, `iat`, `exp` (three hours), and optional SN
   source, without `iss`, `aud` or `typ`. Existing ES256 Yii trust or auth-service
   HS256 `sub/aud/typ` trust does not verify this format. Every accepted old token
   still requires current Identity status, revocation and SN authorization.
4. Import valid login-code records and their original 60-second absolute
   deadline. Redis retention is 300 seconds, which does not extend authority.
   Preserve optional `context.frontend_domain`. Stop every old issuer and
   consumer before the final export; switching y1 alone is insufficient.
5. The remote develop synthetic Unity fixture must be explicitly disabled, or
   separately replaced by a reviewed private Identity test account/code. It
   cannot continue issuing local tokens after ownership changes.
6. Preserve business `device_sn`, its UUID uniqueness and activation/audit
   transactions. In identity mode those are the only SN tables written here;
   account eligibility uses private current status and inherited roles. Disable
   blocks login/refresh while existing access follows Identity's configured
   three-hour maximum; disabled/deleted/promoted accounts fail immediately.
7. The separate live `a1-d` service and routes require independent provenance
   and bypass closure. The plan to replace a1 with y1 does not prove retirement.

Rollback application images while retaining Identity as account/session
authority. Restoring legacy account authority requires a new freeze and reviewed
reverse synchronization, not a flag change or a fallback on individual errors.

## Validation

Run PHPUnit against an isolated Redis fixture, never the development or
production Redis database. The added tests also run without Redis or a database:

```bash
php vendor/bin/phpunit --filter 'IdentityAuthority|DeviceSnServiceTest'
```

They cover the real loopback HTTP transport, distinct scoped keys, default-off
configuration, unchanged response shapes, code/context separation, strict
refresh intent, idempotency header forwarding, unavailable authority, disabled
accounts, SN source retention and the absence of local account/session access.
These tests do not establish that online imports or old-token trust are complete.
