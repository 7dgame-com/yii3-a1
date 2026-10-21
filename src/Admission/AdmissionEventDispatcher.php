<?php
declare(strict_types=1);
namespace App\Admission;
use Psr\EventDispatcher\EventDispatcherInterface;
/** Observe errors outside the middleware stack, including AfterRequest/shutdown. */
final class AdmissionEventDispatcher implements EventDispatcherInterface
{
    public function __construct(private readonly EventDispatcherInterface $inner) {}
    public function dispatch(object $event): object
    {
        // Runner can render an emitter/outer-dispatch failure after the first
        // middleware returned. That rendered response is not completion proof.
        if ($event instanceof \Yiisoft\ErrorHandler\Event\ApplicationError) AdmissionLeaseScope::current()?->frameworkError();
        try { return $this->inner->dispatch($event); }
        catch (\Throwable $error) { AdmissionLeaseScope::unknownCurrent(); throw $error; }
    }
}
