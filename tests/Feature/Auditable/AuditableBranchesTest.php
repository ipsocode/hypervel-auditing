<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Tests\Feature\Auditable;

use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Support\Facades\Config;
use Hypervel\Support\Facades\Facade;
use Hypervel\Support\Facades\Request;
use Ipsocode\Auditing\Exceptions\AuditingException;
use Ipsocode\Auditing\Resolvers\UrlResolver;
use Ipsocode\Auditing\Tests\TestCase;
use Mockery as m;
use RuntimeException;
use Workbench\App\Models\Article;
use Workbench\App\Models\VisibleArticle;

class AuditableBranchesTest extends TestCase
{
    public function testStrictModeExcludesEveryAttributeOutsideTheVisibleList(): void
    {
        Config::set('auditing.strict', true);

        $article = VisibleArticle::create([
            'title' => 'Title',
            'content' => 'Body',
            'reviewed' => false,
        ]);

        $fields = $article->audits()
            ->where('event', 'created')
            ->sole()
            ->details
            ->pluck('field')
            ->all();

        $this->assertSame(['title'], $fields);
    }

    public function testStrictModeKeepsNonVisibleAttributesWhenTheModelDeclaresNoVisibleList(): void
    {
        Config::set('auditing.strict', true);

        $article = Article::factory()->create([
            'title' => 'Title',
            'content' => 'Body',
        ]);

        $fields = $article->audits()
            ->where('event', 'created')
            ->sole()
            ->details
            ->pluck('field')
            ->all();

        $this->assertContains('content', $fields);
    }

    public function testAuditingIsEnabledOffConsoleRegardlessOfTheConsoleSwitch(): void
    {
        Config::set('auditing.enabled', true);
        Config::set('auditing.console', false);

        try {
            $this->app->setRunningInConsole(false);

            $this->assertTrue(Article::isAuditingEnabled());
        } finally {
            // The flag lives on the singleton Application, so leaking it would
            // silently disable the console gate for every later test.
            $this->app->setRunningInConsole(true);
        }
    }

    public function testAuditingIsDisabledOffConsoleWhenTheMasterSwitchIsOff(): void
    {
        Config::set('auditing.enabled', false);
        Config::set('auditing.console', false);

        try {
            $this->app->setRunningInConsole(false);

            $this->assertFalse(Article::isAuditingEnabled());
        } finally {
            $this->app->setRunningInConsole(true);
        }
    }

    public function testNumericStringMasterSwitchIsReadAsABoolOffConsole(): void
    {
        try {
            $this->app->setRunningInConsole(false);

            Config::set('auditing.enabled', '1');
            $this->assertTrue(Article::isAuditingEnabled());

            Config::set('auditing.enabled', '0');
            $this->assertFalse(Article::isAuditingEnabled());
        } finally {
            $this->app->setRunningInConsole(true);
        }
    }

    public function testObserverIsRegisteredOffConsoleWhenTheMasterSwitchIsANumericString(): void
    {
        Config::set('auditing.enabled', '1');

        try {
            $this->app->setRunningInConsole(false);

            $registers = (fn (): bool => static::shouldRegisterAuditObserver())
                ->bindTo(null, Article::class);

            $this->assertTrue($registers());
        } finally {
            $this->app->setRunningInConsole(true);
        }
    }

    public function testAContainerWithoutConfigDeclinesToRegisterTheObserver(): void
    {
        $application = Facade::getFacadeApplication();

        $container = m::mock(ContainerContract::class);
        $container->shouldReceive('bound')->with('app')->andReturn(true);
        $container->shouldReceive('bound')->with('config')->andReturn(false);

        $registers = (fn (): bool => static::shouldRegisterAuditObserver())
            ->bindTo(null, Article::class);

        try {
            Facade::setFacadeApplication($container);

            $this->assertFalse($registers());
        } finally {
            Facade::setFacadeApplication($application);
        }
    }

    public function testAConfigFailureWhileBootingPropagatesInsteadOfDisablingAuditing(): void
    {
        $original = $this->app->get('config');
        $failure = new RuntimeException('config unavailable');
        $config = m::mock($original)->makePartial();
        $config->shouldReceive('get')->with('auditing.enabled')->once()->andThrow($failure);
        Config::swap($config);

        $registers = (fn (): bool => static::shouldRegisterAuditObserver())
            ->bindTo(null, Article::class);

        try {
            $registers();
            $this->fail('The config failure should have propagated.');
        } catch (RuntimeException $e) {
            $this->assertSame($failure, $e);
        } finally {
            Config::swap($original);
        }
    }

    public function testAnAuditWithNoEventNameFailsInsteadOfResolvingNoGetter(): void
    {
        $article = new Article;
        $article->isCustomEvent = true;
        $article->auditEvent = null;

        $this->expectException(AuditingException::class);
        $this->expectExceptionMessage('Unable to handle "" event');

        $article->toAudit();
    }

    public function testAuditSettingsAcceptNumericStringConfigValues(): void
    {
        Config::set('auditing.strict', '1');
        Config::set('auditing.timestamps', '1');
        Config::set('auditing.threshold', '5');

        $article = new Article;

        $this->assertTrue($article->getAuditStrict());
        $this->assertTrue($article->getAuditTimestamps());
        $this->assertSame(5, $article->getAuditThreshold());
    }

    public function testUrlResolverFallsBackToTheRequestUrlOffConsole(): void
    {
        $article = Article::factory()->make();

        try {
            $this->app->setRunningInConsole(false);

            $url = UrlResolver::resolve($article);

            $this->assertSame(Request::fullUrl(), $url);
            $this->assertNotSame('console', $url);
            $this->assertNotSame(implode(' ', $_SERVER['argv']), $url);
        } finally {
            $this->app->setRunningInConsole(true);
        }
    }
}
