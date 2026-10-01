<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Tests\Feature\Audit;

use Ipsocode\Auditing\Tests\TestCase;
use Workbench\App\Casts\Wrapper;
use Workbench\App\Models\CachedAccessorArticle;
use Workbench\App\Models\CastArticle;

/**
 * Reading an audit runs historical values through the auditable's casts and
 * accessors. Eloquent memoizes object-returning class casts and cached accessors
 * on the model they are called against, and `save()` merges those caches back
 * into the attributes — so formatting through the live model would both read
 * its cached value and stage an old one for persistence.
 */
class AuditReadSideEffectsTest extends TestCase
{
    private function updatedArticle(): CastArticle
    {
        $article = CastArticle::create([
            'title' => 'Title',
            'content' => 'v1',
            'reviewed' => false,
        ]);

        $article->update(['content' => 'v2']);

        return $article;
    }

    public function testGetModifiedCastsBothSidesThroughTheAuditable(): void
    {
        $audit = $this->updatedArticle()->audits()->where('event', 'updated')->sole();

        $modified = $audit->getModified();

        $this->assertInstanceOf(Wrapper::class, $modified['content']['new']);
        $this->assertSame('v2', $modified['content']['new']->inner);
        $this->assertSame('v1', $modified['content']['old']->inner);
    }

    public function testReadingAnAuditDoesNotRewriteTheAuditableInMemory(): void
    {
        $audit = $this->updatedArticle()->audits()->where('event', 'updated')->sole();

        $auditable = $audit->auditable;
        $this->assertSame('v2', $auditable->content->inner);

        $audit->getModified();

        // Still the model's own current value, not the audited historical one.
        $this->assertSame('v2', $auditable->content->inner);
        $this->assertSame('v2', $auditable->getRawOriginal('content'));
    }

    public function testSavingTheAuditableAfterReadingAnAuditDoesNotRevertIt(): void
    {
        $audit = $this->updatedArticle()->audits()->where('event', 'updated')->sole();

        $auditable = $audit->auditable;

        $audit->getModified();

        $auditable->title = 'Renamed';
        $auditable->save();

        $this->assertDatabaseHas('articles', [
            'id' => $auditable->getKey(),
            'title' => 'Renamed',
            'content' => 'v2',
        ]);
    }

    public function testACachedAccessorFormatsTheStoredValueNotTheCachedOne(): void
    {
        $article = CachedAccessorArticle::create(['title' => 'Title', 'content' => 'v1', 'reviewed' => false]);
        $article->update(['content' => 'v2']);

        $audit = $article->audits()->where('event', 'updated')->sole();
        $audit->auditable->content;

        $this->assertSame(['new' => 'V2', 'old' => 'V1'], $audit->getModified()['content']);
    }

    public function testReadingAnOlderAuditDoesNotStageItsValueThroughACachedAccessor(): void
    {
        $article = CachedAccessorArticle::create(['title' => 'Title', 'content' => 'v1', 'reviewed' => false]);
        $article->update(['content' => 'v2']);
        $article->update(['content' => 'v3']);

        $audit = $article->audits()->where('event', 'updated')->orderBy('id')->first();
        $auditable = $audit->auditable;

        $this->assertSame(['new' => 'V2', 'old' => 'V1'], $audit->getModified()['content']);
        $this->assertSame('V3', $auditable->content);

        $auditable->title = 'Renamed';
        $auditable->save();

        $this->assertDatabaseHas('articles', ['id' => $auditable->getKey(), 'title' => 'Renamed', 'content' => 'v3']);
    }

    public function testGetMetadataDoesNotDisturbTheAuditableEither(): void
    {
        $audit = $this->updatedArticle()->audits()->where('event', 'updated')->sole();

        $auditable = $audit->auditable;
        $auditable->content;

        $audit->getMetadata();

        $this->assertSame('v2', $auditable->content->inner);
    }
}
