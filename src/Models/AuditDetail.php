<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Models;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\BelongsTo;
use Hypervel\Support\Facades\Config;

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

    public function audit(): BelongsTo
    {
        return $this->belongsTo(Audit::class, 'audit_id');
    }
}
