<?php

declare(strict_types=1);

use GuzzleHttp\Client as GuzzleClient;
use HttpSoft\Message\RequestFactory;
use HttpSoft\Message\ResponseFactory;
use HttpSoft\Message\ServerRequestFactory;
use HttpSoft\Message\StreamFactory;
use HttpSoft\Message\UploadedFileFactory;
use HttpSoft\Message\UriFactory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;

/**
 * PSR-18 HTTP client and PSR-17 factory DI configuration.
 * Maps PSR interfaces to the bounded Guzzle client and HttpSoft factories.
 */
// Keep aligned with WhiteLabelGatewayService::MAX_RESPONSE_BYTES. This
// transport-level guard aborts before Guzzle downloads a declared giant body.
$whiteLabelMaximumResponseBytes = 1_048_576;
$whiteLabelHeaderGuard = static function (ResponseInterface $response) use (
    $whiteLabelMaximumResponseBytes,
): void {
    $contentLength = $response->getHeaderLine('Content-Length');
    if ($contentLength === '') {
        return;
    }

    if (
        strlen($contentLength) > 20
        || preg_match('/\A[0-9]+\z/D', $contentLength) !== 1
    ) {
        throw new \RuntimeException('White-label upstream returned an invalid Content-Length header.');
    }

    $normalized = ltrim($contentLength, '0');
    $normalized = $normalized === '' ? '0' : $normalized;
    $maximum = (string) $whiteLabelMaximumResponseBytes;
    if (
        strlen($normalized) > strlen($maximum)
        || (strlen($normalized) === strlen($maximum) && strcmp($normalized, $maximum) > 0)
    ) {
        throw new \RuntimeException('White-label upstream declared an oversized response.');
    }
};

return [
    ClientInterface::class => static fn(): ClientInterface => new GuzzleClient([
        'allow_redirects' => false,
        'connect_timeout' => 1.5,
        'http_errors' => false,
        'on_headers' => $whiteLabelHeaderGuard,
        'read_timeout' => 3.0,
        'stream' => true,
        'timeout' => 3.0,
    ]),
    ServerRequestFactoryInterface::class => ServerRequestFactory::class,
    RequestFactoryInterface::class => RequestFactory::class,
    ResponseFactoryInterface::class => ResponseFactory::class,
    StreamFactoryInterface::class => StreamFactory::class,
    UploadedFileFactoryInterface::class => UploadedFileFactory::class,
    UriFactoryInterface::class => UriFactory::class,
];
