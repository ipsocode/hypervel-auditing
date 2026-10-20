<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Tests\Feature\Auditable;

use Hypervel\Database\Eloquent\Relations\MorphMany;
use Hypervel\Support\Carbon;
use Hypervel\Support\Facades\DB;
use Ipsocode\Auditing\Tests\TestCase;
use Workbench\App\Models\Article;

class AuditHistoryTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function testItReturnsOnlyThisRecordsAuditsThatRecordedTheField(): void
    {
        $article = Article::factory()->create(['title' => 'V1', 'content' => 'Body']);
        $other = Article::factory()->create(['title' => 'Other']);
        $article->update(['title' => 'V2']);
        $article->update(['content' => 'New body']);
        $other->update(['title' => 'Other V2']);

        $history = $article->auditHistory('title');

        $this->assertInstanceOf(MorphMany::class, $history);
        $this->assertSame(['created', 'updated'], $history->get()->pluck('event')->all());
        $this->assertSame(['title' => 'V2'], $history->get()->last()->new_values);
    }

    public function testItIsOrderedOldestFirstWithSameSecondAuditsInInsertionOrder(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-01 12:00:00'));

        $article = Article::factory()->create(['title' => 'V1']);
        $article->update(['title' => 'V2']);
        $article->update(['title' => 'V3']);

        $this->assertSame(
            ['V1', 'V2', 'V3'],
            $article->auditHistory('title')->get()->map(fn ($audit) => $audit->new_values['title'])->all(),
        );
    }

    public function testDetailsAreEagerLoaded(): void
    {
        $article = Article::factory()->create(['title' => 'V1']);
        $article->update(['title' => 'V2']);

        $history = $article->auditHistory('title')->get();

        DB::enableQueryLog();

        foreach ($history as $audit) {
            $audit->details->count();
        }

        $this->assertCount(0, DB::getQueryLog());
    }
}
