<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Tests\Fixtures;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Support\Facades\Config;
use Hypervel\Support\Facades\DB;

/**
 * Renders a package migration's MySQL DDL without a MySQL server.
 */
trait RendersMysqlDdl
{
    /**
     * Register the `mysql_pretend` connection from `defineEnvironment()`. It is
     * never connected to: pretend() renders the DDL without executing it.
     */
    protected function defineMysqlPretendConnection(Application $app): void
    {
        $app->get('config')->set('database.connections.mysql_pretend', [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => 3306,
            'database' => 'nowhere',
            'username' => 'nobody',
            'password' => '',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
        ]);
    }

    /**
     * The DDL the given migration would issue on MySQL. SQLite maps `char` to a
     * bare `varchar` with no length, so it cannot catch a column too narrow for
     * the value the driver writes.
     *
     * @return array<int, string>
     */
    private function mysqlDdl(string $migration): array
    {
        $definition = require dirname(__DIR__, 2) . '/database/migrations/' . $migration;

        Config::set('auditing.connection', 'mysql_pretend');

        return array_map(
            static fn (array $entry): string => $entry['query'],
            DB::connection('mysql_pretend')->pretend(static fn () => $definition->up())
        );
    }
}
