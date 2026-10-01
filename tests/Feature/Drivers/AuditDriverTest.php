<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Tests\Feature\Drivers;

use Hypervel\Support\Facades\DB;
use Ipsocode\Auditing\Contracts\Auditor;
use Ipsocode\Auditing\Drivers\AuditDetails;
use Ipsocode\Auditing\Tests\TestCase;
use Workbench\App\Models\Article;

class AuditDriverTest extends TestCase
{
    private function makeArticle(): Article
    {
        return Article::factory()->create();
    }

    public function testDefaultDriverIsAuditDetails(): void
    {
        $this->assertSame('audit_details', config('auditing.driver'));
    }

    public function testDriverResolvesToTheAuditDetailsDriver(): void
    {
        $driver = $this->app->get(Auditor::class)->auditDriver($this->makeArticle());

        $this->assertInstanceOf(AuditDetails::class, $driver);
    }

    public function testCalledDirectlyTheDriverResolvesThePayloadItself(): void
    {
        // Auditor::execute() hands the driver an already-resolved payload
        // (AcceptsResolvedAudit); a caller using the plain AuditDriver contract
        // gets the same audit without one.
        $article = Article::factory()->create(['title' => 'V1']);
        $article->title = 'V2';
        $article->setAuditEvent('updated');

        $audit = (new AuditDetails)->audit($article);

        $this->assertSame(['title' => 'V1'], $audit->old_values);
        $this->assertSame(['title' => 'V2'], $audit->new_values);
    }

    public function testAnAuditIsSplitAcrossBothTables(): void
    {
        $article = $this->makeArticle();

        $auditId = DB::table('audits')
            ->where('auditable_type', $article->getMorphClass())
            ->where('auditable_id', $article->getKey())
            ->value('id');

        $this->assertNotNull($auditId, 'metadata row should be written to the audits table');

        $this->assertGreaterThan(
            0,
            DB::table('audit_details')->where('audit_id', $auditId)->count()
        );
    }

    public function testAllDetailRowsForOneAuditAreWrittenInASingleStatement(): void
    {
        DB::connection()->enableQueryLog();

        $article = $this->makeArticle();

        $inserts = collect(DB::connection()->getQueryLog())
            ->filter(fn (array $entry) => str_contains($entry['query'], 'insert into "audit_details"'))
            ->all();

        DB::connection()->disableQueryLog();

        $this->assertCount(1, $inserts, 'every changed field must be inserted in one statement, not one per field');

        $auditId = DB::table('audits')
            ->where('auditable_type', $article->getMorphClass())
            ->where('auditable_id', $article->getKey())
            ->value('id');

        $detailRows = DB::table('audit_details')->where('audit_id', $auditId)->get();

        $this->assertGreaterThan(1, $detailRows->count(), 'the article factory must touch more than one field for this test to be meaningful');
        $this->assertTrue(
            $detailRows->every(fn ($row) => ! empty($row->created_at)),
            'created_at must be populated on every row inserted via insert()'
        );
    }
}
