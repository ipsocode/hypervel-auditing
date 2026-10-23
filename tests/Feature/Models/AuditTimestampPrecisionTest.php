<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Tests\Feature\Models;

use Carbon\Carbon;
use Hypervel\Support\Facades\DB;
use Ipsocode\Auditing\Models\Audit;
use Ipsocode\Auditing\Models\AuditDetail;
use Ipsocode\Auditing\Support\AuditTables;
use Ipsocode\Auditing\Tests\TestCase;
use Workbench\App\Models\Article;

class AuditTimestampPrecisionTest extends TestCase
{
    public function testAuditsAreStoredWithMilliseconds(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-02 03:04:05.678'));

        try {
            $audit = Article::factory()->create()->audits()->sole();
        } finally {
            Carbon::setTestNow();
        }

        $this->assertSame(
            '2026-01-02 03:04:05.678',
            DB::table(AuditTables::audits())->where('id', $audit->getKey())->value('created_at')
        );
        $this->assertStringContainsString('05.678', $audit->getMetadata()['audit_created_at']);
    }

    public function testAuditDetailsAreStoredWithMilliseconds(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-02 03:04:05.678'));

        try {
            Article::factory()->create();
        } finally {
            Carbon::setTestNow();
        }

        $this->assertSame(
            '2026-01-02 03:04:05.678',
            DB::table(AuditTables::auditDetails())->value('created_at')
        );
    }

    public function testSameSecondAuditsOrderByCreatedAtAlone(): void
    {
        $article = Article::factory()->create();

        foreach ([300, 100, 200] as $millisecond) {
            Audit::query()->forceCreate([
                'event' => 'custom',
                'auditable_type' => $article->getMorphClass(),
                'auditable_id' => $article->getKey(),
                'created_at' => Carbon::parse('2030-01-01 00:00:00')->addMilliseconds($millisecond),
            ]);
        }

        $ordered = $article->audits()->where('event', 'custom')->oldest()->get();

        $this->assertSame(
            ['00:00:00.100', '00:00:00.200', '00:00:00.300'],
            $ordered->map(fn (Audit $audit) => $audit->created_at->format('H:i:s.v'))->all()
        );
    }

    public function testRowsWrittenInWholeSecondsStillReadBack(): void
    {
        $article = Article::factory()->create();
        $audit = $article->audits()->sole();

        DB::table(AuditTables::audits())->where('id', $audit->getKey())->update(['created_at' => '2025-05-06 07:08:09']);
        DB::table(AuditTables::auditDetails())->update(['created_at' => '2025-05-06 07:08:09']);

        $this->assertSame('2025-05-06 07:08:09', $article->audits()->sole()->created_at->format('Y-m-d H:i:s'));
        $this->assertSame('2025-05-06 07:08:09', AuditDetail::query()->first()->created_at->format('Y-m-d H:i:s'));
    }
}
