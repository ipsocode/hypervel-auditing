<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Tests\Feature\Auditor;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Support\Facades\Concurrency;
use Hypervel\Support\Facades\Config;
use Hypervel\Support\Facades\Event;
use Ipsocode\Auditing\Events\DispatchAudit;
use Ipsocode\Auditing\Facades\Auditor;
use Ipsocode\Auditing\Listeners\ProcessDispatchAudit;
use Ipsocode\Auditing\Support\AuditBatch;
use Ipsocode\Auditing\Testing\TestState;
use Ipsocode\Auditing\Tests\TestCase;
use Workbench\App\Models\Article;
use Workbench\App\Models\AuditableUser;
use Workbench\App\Models\Category;

use function Hypervel\Coroutine\parallel;

/**
 * A request that touches six models writes six unrelated `audits` rows.
 * `Auditor::withinBatch()` is what ties them back together: every audit written
 * inside the callback carries the same `batch_uuid`, and an audit written
 * outside one carries null.
 */
class AuditBatchingTest extends TestCase
{
    public function testAuditsWrittenInABatchShareOneId(): void
    {
        $article = null;
        $user = null;

        // Two unrelated models, three audits: the point of a batch is that it
        // spans the cascade, not one model's history.
        $batch = Auditor::withinBatch(function () use (&$article, &$user) {
            $article = Article::factory()->create(['title' => 'V1']);
            $article->update(['title' => 'V2']);
            $user = AuditableUser::factory()->create();

            return Auditor::currentBatch();
        });

        $stamped = $article->audits()->pluck('batch_uuid')->all();

        $this->assertSame([$batch, $batch], $stamped);
        $this->assertSame($batch, $user->audits()->sole()->batch_uuid);
    }

    public function testAuditsWrittenOutsideABatchAreNotStamped(): void
    {
        $article = Article::factory()->create();

        $this->assertNull($article->audits()->sole()->batch_uuid);
        $this->assertNull(Auditor::currentBatch());
    }

    public function testTheBatchClosesAfterTheCallback(): void
    {
        Auditor::withinBatch(fn () => null);

        $article = Article::factory()->create();

        $this->assertNull($article->audits()->sole()->batch_uuid);
    }

    public function testAGeneratedBatchIdIsTimeOrdered(): void
    {
        $batch = Auditor::withinBatch(fn () => Auditor::currentBatch());

        // A v7 UUID carries its version in the thirteenth hex digit.
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $batch);
    }

    public function testAnExplicitBatchIdIsStampedAsGiven(): void
    {
        $article = Auditor::withinBatch(
            fn () => Article::factory()->create(),
            'workflow-77',
        );

        $this->assertSame('workflow-77', $article->audits()->sole()->batch_uuid);
    }

    public function testWithinBatchReturnsTheCallbackResult(): void
    {
        $this->assertSame('result', Auditor::withinBatch(fn () => 'result'));
    }

    public function testTheBatchIdReachesTheMetadata(): void
    {
        $article = Auditor::withinBatch(fn () => Article::factory()->create(), 'metadata-batch');

        $this->assertSame(
            'metadata-batch',
            $article->audits()->sole()->getMetadata()['audit_batch_uuid']
        );
    }

    public function testAChildCoroutineJoinsTheBatchOnlyWhenItCopiesContext(): void
    {
        $fresh = $copied = $concurrent = null;

        Auditor::withinBatch(function () use (&$fresh, &$copied, &$concurrent) {
            parallel([function () use (&$fresh) {
                $fresh = Article::factory()->create();
            }]);

            parallel([function () use (&$copied) {
                $copied = Article::factory()->create();
            }], copyContext: true);

            [$concurrent] = Concurrency::run([fn () => Article::factory()->create()]);
        }, 'parent-batch');

        $this->assertNull($fresh->audits()->sole()->batch_uuid);
        $this->assertSame('parent-batch', $copied->audits()->sole()->batch_uuid);
        $this->assertSame('parent-batch', $concurrent->audits()->sole()->batch_uuid);
    }

    public function testFlushingTestStateClosesAnAbandonedBatch(): void
    {
        // A batch a test leaves open would stamp every audit written by every
        // later test in the worker.
        AuditBatch::within(function () {
            TestState::flushState();

            $this->assertNull(Auditor::currentBatch());
        });
    }

    public function testTheBatchSurvivesTheQueuedPath(): void
    {
        Config::set('auditing.queue.enable', true);

        $serialized = null;

        Event::listen(DispatchAudit::class, function (DispatchAudit $event) use (&$serialized): void {
            $serialized = serialize($event);
        });

        $article = Auditor::withinBatch(
            fn () => Article::factory()->create(['title' => 'V1']),
            'queued-batch',
        );

        // The batch is closed now — which is all a worker coroutine ever sees,
        // since it never entered the scope that opened it.
        $this->assertNull(Auditor::currentBatch());

        (new ProcessDispatchAudit)->handle(unserialize($serialized));

        // Two audits: the one the sync queue wrote inside the batch, and the one
        // replayed from the serialized event after it closed. Only the payload
        // that crossed serialization can explain the second.
        $this->assertSame(
            ['queued-batch', 'queued-batch'],
            $article->audits()->pluck('batch_uuid')->all()
        );
    }

    public function testARestoredEventKeepsItsBatchWhenSerializedAgain(): void
    {
        $article = Article::factory()->create(['title' => 'V1']);
        $article->title = 'V2';
        $article->setAuditEvent('updated')->preloadResolverData();

        $restored = Auditor::withinBatch(
            fn () => unserialize(serialize(new DispatchAudit($article))),
            'round-trip-batch',
        );

        // No batch is open now; the restored model carries the one it was
        // dispatched under, and a second trip must not lose it.
        $again = unserialize(serialize($restored));

        $this->assertSame('round-trip-batch', $again->model->getAuditBatchUuid());
        $this->assertSame(['title' => 'V2'], $again->model->getDirty());
    }

    public function testThePinnedBatchDoesNotOutliveTheDispatch(): void
    {
        Config::set('auditing.queue.enable', true);

        $article = Auditor::withinBatch(
            fn () => Article::factory()->create(),
            'queued-batch',
        );

        // The batch travels in the event's payload. Left on the model, it would
        // stamp a later pivot or manual audit written outside any batch.
        $this->assertNull($article->auditBatchUuid);
        $this->assertNull($article->getAuditBatchUuid());
    }

    public function testACustomAuditIsStampedWithTheOpenBatch(): void
    {
        $article = Article::factory()->create();
        $category = Category::factory()->create();

        Auditor::withinBatch(
            fn () => $article->auditAttach('categories', $category),
            'pivot-batch',
        );

        $this->assertSame(
            'pivot-batch',
            $article->audits()->where('event', 'attach')->sole()->batch_uuid
        );
    }

    public function testAPivotAuditIsNotFiledUnderAClosedBatch(): void
    {
        // Saving inside a batch must not leave anything on the model that a
        // later audit picks up: this attach belongs to no batch.
        $article = Auditor::withinBatch(fn () => Article::factory()->create(), 'save-batch');
        $category = Category::factory()->create();

        $article->auditAttach('categories', $category);

        $this->assertNull($article->audits()->where('event', 'attach')->sole()->batch_uuid);
    }

    protected function defineEnvironment(Application $app): void
    {
        parent::defineEnvironment($app);

        // The pivot helper writes a custom event with no old/new values on the
        // article itself; keep the empty-value guard from swallowing it.
        $app->get('config')->set('auditing.empty_values', true);
    }
}
