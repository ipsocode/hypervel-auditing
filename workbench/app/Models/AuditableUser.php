<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Hypervel\Database\Eloquent\Factories\HasFactory;
use Ipsocode\Auditing\Auditable;
use Ipsocode\Auditing\Contracts\Auditable as AuditableContract;

/**
 * A second, unrelated Auditable model: it tells a class-scoped `withoutAuditing()`
 * apart from the global variant, which must also cover models that never opened
 * a scope of their own.
 */
class AuditableUser extends User implements AuditableContract
{
    use Auditable;
    use HasFactory;

    /**
     * Shares the `users` table with {@see User}; without this Eloquent would
     * infer `auditable_users` from the class name.
     */
    protected ?string $table = 'users';
}
