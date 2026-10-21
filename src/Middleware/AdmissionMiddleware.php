<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Admission\AdmissionLeaseScope;
use App\Admission\AdmissionSettings;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** First PSR boundary; the outer runner lifecycle owns actual completion. */
final class AdmissionMiddleware implements MiddlewareInterface
{
    public static function privateMarker(string $name): bool
    {
        return preg_match('/^x-(?:identity-)?admission(?:-|$)|^x-.*continu(?:ation|ed)|^x-identity-(?:instance(?:-|$)|router(?:-|$)|generation$)/i', $name) === 1;
    }
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $scope = AdmissionLeaseScope::current();
        if (($scope?->settings->enabled ?? AdmissionSettings::enabledFromEnvironment())) AdmissionLeaseScope::assertWriter();
        foreach (array_keys($request->getHeaders()) as $name) {
            if (self::privateMarker($name)) {
                $request = $request->withoutHeader($name);
            }
        }
        // Attributes are local PHP objects, never caller-supplied string flags.
        $request = $request->withAttribute(self::class, $scope);
        try { $response = $handler->handle($request); $scope?->handlerFinished($response->getStatusCode() < 500); return $response; }
        catch (\Throwable $error) { $scope?->handlerFinished(false); throw $error; }
    }
}
