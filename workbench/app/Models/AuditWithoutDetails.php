<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Hypervel\Database\Eloquent\Model;
use Ipsocode\Auditing\Audit as AuditTrait;
use Ipsocode\Auditing\Contracts\Audit as AuditContract;

/**
 * A valid `auditing.implementation` that satisfies the Audit contract but exposes
 * no `details()` relation — the normalized driver has nowhere to write the
 * per-field rows, and has to say so rather than fail with BadMethodCallException.
 */
class AuditWithoutDetails extends Model implements AuditContract
{
    use AuditTrait;

    protected array $guarded = [];
}
