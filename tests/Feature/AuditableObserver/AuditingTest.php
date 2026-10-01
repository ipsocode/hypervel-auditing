<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Tests\Feature\AuditableObserver;

use Ipsocode\Auditing\Tests\TestCase;
use Workbench\App\Models\Article;

class AuditingTest extends TestCase
{
    public function testCreatingAModelRecordsAnAuditWithPerFieldDetails(): void
    {
        $article = Article::factory()->create(['title' => 'First post']);

        $audit = $article->audits()->where('event', 'created')->sole();

        $this->assertSame(Article::class, $audit->auditable_type);
        $this->assertEquals($article->getKey(), $audit->auditable_id);

        $titleDetail = $audit->details()->where('field', 'title')->sole();

        $this->assertNull($titleDetail->old_value);
        $this->assertSame('First post', $titleDetail->new_value);
    }

    public function testUpdatingAModelRecordsOldAndNewValuesPerField(): void
    {
        $article = Article::factory()->create(['title' => 'Original title']);

        $article->update(['title' => 'Updated title']);

        $audit = $article->audits()->where('event', 'updated')->sole();
        $detail = $audit->details()->where('field', 'title')->sole();

        $this->assertSame('Original title', $detail->old_value);
        $this->assertSame('Updated title', $detail->new_value);

        $this->assertSame(
            ['title' => ['new' => 'Updated title', 'old' => 'Original title']],
            $audit->getModified()
        );
    }

    public function testDeletingAModelRecordsAnAudit(): void
    {
        $article = Article::factory()->create();

        $article->delete();

        $this->assertSame(1, $article->audits()->where('event', 'deleted')->count());
    }

    public function testRestoringAModelRecordsExactlyOneAudit(): void
    {
        $article = Article::factory()->create();

        $article->delete();
        $article->restore();

        $this->assertSame(1, $article->audits()->where('event', 'restored')->count());
        $this->assertSame(0, $article->audits()->where('event', 'updated')->count());
    }
}
