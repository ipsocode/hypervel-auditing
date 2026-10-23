<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Contracts;

use Hypervel\Contracts\Auth\Authenticatable;

interface UserResolver
{
    public static function resolve(): ?Authenticatable;
}
