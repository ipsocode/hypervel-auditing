<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Tests\Feature\Auditor\Fixtures;

use LogicException;

/**
 * Not Auditable, and its constructor has a visible side effect.
 */
class ProbedConstructor
{
    public function __construct()
    {
        throw new LogicException('The constructor ran.');
    }
}
