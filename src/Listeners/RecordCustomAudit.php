<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Listeners;

use Ipsocode\Auditing\Concerns\WritesAudit;
use Ipsocode\Auditing\Events\AuditCustom;

class RecordCustomAudit
{
    use WritesAudit;

    public function handle(AuditCustom $event): void
    {
        $this->writeAudit($event->model);
    }
}
