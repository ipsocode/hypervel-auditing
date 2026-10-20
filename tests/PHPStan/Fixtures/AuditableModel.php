<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Tests\PHPStan\Fixtures;

use Hypervel\Database\Eloquent\Model;
use Ipsocode\Auditing\Auditable;
use Ipsocode\Auditing\Contracts\Auditable as AuditableContract;

/**
 * Gives PHPStan a class that uses the Auditable trait, so it analyses the trait's body.
 * Never loaded at runtime.
 */
class AuditableModel extends Model implements AuditableContract
{
    use Auditable;
}
