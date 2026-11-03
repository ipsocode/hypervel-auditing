<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Support;

use Closure;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Database\Schema\Builder;

/**
 * Schema steps the two audit migrations share.
 */
class AuditSchema
{
    /**
     * Add the columns an existing table lacks, matching names case-insensitively;
     * columns it already has are left alone.
     *
     * @param array<string, Closure(Blueprint): mixed> $columns defined by name, in the order create() adds them
     */
    public static function addMissingColumns(Builder $schema, string $table, array $columns): void
    {
        $existing = array_map('strtolower', $schema->getColumnListing($table));

        $missing = array_filter(
            $columns,
            static fn (callable $define, string $name): bool => ! in_array(strtolower($name), $existing, true),
            ARRAY_FILTER_USE_BOTH,
        );

        if ($missing === []) {
            return;
        }

        $schema->table($table, function (Blueprint $table) use ($missing) {
            foreach ($missing as $define) {
                $define($table);
            }
        });
    }
}
