<?php

declare(strict_types=1);

namespace Workbench\Database\Factories;

use Workbench\App\Models\AuditableUser;

/**
 * Same shape as {@see UserFactory}, bound to the Auditable subclass.
 *
 * @extends UserFactory<AuditableUser>
 */
class AuditableUserFactory extends UserFactory
{
    /** @var class-string<AuditableUser> */
    protected ?string $model = AuditableUser::class;
}
