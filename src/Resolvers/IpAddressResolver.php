<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Resolvers;

use Hypervel\Support\Facades\Request;
use Ipsocode\Auditing\Contracts\Auditable;
use Ipsocode\Auditing\Contracts\Resolver;

class IpAddressResolver implements Resolver
{
    public static function resolve(Auditable $auditable): string
    {
        return $auditable->preloadedResolverData['ip_address'] ?? Request::ip();
    }
}
