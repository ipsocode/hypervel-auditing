<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Tests\Feature\Listeners;

use Hypervel\Support\Facades\Config;
use Hypervel\Support\Facades\Event;
use Ipsocode\Auditing\Events\DispatchAudit;
use Ipsocode\Auditing\Events\DispatchingAudit;
use Ipsocode\Auditing\Listeners\ProcessDispatchAudit;
use Ipsocode\Auditing\Tests\TestCase;
use Workbench\App\Models\Article;
use Workbench\App\Models\User;

use function Hypervel\Coroutine\parallel;

/**
 * With `auditing.queue.enable` the observer emits DispatchAudit carrying a
 * stripped copy of the model, and ProcessDispatchAudit writes the audit from the
 * queue. Only the properties DispatchAudit captures cross the queue; anything
 * else is silently absent when the audit is written.
 */
class QueuedAuditTest extends TestCase
{
    protected function defineEnvironment(\Hypervel\Contracts\Foundation\Application $app): void
    {
        parent::defineEnvironment($app);

        $app->get('config')->set('auditing.queue.enable', true);
    }

    private function article(): Article
    {
        return Article::factory()->create(['title' => 'V1']);
    }

    public function testAuditsAreStillRecordedThroughTheQueuedPath(): void
    {
        $article = $this->article();
        $article->update(['title' => 'V2']);

        $audit = $article->audits()->where('event', 'updated')->sole();

        $this->assertSame(['title' => 'V1'], $audit->old_values);
        $this->assertSame(['title' => 'V2'], $audit->new_values);
    }

    public function testTheQueuedPathEmitsADispatchAuditEvent(): void
    {
        $dispatched = [];

        Event::listen(DispatchAudit::class, function (DispatchAudit $event) use (&$dispatched) {
            $dispatched[] = $event->model->getAuditEvent();
        });

        $this->article();

        $this->assertSame(['created'], $dispatched);
    }

    public function testReturningFalseFromDispatchingAuditHaltsTheAudit(): void
    {
        Event::listen(DispatchingAudit::class, fn () => false);

        $article = $this->article();

        $this->assertSame(0, $article->audits()->count());
    }

    public function testTheDispatchedModelCarriesNoLoadedRelations(): void
    {
        $captured = null;

        Event::listen(DispatchAudit::class, function (DispatchAudit $event) use (&$captured) {
            $captured = $event->model;
        });

        $article = $this->article();
        $article->load('categories');
        $article->update(['title' => 'V2']);

        $this->assertNotNull($captured);
        $this->assertSame([], $captured->getRelations());
    }

    public function testSerializingAndRestoringTheEventPreservesTheAuditPayload(): void
    {
        Config::set('auditing.queue.enable', false);

        $article = $this->article();

        $article->title = 'V2';
        $article->setAuditEvent('updated')->preloadResolverData();

        $restored = unserialize(serialize(new DispatchAudit($article)));

        $this->assertInstanceOf(Article::class, $restored->model);
        $this->assertSame('updated', $restored->model->getAuditEvent());

        // `original` has to survive the trip or every queued update loses its
        // old values without anything failing.
        $payload = $restored->model->toAudit();
        $this->assertSame(['title' => 'V1'], $payload['old_values']);
        $this->assertSame(['title' => 'V2'], $payload['new_values']);
    }

    public function testProcessDispatchAuditWritesTheAuditFromARestoredEvent(): void
    {
        Config::set('auditing.queue.enable', false);

        $article = $this->article();
        $article->title = 'V2';
        $article->setAuditEvent('updated')->preloadResolverData();

        $restored = unserialize(serialize(new DispatchAudit($article)));

        (new ProcessDispatchAudit)->handle($restored);

        $audit = $article->audits()->where('event', 'updated')->sole();

        $this->assertSame(['title' => 'V1'], $audit->old_values);
        $this->assertSame(['title' => 'V2'], $audit->new_values);
    }

    public function testTheCauserResolvedOnTheRequestReachesTheWorker(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $serialized = null;

        Event::listen(DispatchAudit::class, function (DispatchAudit $event) use (&$serialized): void {
            $serialized = serialize($event);
        });

        $article = $this->article();

        // A child coroutine starts with a fresh context — no request and no
        // authenticated user — which is what a queue worker sees. Only the
        // user resolved on the request and carried in the payload can explain
        // a causer on this audit.
        parallel([fn () => (new ProcessDispatchAudit)->handle(unserialize($serialized))]);

        $this->assertSame(
            [$user->getKey(), $user->getKey()],
            $article->audits()->where('event', 'created')->pluck('user_id')->all()
        );
    }

    public function testTheListenerReadsItsQueueSettingsFromConfig(): void
    {
        $event = new DispatchAudit($this->article());

        Config::set('auditing.queue.connection', 'redis');
        Config::set('auditing.queue.queue', 'audits');
        Config::set('auditing.queue.delay', 30);

        $listener = new ProcessDispatchAudit;

        $this->assertSame('redis', $listener->viaConnection());
        $this->assertSame('audits', $listener->viaQueue());
        $this->assertSame(30, $listener->withDelay($event));
    }

    public function testTheListenerDefaultsToTheSyncConnection(): void
    {
        $listener = new ProcessDispatchAudit;

        $this->assertSame('sync', $listener->viaConnection());
        $this->assertSame('default', $listener->viaQueue());

        // No delay is null, not 0: any delay sends the job through later(),
        // which on `deferred` and `background` is an in-memory timer.
        $this->assertNull($listener->withDelay(new DispatchAudit($this->article())));
    }

    public function testADelayFromTheEnvironmentIsReadAsSeconds(): void
    {
        $event = new DispatchAudit($this->article());
        $listener = new ProcessDispatchAudit;

        // env() hands the config a string.
        Config::set('auditing.queue.delay', '45');
        $this->assertSame(45, $listener->withDelay($event));

        Config::set('auditing.queue.delay', '0');
        $this->assertNull($listener->withDelay($event));
    }
}
