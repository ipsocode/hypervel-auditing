<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Tests\Feature\Drivers;

use Hypervel\Support\Facades\Config;
use Ipsocode\Auditing\Tests\TestCase;
use Workbench\App\Models\Article;

class ThresholdTest extends TestCase
{
    private function articleWithManyAudits(): Article
    {
        $article = Article::factory()->create();

        foreach (['V2', 'V3', 'V4', 'V5'] as $title) {
            $article->update(['title' => $title]);
        }

        return $article;
    }

    public function testAuditsAreNotPrunedWhenThresholdIsZero(): void
    {
        $article = $this->articleWithManyAudits();

        $this->assertSame(5, $article->audits()->count());
    }

    public function testThresholdCapsTheNumberOfRetainedAudits(): void
    {
        Config::set('auditing.threshold', 2);

        $article = $this->articleWithManyAudits();

        $this->assertSame(2, $article->audits()->count());
    }
}
