<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Drivers;

use Ipsocode\Auditing\Contracts\AcceptsResolvedAudit;
use Ipsocode\Auditing\Contracts\Audit;
use Ipsocode\Auditing\Contracts\Auditable;
use Ipsocode\Auditing\Contracts\AuditDriver;
use Ipsocode\Auditing\Exceptions\AuditingException;
use Ipsocode\Auditing\Support\NormalizesAuditValues;

class AuditDetails implements AuditDriver, AcceptsResolvedAudit
{
    use NormalizesAuditValues;

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
}
