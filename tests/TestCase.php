<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Tests;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Testbench\Concerns\WithWorkbench;
use Hypervel\Testbench\TestCase as BaseTestCase;

use function Hypervel\Testbench\default_migration_path;
use function Hypervel\Testbench\workbench_path;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;
    use WithWorkbench;

    protected function defineEnvironment(Application $app): void
    {
        // The rest of the environment comes from phpunit.xml's <env> block.
        // config/auditing.php hard-codes `auditing.console`, and the suite runs
        // in a console process, which isAuditingEnabled() otherwise gates off.
        $app->get('config')->set('auditing.console', true);
    }

    /**
     * The Workbench migrations, registered early enough for RefreshDatabase's
     * `migrate:fresh` (testbench.yaml's are registered after it has run). The
     * package's own migrations are left to the service provider, so the
     * `auditing.run_migrations` guard stays under test.
     */
    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom([
            // Hypervel's default migrations, for the `users` table behind User.
            default_migration_path(),
            workbench_path('database', 'migrations'),
        ]);
    }
}
