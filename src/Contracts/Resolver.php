<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Contracts;

interface Resolver
{
    /**
     * @return mixed
     */
    public static function resolve(Auditable $auditable);
}
