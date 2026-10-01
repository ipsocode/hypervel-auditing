<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Tests\Feature\AuditableObserver;

use Hypervel\Context\CoroutineContext;
use Hypervel\Engine\Channel;
use Hypervel\Support\Facades\Event;
use Ipsocode\Auditing\Support\ContextKeys;
use Ipsocode\Auditing\Tests\TestCase;
use WeakMap;
use Workbench\App\Models\Article;

use function Hypervel\Coroutine\parallel;

class CoroutineRestoreTest extends TestCase
{
    /**
     * The observer keeps in-flight restores in a per-coroutine WeakMap, so
     * concurrent restores of distinct models must not interfere. The overlap is
     * forced: the first restore is held after `restoring`, already marked, while
     * the second runs start to finish; a shared marker would be cleared by the
     * second and let the first model's `updated` audit through. The hold also
     * serialises the two audit writes: every coroutine here shares one in-memory
     * SQLite connection, where interleaved transactions corrupt each other's
     * savepoints.
     */
    public function testConcurrentRestoresDoNotLeakStateAcrossCoroutines(): void
    {
        $first = Article::factory()->create();
        $second = Article::factory()->create();

        $first->delete();
        $second->delete();

        $firstId = $first->getKey();
        $secondId = $second->getKey();

        $secondFinished = new Channel(1);
        $trackedWhenHeld = $secondFinishedWhileHeld = false;

        // Registered after the observer, so the first model is already marked
        // as restoring when this holds it. The listener only records what it
        // saw: an assertion in here would pass silently if it never ran, and a
        // failure might not make it out of the child coroutine.
        Event::listen('eloquent.restoring: ' . Article::class, function (Article $article) use ($firstId, $secondFinished, &$trackedWhenHeld, &$secondFinishedWhileHeld) {
            if ($article->getKey() === $firstId) {
                $restoring = CoroutineContext::get(ContextKeys::RESTORING);
                $trackedWhenHeld = $restoring instanceof WeakMap && isset($restoring[$article]);
                $secondFinishedWhileHeld = $secondFinished->pop(30);
            }
        });

        parallel([
            function () use ($firstId) {
                Article::withTrashed()->findOrFail($firstId)->restore();
            },
            function () use ($secondId, $secondFinished) {
                try {
                    Article::withTrashed()->findOrFail($secondId)->restore();
                } finally {
                    // Release the first restore even when this one throws, so
                    // only a restore that never returns waits out the timeout.
                    $secondFinished->push(true);
                }
            },
        ]);

        $this->assertTrue($trackedWhenHeld, 'The first restore was not held, or was held before the observer marked it as restoring.');
        $this->assertTrue($secondFinishedWhileHeld, 'The second restore did not finish while the first was held.');

        foreach ([$firstId, $secondId] as $id) {
            $article = Article::findOrFail($id);

            $this->assertSame(1, $article->audits()->where('event', 'restored')->count());
            $this->assertSame(0, $article->audits()->where('event', 'updated')->count());
        }
    }
}
