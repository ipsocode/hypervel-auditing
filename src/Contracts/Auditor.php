<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Contracts;

interface Auditor
{
    public function auditDriver(Auditable $model): AuditDriver;

    /**
     * @return null|Audit the Audit written, or null when nothing was
     */
    public function execute(Auditable $model);

    /**
     * Start a manual audit for a model, for an event with no column behind it.
     */
    public function on(Auditable $model): \Ipsocode\Auditing\PendingAudit;

    /**
     * Run a callback with an audit batch open, grouping every audit it writes,
     * and return its result.
     */
    public function withinBatch(callable $callback, ?string $batchUuid = null): mixed;

    /**
     * The batch open on this coroutine, if any.
     */
    public function currentBatch(): ?string;
}
