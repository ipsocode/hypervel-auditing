<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Concerns;

use Closure;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\BelongsToMany;
use Hypervel\Database\Eloquent\Relations\Pivot;
use Hypervel\Support\Arr;
use Hypervel\Support\Collection;
use Hypervel\Support\Facades\Event;
use Ipsocode\Auditing\Contracts\Auditable as ContractsAuditable;
use Ipsocode\Auditing\Events\AuditCustom;
use Ipsocode\Auditing\Exceptions\AuditingException;
use Throwable;

/**
 * The pivot helpers: attach, detach and sync a belongs-to-many relation and
 * record the change as one custom audit. Applications use only Auditable,
 * which uses this trait.
 */
trait AuditsRelationships
{
    /**
     * @param array<array-key, mixed> $attributes
     * @param array<int, string> $columns
     *
     * @throws AuditingException
     */
    public function auditAttach(string $relationName, mixed $id, array $attributes = [], bool $touch = true, array $columns = ['*'], ?Closure $callback = null): void
    {
        $this->validateRelationshipMethodExistence($relationName, 'attach');

        $relationCall = $this->{$relationName}();

        if ($callback instanceof Closure) {
            $this->applyClosureToRelationship($relationCall, $callback);
        }

        $old = $relationCall->get($columns);
        $relationCall->attach($id, $attributes, $touch);
        $new = $relationCall->get($columns);

        $this->dispatchRelationAuditEvent($relationName, 'attach', $old, $new);
    }

    /**
     * @param array<int, string> $columns
     *
     * @throws AuditingException
     */
    public function auditDetach(string $relationName, mixed $ids = null, bool $touch = true, array $columns = ['*'], ?Closure $callback = null): int
    {
        $this->validateRelationshipMethodExistence($relationName, 'detach');

        $relationCall = $this->{$relationName}();

        if ($callback instanceof Closure) {
            $this->applyClosureToRelationship($relationCall, $callback);
        }

        $old = $relationCall->get($columns);

        $pivotClass = $relationCall->getPivotClass();

        if ($pivotClass !== Pivot::class && is_a($pivotClass, ContractsAuditable::class, true)) {
            // @phpstan-ignore staticMethod.notFound (is_a() above confirms an Auditable pivot, which has withoutAuditing() through its trait)
            $results = $pivotClass::withoutAuditing(function () use ($relationCall, $ids, $touch) {
                return $relationCall->detach($ids, $touch);
            });
        } else {
            $results = $relationCall->detach($ids, $touch);
        }

        $new = $relationCall->get($columns);

        $this->dispatchRelationAuditEvent($relationName, 'detach', $old, $new);

        return empty($results) ? 0 : $results;
    }

    /**
     * @param null|array<array-key, mixed>|Collection<array-key, mixed>|int|Model|string $ids
     * @param array<int, string> $columns
     * @return array{attached: array<array-key, mixed>, detached: array<array-key, mixed>, updated: array<array-key, mixed>}
     *
     * @throws AuditingException
     */
    public function auditSync(string $relationName, Collection|Model|array|int|string|null $ids, bool $detaching = true, array $columns = ['*'], ?Closure $callback = null): array
    {
        $this->validateRelationshipMethodExistence($relationName, 'sync');

        $relationCall = $this->{$relationName}();

        if ($callback instanceof Closure) {
            $this->applyClosureToRelationship($relationCall, $callback);
        }

        $old = $relationCall->get($columns);

        $pivotClass = $relationCall->getPivotClass();

        if ($pivotClass !== Pivot::class && is_a($pivotClass, ContractsAuditable::class, true)) {
            // @phpstan-ignore staticMethod.notFound (is_a() above confirms an Auditable pivot, which has withoutAuditing() through its trait)
            $changes = $pivotClass::withoutAuditing(function () use ($relationCall, $ids, $detaching) {
                return $relationCall->sync($ids, $detaching);
            });
        } else {
            $changes = $relationCall->sync($ids, $detaching);
        }

        /** @var array{attached: array<array-key, mixed>, detached: array<array-key, mixed>, updated: array<array-key, mixed>} $changes */
        if (collect($changes)->flatten()->isEmpty()) {
            $old = $new = collect([]);
        } else {
            $new = $relationCall->get($columns);
        }

        $this->dispatchRelationAuditEvent($relationName, 'sync', $old, $new);

        return $changes;
    }

    /**
     * @param null|array<array-key, mixed>|Collection<array-key, mixed>|int|Model|string $ids
     * @param array<int, string> $columns
     * @return array{attached: array<array-key, mixed>, detached: array<array-key, mixed>, updated: array<array-key, mixed>}
     *
     * @throws AuditingException
     */
    public function auditSyncWithoutDetaching(string $relationName, Collection|Model|array|int|string|null $ids, array $columns = ['*'], ?Closure $callback = null): array
    {
        $this->validateRelationshipMethodExistence($relationName, 'syncWithoutDetaching');

        return $this->auditSync($relationName, $ids, false, $columns, $callback);
    }

    /**
     * @param null|array<array-key, mixed>|Collection<array-key, mixed>|int|Model|string $ids
     * @param array<array-key, mixed> $values
     * @param array<int, string> $columns
     * @return array{attached: array<array-key, mixed>, detached: array<array-key, mixed>, updated: array<array-key, mixed>}
     */
    public function auditSyncWithPivotValues(string $relationName, Collection|Model|array|int|string|null $ids, array $values, bool $detaching = true, array $columns = ['*'], ?Closure $callback = null): array
    {
        $this->validateRelationshipMethodExistence($relationName, 'syncWithPivotValues');

        if ($ids instanceof Model) {
            $ids = $ids->getKey();
        } elseif ($ids instanceof \Hypervel\Database\Eloquent\Collection) {
            $ids = $ids->isEmpty() ? [] : $ids->pluck($ids->first()->getKeyName())->toArray();
        } elseif ($ids instanceof Collection) {
            $ids = $ids->toArray();
        }

        return $this->auditSync($relationName, collect(Arr::wrap($ids))->mapWithKeys(function ($id) use ($values) {
            return [$id => $values];
        }), $detaching, $columns, $callback);
    }

    /**
     * @param Collection<array-key, mixed> $old
     * @param Collection<array-key, mixed> $new
     */
    private function dispatchRelationAuditEvent(string $relationName, string $event, Collection $old, Collection $new): void
    {
        $this->auditCustomOld[$relationName] = $old->diff($new)->toArray();
        $this->auditCustomNew[$relationName] = $new->diff($old)->toArray();

        if (
            empty($this->auditCustomOld[$relationName])
            && empty($this->auditCustomNew[$relationName])
        ) {
            $this->auditCustomOld = $this->auditCustomNew = [];
        }

        $this->auditEvent = $event;
        $this->isCustomEvent = true;

        // In a finally: a throwing listener must not leave custom-audit state
        // for the model's next save (PendingAudit::log() restores the same way).
        try {
            Event::dispatch(new AuditCustom($this));
        } finally {
            $this->auditCustomOld = $this->auditCustomNew = [];
            $this->isCustomEvent = false;
            $this->auditEvent = null;
        }
    }

    private function validateRelationshipMethodExistence(string $relationName, string $methodName): void
    {
        if (! method_exists($this, $relationName) || ! method_exists($this->{$relationName}(), $methodName)) {
            throw new AuditingException("Relationship {$relationName} was not found or does not support method {$methodName}");
        }
    }

    /**
     * @param BelongsToMany<*, *, *, *> $relation
     */
    private function applyClosureToRelationship(BelongsToMany $relation, Closure $closure): void
    {
        try {
            $closure($relation);
        } catch (Throwable $exception) {
            throw new AuditingException(
                "Invalid Closure for {$relation->getRelationName()} Relationship: {$exception->getMessage()}",
                previous: $exception
            );
        }
    }
}
