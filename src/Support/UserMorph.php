<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Support;

use Hypervel\Support\Facades\Config;

/**
 * The names of the audits table's polymorphic user columns, from `auditing.user.morph_prefix`.
 */
class UserMorph
{
    public static function prefix(): string
    {
        return (string) Config::get('auditing.user.morph_prefix');
    }

    public static function typeColumn(): string
    {
        return static::prefix() . '_type';
    }

    public static function idColumn(): string
    {
        return static::prefix() . '_id';
    }
}
