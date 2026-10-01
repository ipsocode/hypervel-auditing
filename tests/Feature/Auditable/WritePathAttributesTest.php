<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Tests\Feature\Auditable;

use Hypervel\Support\Facades\DB;
use Ipsocode\Auditing\Tests\TestCase;
use Workbench\App\Models\Article;
use Workbench\App\Models\RefreshingArticle;

/**
 * `created` and `updated` audits record what Eloquent's write paths leave on
 * the model when the event fires: columns re-read from the database after the
 * write, and the extra columns an increment stores beside its own.
 */
class WritePathAttributesTest extends TestCase
{
    public function testACreatedAuditRecordsTheDatabaseDefaultOfARefreshedColumn(): void
    {
        $plain = Article::create(['title' => 'Title', 'content' => 'Body']);
        $refreshing = RefreshingArticle::create(['title' => 'Title', 'content' => 'Body']);

        $this->assertArrayNotHasKey('reviewed', $plain->audits()->sole()->getModified());
        $this->assertSame(['new' => false], $refreshing->audits()->sole()->getModified()['reviewed']);
    }

    public function testAnUpdatedAuditRecordsATriggersChangeToARefreshedColumn(): void
    {
        DB::unprepared(
            'CREATE TRIGGER articles_review_on_retitle AFTER UPDATE OF title ON articles
             BEGIN UPDATE articles SET reviewed = 1 WHERE id = NEW.id; END'
        );

        $article = RefreshingArticle::create(['title' => 'First', 'content' => 'Body', 'reviewed' => false]);
        $article->update(['title' => 'Second']);

        $modified = $article->audits()->where('event', 'updated')->sole()->getModified();

        $this->assertEqualsCanonicalizing(['title', 'reviewed'], array_keys($modified));
        $this->assertSame(['new' => 'Second', 'old' => 'First'], $modified['title']);
        $this->assertSame(['new' => true, 'old' => false], $modified['reviewed']);
    }

    public function testIncrementWithExtraColumnsIsAuditedOnce(): void
    {
        $article = Article::create(['title' => 'First', 'content' => 'Body', 'reviewed' => false]);

        $article->increment('reviewed', 1, ['title' => 'Second']);
        $article->save();

        $this->assertIncrementAuditedOnce($article);
    }

    public function testIncrementEachWithExtraColumnsIsAuditedOnce(): void
    {
        $article = Article::create(['title' => 'First', 'content' => 'Body', 'reviewed' => false]);

        $article->incrementEach(['reviewed' => 1], ['title' => 'Second']);
        $article->save();

        $this->assertIncrementAuditedOnce($article);
    }

    private function assertIncrementAuditedOnce(Article $article): void
    {
        $this->assertFalse($article->isDirty());

        $modified = $article->audits()->where('event', 'updated')->sole()->getModified();

        $this->assertSame(['new' => 'Second', 'old' => 'First'], $modified['title']);
        $this->assertSame(['new' => true, 'old' => false], $modified['reviewed']);
    }
}
