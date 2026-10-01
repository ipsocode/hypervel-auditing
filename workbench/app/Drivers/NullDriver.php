<?php

declare(strict_types=1);

namespace Workbench\App\Drivers;

use Ipsocode\Auditing\Contracts\Audit;
use Ipsocode\Auditing\Contracts\Auditable;
use Ipsocode\Auditing\Contracts\AuditDriver;

/**
 * Records nothing and returns null, the documented way for a driver to opt out
 * of an audit — Auditor::execute() must then skip both prune() and the Audited
 * event.
 */
class NullDriver implements AuditDriver
{
    public static int $auditCalls = 0;

    public static int $pruneCalls = 0;

    public function audit(Auditable $model): ?Audit
    {
        ++static::$auditCalls;

        return null;
    }

    public function prune(Auditable $model): bool
    {
        ++static::$pruneCalls;

        return false;
    }
}
