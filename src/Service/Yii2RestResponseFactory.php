<?php

declare(strict_types=1);

namespace App\Service;

use DOMDocument;
use DOMElement;
use DOMException;
use DOMText;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Creates Yii2 REST-compatible JSON or XML responses.
 */
final class Yii2RestResponseFactory
{
    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {
    }

    public function create(ServerRequestInterface $request, mixed $data, int $statusCode = 200): ResponseInterface
    {
        $format = $this->negotiateFormat($request);

        if ($format === null) {
            return $this->createJsonResponse($this->notAcceptablePayload(), 406);
        }

        return $format === 'xml'
            ? $this->createXmlResponse($data, $statusCode)
            : $this->createJsonResponse($data, $statusCode);
    }

    public function createError(ServerRequestInterface $request, int $statusCode, string $message): ResponseInterface
    {
        return $this->create($request, $this->errorPayload($statusCode, $message), $statusCode);
    }

    /**
     * @return array{name:string,message:string,code:int,status:int}
     */
    public function errorPayload(int $statusCode, string $message): array
    {
        $nameMap = [
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            406 => 'Not Acceptable',
        ];

        return [
            'name' => $nameMap[$statusCode] ?? 'Error',
            'message' => $message,
            'code' => 0,
            'status' => $statusCode,
        ];
    }

    /**
     * @return array{name:string,message:string,code:int,status:int}
     */
    private function notAcceptablePayload(): array
    {
        return $this->errorPayload(406, 'None of your requested content types is supported.');
    }

    private function negotiateFormat(ServerRequestInterface $request): ?string
    {
        $accept = trim($request->getHeaderLine('Accept'));
        if ($accept === '') {
            return 'json';
        }

        foreach ($this->parseAcceptHeader($accept) as $type) {
            if ($type === '*/*' || $type === 'application/json') {
                return 'json';
            }

            if ($type === 'application/xml') {
                return 'xml';
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function parseAcceptHeader(string $accept): array
    {
        $items = [];

        foreach (explode(',', $accept) as $index => $part) {
            $segments = array_map('trim', explode(';', $part));
            $type = strtolower($segments[0] ?? '');
            if ($type === '') {
                continue;
            }

            $quality = 1.0;
            foreach (array_slice($segments, 1) as $segment) {
                if (str_starts_with($segment, 'q=')) {
                    $quality = (float) substr($segment, 2);
                    break;
                }
            }

            $items[] = ['type' => $type, 'quality' => $quality, 'index' => $index];
        }

        usort(
            $items,
            static fn(array $left, array $right): int => $right['quality'] <=> $left['quality']
                ?: $left['index'] <=> $right['index'],
        );

        return array_column($items, 'type');
    }

    private function createJsonResponse(mixed $data, int $statusCode): ResponseInterface
    {
        $stream = $this->streamFactory->createStream(json_encode($data, JSON_THROW_ON_ERROR));

        return $this->responseFactory->createResponse($statusCode)
            ->withHeader('Content-Type', 'application/json; charset=UTF-8')
            ->withHeader('Vary', 'Accept')
            ->withBody($stream);
    }

    private function createXmlResponse(mixed $data, int $statusCode): ResponseInterface
    {
        $stream = $this->streamFactory->createStream($this->toXml($data));

        return $this->responseFactory->createResponse($statusCode)
            ->withHeader('Content-Type', 'application/xml; charset=UTF-8')
            ->withHeader('Vary', 'Accept')
            ->withBody($stream);
    }

    private function toXml(mixed $data): string
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $root = $dom->createElement('response');
        $dom->appendChild($root);

        $this->appendXmlValue($dom, $root, $data);

        return (string) $dom->saveXML();
    }

    private function appendXmlValue(DOMDocument $dom, DOMElement $element, mixed $value): void
    {
        if (is_array($value)) {
            foreach ($value as $name => $item) {
                $child = $dom->createElement($this->xmlTagName($name));
                $element->appendChild($child);
                $this->appendXmlValue($dom, $child, $item);
            }
            return;
        }

        if (is_object($value)) {
            $this->appendXmlValue($dom, $element, get_object_vars($value));
            return;
        }

        if ($value === null || $value === '') {
            return;
        }

        if ($value === true) {
            $value = 'true';
        } elseif ($value === false) {
            $value = 'false';
        }

        $element->appendChild(new DOMText((string) $value));
    }

    private function xmlTagName(int|string $name): string
    {
        if (is_int($name) || $name === '') {
            return 'item';
        }

        try {
            new DOMElement($name);
            return $name;
        } catch (DOMException) {
            return 'item';
        }
    }
}
