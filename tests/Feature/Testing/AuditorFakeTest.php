<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Tests\Feature\Testing;

use Hypervel\Support\Facades\Config;
use Ipsocode\Auditing\Contracts\Audit as AuditContract;
use Ipsocode\Auditing\Contracts\Auditor as AuditorContract;
use Ipsocode\Auditing\Drivers\AuditDetails;
use Ipsocode\Auditing\Facades\Auditor;
use Ipsocode\Auditing\Models\Audit;
use Ipsocode\Auditing\Models\AuditDetail;
use Ipsocode\Auditing\Testing\AuditorFake;
use Ipsocode\Auditing\Tests\TestCase;
use PHPUnit\Framework\ExpectationFailedException;
use Workbench\App\Models\Article;
use Workbench\App\Models\Category;
use Workbench\App\Models\User;

/**
 * `Auditor::fake()` records the audits the real auditor would write, without
 * touching the audit tables, and asserts on them.
 */
class AuditorFakeTest extends TestCase
{
    private function failureOf(callable $assertion): string
    {
        try {
            $assertion();
        } catch (ExpectationFailedException $exception) {
            return $exception->getMessage();
        }

        $this->fail('The assertion was expected to fail.');
    }

    public function testModelEventsAreRecordedAndAsserted(): void
    {
        $article = Article::factory()->create(['title' => 'V1']);

        $fake = Auditor::fake();

        $article->update(['title' => 'Revised']);

        $fake->assertAudited($article);
        $fake->assertAudited($article, 'updated');
        $fake->assertAudited(Article::class, 'updated');
        $fake->assertAudited(
            Article::class,
            'updated',
            fn (AuditContract $audit) => $audit->getModified()['title']['new'] === 'Revised'
        );
        $fake->assertNotAudited($article, 'deleted');
        $fake->assertNotAudited(Category::class);
        $fake->assertNotAudited($article, 'updated', fn (AuditContract $audit) => false);
        $fake->assertNotAudited(Article::factory()->create(), 'updated');
        $fake->assertAuditedCount(2);
    }

    public function testRecordedReturnsACollectionNarrowedByACallback(): void
    {
        $fake = Auditor::fake();

        $article = Article::factory()->create(['title' => 'V1']);
        $article->update(['title' => 'V2']);

        $this->assertCount(2, $fake->recorded());
        $this->assertSame(['updated'], $fake->recorded(fn (AuditContract $audit) => $audit->event === 'updated')
            ->pluck('event')->all());
    }

    public function testARecordedAuditReadsBackLikeAStoredOne(): void
    {
        $user = User::factory()->create();
        $article = Article::factory()->create(['title' => 'V1', 'reviewed' => false]);

        $fake = Auditor::fake();

        Auditor::actingAs($user, fn () => $article->update(['title' => 'V2', 'reviewed' => true]));

        $audit = $fake->recorded()->sole();

        $this->assertSame(
            ['title' => ['new' => 'V2', 'old' => 'V1'], 'reviewed' => ['new' => true, 'old' => false]],
            $audit->getModified(),
        );
        $this->assertSame($user->getKey(), $audit->getMetadata()['user_id']);
        $this->assertSame('updated', $audit->getMetadata()['audit_event']);
        $this->assertTrue($audit->auditable->is($article));
    }

    public function testNothingReachesTheAuditTables(): void
    {
        $article = Article::factory()->create();
        $details = AuditDetail::query()->count();

        $fake = Auditor::fake();

        $article->update(['title' => 'Revised']);
        $article->auditAttach('categories', Category::factory()->create());
        Auditor::on($article)->as('emailed')->with(['to' => 'x'])->log();

        $fake->assertAuditedCount(3);
        $this->assertSame(1, Audit::query()->count());
        $this->assertSame($details, AuditDetail::query()->count());
    }

    public function testRelationshipHelpersAreRecorded(): void
    {
        $article = Article::factory()->create();
        $category = Category::factory()->create();

        $fake = Auditor::fake();

        $article->auditAttach('categories', $category);

        $fake->assertAudited($article, 'attach');
    }

    public function testAManualAuditIsRecordedAndReturned(): void
    {
        $article = Article::factory()->create();

        $fake = Auditor::fake();

        $audit = Auditor::on($article)->as('exported')->with(['format' => 'pdf'])->log();

        $this->assertSame($fake->recorded()->sole(), $audit);
        $this->assertSame(['format' => ['new' => 'pdf', 'old' => null]], $audit->getModified());
        $fake->assertAudited($article, 'exported');
    }

    public function testWithoutAuditingAndUnauditedEventsRecordNothing(): void
    {
        $fake = Auditor::fake();

        $article = Article::withoutAuditing(fn () => Article::factory()->create());
        $article->update(['title' => 'Revised']);
        $fake->assertAuditedCount(1);

        $fake->assertNotAudited($article, 'created');

        $this->assertNull($fake->execute(new Article));
    }

    public function testTheEmptyValueGuardStillPreventsRecording(): void
    {
        $article = Article::factory()->create();

        Config::set('auditing.empty_values', false);
        Config::set('auditing.allowed_empty_values', []);

        $fake = Auditor::fake();

        $this->assertNull(Auditor::on($article)->as('noop')->log());

        $fake->assertNothingAudited();
    }

    public function testBatchAndCauserHelpersDelegateToTheRealAuditor(): void
    {
        $user = User::factory()->create();
        $article = Article::factory()->create();

        $fake = Auditor::fake();

        $result = Auditor::withinBatch(function () use ($article, $fake) {
            $this->assertNotNull(Auditor::currentBatch());
            $article->update(['title' => 'Revised']);

            return $fake->recorded()->sole()->batch_uuid;
        }, 'batch-1');

        $this->assertSame('batch-1', $result);
        $this->assertNull(Auditor::currentBatch());

        $this->assertSame($user, Auditor::actingAs($user, fn () => Auditor::currentCauser()));
        $this->assertNull(Auditor::currentCauser());
    }

    public function testTheDriverResolvesThroughTheRealAuditor(): void
    {
        Auditor::fake();

        $this->assertInstanceOf(AuditDetails::class, Auditor::auditDriver(new Article));
    }

    public function testAQueuedAuditOnTheSyncConnectionIsRecordedThroughTheFake(): void
    {
        Config::set('auditing.queue.enable', true);

        $fake = Auditor::fake();

        $article = Article::factory()->create();

        $fake->assertAudited($article, 'created');
    }

    public function testCodeThatInjectsTheContractGetsTheFake(): void
    {
        $fake = Auditor::fake();

        $this->assertSame($fake, $this->app->make(AuditorContract::class));
        $this->assertSame($fake, $this->app->make('auditor'));
        $this->assertSame($fake, Auditor::getFacadeRoot());
    }

    public function testFakingAgainWrapsTheRealAuditorAndStartsFresh(): void
    {
        $first = Auditor::fake();
        Article::factory()->create();

        $second = Auditor::fake();

        $this->assertNotSame($first, $second);
        $this->assertSame($first->auditor, $second->auditor);
        $this->assertNotInstanceOf(AuditorFake::class, $second->auditor);
        $second->assertNothingAudited();
    }

    public function testTheFakeDoesNotOutliveTheTest(): void
    {
        $this->assertFalse(Auditor::isFake());
        $this->assertNotInstanceOf(AuditorFake::class, $this->app->make(AuditorContract::class));
    }

    public function testFailureMessagesListWhatWasRecorded(): void
    {
        $article = Article::factory()->create();
        $fake = Auditor::fake();

        $message = $this->failureOf(fn () => $fake->assertAudited($article, 'updated'));
        $this->assertStringContainsString(sprintf('%s #%s [updated]', Article::class, $article->getKey()), $message);
        $this->assertStringContainsString('Nothing was recorded.', $message);

        $article->update(['title' => 'Revised']);

        $message = $this->failureOf(fn () => $fake->assertAudited(Category::class));
        $this->assertStringContainsString('The expected audit was not recorded: ' . Category::class, $message);
        $this->assertStringContainsString(
            sprintf("Recorded:\n- %s #%s [updated]", Article::class, $article->getKey()),
            $message
        );

        $message = $this->failureOf(fn () => $fake->assertNotAudited($article, 'updated'));
        $this->assertStringContainsString('An unexpected audit was recorded', $message);

        $message = $this->failureOf(fn () => $fake->assertNothingAudited());
        $this->assertStringContainsString('Audits were recorded.', $message);

        $message = $this->failureOf(fn () => $fake->assertAuditedCount(3));
        $this->assertStringContainsString('Expected 3 recorded audits, found 1.', $message);
    }

    public function testAnUnsavedModelIsLabelledInAFailureMessage(): void
    {
        $fake = Auditor::fake();

        $message = $this->failureOf(fn () => $fake->assertAudited(new Article, 'created'));

        $this->assertStringContainsString(Article::class . ' #unsaved [created]', $message);
    }
}
