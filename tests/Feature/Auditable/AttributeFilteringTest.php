<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Tests\Feature\Auditable;

use Hypervel\Support\Facades\Config;
use Ipsocode\Auditing\Tests\TestCase;
use Workbench\App\Models\Article;
use Workbench\App\Models\HiddenArticle;
use Workbench\App\Models\IncludeArticle;

class AttributeFilteringTest extends TestCase
{
    /**
     * Field names recorded on the model's `created` audit.
     *
     * @return array<int, string>
     */
    private function auditedFields(Article $article): array
    {
        return $article->audits()
            ->where('event', 'created')
            ->sole()
            ->details
            ->pluck('field')
            ->all();
    }

    public function testGlobalExcludeConfigDropsAttributes(): void
    {
        Config::set('auditing.exclude', ['content']);

        $article = Article::factory()->create();

        $fields = $this->auditedFields($article);

        $this->assertContains('title', $fields);
        $this->assertNotContains('content', $fields);
    }

    public function testAuditIncludeWhitelistsAttributes(): void
    {
        $article = IncludeArticle::create([
            'title' => 'Title',
            'content' => 'Body',
            'reviewed' => false,
        ]);

        $fields = $this->auditedFields($article);

        $this->assertSame(['title'], $fields);
    }

    public function testStrictModeExcludesHiddenAttributes(): void
    {
        Config::set('auditing.strict', true);

        $article = HiddenArticle::create([
            'title' => 'Title',
            'content' => 'Hidden body',
            'reviewed' => false,
        ]);

        $fields = $this->auditedFields($article);

        $this->assertContains('title', $fields);
        $this->assertNotContains('content', $fields);
    }

    public function testHiddenAttributesAreKeptWhenStrictModeIsOff(): void
    {
        Config::set('auditing.strict', false);

        $article = HiddenArticle::create([
            'title' => 'Title',
            'content' => 'Hidden body',
            'reviewed' => false,
        ]);

        $this->assertContains('content', $this->auditedFields($article));
    }

    public function testTimestampsAreExcludedByDefault(): void
    {
        $article = Article::factory()->create();

        $fields = $this->auditedFields($article);

        $this->assertNotContains('created_at', $fields);
        $this->assertNotContains('updated_at', $fields);
    }

    public function testTimestampsAreAuditedWhenEnabled(): void
    {
        Config::set('auditing.timestamps', true);

        $article = Article::factory()->create();

        $this->assertContains('created_at', $this->auditedFields($article));
    }
}
