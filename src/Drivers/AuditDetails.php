<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Drivers;

use BackedEnum;
use Ipsocode\Auditing\Contracts\AcceptsResolvedAudit;
use Ipsocode\Auditing\Contracts\Audit;
use Ipsocode\Auditing\Contracts\Auditable;
use Ipsocode\Auditing\Contracts\AuditDriver;
use Ipsocode\Auditing\Exceptions\AuditingException;
use Stringable;
use UnitEnum;

class AuditDetails implements AuditDriver, AcceptsResolvedAudit
{
    /**
     * Writes one `audits` row and one `audit_details` row per audited field.
     */
    public function audit(Auditable $model): ?Audit
    {
        return $this->auditWithData($model, $model->toAudit());
    }

    public function auditWithData(Auditable $model, array $data): ?Audit
    {
        $oldValues = $data['old_values'] ?? [];
        $newValues = $data['new_values'] ?? [];

        unset($data['old_values'], $data['new_values']);

        $auditClass = get_class($model->audits()->getModel());

        // Both writes run on the Audit model's own connection (`auditing.connection`
        // or a custom implementation's override): a transaction on the default
        // connection would not roll back a half-written audit.
        return (new $auditClass)->getConnection()->transaction(function () use ($auditClass, $data, $oldValues, $newValues) {
            /** @var Audit $audit */
            $audit = call_user_func([$auditClass, 'create'], $data);

            if (! method_exists($audit, 'details')) {
                throw new AuditingException(sprintf(
                    'The audit implementation %s must expose a details() relation to use the audit_details driver',
                    $auditClass
                ));
            }

            $fields = array_unique(array_merge(array_keys($oldValues), array_keys($newValues)));

            if ($fields !== []) {
                $details = $audit->details();

                // insert() bypasses the relation's own event/casting pipeline, so
                // the foreign key and the timestamp it would normally stamp on
                // create() have to be set explicitly here.
                $createdAt = $details->getRelated()->freshTimestampString();

                $details->insert(array_map(fn ($field) => [
                    'audit_id' => $audit->getKey(),
                    'field' => $field,
                    'old_value' => $this->normalizeValue($oldValues[$field] ?? null),
                    'new_value' => $this->normalizeValue($newValues[$field] ?? null),
                    'created_at' => $createdAt,
                ], $fields));
            }

            return $audit;
        });
    }

    public function prune(Auditable $model): bool
    {
        if (($threshold = $model->getAuditThreshold()) > 0) {
            $relation = $model->audits();
            $auditClass = get_class($relation->getModel());
            $auditModel = new $auditClass;
            $keyName = $auditModel->getKeyName();

            // Cloned before the delete query below joins onto it — the relation's
            // query builder is mutated in place by leftJoinSub(), so the subquery
            // has to be captured first or it would inherit that join too.
            $subQuery = (clone $relation->getQuery())
                ->select($keyName)
                // `created_at` has whole-second precision, so same-second
                // audits tie on it; the auto-incrementing key breaks the tie
                // in insertion order.
                ->latest()
                ->orderByDesc($keyName)
                ->limit($threshold);

            return $relation
                ->leftJoinSub(
                    $subQuery,
                    'audit_threshold',
                    function ($join) use ($auditModel, $keyName) {
                        $join->on(
                            $auditModel->getTable() . '.' . $keyName,
                            '=',
                            'audit_threshold.' . $keyName
                        );
                    }
                )
                ->whereNull('audit_threshold.' . $keyName)
                ->delete() > 0;
        }

        return false;
    }

    /**
     * Reduce an attribute value to a string for one column: scalars are cast,
     * enums give their value or name, stringable objects their string form, and
     * anything else is JSON-encoded.
     */
    protected function normalizeValue(mixed $value): ?string
    {
        if ($value === null || is_string($value)) {
            return $value;
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        // resolveAuditExclusions() keeps enums and stringable objects; json_encode()
        // would flatten such an object to "{}" and cannot encode a pure enum.
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        if ($value instanceof UnitEnum) {
            return $value->name;
        }

        if ($value instanceof Stringable || (is_object($value) && method_exists($value, '__toString'))) {
            return (string) $value;
        }

        // Arrays (kept with `auditing.allowed_array_values`) are stored as JSON; a
        // value json_encode() cannot represent returns false and is stored as null.
        $encoded = json_encode($value);

        return $encoded === false ? null : $encoded;
    }
}
