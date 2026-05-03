<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\Yii2RestResponseFactory;
use HttpSoft\Message\ResponseFactory;
use HttpSoft\Message\StreamFactory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

final class Yii2RestResponseFactoryTest extends TestCase
{
    private Yii2RestResponseFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new Yii2RestResponseFactory(
            new ResponseFactory(),
            new StreamFactory(),
        );
    }

    public function testCreatesJsonByDefaultForAnyAccept(): void
    {
        $response = $this->factory->create(
            $this->createRequest('*/*'),
            [[], []],
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/json; charset=UTF-8', $response->getHeaderLine('Content-Type'));
        $this->assertSame('[[],[]]', (string) $response->getBody());
    }

    public function testCreatesYii2XmlForApplicationXmlAccept(): void
    {
        $response = $this->factory->create(
            $this->createRequest('application/xml'),
            [[], []],
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/xml; charset=UTF-8', $response->getHeaderLine('Content-Type'));
        $this->assertSame(
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<response><item/><item/></response>\n",
            (string) $response->getBody(),
        );
    }

    public function testCreatesYii2EmptyXmlRootForEmptyArray(): void
    {
        $response = $this->factory->create(
            $this->createRequest('application/xml'),
            [],
        );

        $this->assertSame(
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<response/>\n",
            (string) $response->getBody(),
        );
    }

    public function testUnsupportedAcceptReturnsYii2NotAcceptableJson(): void
    {
        $response = $this->factory->create(
            $this->createRequest('text/xml'),
            [[], []],
        );

        $this->assertSame(406, $response->getStatusCode());
        $this->assertSame('application/json; charset=UTF-8', $response->getHeaderLine('Content-Type'));
        $this->assertSame(
            '{"name":"Not Acceptable","message":"None of your requested content types is supported.","code":0,"status":406}',
            (string) $response->getBody(),
        );
    }

    public function testCreatesXmlErrorForApplicationXmlAccept(): void
    {
        $response = $this->factory->createError(
            $this->createRequest('application/xml'),
            400,
            'Snapshot not found.',
        );

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('application/xml; charset=UTF-8', $response->getHeaderLine('Content-Type'));
        $this->assertSame(
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<response><name>Bad Request</name><message>Snapshot not found.</message><code>0</code><status>400</status></response>\n",
            (string) $response->getBody(),
        );
    }

    private function createRequest(string $accept): ServerRequestInterface
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getHeaderLine')
            ->with('Accept')
            ->willReturn($accept);

        return $request;
    }
}
