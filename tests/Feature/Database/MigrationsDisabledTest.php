<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Tests\Feature\Database;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Database\Migrations\Migrator;
use Ipsocode\Auditing\Tests\TestCase;

/**
 * When `auditing.run_migrations` is false (the state `auditing:install` writes
 * after adopting existing tables), the provider must not register the bundled
 * create migrations with the migrator.
 */
class MigrationsDisabledTest extends TestCase
{
    protected function defineEnvironment(Application $app): void
    {
        parent::defineEnvironment($app);

        $app->get('config')->set('auditing.run_migrations', false);
    }

    public function testBundledMigrationsAreNotRegistered(): void
    {
        $paths = array_map('realpath', $this->app->get(Migrator::class)->paths());

        $this->assertNotContains(
            realpath(dirname(__DIR__, 3) . '/database/migrations'),
            $paths,
        );
    }
}
