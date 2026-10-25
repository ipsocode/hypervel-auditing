<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Tests\PHPStan\Fixtures;

use Hypervel\Foundation\Auth\User;
use Ipsocode\Auditing\CausesAudits;

/**
 * Gives PHPStan a class that uses the CausesAudits trait, so it analyzes the trait's body.
 * Never loaded at runtime.
 */
class CausesAuditsUser extends User
{
    use CausesAudits;
}
