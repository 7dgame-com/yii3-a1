<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Applies JWT authentication only to protected /v2/snapshots scopes.
 */
final class SnapshotScopeAuthMiddleware implements MiddlewareInterface
{
    private const PROTECTED_SCOPES = ['group', 'private'];

    public function __construct(
        private readonly ContainerInterface $container,
    ) {
    }

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        $scope = (string) ($request->getQueryParams()['scope'] ?? 'public');

        if (in_array($scope, self::PROTECTED_SCOPES, true)) {
            /** @var JwtAuthMiddleware $jwtAuthMiddleware */
            $jwtAuthMiddleware = $this->container->get(JwtAuthMiddleware::class);

            return $jwtAuthMiddleware->process($request, $handler);
        }

        return $handler->handle($request);
    }
}
