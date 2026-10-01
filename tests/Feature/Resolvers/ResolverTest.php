<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Tests\Feature\Resolvers;

use Hypervel\Support\Facades\Config;
use Ipsocode\Auditing\Contracts\Auditable;
use Ipsocode\Auditing\Contracts\Resolver;
use Ipsocode\Auditing\Exceptions\AuditingException;
use Ipsocode\Auditing\Resolvers\IpAddressResolver;
use Ipsocode\Auditing\Resolvers\UrlResolver;
use Ipsocode\Auditing\Resolvers\UserAgentResolver;
use Ipsocode\Auditing\Tests\TestCase;
use stdClass;
use Workbench\App\Models\Article;

class ResolverTest extends TestCase
{
    private function article(): Article
    {
        return Article::factory()->create();
    }

    public function testResolverOutputIsStoredOnTheAudit(): void
    {
        $audit = $this->article()->audits()->sole();

        $this->assertNotNull($audit->ip_address);
        $this->assertNotNull($audit->url);
    }

    public function testConsoleAuditsRecordTheCommandLineRatherThanAPlaceholder(): void
    {
        // Hypervel's Request has no `argv` in its server bag. The command line
        // comes from the superglobal, so an operator can tell which command made
        // a mass change.
        $url = UrlResolver::resolveCommandLine();

        $this->assertSame(implode(' ', $_SERVER['argv']), $url);
        $this->assertNotSame('console', $url);
    }

    public function testCommandLineFallsBackWhenNoArgvIsAvailable(): void
    {
        $argv = $_SERVER['argv'] ?? null;

        try {
            unset($_SERVER['argv']);

            $this->assertSame('console', UrlResolver::resolveCommandLine());
        } finally {
            $_SERVER['argv'] = $argv;
        }
    }

    public function testAnInlineAuditRunsEachResolverOnce(): void
    {
        CountingUrlResolver::$calls = 0;
        Config::set('auditing.resolvers.url', CountingUrlResolver::class);

        $article = $this->article();

        $this->assertSame('https://counted.test', $article->audits()->sole()->url);
        $this->assertSame(1, CountingUrlResolver::$calls);

        // Nothing was preloaded: the inline path resolves once, where it writes.
        $this->assertSame([], $article->preloadedResolverData);
    }

    public function testPreloadedResolverDataTakesPrecedence(): void
    {
        $article = $this->article();

        $article->preloadedResolverData = [
            'url' => 'https://preloaded.test/path',
            'ip_address' => '10.0.0.1',
            'user_agent' => 'PreloadedAgent/1.0',
        ];

        $this->assertSame('https://preloaded.test/path', UrlResolver::resolve($article));
        $this->assertSame('10.0.0.1', IpAddressResolver::resolve($article));
        $this->assertSame('PreloadedAgent/1.0', UserAgentResolver::resolve($article));
    }

    public function testPreloadedDataIsCapturedBeforeTheAuditIsWritten(): void
    {
        $article = Article::factory()->make();

        $article->preloadResolverData();

        $this->assertArrayHasKey('url', $article->preloadedResolverData);
        $this->assertArrayHasKey('ip_address', $article->preloadedResolverData);
        $this->assertArrayHasKey('user_agent', $article->preloadedResolverData);
    }

    public function testAResolverThatDoesNotImplementTheContractIsRejected(): void
    {
        Config::set('auditing.resolvers.url', stdClass::class);

        $this->expectException(AuditingException::class);
        $this->expectExceptionMessage('Invalid Resolver implementation for: url');

        $this->article();
    }

    public function testAnEmptyResolverEntryIsSkipped(): void
    {
        Config::set('auditing.resolvers.url', null);

        $audit = $this->article()->audits()->sole();

        $this->assertNull($audit->url);
        $this->assertNotNull($audit->ip_address);
    }

    public function testAUserResolverThatDoesNotImplementTheContractIsRejected(): void
    {
        Config::set('auditing.user.resolver', stdClass::class);

        $this->expectException(AuditingException::class);
        $this->expectExceptionMessage('Invalid UserResolver implementation');

        $this->article();
    }
}

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
}
