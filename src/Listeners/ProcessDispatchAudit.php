<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Listeners;

use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Support\Facades\Config;
use Ipsocode\Auditing\Events\DispatchAudit;
use Ipsocode\Auditing\Facades\Auditor;

class ProcessDispatchAudit implements ShouldQueue
{
    public function viaConnection(): string
    {
        return Config::get('auditing.queue.connection', 'sync');
    }

    public function viaQueue(): string
    {
        return Config::get('auditing.queue.queue', 'default');
    }

    /**
     * Null, not 0, when no delay is set: the dispatcher sends any non-null delay
     * through the connection's later(), which on `deferred` and `background` arms
     * an in-memory timer, so a `deferred` audit would be written as soon as the
     * request coroutine yields, not when it ends. Cast: env values are strings.
     */
    public function withDelay(DispatchAudit $event): ?int
    {
        $delay = (int) Config::get('auditing.queue.delay', 0);

        return $delay > 0 ? $delay : null;
    }

    public function handle(DispatchAudit $event): void
    {
        if (! Config::get('auditing.enabled', true)) {
            return;
        }

        Auditor::execute($event->model);
    }
}
