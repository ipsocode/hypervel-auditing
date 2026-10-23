<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Resolvers;

use Exception;
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
            try {
                $authenticated = Auth::guard($guard)->check();
            } catch (Exception $exception) {
                continue;
            }

            if ($authenticated === true) {
                return Auth::guard($guard)->user();
            }
        }

        return null;
    }
}
