<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Resolvers;

use Exception;
use Hypervel\Support\Facades\Auth;
use Hypervel\Support\Facades\Config;
use Ipsocode\Auditing\Contracts\UserResolver as Resolver;

class UserResolver implements Resolver
{
    /**
     * @return null|\Hypervel\Contracts\Auth\Authenticatable
     */
    public static function resolve()
    {
        $guards = Config::get('auditing.user.guards', [
            Config::get('auth.defaults.guard'),
        ]);

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
