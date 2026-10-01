<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Tests\Feature\AuditableObserver;

use Hypervel\Context\CoroutineContext;
use Ipsocode\Auditing\Support\ContextKeys;
use Ipsocode\Auditing\Tests\TestCase;
use RuntimeException;
use WeakMap;
use Workbench\App\Models\Article;

/**
 * A restore fires `updated` as well as `restored`, so the observer suppresses
 * `updated` while a restore is in flight. The marker must clear on every exit
 * path and must not outlive its model.
 */
class RestoreTrackingTest extends TestCase
{
    /**
     * The observer's in-flight restore markers for this coroutine.
     *
     * @return WeakMap<object, true>
     */
    private function restoreMarkers(): WeakMap
    {
        return CoroutineContext::get(ContextKeys::RESTORING, new WeakMap);
    }

    private function trashedArticle(string $title = 'V1'): Article
    {
        $article = Article::factory()->create(['title' => $title]);

        $article->delete();

        return $article;
    }

    /**
     * Veto every Article restore. Call it after Article has booted, so the
     * observer's `restoring` handler sets the marker before the veto halts dispatch.
     */
    private function vetoRestores(): void
    {
        Article::restoring(fn () => false);
    }

    public function testARestoreRecordsExactlyOneAudit(): void
    {
        $article = $this->trashedArticle();
        $article->restore();

        $this->assertSame(1, $article->audits()->where('event', 'restored')->count());
        $this->assertSame(0, $article->audits()->where('event', 'updated')->count());
    }

    public function testAnUpdateAfterARestoreIsStillAudited(): void
    {
        $article = $this->trashedArticle();
        $article->restore();

        $article->update(['title' => 'V2']);

        $this->assertSame(1, $article->audits()->where('event', 'updated')->count());
    }

    public function testAVetoedRestoreDoesNotSuppressALaterUpdate(): void
    {
        // A `restoring` listener returning false aborts the restore, so
        // `restored` never fires to clear the marker. Nothing after that point
        // may inherit it.
        $article = $this->trashedArticle();

        $this->vetoRestores();

        $this->assertFalse($article->restore());

        $other = Article::factory()->create();
        $other->update(['title' => 'Other v2']);

        $this->assertSame(1, $other->audits()->where('event', 'updated')->count());
    }

    public function testTheMarkerForAVetoedRestoreIsReleasedWithItsModel(): void
    {
        // A vetoed restore never reaches `restored`, so only collecting the model
        // drops its marker; one keyed by object id would pass to the next model.
        // The primer boots Article before the veto.
        $this->trashedArticle('Primer');
        $this->vetoRestores();

        $before = count($this->restoreMarkers());
        $whileVetoed = null;

        (function () use (&$whileVetoed): void {
            $article = $this->trashedArticle('Abandoned');

            $this->assertFalse($article->restore());

            $whileVetoed = count($this->restoreMarkers());
        })();

        gc_collect_cycles();

        $this->assertSame($before + 1, $whileVetoed, 'the in-flight restore should have been marked');
        $this->assertCount($before, $this->restoreMarkers(), 'a marker must not outlive its model');
    }

    public function testTheMarkerIsClearedWhenTheRestoredAuditThrows(): void
    {
        $article = $this->trashedArticle();

        \Hypervel\Support\Facades\Event::listen(
            \Ipsocode\Auditing\Events\Audited::class,
            function (\Ipsocode\Auditing\Events\Audited $event) {
                if ($event->model->getAuditEvent() === 'restored') {
                    throw new RuntimeException('listener blew up');
                }
            }
        );

        try {
            $article->restore();
        } catch (RuntimeException) {
        }

        \Hypervel\Support\Facades\Event::forget(\Ipsocode\Auditing\Events\Audited::class);

        $article->update(['title' => 'V2']);

        $this->assertSame(1, $article->audits()->where('event', 'updated')->count());
    }
}
