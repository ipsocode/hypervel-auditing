<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Listeners;

use Hypervel\Support\Facades\Config;
use Ipsocode\Auditing\Events\AuditCustom;
use Ipsocode\Auditing\Facades\Auditor;

class RecordCustomAudit
{
    public function handle(AuditCustom $event): void
    {
        if (! Config::get('auditing.enabled', true)) {
            return;
        }

        Auditor::execute($event->model);
    }
}
