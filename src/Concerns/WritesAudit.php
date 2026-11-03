<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Concerns;

use Hypervel\Support\Facades\Config;
use Ipsocode\Auditing\Contracts\Auditable;
use Ipsocode\Auditing\Facades\Auditor;

trait WritesAudit
{
    /**
     * Write the model's audit unless auditing is switched off, which is read per event.
     */
    protected function writeAudit(Auditable $model): void
    {
        if (! Config::boolean('auditing.enabled')) {
            return;
        }

        Auditor::execute($model);
    }
}
