<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Tests\Fixtures;

use Ipsocode\Auditing\Contracts\Auditable;
use Ipsocode\Auditing\Contracts\Resolver;

/**
 * Counts how often the audit pipeline asks for the URL.
 */
class CountingUrlResolver implements Resolver
{
    public static int $calls = 0;

    public static function resolve(Auditable $auditable): string
    {
        ++static::$calls;

        return 'https://counted.test';
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        static::$calls = 0;
    }
}
