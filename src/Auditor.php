<?php

declare(strict_types=1);

namespace Ipsocode\Auditing;

use Hypervel\Support\Facades\Config;
use Hypervel\Support\Manager;
use InvalidArgumentException;
use Ipsocode\Auditing\Contracts\AcceptsResolvedAudit;
use Ipsocode\Auditing\Contracts\Auditable;
use Ipsocode\Auditing\Contracts\AuditDriver;
use Ipsocode\Auditing\Drivers\AuditDetails;
use Ipsocode\Auditing\Events\Audited;
use Ipsocode\Auditing\Events\Auditing;
use Ipsocode\Auditing\Exceptions\AuditingException;
use Ipsocode\Auditing\Support\AuditBatch;

class Auditor extends Manager implements Contracts\Auditor
{
    public function getDefaultDriver(): string
    {
        return Config::get('auditing.driver', 'audit_details');
    }

    /**
     * Manager::driver() keeps what this returns for the worker's lifetime, a
     * class-name driver from the class_exists() fallback included: one instance
     * serves every coroutine, so a driver keeps per-request state in
     * CoroutineContext, never on itself.
     */
    protected function createDriver(string $driver): mixed
    {
        try {
            return parent::createDriver($driver);
        } catch (InvalidArgumentException $exception) {
            if (class_exists($driver)) {
                return $this->container->make($driver);
            }

            throw $exception;
        }
    }

    public function auditDriver(Auditable $model): AuditDriver
    {
        $driver = $this->driver($model->getAuditDriver());

        if (! $driver instanceof AuditDriver) {
            throw new AuditingException('The driver must implement the AuditDriver contract');
        }

        return $driver;
    }

    /**
     * Run a callback with an audit batch open and return its result: every audit
     * written inside it, cascading writes on other models included, shares one
     * `batch_uuid`.
     *
     * @param null|string $batchUuid an explicit id instead of a generated one
     */
    public function withinBatch(callable $callback, ?string $batchUuid = null): mixed
    {
        return AuditBatch::within($callback, $batchUuid);
    }

    /**
     * The batch open on this coroutine, if any.
     */
    public function currentBatch(): ?string
    {
        return AuditBatch::current();
    }

    /**
     * Start a manual audit; see PendingAudit.
     */
    public function on(Auditable $model): PendingAudit
    {
        return new PendingAudit($this, $model);
    }

    /**
     * Returns the Audit written, or null when nothing was: auditing disabled, an
     * `Auditing` listener vetoing it, or nothing worth recording.
     */
    public function execute(Auditable $model): ?Contracts\Audit
    {
        if (! $model->readyForAuditing()) {
            return null;
        }

        $driver = $this->auditDriver($model);

        if (! $this->fireAuditingEvent($model, $driver)) {
            return null;
        }

        // Resolved once; an AcceptsResolvedAudit driver is handed this same payload.
        $data = $model->toAudit();

        $allowEmpty = Config::get('auditing.empty_values');
        $explicitAllowEmpty = in_array($model->getAuditEvent(), Config::get('auditing.allowed_empty_values', []));

        if (! $allowEmpty && ! $explicitAllowEmpty && empty($data['new_values']) && empty($data['old_values'])) {
            return null;
        }

        $audit = $driver instanceof AcceptsResolvedAudit
            ? $driver->auditWithData($model, $data)
            : $driver->audit($model);
        if (! $audit) {
            return null;
        }

        $driver->prune($model);

        $this->container->make('events')->dispatch(
            new Audited($model, $driver, $audit)
        );

        return $audit;
    }

    /**
     * The `audit_details` driver, found by name through Manager::createDriver().
     */
    protected function createAuditDetailsDriver(): AuditDetails
    {
        return $this->container->make(AuditDetails::class);
    }

    protected function fireAuditingEvent(Auditable $model, AuditDriver $driver): bool
    {
        return $this
            ->container
            ->make('events')
            ->until(new Auditing($model, $driver)) !== false;
    }
}
