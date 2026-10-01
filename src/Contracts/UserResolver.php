<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Contracts;

interface UserResolver
{
    /**
     * @return null|\Hypervel\Contracts\Auth\Authenticatable
     */
    public static function resolve();
}
