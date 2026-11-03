<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Resolvers;

use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Support\Facades\Auth;
use Hypervel\Support\Facades\Config;
use Ipsocode\Auditing\Contracts\UserResolver as Resolver;

class UserResolver implements Resolver
{
    public static function resolve(): ?Authenticatable
    {
        $guards = Config::get('auditing.user.guards');

        foreach ($guards as $guard) {
            // The shipped guards include one an application may not define.
            if (! Config::has("auth.guards.{$guard}")) {
                continue;
            }

            $instance = Auth::guard($guard);

            if ($instance->check() === true) {
                return $instance->user();
            }
        }

        return null;
    }
}
