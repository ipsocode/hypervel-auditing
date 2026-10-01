<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Tests\Feature\Auditable;

use Hypervel\Support\Facades\Config;
use Hypervel\Support\Facades\Request;
use Ipsocode\Auditing\Resolvers\UrlResolver;
use Ipsocode\Auditing\Tests\TestCase;
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
