<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Tests\Feature;

use Hypervel\Testbench\Attributes\WithConfig;
use Ipsocode\Auditing\AuditingServiceProvider;
use Ipsocode\Auditing\Facades\Auditor;
use Ipsocode\Auditing\Resolvers\UrlResolver;
use Ipsocode\Auditing\Resolvers\UserAgentResolver;
use Ipsocode\Auditing\Resolvers\UserResolver;
use Ipsocode\Auditing\Tests\TestCase;
use Workbench\App\Models\Article;
use Workbench\App\Models\User;

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

    /**
     * `user`, `queue` and `tables` merge key by key too, which is why src/ reads
     * their keys without an inline fallback. `tables.audits` is set to its
     * default so the suite's migrations are unaffected.
     */
    #[WithConfig('auditing.user', ['guards' => ['web']])]
    #[WithConfig('auditing.queue', ['enable' => true])]
    #[WithConfig('auditing.tables', ['audits' => 'audits'])]
    public function testAPublishedConfigWithPartialNestedArraysKeepsThePackagesOtherKeys(): void
    {
        $this->assertSame(['web'], config('auditing.user.guards'));
        $this->assertSame('user', config('auditing.user.morph_prefix'));
        $this->assertSame(UserResolver::class, config('auditing.user.resolver'));

        $this->assertTrue(config('auditing.queue.enable'));
        $this->assertSame('sync', config('auditing.queue.connection'));
        $this->assertSame('default', config('auditing.queue.queue'));
        $this->assertSame(0, config('auditing.queue.delay'));

        $this->assertSame('audit_details', config('auditing.tables.audit_details'));
    }

    #[WithConfig('auditing.user', ['guards' => ['web']])]
    public function testAPublishedUserConfigWithoutAMorphPrefixStillWritesUserColumns(): void
    {
        $user = User::factory()->create();
        $article = Article::factory()->create();

        Auditor::actingAs($user, fn () => $article->update(['title' => 'Prefixed']));

        $audit = $article->audits()->latest('id')->first();

        $this->assertSame($user->getKey(), $audit->user_id);
        $this->assertSame($user->getMorphClass(), $audit->user_type);
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

    public function testTheAboutCommandShowsAnAuditingSection(): void
    {
        $this->artisan('about', ['--only' => 'auditing'])
            ->expectsOutputToContain('Auditing')
            ->expectsOutputToContain('Console')
            ->expectsOutputToContain('audit_details')
            ->expectsOutputToContain('Enabled')
            ->expectsOutputToContain('365 days')
            ->expectsOutputToContain('Queue')
            ->expectsOutputToContain('Version')
            ->assertExitCode(0);
    }

    public function testTheAboutSectionReadsConfigAtTheTimeTheCommandRuns(): void
    {
        config(['auditing.driver' => 'late_driver', 'auditing.delete_records_older_than_days' => 7]);

        $this->artisan('about', ['--only' => 'auditing'])
            ->expectsOutputToContain('late_driver')
            ->expectsOutputToContain('7 days')
            ->assertExitCode(0);
    }
}
