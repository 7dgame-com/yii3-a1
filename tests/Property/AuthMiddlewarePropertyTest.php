<?php

declare(strict_types=1);

namespace App\Tests\Property;

use App\Middleware\JwtAuthMiddleware;
use App\Service\JwtService;
use Eris\Generator;
use Eris\TestTrait;
use HttpSoft\Message\ResponseFactory;
use HttpSoft\Message\ServerRequest;
use HttpSoft\Message\StreamFactory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Property-based tests for JwtAuthMiddleware.
 *
 * Feature: yii2-to-yii3-migration, Property 4: 无效认证返回 401
 *
 * **Validates: Requirements 3.3, 3.4, 9.1, 9.2**
 *
 * Property 4: For any randomly generated invalid JWT token string,
 * accessing a protected endpoint through JwtAuthMiddleware should return
 * a 401 status code.
 */
final class AuthMiddlewarePropertyTest extends TestCase
{
    use TestTrait;

    private string $keyFilePath;
    private JwtAuthMiddleware $middleware;

    protected function setUp(): void
    {
        $this->keyFilePath = tempnam(sys_get_temp_dir(), 'jwt_mw_prop_');
        file_put_contents($this->keyFilePath, bin2hex(random_bytes(32)));

        $jwtService = new JwtService($this->keyFilePath);
        $this->middleware = new JwtAuthMiddleware(
            $jwtService,
            new ResponseFactory(),
            new StreamFactory(),
        );
    }

    protected function tearDown(): void
    {
        if (file_exists($this->keyFilePath)) {
            unlink($this->keyFilePath);
        }
    }

    /**
     * Property 4: 无效认证返回 401
     *
     * For any random string used as a Bearer token, the middleware must
     * return a 401 response because the string is not a valid JWT signed
     * with the service's key.
     *
     * **Validates: Requirements 3.3, 3.4, 9.1, 9.2**
     *
     * @eris-repeat 100
     */
    public function testInvalidTokensReturn401(): void
    {
        $handler = new class () implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new \RuntimeException('Handler should not be reached for invalid tokens');
            }
        };

        $this->forAll(
            Generator\string()
        )
            ->then(function (string $invalidToken) use ($handler): void {
                $request = (new ServerRequest([], [], [], [], [], 'GET', '/protected'))
                    ->withHeader('Authorization', 'Bearer ' . $invalidToken);

                $response = $this->middleware->process($request, $handler);

                $this->assertSame(
                    401,
                    $response->getStatusCode(),
                    "Invalid token '{$invalidToken}' must produce 401 response",
                );

                $body = json_decode((string) $response->getBody(), true);
                $this->assertSame(401, $body['status']);
                $this->assertSame(
                    'Your request was made with invalid credentials.',
                    $body['message'],
                );
            });
    }
}
