<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Models;

use DateTimeInterface;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\HasMany;
use Ipsocode\Auditing\Audit as AuditTrait;
use Ipsocode\Auditing\Contracts\Audit as AuditContract;

/**
 * @property string $tags
 * @property string $event
 * @property array<string,mixed> $new_values
 * @property array<string,mixed> $old_values
 * @property mixed $user
 * @property mixed $auditable
 */
class Audit extends Model implements AuditContract
{
    use AuditTrait;

    protected array $guarded = [];

    /**
     * Worker-global like `Auditable::$auditingDisabled`; boot or tests only.
     */
    public static bool $auditingGloballyDisabled = false;

    /**
     * Events with no "old" side. An `audit_details` row always has both columns,
     * so a side the event never had is stored as NULL, the same as a genuinely
     * null value; the event name tells them apart. `created` has no previous
     * state, `restored` records only the state brought back, and `retrieved`
     * records no attributes at all.
     *
     * @var list<string>
     */
    protected array $eventsWithoutOldValues = [
        'created',
        'restored',
        'retrieved',
    ];

    /**
     * Events with no "new" side: `deleted` records only the state removed.
     *
     * @var list<string>
     */
    protected array $eventsWithoutNewValues = [
        'deleted',
        'retrieved',
    ];

    /**
     * One row per audited field.
     */
    public function details(): HasMany
    {
        // Explicit key: the default derives from the class name, so a subclass
        // set as `auditing.implementation` would look for e.g. `activity_audit_id`
        // on a table that only has `audit_id`.
        return $this->hasMany(AuditDetail::class, 'audit_id');
    }

    /**
     * Each detail's old value, keyed by field; empty for $eventsWithoutOldValues.
     *
     * @return array<string,mixed>
     */
    public function getOldValuesAttribute(): array
    {
        if (in_array($this->event, $this->eventsWithoutOldValues, true)) {
            return [];
        }

        return $this->details->pluck('old_value', 'field')->all();
    }

    /**
     * Each detail's new value, keyed by field; empty for $eventsWithoutNewValues.
     *
     * @return array<string,mixed>
     */
    public function getNewValuesAttribute(): array
    {
        if (in_array($this->event, $this->eventsWithoutNewValues, true)) {
            return [];
        }

        return $this->details->pluck('new_value', 'field')->all();
    }

    public function getSerializedDate(DateTimeInterface $date): string
    {
        return $this->serializeDate($date);
    }
}
