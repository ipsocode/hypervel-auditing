<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Tests\Feature;

use Hypervel\Testbench\Attributes\WithConfig;
use Ipsocode\Auditing\AuditingServiceProvider;
use Ipsocode\Auditing\Resolvers\UrlResolver;
use Ipsocode\Auditing\Resolvers\UserAgentResolver;
use Ipsocode\Auditing\Tests\TestCase;

class AuditingServiceProviderTest extends TestCase
{
    #[WithConfig('auditing.resolvers', ['ip_address' => UrlResolver::class])]
    public function testOverridingOneResolverKeepsThePackagesOtherResolverDefaults(): void
    {
        // `resolvers` merges key by key: overriding `ip_address` keeps the
        // package's `user_agent` and `url` defaults.
        $this->assertSame(UrlResolver::class, config('auditing.resolvers.ip_address'));
        $this->assertSame(UserAgentResolver::class, config('auditing.resolvers.user_agent'));
        $this->assertSame(UrlResolver::class, config('auditing.resolvers.url'));
    }

    public function testPublishesConfigAndMigrationsWhenRunningInConsole(): void
    {
        $configPaths = AuditingServiceProvider::pathsToPublish(AuditingServiceProvider::class, 'auditing-config');
        $migrationPaths = AuditingServiceProvider::pathsToPublish(AuditingServiceProvider::class, 'auditing-migrations');

        $this->assertSame([config_path('auditing.php')], array_values($configPaths));

        $this->assertCount(1, $migrationPaths);
        $migrationSource = array_key_first($migrationPaths);
        $this->assertSame(realpath(dirname(__DIR__, 2) . '/database/migrations'), realpath($migrationSource));
        $this->assertSame(database_path('migrations'), $migrationPaths[$migrationSource]);
    }
}
