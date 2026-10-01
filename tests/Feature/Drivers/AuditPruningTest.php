<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Tests\Feature\Drivers;

use Hypervel\Support\Facades\Config;
use Ipsocode\Auditing\Models\AuditDetail;
use Ipsocode\Auditing\Tests\TestCase;
use Workbench\App\Models\Article;
use Workbench\App\Models\ThresholdArticle;

class AuditPruningTest extends TestCase
{
    /**
     * @param class-string<Article> $model
     */
    private function articleWithHistory(string $model = Article::class): Article
    {
        $article = $model::create([
            'title' => 'V1',
            'content' => 'Body',
            'reviewed' => false,
        ]);

        foreach (['V2', 'V3', 'V4', 'V5'] as $title) {
            $article->update(['title' => $title]);
        }

        return $article;
    }

    /**
     * The titles recorded by the audits still on record, oldest first.
     *
     * @return array<int, null|string>
     */
    private function retainedTitles(Article $article): array
    {
        return $article->audits()
            ->orderBy('id')
            ->get()
            ->map(fn ($audit) => $audit->details->firstWhere('field', 'title')?->new_value)
            ->all();
    }

    public function testThresholdRetainsTheMostRecentAudits(): void
    {
        Config::set('auditing.threshold', 2);

        $article = $this->articleWithHistory();

        // Every audit here lands in the same second, so `created_at` alone
        // cannot order them — the survivors must still be the newest two.
        $this->assertSame(['V4', 'V5'], $this->retainedTitles($article));
    }

    public function testPruningAlsoRemovesTheDetailRowsOfDroppedAudits(): void
    {
        Config::set('auditing.threshold', 2);

        $article = $this->articleWithHistory();

        $liveAuditIds = $article->audits()->pluck('id')->all();
        $orphans = AuditDetail::query()->whereNotIn('audit_id', $liveAuditIds)->count();

        $this->assertSame(0, $orphans);
    }

    public function testAModelLevelThresholdIsHonoured(): void
    {
        $article = $this->articleWithHistory(ThresholdArticle::class);

        $this->assertSame(2, $article->getAuditThreshold());
        $this->assertSame(['V4', 'V5'], $this->retainedTitles($article));
    }

    public function testNothingIsPrunedWhenTheThresholdIsZero(): void
    {
        $article = $this->articleWithHistory();

        $this->assertSame(0, $article->getAuditThreshold());
        $this->assertSame(['V1', 'V2', 'V3', 'V4', 'V5'], $this->retainedTitles($article));
    }

    public function testPruneReportsWhetherItDeletedAnything(): void
    {
        $driver = new \Ipsocode\Auditing\Drivers\AuditDetails;

        $article = $this->articleWithHistory();

        $this->assertFalse($driver->prune($article), 'threshold 0 must prune nothing');

        Config::set('auditing.threshold', 2);
        $this->assertTrue($driver->prune($article));
        $this->assertFalse($driver->prune($article), 'a second run has nothing left to drop');
    }

    public function testPruningIsScopedToTheAuditedModel(): void
    {
        Config::set('auditing.threshold', 2);

        $other = Article::factory()->create();
        $article = $this->articleWithHistory();

        $this->assertSame(1, $other->audits()->count());
        $this->assertSame(2, $article->audits()->count());
    }
}
