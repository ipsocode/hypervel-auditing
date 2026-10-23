<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Resolvers;

use Hypervel\Support\Facades\Request;
use Ipsocode\Auditing\Contracts\Auditable;
use Ipsocode\Auditing\Contracts\Resolver;

class UserAgentResolver implements Resolver
{
    /**
     * The length of the `user_agent` column in the audits migration.
     */
    public const MAX_LENGTH = 1023;

    public static function resolve(Auditable $auditable): string
    {
        $userAgent = $auditable->preloadedResolverData['user_agent'] ?? Request::header('User-Agent', '');

        return mb_substr((string) $userAgent, 0, self::MAX_LENGTH);
    }
}
