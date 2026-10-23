<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Hypervel\Database\Eloquent\Concerns\HasUuids;
use Hypervel\Database\Eloquent\Model;
use Ipsocode\Auditing\Auditable;
use Ipsocode\Auditing\Contracts\Auditable as AuditableContract;

class UuidArticle extends Model implements AuditableContract
{
    use Auditable;
    use HasUuids;

    protected ?string $table = 'uuid_articles';

    protected array $fillable = [
        'title',
    ];
}
