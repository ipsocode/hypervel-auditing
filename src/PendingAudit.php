<?php

declare(strict_types=1);

namespace Ipsocode\Auditing;

use Hypervel\Contracts\Auth\Authenticatable;
use Ipsocode\Auditing\Contracts\Audit;
use Ipsocode\Auditing\Contracts\Auditable;
use Ipsocode\Auditing\Contracts\Auditor;
use Ipsocode\Auditing\Exceptions\AuditingException;

/**
 * A manual audit, built up and then written.
 *
 * Model events and the pivot helpers record changes to a model; this records
 * events with no column behind them, such as a login, an export or a grant:
 *
 *     Auditor::on($contract)->as('exported')->with(['format' => 'pdf'])->log();
 *
 * `with()` fills the new side and `from()` the old; the default driver writes
 * one `audit_details` row per property.
 */
class PendingAudit
{
    protected ?string $event = null;

    /** @var array<string, mixed> */
    protected array $old = [];

    /** @var array<string, mixed> */
    protected array $new = [];

    /**
     * An explicit causer, overriding the configured UserResolver.
     */
    protected ?Authenticatable $user = null;

    public function __construct(
        protected Auditor $auditor,
        protected Auditable $model
    ) {
    }

    /**
     * Name the event, e.g. "exported" or "granted".
     */
    public function as(string $event): static
    {
        $this->event = $event;

        return $this;
    }

    /**
     * Record properties on the new side of the audit.
     *
     * @param array<string, mixed> $properties
     */
    public function with(array $properties): static
    {
        $this->new = array_merge($this->new, $properties);

        return $this;
    }

    /**
     * Record properties on the old side of the audit.
     *
     * @param array<string, mixed> $properties
     */
    public function from(array $properties): static
    {
        $this->old = array_merge($this->old, $properties);

        return $this;
    }

    /**
     * Attribute the audit to a specific user. Otherwise `auditing.user.resolver`
     * supplies the request's authenticated user: wrong for work done on someone
     * else's behalf, and absent in a queue worker or a console command.
     */
    public function by(?Authenticatable $user): static
    {
        $this->user = $user;

        return $this;
    }

    /**
     * Write the audit. Null when nothing was written: auditing disabled for the
     * model, an `Auditing` listener vetoing it, or the empty-value guard turning
     * away an audit with no properties on either side.
     *
     * @throws AuditingException when no event name has been set
     */
    public function log(?string $event = null): ?Audit
    {
        $event ??= $this->event;

        if ($event === null || $event === '') {
            throw new AuditingException(
                'A custom audit needs an event name. Pass one to as() or to log().'
            );
        }

        $model = $this->model;

        // The custom-audit state is six properties on the caller's model, which
        // may be mid-save: snapshot and restore them so logging never disturbs
        // an audit in flight.
        $restore = [
            'auditEvent' => $model->auditEvent,
            'auditCustomOld' => $model->auditCustomOld,
            'auditCustomNew' => $model->auditCustomNew,
            'isCustomEvent' => $model->isCustomEvent,
            'preloadedResolverData' => $model->preloadedResolverData,
            'auditBatchUuid' => $model->auditBatchUuid,
        ];

        $model->auditEvent = $event;
        $model->auditCustomOld = $this->old;
        $model->auditCustomNew = $this->new;
        $model->isCustomEvent = true;
        // Join the batch open on this coroutine: the property is public, and a
        // stale value would file this audit under another batch.
        $model->auditBatchUuid = null;

        if ($this->user !== null) {
            $model->preloadedResolverData['user'] = $this->user;
        }

        try {
            return $this->auditor->execute($model);
        } finally {
            foreach ($restore as $property => $value) {
                $model->{$property} = $value;
            }
        }
    }
}
