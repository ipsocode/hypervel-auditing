<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Resolvers;

use Hypervel\Support\Facades\Request;
use Ipsocode\Auditing\Contracts\Auditable;
use Ipsocode\Auditing\Contracts\Resolver;

class UserAgentResolver implements Resolver
{
    public static function resolve(Auditable $auditable): string
    {
        return $auditable->preloadedResolverData['user_agent'] ?? Request::header('User-Agent', '');
    }
}
