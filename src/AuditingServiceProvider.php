<?php

declare(strict_types=1);

namespace Ipsocode\Auditing;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Support\Facades\Config;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\ServiceProvider;
use Ipsocode\Auditing\Console\InstallCommand;
use Ipsocode\Auditing\Console\PruneCommand;
use Ipsocode\Auditing\Contracts\Auditor;
use Ipsocode\Auditing\Events\AuditCustom;
use Ipsocode\Auditing\Events\DispatchAudit;
use Ipsocode\Auditing\Listeners\ProcessDispatchAudit;
use Ipsocode\Auditing\Listeners\RecordCustomAudit;

class AuditingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/auditing.php', 'auditing');

        $this->app->singleton(Auditor::class, function (Application $app) {
            return new \Ipsocode\Auditing\Auditor($app);
        });

        $this->app->alias(Auditor::class, 'auditor');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/auditing.php' => config_path('auditing.php'),
            ], 'auditing-config');

            $this->publishesMigrations([
                __DIR__ . '/../database/migrations' => database_path('migrations'),
            ], 'auditing-migrations');

            $this->commands([InstallCommand::class, PruneCommand::class]);
        }

        if (Config::get('auditing.run_migrations', true)) {
            $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        }

        // Registered unconditionally: `auditing.enabled` is a runtime switch the
        // listeners read per event, so toggling it after boot takes effect.
        Event::listen(AuditCustom::class, RecordCustomAudit::class);
        Event::listen(DispatchAudit::class, ProcessDispatchAudit::class);
    }

    /**
     * Config arrays merged one level deep: an app that sets one resolver, user
     * option, queue option or table name keeps the package's others.
     *
     * @return array<int, string>
     */
    protected function mergeableOptions(string $name): array
    {
        return $name === 'auditing' ? ['resolvers', 'user', 'queue', 'tables'] : [];
    }
}
