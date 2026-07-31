<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;

/**
 * Calls the white-label plugin over its fixed internal service endpoint.
 *
 * The upstream URL and credential are constructor configuration only. No
 * request-derived host, scheme, path, credential, or redirect is accepted,
 * which keeps this gateway from becoming an SSRF primitive.
 */
final class WhiteLabelGatewayService implements WhiteLabelGatewayInterface
{
    public const MAX_RESPONSE_BYTES = 1_048_576;

    private const MAX_SAFE_ID = 9_007_199_254_740_991;
    private const MIN_INTERNAL_TOKEN_BYTES = 32;
    private const MAX_CONTENT_TYPE_BYTES = 256;
    private const MAX_ETAG_BYTES = 256;
    private const MAX_CACHE_CONTROL_BYTES = 512;
    private const MAX_CONTENT_LENGTH_BYTES = 20;
    private const MAX_IF_NONE_MATCH_BYTES = 4_096;

    private readonly ?string $serviceBaseUrl;
    private readonly string $internalToken;

    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly LoggerInterface $logger,
        string $serviceUrl,
        string $internalToken,
    ) {
        $this->serviceBaseUrl = $this->normalizeServiceUrl($serviceUrl);
        $this->internalToken = trim($internalToken);
    }

    public function fetch(
        int $organizationId,
        int $domainId,
        string $ifNoneMatch = '',
    ): WhiteLabelGatewayResponse {
        if (
            $organizationId <= 0
            || $domainId <= 0
            || $organizationId > self::MAX_SAFE_ID
            || $domainId > self::MAX_SAFE_ID
        ) {
            throw new \InvalidArgumentException('Organization and domain IDs must be positive safe integers.');
        }

        if (!$this->isConfigured()) {
            $this->logger->error('White-label gateway is not configured.');
            throw new WhiteLabelGatewayException('White-label gateway is not configured.');
        }

        try {
            $request = $this->requestFactory
                ->createRequest(
                    'GET',
                    $this->serviceBaseUrl
                        . '/internal/v1/white-label-configs/resolve?'
                        . http_build_query(
                            ['o' => $organizationId, 'd' => $domainId],
                            '',
                            '&',
                            PHP_QUERY_RFC3986,
                        ),
                )
                ->withHeader('Accept', 'application/json')
                ->withHeader('X-Internal-Token', $this->internalToken);

            $ifNoneMatch = $this->normalizeIfNoneMatch($ifNoneMatch);
            if ($ifNoneMatch !== '') {
                $request = $request->withHeader('If-None-Match', $ifNoneMatch);
            }

            $response = $this->httpClient->sendRequest($request);
        } catch (\Throwable $exception) {
            $this->logger->warning('White-label upstream request failed.', [
                'organizationId' => $organizationId,
                'domainId' => $domainId,
                'exception' => $exception,
            ]);

            throw new WhiteLabelGatewayException(
                'White-label upstream request failed.',
                previous: $exception,
            );
        }

        try {
            return $this->mapResponse(
                $organizationId,
                $domainId,
                $ifNoneMatch,
                $response,
            );
        } catch (WhiteLabelGatewayException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            $this->logger->warning('White-label upstream response could not be read.', [
                'organizationId' => $organizationId,
                'domainId' => $domainId,
                'exception' => $exception,
            ]);

            throw new WhiteLabelGatewayException(
                'White-label upstream response could not be read.',
                previous: $exception,
            );
        }
    }

    private function mapResponse(
        int $organizationId,
        int $domainId,
        string $ifNoneMatch,
        ResponseInterface $response,
    ): WhiteLabelGatewayResponse {
        $statusCode = $response->getStatusCode();

        if ($statusCode === 404) {
            $this->assertAcceptableContentLength($response);
            $contentType = $this->safeOptionalHeader(
                $response,
                'Content-Type',
                self::MAX_CONTENT_TYPE_BYTES,
            );
            if ($contentType === null || !$this->isJsonContentType($contentType)) {
                throw new WhiteLabelGatewayException(
                    'White-label upstream returned a non-JSON not-found response.',
                );
            }

            $body = $this->readBoundedBody($response->getBody());

            if (
                $body === ''
                || !$this->isStableNotFoundDocument($body)
            ) {
                $this->logger->warning('White-label upstream returned an invalid not-found contract.', [
                    'organizationId' => $organizationId,
                    'domainId' => $domainId,
                    'responseBytes' => strlen($body),
                ]);

                throw new WhiteLabelGatewayException('White-label upstream returned an invalid not-found contract.');
            }

            // Missing, disabled, and mismatched organization/domain pairs
            // intentionally collapse to the same public result.
            return new WhiteLabelGatewayResponse(404);
        }

        if ($statusCode !== 200 && $statusCode !== 304) {
            $this->logger->warning('White-label upstream returned an unexpected status.', [
                'organizationId' => $organizationId,
                'domainId' => $domainId,
                'statusCode' => $statusCode,
            ]);

            throw new WhiteLabelGatewayException('White-label upstream returned an unexpected status.');
        }

        $etag = $this->requiredEntityTag($response);
        $cacheControl = $this->requiredSafeHeader(
            $response,
            'Cache-Control',
            self::MAX_CACHE_CONTROL_BYTES,
        );

        if ($statusCode === 304) {
            if ($ifNoneMatch === '' || !$this->entityTagMatches($ifNoneMatch, $etag)) {
                $this->logger->warning('White-label upstream returned an unverifiable not-modified response.', [
                    'organizationId' => $organizationId,
                    'domainId' => $domainId,
                ]);

                throw new WhiteLabelGatewayException(
                    'White-label upstream returned an unverifiable not-modified response.',
                );
            }

            return new WhiteLabelGatewayResponse(
                statusCode: 304,
                etag: $etag,
                cacheControl: $cacheControl,
            );
        }

        $this->assertAcceptableContentLength($response);
        $contentType = $this->safeOptionalHeader(
            $response,
            'Content-Type',
            self::MAX_CONTENT_TYPE_BYTES,
        );
        if ($contentType === null || !$this->isJsonContentType($contentType)) {
            $this->logger->warning('White-label upstream returned a non-JSON response.', [
                'organizationId' => $organizationId,
                'domainId' => $domainId,
                'contentType' => $contentType,
            ]);

            throw new WhiteLabelGatewayException('White-label upstream returned a non-JSON response.');
        }

        $body = $this->readBoundedBody($response->getBody());
        if (
            $body === ''
            || !$this->isValidConfigurationDocument($body, $organizationId, $domainId)
        ) {
            $this->logger->warning('White-label upstream returned an invalid configuration contract.', [
                'organizationId' => $organizationId,
                'domainId' => $domainId,
                'responseBytes' => strlen($body),
            ]);

            throw new WhiteLabelGatewayException('White-label upstream returned an invalid configuration contract.');
        }

        // An HTTP-compliant upstream should produce 304 itself. This fallback
        // preserves conditional GET semantics if an older plugin returns 200
        // with the same validator.
        if ($ifNoneMatch !== '' && $this->entityTagMatches($ifNoneMatch, $etag)) {
            return new WhiteLabelGatewayResponse(
                statusCode: 304,
                etag: $etag,
                cacheControl: $cacheControl,
            );
        }

        return new WhiteLabelGatewayResponse(
            statusCode: 200,
            body: $body,
            contentType: $contentType,
            etag: $etag,
            cacheControl: $cacheControl,
        );
    }

    private function isConfigured(): bool
    {
        return $this->serviceBaseUrl !== null
            && strlen($this->internalToken) >= self::MIN_INTERNAL_TOKEN_BYTES
            && !preg_match('/[\x00-\x1F\x7F]/', $this->internalToken);
    }

    private function normalizeServiceUrl(string $serviceUrl): ?string
    {
        $serviceUrl = trim($serviceUrl);
        if ($serviceUrl === '' || preg_match('/[\x00-\x20\x7F]/', $serviceUrl)) {
            return null;
        }

        $parts = parse_url($serviceUrl);
        if (
            $parts === false
            || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)
            || $parts['host'] === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || array_key_exists('query', $parts)
            || array_key_exists('fragment', $parts)
        ) {
            return null;
        }

        return rtrim($serviceUrl, '/');
    }

    private function safeOptionalHeader(
        ResponseInterface $response,
        string $name,
        int $maximumBytes,
    ): ?string
    {
        $rawValue = $response->getHeaderLine($name);
        if ($rawValue === '') {
            return null;
        }

        if (
            strlen($rawValue) > $maximumBytes
            || preg_match('/[^\x20-\x7E]/', $rawValue) === 1
        ) {
            throw new WhiteLabelGatewayException("White-label upstream returned an unsafe {$name} header.");
        }

        $value = trim($rawValue);

        return $value === '' ? null : $value;
    }

    private function requiredSafeHeader(
        ResponseInterface $response,
        string $name,
        int $maximumBytes,
    ): string
    {
        $value = $this->safeOptionalHeader($response, $name, $maximumBytes);
        if ($value === null) {
            throw new WhiteLabelGatewayException("White-label upstream omitted the {$name} header.");
        }

        return $value;
    }

    private function requiredEntityTag(ResponseInterface $response): string
    {
        $etag = $this->requiredSafeHeader($response, 'ETag', self::MAX_ETAG_BYTES);
        if (!$this->isValidEntityTag($etag)) {
            throw new WhiteLabelGatewayException('White-label upstream returned an invalid ETag header.');
        }

        return $etag;
    }

    private function assertAcceptableContentLength(ResponseInterface $response): void
    {
        $value = $this->safeOptionalHeader(
            $response,
            'Content-Length',
            self::MAX_CONTENT_LENGTH_BYTES,
        );
        if ($value === null) {
            return;
        }

        if (preg_match('/\A[0-9]+\z/D', $value) !== 1) {
            throw new WhiteLabelGatewayException('White-label upstream returned an invalid Content-Length header.');
        }

        $normalized = ltrim($value, '0');
        $normalized = $normalized === '' ? '0' : $normalized;
        $maximum = (string) self::MAX_RESPONSE_BYTES;
        if (
            strlen($normalized) > strlen($maximum)
            || (strlen($normalized) === strlen($maximum) && strcmp($normalized, $maximum) > 0)
        ) {
            throw new WhiteLabelGatewayException('White-label upstream declared an oversized response.');
        }
    }

    private function readBoundedBody(StreamInterface $stream): string
    {
        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        $body = '';
        while (!$stream->eof()) {
            $remaining = self::MAX_RESPONSE_BYTES + 1 - strlen($body);
            if ($remaining <= 0) {
                throw new WhiteLabelGatewayException('White-label upstream response exceeds the size limit.');
            }

            $chunk = $stream->read(min(8_192, $remaining));
            if ($chunk === '') {
                if ($stream->eof()) {
                    break;
                }

                throw new WhiteLabelGatewayException('White-label upstream response stream stalled.');
            }

            $body .= $chunk;
        }

        if (strlen($body) > self::MAX_RESPONSE_BYTES) {
            throw new WhiteLabelGatewayException('White-label upstream response exceeds the size limit.');
        }

        return $body;
    }

    private function isJsonContentType(string $contentType): bool
    {
        $mediaType = strtolower(trim(explode(';', $contentType, 2)[0]));

        return $mediaType === 'application/json'
            || preg_match(
                '/\A[a-z0-9][a-z0-9!#$&^_.+\-]*\/[a-z0-9][a-z0-9!#$&^_.+\-]*\+json\z/iD',
                $mediaType,
            ) === 1;
    }

    private function isValidConfigurationDocument(
        string $body,
        int $organizationId,
        int $domainId,
    ): bool
    {
        try {
            $document = json_decode($body, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }

        if (
            !$document instanceof \stdClass
            || !isset($document->version)
            || !is_int($document->version)
            || $document->version !== 1
        ) {
            return false;
        }

        $topLevelKeys = array_keys(get_object_vars($document));
        sort($topLevelKeys);
        if ($topLevelKeys !== ['domain', 'organization', 'version']) {
            return false;
        }

        return $this->isValidOrganizationNamespace($document->organization, $organizationId)
            && $this->isValidDomainNamespace($document->domain, $domainId);
    }

    private function isValidOrganizationNamespace(mixed $namespace, int $expectedId): bool
    {
        return $this->isValidConfigurationNamespace($namespace, $expectedId)
            && isset($namespace->name, $namespace->title)
            && is_string($namespace->name)
            && $namespace->name !== ''
            && is_string($namespace->title)
            && $namespace->title !== '';
    }

    private function isValidDomainNamespace(mixed $namespace, int $expectedId): bool
    {
        return $this->isValidConfigurationNamespace($namespace, $expectedId)
            && isset($namespace->configKey)
            && is_string($namespace->configKey)
            && $this->isValidDomainConfigKey($namespace->configKey)
            && $this->isValidStaticDomainConfig($namespace->config, $namespace->configKey);
    }

    private function isValidDomainConfigKey(string $configKey): bool
    {
        return strlen($configKey) <= 253
            && preg_match(
                '/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*\z/D',
                $configKey,
            ) === 1;
    }

    private function isValidStaticDomainConfig(mixed $config, string $configKey): bool
    {
        if (
            !$config instanceof \stdClass
            || !isset(
                $config->name,
                $config->description,
                $config->is_active,
                $config->default_config,
                $config->configs,
            )
            || !property_exists($config, 'fallback_domain')
            || !is_string($config->name)
            || $config->name !== $configKey
            || !is_string($config->description)
            || !is_bool($config->is_active)
            || !($config->fallback_domain === null || (
                is_string($config->fallback_domain)
                && $this->isValidDomainConfigKey($config->fallback_domain)
            ))
            || !$config->default_config instanceof \stdClass
            || !$config->configs instanceof \stdClass
        ) {
            return false;
        }

        $hasLocalizedConfig = false;
        foreach (get_object_vars($config->configs) as $localizedConfig) {
            if (!$localizedConfig instanceof \stdClass) {
                return false;
            }
            if (get_object_vars($localizedConfig) !== []) {
                $hasLocalizedConfig = true;
            }
        }

        if (
            $config->fallback_domain !== null
            && $config->fallback_domain !== $configKey
            && get_object_vars($config->default_config) === []
            && !$hasLocalizedConfig
        ) {
            return false;
        }

        return true;
    }

    private function isValidConfigurationNamespace(mixed $namespace, int $expectedId): bool
    {
        return $namespace instanceof \stdClass
            && isset(
                $namespace->id,
                $namespace->revision,
                $namespace->schemaVersion,
                $namespace->config,
            )
            && is_int($namespace->id)
            && $namespace->id === $expectedId
            && is_int($namespace->revision)
            && $namespace->revision > 0
            && is_int($namespace->schemaVersion)
            && $namespace->schemaVersion > 0
            && $namespace->config instanceof \stdClass;
    }

    private function isStableNotFoundDocument(string $body): bool
    {
        try {
            $document = json_decode($body, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }

        return $document instanceof \stdClass
            && isset($document->error)
            && $document->error instanceof \stdClass
            && isset($document->error->code)
            && is_string($document->error->code)
            && $document->error->code === 'NOT_FOUND';
    }

    private function normalizeIfNoneMatch(string $ifNoneMatch): string
    {
        if (
            strlen($ifNoneMatch) > self::MAX_IF_NONE_MATCH_BYTES
            || preg_match('/[^\x20-\x7E]/', $ifNoneMatch) === 1
        ) {
            return '';
        }

        $ifNoneMatch = trim($ifNoneMatch);
        if ($ifNoneMatch === '') {
            return '';
        }

        if ($ifNoneMatch === '*') {
            return $ifNoneMatch;
        }

        return preg_match(
            '/\A(?:W\/)?"[\x21\x23-\x7E]*"(?: *, *(?:W\/)?"[\x21\x23-\x7E]*")*\z/D',
            $ifNoneMatch,
        ) === 1 ? $ifNoneMatch : '';
    }

    private function isValidEntityTag(string $etag): bool
    {
        return preg_match('/\A(?:W\/)?"[\x21\x23-\x7E]*"\z/D', $etag) === 1;
    }

    private function entityTagMatches(string $ifNoneMatch, string $etag): bool
    {
        if ($ifNoneMatch === '') {
            return false;
        }

        if ($ifNoneMatch === '*') {
            return true;
        }

        $etag = $this->weakEntityTag($etag);
        preg_match_all('/(?:W\/)?"[\x21\x23-\x7E]*"/', $ifNoneMatch, $matches);
        foreach ($matches[0] as $candidate) {
            if ($this->weakEntityTag($candidate) === $etag) {
                return true;
            }
        }

        return false;
    }

    private function weakEntityTag(string $etag): string
    {
        $etag = trim($etag);

        return str_starts_with($etag, 'W/') ? substr($etag, 2) : $etag;
    }
}
