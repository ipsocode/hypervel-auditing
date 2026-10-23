<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Models;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\BelongsTo;
use Hypervel\Support\Facades\Config;
use Ipsocode\Auditing\Contracts\Audit as AuditContract;

/**
 * @property int $id
 * @property int $audit_id
 * @property string $field
 * @property null|string $old_value
 * @property null|string $new_value
 */
class AuditDetail extends Model
{
    /**
     * Audit details are immutable records — only `created_at` is tracked.
     */
    public const ?string UPDATED_AT = null;

    /**
     * The column holds milliseconds; the grammar default would write whole seconds.
     */
    protected ?string $dateFormat = 'Y-m-d H:i:s.v';

    protected array $fillable = [
        'audit_id',
        'field',
        'old_value',
        'new_value',
    ];

    public function getConnectionName(): ?string
    {
        return Config::get('auditing.connection');
    }

    public function getTable(): string
    {
        return \Ipsocode\Auditing\Support\AuditTables::auditDetails();
    }

    /**
     * @return BelongsTo<AuditContract&Model, $this>
     */
    public function audit(): BelongsTo
    {
        /** @var class-string<AuditContract&Model> $class */
        $class = Config::get('auditing.implementation');

        return $this->belongsTo($class, 'audit_id');
    }
}
