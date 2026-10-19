<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Testing;

use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Support\Collection;
use Hypervel\Support\Facades\Config;
use Hypervel\Support\Testing\Fakes\Fake;
use Ipsocode\Auditing\Contracts\Audit;
use Ipsocode\Auditing\Contracts\Auditable;
use Ipsocode\Auditing\Contracts\AuditDriver;
use Ipsocode\Auditing\Contracts\Auditor;
use Ipsocode\Auditing\PendingAudit;
use Ipsocode\Auditing\Support\NormalizesAuditValues;
use PHPUnit\Framework\Assert;

/**
 * Installed by `Facades\Auditor::fake()`. Records the audits the real auditor
 * would write, without a driver and without touching the audit tables, and
 * asserts on them.
 *
 * It applies the gates `Auditor::execute()` applies before a driver is reached:
 * an unaudited event, `withoutAuditing()` and the empty-value guard record
 * nothing. It fires neither `Auditing` nor `Audited`, so a veto listener is not
 * consulted and no listener is handed an audit that was never saved.
 */
class AuditorFake implements Auditor, Fake
{
    use NormalizesAuditValues;

    /** @var list<Audit&Model> */
    protected array $audits = [];

    public function __construct(public readonly Auditor $auditor)
    {
    }

    public function auditDriver(Auditable $model): AuditDriver
    {
        return $this->auditor->auditDriver($model);
    }

    public function withinBatch(callable $callback, ?string $batchUuid = null): mixed
    {
        return $this->auditor->withinBatch($callback, $batchUuid);
    }

    public function currentBatch(): ?string
    {
        return $this->auditor->currentBatch();
    }

    public function actingAs(?Authenticatable $user, callable $callback): mixed
    {
        return $this->auditor->actingAs($user, $callback);
    }

    public function currentCauser(): ?Authenticatable
    {
        return $this->auditor->currentCauser();
    }

    /**
     * A manual audit bound to the fake, so `log()` records here.
     */
    public function on(Auditable $model): PendingAudit
    {
        return new PendingAudit($this, $model);
    }

    /**
     * Record the audit the real auditor would write and return it, or null when
     * the real auditor would write nothing.
     */
    public function execute(Auditable $model): ?Audit
    {
        if (! $model->readyForAuditing()) {
            return null;
        }

        $data = $model->toAudit();

        $allowEmpty = Config::get('auditing.empty_values');
        $explicitAllowEmpty = in_array($model->getAuditEvent(), Config::get('auditing.allowed_empty_values', []));

        if (! $allowEmpty && ! $explicitAllowEmpty && empty($data['new_values']) && empty($data['old_values'])) {
            return null;
        }

        $oldValues = $data['old_values'] ?? [];
        $newValues = $data['new_values'] ?? [];

        unset($data['old_values'], $data['new_values']);

        $auditClass = Config::get('auditing.implementation');

        $audit = new $auditClass($data);
        $related = $audit->details()->getRelated();

        $details = array_map(fn (string $field) => $related->newInstance([
            'field' => $field,
            'old_value' => $this->normalizeValue($oldValues[$field] ?? null),
            'new_value' => $this->normalizeValue($newValues[$field] ?? null),
        ]), array_values(array_unique(array_merge(array_keys($oldValues), array_keys($newValues)))));

        $audit->setRelation('details', $related->newCollection($details));
        $audit->setRelation('auditable', $model);

        return $this->audits[] = $audit;
    }

    /**
     * The recorded audits, narrowed by a callback that receives each one.
     *
     * @return Collection<int, Audit>
     */
    public function recorded(?callable $callback = null): Collection
    {
        return (new Collection($this->audits))
            ->filter(fn (Audit $audit) => $callback === null || $callback($audit))
            ->values();
    }

    /**
     * @param class-string|Model $model an instance, matched with `is()`, or a class matching any instance of it
     */
    public function assertAudited(Model|string $model, ?string $event = null, ?callable $callback = null): void
    {
        Assert::assertTrue(
            $this->matching($model, $event, $callback)->isNotEmpty(),
            sprintf('The expected audit was not recorded: %s.%s', $this->describe($model, $event), $this->listing())
        );
    }

    /**
     * @param class-string|Model $model
     */
    public function assertNotAudited(Model|string $model, ?string $event = null, ?callable $callback = null): void
    {
        Assert::assertTrue(
            $this->matching($model, $event, $callback)->isEmpty(),
            sprintf('An unexpected audit was recorded: %s.%s', $this->describe($model, $event), $this->listing())
        );
    }

    public function assertNothingAudited(): void
    {
        Assert::assertSame([], $this->audits, sprintf('Audits were recorded.%s', $this->listing()));
    }

    public function assertAuditedCount(int $count): void
    {
        Assert::assertCount(
            $count,
            $this->audits,
            sprintf('Expected %d recorded audits, found %d.%s', $count, count($this->audits), $this->listing())
        );
    }

    /**
     * @param class-string|Model $model
     * @return Collection<int, Audit>
     */
    protected function matching(Model|string $model, ?string $event, ?callable $callback): Collection
    {
        return $this->recorded(function (Audit&Model $audit) use ($model, $event, $callback) {
            $auditable = $audit->getRelation('auditable');

            if ($model instanceof Model ? ! $auditable->is($model) : ! $auditable instanceof $model) {
                return false;
            }

            if ($event !== null && $audit->getAttribute('event') !== $event) {
                return false;
            }

            return $callback === null || $callback($audit);
        });
    }

    /**
     * @param class-string|Model $model
     */
    protected function describe(Model|string $model, ?string $event): string
    {
        $target = $model instanceof Model ? $this->label($model) : $model;

        return $event === null ? $target : sprintf('%s [%s]', $target, $event);
    }

    protected function label(Model $model): string
    {
        return sprintf('%s #%s', $model::class, $model->getKey() ?? 'unsaved');
    }

    /**
     * What was recorded, by model, key and event, for a failure message.
     */
    protected function listing(): string
    {
        if ($this->audits === []) {
            return ' Nothing was recorded.';
        }

        return "\nRecorded:\n" . implode("\n", array_map(
            fn (Audit&Model $audit) => sprintf('- %s [%s]', $this->label($audit->getRelation('auditable')), $audit->getAttribute('event')),
            $this->audits
        ));
    }
}
