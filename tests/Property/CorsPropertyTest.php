<?php

declare(strict_types=1);

namespace App\Tests\Property;

use App\Middleware\CorsMiddleware;
use Eris\Generator;
use Eris\TestTrait;
use HttpSoft\Message\Response;
use HttpSoft\Message\ServerRequest;
use HttpSoft\Message\ResponseFactory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Property-based tests for CorsMiddleware.
 *
 * Feature: yii2-to-yii3-migration, Property 1: CORS 响应头完整性
 *
 * **Validates: Requirements 2.1, 2.2**
 *
 * Property 1: For any HTTP request (any Origin, any Method), the CORS middleware
 * should add Access-Control-Allow-Origin, Access-Control-Allow-Methods, and
 * Access-Control-Allow-Headers headers to the response.
 */
final class CorsPropertyTest extends TestCase
{
    use TestTrait;

    private CorsMiddleware $middleware;

    protected function setUp(): void
    {
        $this->middleware = new CorsMiddleware(new ResponseFactory());
    }

    /**
     * Property 1: CORS 响应头完整性
     *
     * For any HTTP method and any Origin header value, the CorsMiddleware
     * must always produce a response containing all three CORS headers:
     * - Access-Control-Allow-Origin
     * - Access-Control-Allow-Methods
     * - Access-Control-Allow-Headers
     *
     * **Validates: Requirements 2.1, 2.2**
     *
     * @eris-repeat 100
     */
    public function testCorsHeadersArePresentForAnyMethodAndOrigin(): void
    {
        $this->forAll(
            Generator\elements('GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS', 'HEAD'),
            Generator\string()
        )
            ->then(function (string $method, string $origin): void {
                $request = new ServerRequest([], [], [], [], [], $method, '/any-path');
                if ($origin !== '') {
                    $request = $request->withHeader('Origin', $origin);
                }

                $handler = new class () implements RequestHandlerInterface {
                    public function handle(ServerRequestInterface $request): ResponseInterface
                    {
                        return new Response();
                    }
                };

                $response = $this->middleware->process($request, $handler);

                $this->assertTrue(
                    $response->hasHeader('Access-Control-Allow-Origin'),
                    "Response for {$method} request must have Access-Control-Allow-Origin header"
                );
                $this->assertTrue(
                    $response->hasHeader('Access-Control-Allow-Methods'),
                    "Response for {$method} request must have Access-Control-Allow-Methods header"
                );
                $this->assertTrue(
                    $response->hasHeader('Access-Control-Allow-Headers'),
                    "Response for {$method} request must have Access-Control-Allow-Headers header"
                );
            });
    }
}
