<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Facades;

use Hypervel\Support\Facades\Facade;
use Ipsocode\Auditing\Contracts\Auditor as AuditorContract;
use Ipsocode\Auditing\Testing\AuditorFake;

/**
 * @method static \Ipsocode\Auditing\Contracts\AuditDriver auditDriver(\Ipsocode\Auditing\Contracts\Auditable $model)
 * @method static null|\Ipsocode\Auditing\Contracts\Audit execute(\Ipsocode\Auditing\Contracts\Auditable $model)
 * @method static \Ipsocode\Auditing\PendingAudit on(\Ipsocode\Auditing\Contracts\Auditable $model)
 * @method static mixed withinBatch(callable $callback, ?string $batchUuid = null)
 * @method static null|string currentBatch()
 * @method static mixed actingAs(null|\Hypervel\Contracts\Auth\Authenticatable $user, callable $callback)
 * @method static null|\Hypervel\Contracts\Auth\Authenticatable currentCauser()
 */
class Auditor extends Facade
{
    /**
     * Replace the auditor with a fake that records audits instead of writing
     * them. The facade root and `Contracts\Auditor` both resolve to it, so code
     * that injects the contract gets it too. Faking again wraps the real auditor
     * once more and starts a fresh recording.
     */
    public static function fake(): AuditorFake
    {
        $auditor = static::isFake() ? static::getFacadeRoot()->auditor : static::getFacadeRoot();

        $fake = new AuditorFake($auditor);

        static::swap($fake);
        static::$app->instance(AuditorContract::class, $fake);

        return $fake;
    }

    /**
     * The provider aliases the contract to this short abstract, the form the
     * framework's facades use (`'redis'`, `'queue'`); `app('auditor')` works too.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'auditor';
    }
}
