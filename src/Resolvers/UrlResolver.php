<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Resolvers;

use Hypervel\Support\Facades\App;
use Hypervel\Support\Facades\Request;
use Ipsocode\Auditing\Contracts\Auditable;
use Ipsocode\Auditing\Contracts\Resolver;

class UrlResolver implements Resolver
{
    public static function resolve(Auditable $auditable): string
    {
        if (! empty($auditable->preloadedResolverData['url'] ?? null)) {
            return $auditable->preloadedResolverData['url'] ?? '';
        }

        if (App::runningInConsole()) {
            return self::resolveCommandLine();
        }

        return Request::fullUrl();
    }

    public static function resolveCommandLine(): string
    {
        // Hypervel builds its Request from the Swoole request, whose server bag
        // carries no `argv`, so the superglobal is the only place the command
        // line is actually available in a console process.
        $command = Request::server('argv', null) ?? ($_SERVER['argv'] ?? null);

        if (is_array($command) && $command !== []) {
            return implode(' ', $command);
        }

        return 'console';
    }
}
