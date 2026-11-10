<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Tests\Feature\Auditor;

use Hypervel\Support\Facades\Event;
use Ipsocode\Auditing\Contracts\Audit as AuditContract;
use Ipsocode\Auditing\Events\Audited;
use Ipsocode\Auditing\Events\Auditing;
use Ipsocode\Auditing\Exceptions\AuditingException;
use Ipsocode\Auditing\Facades\Auditor;
use Ipsocode\Auditing\Tests\TestCase;
use Workbench\App\Models\Article;
use Workbench\App\Models\User;

/**
 * Manual audits record events with no column behind them (a login, an export,
 * a permission grant) without hand-assembling the model's custom-audit state
 * and dispatching `AuditCustom`.
 */
class ManualAuditTest extends TestCase
{
    public function testItRecordsAnEventWithNoColumnBehindIt(): void
    {
        $article = $this->article(['title' => 'V1']);

        $audit = Auditor::on($article)->as('exported')->with(['format' => 'pdf'])->log();

        $this->assertInstanceOf(AuditContract::class, $audit);
        $this->assertSame('exported', $audit->event);
        $this->assertSame($article->getKey(), $audit->auditable_id);
        $this->assertSame($article->getMorphClass(), $audit->auditable_type);
        $this->assertSame(['format' => 'pdf'], $audit->fresh()->new_values);
    }

    public function testPropertiesBecomeDetailRows(): void
    {
        $article = $this->article(['title' => 'V1']);

        $audit = Auditor::on($article)
            ->as('exported')
            ->with(['format' => 'pdf', 'pages' => 12])
            ->log();

        $this->assertSame(
            ['format' => 'pdf', 'pages' => '12'],
            $audit->fresh()->details->pluck('new_value', 'field')->all()
        );
    }

    public function testBothSidesOfATransitionCanBeRecorded(): void
    {
        $article = $this->article(['title' => 'V1']);

        $audit = Auditor::on($article)
            ->as('approved')
            ->from(['state' => 'pending'])
            ->with(['state' => 'approved'])
            ->log()
            ->fresh();

        $this->assertSame(['state' => 'pending'], $audit->old_values);
        $this->assertSame(['state' => 'approved'], $audit->new_values);
    }

    public function testPropertiesFromSeveralCallsAreMerged(): void
    {
        $article = $this->article(['title' => 'V1']);

        $audit = Auditor::on($article)
            ->as('exported')
            ->with(['format' => 'pdf'])
            ->with(['pages' => 12])
            ->log();

        $this->assertSame(['format', 'pages'], $audit->fresh()->details->pluck('field')->all());
    }

    public function testAnArrayPropertyIsStoredAsJson(): void
    {
        $article = $this->article(['title' => 'V1']);

        $audit = Auditor::on($article)->as('exported')->with(['columns' => ['id', 'title']])->log();

        $this->assertSame('["id","title"]', $audit->fresh()->details->sole()->new_value);
    }

    public function testTheEventNameCanBePassedToLog(): void
    {
        $audit = Auditor::on($this->article(['title' => 'V1']))->log('viewed');

        $this->assertSame('viewed', $audit->event);
    }

    public function testAnAuditWithoutAnEventNameIsRejected(): void
    {
        $this->expectException(AuditingException::class);
        $this->expectExceptionMessage('A custom audit needs an event name');

        Auditor::on($this->article(['title' => 'V1']))->with(['format' => 'pdf'])->log();
    }

    public function testTheCauserDefaultsToTheResolvedUser(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $audit = Auditor::on($this->article(['title' => 'V1']))->as('exported')->log();

        $this->assertSame($user->getKey(), $audit->user_id);
    }

    public function testAnExplicitCauserOverridesTheResolver(): void
    {
        $actor = User::factory()->create();
        $subject = User::factory()->create();
        $this->actingAs($actor);

        $audit = Auditor::on($this->article(['title' => 'V1']))->as('impersonated')->by($subject)->log();

        $this->assertSame($subject->getKey(), $audit->user_id);
        $this->assertSame($subject->getMorphClass(), $audit->user_type);
    }

    public function testAnExplicitNullCauserFallsBackToTheResolver(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $audit = Auditor::on($this->article(['title' => 'V1']))->as('exported')->by(null)->log();

        $this->assertSame($user->getKey(), $audit->user_id);
    }

    public function testLoggingLeavesTheModelExactlyAsItFoundIt(): void
    {
        $article = $this->article(['title' => 'V1']);

        $before = [
            $article->auditEvent,
            $article->auditCustomOld,
            $article->auditCustomNew,
            $article->isCustomEvent,
            $article->preloadedResolverData,
        ];

        Auditor::on($article)->as('exported')->with(['format' => 'pdf'])->by(User::factory()->create())->log();

        $this->assertSame($before, [
            $article->auditEvent,
            $article->auditCustomOld,
            $article->auditCustomNew,
            $article->isCustomEvent,
            $article->preloadedResolverData,
        ]);
    }

    public function testTheModelIsRestoredEvenWhenTheAuditThrows(): void
    {
        $article = $this->article(['title' => 'V1']);

        $exception = new AuditingException('listener exploded');

        Event::listen(Auditing::class, function () use ($exception): void {
            throw $exception;
        });

        try {
            Auditor::on($article)->as('exported')->log();
            $this->fail('the listener should have thrown');
        } catch (AuditingException $caught) {
            $this->assertSame($exception, $caught);
        }

        $this->assertFalse($article->isCustomEvent);
        $this->assertSame('created', $article->auditEvent);
    }

    public function testNothingIsWrittenWhileAuditingIsDisabled(): void
    {
        $article = $this->article(['title' => 'V1']);

        $audit = Article::withoutAuditing(
            fn () => Auditor::on($article)->as('exported')->with(['format' => 'pdf'])->log()
        );

        $this->assertNull($audit);
        $this->assertSame(0, $article->audits()->where('event', 'exported')->count());
    }

    public function testAVetoingAuditingListenerStopsTheWrite(): void
    {
        $article = $this->article(['title' => 'V1']);

        Event::listen(Auditing::class, fn () => false);

        $this->assertNull(Auditor::on($article)->as('exported')->log());
        $this->assertSame(0, $article->audits()->where('event', 'exported')->count());
    }

    public function testTheUsualAuditedEventIsFired(): void
    {
        $article = $this->article(['title' => 'V1']);
        $events = [];

        Event::listen(Audited::class, function (Audited $event) use (&$events): void {
            $events[] = $event->audit->event;
        });

        Auditor::on($article)->as('exported')->log();

        $this->assertSame(['exported'], $events);
    }

    public function testAManualAuditJoinsTheOpenBatch(): void
    {
        $article = $this->article(['title' => 'V1']);

        $audit = Auditor::withinBatch(
            fn () => Auditor::on($article)->as('exported')->log(),
            '0197c0de-0000-7000-8000-000000000101',
        );

        $this->assertSame('0197c0de-0000-7000-8000-000000000101', $audit->batch_uuid);
    }

    public function testAManualAuditDoesNotInheritABatchThatHasSinceClosed(): void
    {
        // The save's batch is never kept on the model, so a later manual audit
        // gets whatever batch is open when it is logged: here, none.
        $article = Auditor::withinBatch(fn () => $this->article(['title' => 'V1']), '00000000-0000-0000-0000-000000001184');

        $audit = Auditor::on($article)->as('exported')->log();

        $this->assertNull($audit->batch_uuid);
    }
}
