<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Hypervel\Database\Eloquent\Concerns\HasUuids;
use Hypervel\Foundation\Auth\User as Authenticatable;
use Ipsocode\Auditing\CausesAudits;

class UuidUser extends Authenticatable
{
    use CausesAudits;
    use HasUuids;

    protected ?string $table = 'uuid_users';

    /** @var array<int, string> */
    protected array $fillable = [
        'name',
    ];
}
