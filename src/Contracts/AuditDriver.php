<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Contracts;

interface AuditDriver
{
    public function audit(Auditable $model): ?Audit;

    /**
     * Remove older audits that go over the threshold.
     */
    public function prune(Auditable $model): bool;
}
