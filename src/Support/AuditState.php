<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Support;

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
}
