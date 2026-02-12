# Yii2 → Yii3 JWT 认证迁移

## 功能说明
将 Yii2 的 `bizley/jwt` 桥接包迁移为直接使用 `lcobucci/jwt` v5，并将 Redis ActiveRecord 存储的 RefreshToken 迁移为 Predis 直接操作。

## JWT 服务迁移

### Yii2 原始方式
```php
// 通过 bizley/jwt 桥接
$jwt = Yii::$app->jwt;
$token = $jwt->getBuilder()->...->getToken($jwt->getSigner(), $jwt->getSigningKey());
```

### Yii3 迁移方式
```php
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;

class JwtService {
    public function __construct(string $keyFilePath) {
        $keyContent = trim(file_get_contents($keyFilePath));
        $this->config = Configuration::forSymmetricSigner(
            new Sha256(),
            InMemory::plainText($keyContent),
        );
    }
}
```

### 关键差异
- 密钥从文件读取（`JWT_KEY` 环境变量指定路径），不是直接传字符串
- 使用 `LooseValidAt` 而非 `StrictValidAt`（因为只设了 `iat` 和 `exp`，没设 `nbf`）
- claim 名称：Yii2 用 `uid`，迁移时保持一致以兼容已发出的 token
- 时钟注入：支持 `ClockInterface` 用于测试

### Token 生成
```php
$token = $this->config->builder()
    ->issuedAt($now)
    ->expiresAt($now->modify("+{$this->ttl} seconds"))
    ->withClaim('uid', $userId)
    ->getToken($this->config->signer(), $this->config->signingKey());
```

### Token 验证
```php
$constraints = [
    new SignedWith($this->config->signer(), $this->config->verificationKey()),
    new LooseValidAt($this->clock),
];
$this->config->validator()->validate($parsedToken, ...$constraints);
```

## RefreshToken 迁移

### Yii2 原始方式
```php
// Redis ActiveRecord
class RefreshToken extends \yii\redis\ActiveRecord {
    public static function primaryKey() { return ['token']; }
    public function attributes() { return ['token', 'user_id', 'created_at']; }
}
```

### Yii3 迁移方式
```php
class RefreshTokenService {
    private string $prefix = 'refresh_token:';
    private int $ttl = 2592000; // 30 天

    public function create(int $userId): string {
        $token = bin2hex(random_bytes(32));
        $this->redis->setex($this->prefix . $token, $this->ttl, (string) $userId);
        return $token;
    }

    public function validate(string $token): ?int {
        $userId = $this->redis->get($this->prefix . $token);
        return $userId !== null ? (int) $userId : null;
    }

    public function delete(string $token): void {
        $this->redis->del($this->prefix . $token);
    }
}
```

## AuthService 响应格式（匹配 Yii2）
```php
return [
    'success' => true,
    'message' => 'login',
    'nickname' => $user->get('nickname') ?? '',
    'token' => [
        'accessToken' => $accessToken,
        'expires' => $expires->format('Y-m-d H:i:s'),
        'refreshToken' => $refreshToken,
    ],
    'user' => $user,
];
```

## 依赖
- `lcobucci/jwt` ^5.0（直接使用，不需要桥接包）
- `predis/predis` ^2.0（替代 `yiisoft/yii2-redis`）

## 注意事项
- Yii2 的 `bizley/jwt` 不兼容 Yii3，必须直接用 `lcobucci/jwt`
- RefreshToken 的 Redis key 格式要与 Yii2 版本一致，否则迁移期间已有 token 失效
- 错误码使用 `RuntimeException` + HTTP 状态码作为 code（如 `new RuntimeException('no user', 400)`）
