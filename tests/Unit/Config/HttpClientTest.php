<?php

declare(strict_types=1);

namespace App\Tests\Unit\Config;

use App\Service\WhiteLabelGatewayService;
use GuzzleHttp\Client as GuzzleClient;
use HttpSoft\Message\ResponseFactory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseInterface;

final class HttpClientTest extends TestCase
{
    public function testHeaderGuardRejectsOversizedDeclaredResponse(): void
    {
        $guard = $this->headerGuard();
        $response = (new ResponseFactory())
            ->createResponse(200)
            ->withHeader('Content-Length', (string) (WhiteLabelGatewayService::MAX_RESPONSE_BYTES + 1));

        $this->expectException(\RuntimeException::class);

        $guard($response);
    }

    public function testHeaderGuardRejectsInvalidDeclaredLength(): void
    {
        $guard = $this->headerGuard();
        $response = (new ResponseFactory())
            ->createResponse(200)
            ->withHeader('Content-Length', '1, 1');

        $this->expectException(\RuntimeException::class);

        $guard($response);
    }

    public function testHeaderGuardAllowsMissingOrBoundedDeclaredLength(): void
    {
        $guard = $this->headerGuard();
        $responseFactory = new ResponseFactory();

        $guard($responseFactory->createResponse(200));
        $guard(
            $responseFactory
                ->createResponse(200)
                ->withHeader('Content-Length', (string) WhiteLabelGatewayService::MAX_RESPONSE_BYTES),
        );

        $this->addToAssertionCount(2);
    }

    /**
     * @return callable(ResponseInterface): void
     */
    private function headerGuard(): callable
    {
        /** @var array<class-string, mixed> $definitions */
        $definitions = require dirname(__DIR__, 3) . '/config/common/di/http.php';
        $factory = $definitions[ClientInterface::class];
        $client = $factory();

        $this->assertInstanceOf(GuzzleClient::class, $client);
        $this->assertTrue($client->getConfig('stream'));
        $this->assertSame(3.0, $client->getConfig('read_timeout'));
        $guard = $client->getConfig('on_headers');
        $this->assertIsCallable($guard);

        return $guard;
    }
}
