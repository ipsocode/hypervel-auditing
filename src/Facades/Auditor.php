<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Facades;

use Hypervel\Support\Facades\Facade;

/**
 * @method static \Ipsocode\Auditing\Contracts\AuditDriver auditDriver(\Ipsocode\Auditing\Contracts\Auditable $model)
 * @method static null|\Ipsocode\Auditing\Contracts\Audit execute(\Ipsocode\Auditing\Contracts\Auditable $model)
 * @method static \Ipsocode\Auditing\PendingAudit on(\Ipsocode\Auditing\Contracts\Auditable $model)
 * @method static mixed withinBatch(callable $callback, ?string $batchUuid = null)
 * @method static null|string currentBatch()
 */
class Auditor extends Facade
{
    /**
     * The provider aliases the contract to this short abstract, the form the
     * framework's facades use (`'redis'`, `'queue'`); `app('auditor')` works too.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'auditor';
    }
}
