<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Tests\Feature\Listeners;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Support\Facades\Config;
use Hypervel\Support\Facades\DB;
use Ipsocode\Auditing\Facades\Auditor;
use Ipsocode\Auditing\Tests\TestCase;
use RuntimeException;
use Workbench\App\Models\Article;

use function Hypervel\Coroutine\parallel;

/**
 * With `after_commit` (on by default for `deferred` and `background`), a job
 * dispatched inside a transaction is held and its payload built at commit. The
 * event holds the live model, which may have been saved again by then, and the
 * batch may have closed, so both are captured at dispatch. `sync` with
 * `after_commit` on takes the same path but runs the job at commit, which makes
 * it assertable.
 */
class AfterCommitDispatchTest extends TestCase
{
    protected function defineEnvironment(Application $app): void
    {
        parent::defineEnvironment($app);

        $app->get('config')->set('auditing.queue.enable', true);
        $app->get('config')->set('auditing.queue.connection', 'sync');
        $app->get('config')->set('queue.connections.sync.after_commit', true);
    }

    public function testEachQueuedAuditKeepsTheValuesItWasDispatchedWith(): void
    {
        $article = Article::factory()->create(['title' => 'V1']);

        DB::transaction(function () use ($article): void {
            $article->update(['title' => 'V2']);
            $article->update(['title' => 'V3']);
        });

        $updates = $article->audits()->where('event', 'updated')->orderBy('id')->get();

        $this->assertCount(2, $updates);
        $this->assertSame(['title' => 'V1'], $updates[0]->old_values);
        $this->assertSame(['title' => 'V2'], $updates[0]->new_values);
        $this->assertSame(['title' => 'V2'], $updates[1]->old_values);
        $this->assertSame(['title' => 'V3'], $updates[1]->new_values);
    }

    public function testTheBatchSurvivesACommitAfterTheBatchCloses(): void
    {
        $article = Article::factory()->create(['title' => 'V1']);

        // The batch closes before the transaction commits, so it is gone by the
        // time the payload is built unless the event captured it at dispatch.
        DB::transaction(function () use ($article): void {
            Auditor::withinBatch(fn () => $article->update(['title' => 'V2']), 'closed-batch');

            $this->assertNull(Auditor::currentBatch());
        });

        $this->assertSame(
            'closed-batch',
            $article->audits()->where('event', 'updated')->sole()->batch_uuid
        );
    }

    public function testTheDeferredConnectionWritesTheAuditAsTheCoroutineEnds(): void
    {
        $article = Article::factory()->create(['title' => 'V1']);

        Config::set('auditing.queue.connection', 'deferred');

        $duringTheCoroutine = null;

        // A child coroutine stands in for a request: `deferred` runs the job
        // through Coroutine::defer(), when the coroutine that dispatched it ends.
        parallel([
            function () use ($article, &$duringTheCoroutine): void {
                $article->update(['title' => 'V2']);

                // Yield before looking, so anything scheduled to run elsewhere
                // — a timer, another coroutine — has had its chance. Only a job
                // held for this coroutine's end is still unwritten here.
                Coroutine::sleep(0.05);

                $duringTheCoroutine = $article->audits()->where('event', 'updated')->count();
            },
        ]);

        $this->assertSame(0, $duringTheCoroutine);

        $audit = $article->audits()->where('event', 'updated')->sole();

        $this->assertSame(['title' => 'V1'], $audit->old_values);
        $this->assertSame(['title' => 'V2'], $audit->new_values);
    }

    public function testARolledBackTransactionWritesNoQueuedAudit(): void
    {
        $article = Article::factory()->create(['title' => 'V1']);

        try {
            DB::transaction(function () use ($article): void {
                $article->update(['title' => 'V2']);

                throw new RuntimeException('roll back');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame(0, $article->audits()->where('event', 'updated')->count());
    }
}
