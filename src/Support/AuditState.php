<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Support;

use Closure;
use Ipsocode\Auditing\Contracts\Auditable;

/**
 * The audit state an Auditable model carries between the observer firing and
 * the driver writing the row.
 *
 * Declared on the Auditable trait, snapshotted around a manual audit by
 * PendingAudit and captured into the queued payload by DispatchAudit, so the
 * list is defined once: a property added to the trait and missed here would
 * silently drop out of the queue or leak across a manual audit.
 *
 * @see \Ipsocode\Auditing\Auditable for where the properties are declared
 */
final class AuditState
{
    /**
     * @var list<string>
     */
    public const array PROPERTIES = [
        'auditEvent',
        'auditCustomOld',
        'auditCustomNew',
        'isCustomEvent',
        'preloadedResolverData',
        'auditBatchUuid',
    ];

    /**
     * Run a callback that sets custom-audit state on a model, then put the
     * state back. The model may be mid-save, so logging never disturbs an audit
     * in flight. The batch is cleared for the callback: the property is public,
     * and a stale value would file the audit under another batch instead of the
     * one open on this coroutine.
     *
     * @template TReturn
     * @param Closure(): TReturn $callback
     * @return TReturn
     */
    public static function scoped(Auditable $model, Closure $callback): mixed
    {
        $restore = [];

        foreach (self::PROPERTIES as $property) {
            $restore[$property] = $model->{$property};
        }

        $model->auditBatchUuid = null;

        try {
            return $callback();
        } finally {
            foreach ($restore as $property => $value) {
                $model->{$property} = $value;
            }
        }
    }
}
