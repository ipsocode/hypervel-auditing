<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Ipsocode\Auditing\Models\Audit;

/**
 * A custom `auditing.implementation`. The class name deliberately does not end in
 * "Audit", so a convention-derived foreign key would resolve to `audit_log_id`
 * and miss the `audit_id` column the table actually has.
 */
class AuditLog extends Audit
{
}
