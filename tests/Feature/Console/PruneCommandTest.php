<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Tests\Feature\Console;

use Hypervel\Database\Eloquent\Relations\Relation;
use Hypervel\Support\Carbon;
use Hypervel\Support\Facades\Config;
use Hypervel\Support\Facades\DB;
use Ipsocode\Auditing\Models\Audit;
use Ipsocode\Auditing\Support\AuditTables;
use Ipsocode\Auditing\Tests\TestCase;
use Workbench\App\Models\Article;
use Workbench\App\Models\AuditableUser;

/**
 * Date-based retention. The per-model threshold caps how many audits one row
 * keeps and defaults to unlimited, so without this command the trail grows for
 * the lifetime of the application.
 */
class PruneCommandTest extends TestCase
{
    /**
     * Backdate an audit — nothing else can produce one older than "now".
     */
    private function age(Audit $audit, int $days): Audit
    {
        $audit->forceFill(['created_at' => Carbon::now()->subDays($days)])->saveQuietly();

        return $audit;
    }

    private function agedArticleAudit(int $days): Audit
    {
        return $this->age(Article::factory()->create()->audits()->sole(), $days);
    }

    public function testItDeletesAuditsOlderThanTheRetentionPeriod(): void
    {
        $old = $this->agedArticleAudit(400);
        $recent = $this->agedArticleAudit(10);

        $this->artisan('auditing:prune')->assertExitCode(0);

        $this->assertNull(Audit::find($old->getKey()));
        $this->assertNotNull(Audit::find($recent->getKey()));
    }

    public function testTheRetentionPeriodComesFromConfig(): void
    {
        Config::set('auditing.delete_records_older_than_days', 5);

        $audit = $this->agedArticleAudit(10);

        $this->artisan('auditing:prune')->assertExitCode(0);

        $this->assertNull(Audit::find($audit->getKey()));
    }

    public function testTheDaysOptionOverridesTheConfiguredRetention(): void
    {
        $audit = $this->agedArticleAudit(30);

        $this->artisan('auditing:prune', ['--days' => 7])->assertExitCode(0);

        $this->assertNull(Audit::find($audit->getKey()));
    }

    public function testZeroDaysMeansEverythingWrittenBeforeNow(): void
    {
        $this->agedArticleAudit(1);

        $this->artisan('auditing:prune', ['--days' => 0])->assertExitCode(0);

        $this->assertSame(0, Audit::query()->count());
    }

    public function testDetailRowsGoWithTheAuditsTheyBelongTo(): void
    {
        $audit = $this->agedArticleAudit(400);
        $auditId = $audit->getKey();

        $this->assertGreaterThan(0, $audit->details()->count());

        $this->artisan('auditing:prune')->assertExitCode(0);

        $this->assertSame(
            0,
            DB::table(AuditTables::auditDetails())->where('audit_id', $auditId)->count()
        );
    }

    public function testADryRunReportsWithoutDeleting(): void
    {
        $audit = $this->agedArticleAudit(400);

        // One substring per run: each expectation is a separate mock
        // expectation on the same write, and only the first to match fires.
        $this->artisan('auditing:prune', ['--dry-run' => true])
            ->expectsOutputToContain('would be deleted.')
            ->assertExitCode(0);

        $this->assertNotNull(Audit::find($audit->getKey()));
    }

    public function testItReportsHowManyAuditsItDeleted(): void
    {
        $this->agedArticleAudit(400);
        $this->agedArticleAudit(400);

        $this->artisan('auditing:prune')
            ->expectsOutputToContain('2 audits older than')
            ->assertExitCode(0);
    }

    public function testTheModelOptionScopesThePruneToOneAuditableType(): void
    {
        $article = $this->agedArticleAudit(400);
        $user = $this->age(AuditableUser::factory()->create()->audits()->sole(), 400);

        $this->artisan('auditing:prune', ['--model' => Article::class])->assertExitCode(0);

        $this->assertNull(Audit::find($article->getKey()));
        $this->assertNotNull(Audit::find($user->getKey()));
    }

    public function testTheModelOptionAcceptsAMorphAlias(): void
    {
        Relation::morphMap(['article' => Article::class]);

        try {
            $audit = $this->agedArticleAudit(400);

            $this->artisan('auditing:prune', ['--model' => 'article'])->assertExitCode(0);

            $this->assertNull(Audit::find($audit->getKey()));
        } finally {
            Relation::morphMap([], false);
        }
    }

    public function testTheDeleteIsChunked(): void
    {
        foreach (range(1, 3) as $ignored) {
            $this->agedArticleAudit(400);
        }

        // One round per chunk, and the loop has to come back for the rest
        // rather than stopping at the first.
        $this->artisan('auditing:prune', ['--chunk' => 1])
            ->expectsOutputToContain('3 audits older than')
            ->assertExitCode(0);

        $this->assertSame(0, Audit::query()->count());
    }

    public function testANonsensicalRetentionIsRejectedRatherThanTreatedAsZero(): void
    {
        // `(int) 'nonsense'` is 0, which would delete the entire trail.
        $audit = $this->agedArticleAudit(400);

        $this->artisan('auditing:prune', ['--days' => 'nonsense'])
            ->expectsOutputToContain('Retention must be a non-negative number of days')
            ->assertExitCode(1);

        $this->assertNotNull(Audit::find($audit->getKey()));
    }

    public function testANegativeRetentionIsRejected(): void
    {
        $this->artisan('auditing:prune', ['--days' => -1])
            ->expectsOutputToContain('Retention must be a non-negative number of days')
            ->assertExitCode(1);
    }

    public function testNothingToPruneIsNotAnError(): void
    {
        Article::factory()->create();

        $this->artisan('auditing:prune')
            ->expectsOutputToContain('0 audits older than')
            ->assertExitCode(0);

        $this->assertSame(1, Audit::query()->count());
    }
}
