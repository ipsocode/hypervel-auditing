<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Tests\Fixtures;

use Hypervel\Support\Facades\Config;
use Hypervel\Support\Facades\DB;
use Ipsocode\Auditing\Drivers\AuditDetails;

/**
 * Records both connections' transaction levels while the driver builds its
 * detail rows, inside the transaction it opened.
 */
class LevelRecordingDriver extends AuditDetails
{
    /** @var array<int, array{default: int, audit: int}> */
    public static array $levels = [];

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        static::$levels = [];
    }

    protected function normalizeValue(mixed $value): ?string
    {
        static::$levels[] = [
            'default' => DB::connection(Config::get('database.default'))->transactionLevel(),
            'audit' => DB::connection('audit_db')->transactionLevel(),
        ];

        return parent::normalizeValue($value);
    }
}
