<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Tests\Feature\Auditor;

use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Support\Facades\Config;
use Hypervel\Support\Facades\Event;
use Ipsocode\Auditing\Events\DispatchAudit;
use Ipsocode\Auditing\Events\DispatchingAudit;
use Ipsocode\Auditing\Facades\Auditor;
use Ipsocode\Auditing\Listeners\ProcessDispatchAudit;
use Ipsocode\Auditing\Support\AuditCauser;
use Ipsocode\Auditing\Support\ContextKeys;
use Ipsocode\Auditing\Testing\TestState;
use Ipsocode\Auditing\Tests\TestCase;
use RuntimeException;
use Workbench\App\Models\Article;
use Workbench\App\Models\Category;
use Workbench\App\Models\User;

use function Hypervel\Coroutine\parallel;

/**
 * `Auditor::actingAs()` names the causer of every audit written inside the
 * callback, on any model, whatever the guards report.
 */
class ActingAsTest extends TestCase
{
    public function testModelEventsRelationshipHelpersAndManualAuditsRecordTheScopesCauser(): void
    {
        $user = User::factory()->create();
        $article = Article::factory()->create(['title' => 'V1']);
        $category = Category::factory()->create();

        Auditor::actingAs($user, function () use ($article, $category) {
            $article->update(['title' => 'V2']);
            $article->auditAttach('categories', $category);
            Auditor::on($article)->as('emailed')->with(['to' => 'x'])->log();
        });

        $audits = $article->audits()->whereIn('event', ['updated', 'attach', 'emailed'])->get();

        $this->assertCount(3, $audits);
        $this->assertSame([$user->getKey()], $audits->pluck('user_id')->unique()->all());
    }

    public function testByWinsOverTheScope(): void
    {
        $scoped = User::factory()->create();
        $named = User::factory()->create();
        $article = Article::factory()->create();

        Auditor::actingAs($scoped, function () use ($article, $named) {
            Auditor::on($article)->as('emailed')->with(['to' => 'x'])->by($named)->log();
        });

        $this->assertSame($named->getKey(), $article->audits()->where('event', 'emailed')->sole()->user_id);
    }

    public function testANullScopeRecordsNoCauserOnAnAuthenticatedRequest(): void
    {
        $this->actingAs(User::factory()->create());

        $article = Auditor::actingAs(null, fn () => Article::factory()->create());

        $this->assertNull($article->audits()->sole()->user_id);
    }

    public function testTheResolverIsUsedOutsideAScope(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $article = Article::factory()->create();

        $this->assertSame($user->getKey(), $article->audits()->sole()->user_id);
    }

    public function testItReturnsTheCallbacksResultAndExposesTheCurrentCauser(): void
    {
        $user = User::factory()->create();

        $this->assertNull(Auditor::currentCauser());

        $result = Auditor::actingAs($user, fn () => [Auditor::currentCauser(), 'done']);

        $this->assertSame([$user, 'done'], $result);
        $this->assertNull(Auditor::currentCauser());
    }

    public function testNestedScopesReplaceTheCauserAndTheOuterOneReturnsAfterAThrow(): void
    {
        $outer = User::factory()->create();
        $inner = User::factory()->create();
        $article = Article::factory()->create();

        Auditor::actingAs($outer, function () use ($inner, $article) {
            try {
                Auditor::actingAs($inner, function () use ($article) {
                    Auditor::on($article)->as('inner')->with(['a' => 1])->log();

                    throw new RuntimeException('boom');
                });
            } catch (RuntimeException) {
            }

            Auditor::on($article)->as('outer')->with(['a' => 1])->log();
        });

        $this->assertSame($inner->getKey(), $article->audits()->where('event', 'inner')->sole()->user_id);
        $this->assertSame($outer->getKey(), $article->audits()->where('event', 'outer')->sole()->user_id);
    }

    public function testAChildCoroutineJoinsTheScopeOnlyWhenItCopiesContext(): void
    {
        $user = User::factory()->create();
        $fresh = $copied = null;

        Auditor::actingAs($user, function () use (&$fresh, &$copied) {
            parallel([function () use (&$fresh) {
                $fresh = Article::factory()->create();
            }]);

            parallel([function () use (&$copied) {
                $copied = Article::factory()->create();
            }], copyContext: true);
        });

        $this->assertNull($fresh->audits()->sole()->user_id);
        $this->assertSame($user->getKey(), $copied->audits()->sole()->user_id);
    }

    public function testAScopeDoesNotReachAConcurrentCoroutineOutsideIt(): void
    {
        $user = User::factory()->create();
        $outside = null;

        Auditor::actingAs($user, function () use (&$outside) {
            parallel([function () use (&$outside) {
                $outside = [Auditor::currentCauser(), AuditCauser::isScoped()];
            }]);
        });

        $this->assertSame([null, false], $outside);
    }

    public function testTheCauserSurvivesSerializationIntoAWorkerWithNoUser(): void
    {
        Config::set('auditing.queue.enable', true);
        $user = User::factory()->create();
        $serialized = null;

        Event::listen(DispatchAudit::class, function (DispatchAudit $event) use (&$serialized): void {
            $serialized = serialize($event);
        });

        $article = Auditor::actingAs($user, fn () => Article::factory()->create());

        parallel([fn () => (new ProcessDispatchAudit)->handle(unserialize($serialized))]);

        // The sync connection wrote one audit already; the replay in a bare
        // coroutine wrote the second, and only the payload can name its causer.
        $this->assertSame(
            [$user->getKey(), $user->getKey()],
            $article->audits()->where('event', 'created')->pluck('user_id')->all()
        );
    }

    public function testANullScopeTravelsWithAQueuedAudit(): void
    {
        Config::set('auditing.queue.enable', true);
        $this->actingAs(User::factory()->create());
        $payload = null;

        Event::listen(DispatchAudit::class, function (DispatchAudit $event) use (&$payload): void {
            $payload = unserialize(serialize($event))->model->preloadedResolverData;
        });

        Auditor::actingAs(null, fn () => Article::factory()->create());

        $this->assertArrayHasKey('user', $payload);
        $this->assertNull($payload['user']);
    }

    public function testADispatchLeavesNoResolvedDataOnTheModel(): void
    {
        Config::set('auditing.queue.enable', true);

        $article = Auditor::actingAs(User::factory()->create(), fn () => Article::factory()->create());

        $this->assertSame([], $article->preloadedResolverData);
    }

    public function testAVetoedDispatchLeavesNoResolvedDataOnTheModel(): void
    {
        Config::set('auditing.queue.enable', true);
        Event::listen(DispatchingAudit::class, fn () => false);

        $article = Auditor::actingAs(User::factory()->create(), fn () => Article::factory()->create());

        $this->assertSame([], $article->preloadedResolverData);
    }

    public function testALaterScopeIsNotOverriddenByAnEarlierQueuedDispatchOnTheSameInstance(): void
    {
        Config::set('auditing.queue.enable', true);
        $first = User::factory()->create();
        $second = User::factory()->create();
        $category = Category::factory()->create();

        $article = Auditor::actingAs($first, fn () => Article::factory()->create());

        Auditor::actingAs($second, fn () => $article->auditAttach('categories', $category));

        $this->assertSame($second->getKey(), $article->audits()->where('event', 'attach')->sole()->user_id);
    }

    public function testFlushingTestStateClosesAnAbandonedScope(): void
    {
        $user = User::factory()->create();

        CoroutineContext::set(ContextKeys::CAUSER, ['user' => $user]);
        $this->assertSame($user, Auditor::currentCauser());

        TestState::flushState();

        $this->assertNull(Auditor::currentCauser());
        $this->assertFalse(CoroutineContext::has(ContextKeys::CAUSER));
    }

    protected function defineEnvironment(Application $app): void
    {
        parent::defineEnvironment($app);

        // The pivot helper writes a custom event with no old/new values on the
        // article itself; keep the empty-value guard from swallowing it.
        $app->get('config')->set('auditing.empty_values', true);
    }
}
