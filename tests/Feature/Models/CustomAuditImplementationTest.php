<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Tests\Feature\Models;

use Hypervel\Support\Facades\Config;
use Ipsocode\Auditing\Tests\TestCase;
use Workbench\App\Models\Article;
use Workbench\App\Models\AuditLog;

/**
 * `auditing.implementation` lets an application substitute its own Audit model.
 * The relations between the two audit tables must not depend on that class
 * being named `Audit`, or the convention-derived foreign key points at a column
 * that does not exist and every audited write fails.
 */
class CustomAuditImplementationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('auditing.implementation', AuditLog::class);
    }

    private function article(): Article
    {
        return Article::factory()->create(['title' => 'V1']);
    }

    public function testAuditsAreRecordedThroughTheCustomImplementation(): void
    {
        $article = $this->article();
        $article->update(['title' => 'V2']);

        $audit = $article->audits()->where('event', 'updated')->sole();

        $this->assertInstanceOf(AuditLog::class, $audit);
        $this->assertSame(['title' => 'V1'], $audit->old_values);
        $this->assertSame(['title' => 'V2'], $audit->new_values);
    }

    public function testDetailsAreLinkedByTheAuditIdColumn(): void
    {
        $audit = $this->article()->audits()->sole();

        $this->assertSame('audit_id', $audit->details()->getForeignKeyName());
        $this->assertGreaterThan(0, $audit->details()->count());

        foreach ($audit->details as $detail) {
            $this->assertSame($audit->getKey(), $detail->audit_id);
            $this->assertSame($audit->getKey(), $detail->audit->getKey());
        }
    }

    public function testPruningWorksThroughTheCustomImplementation(): void
    {
        Config::set('auditing.threshold', 2);

        $article = $this->article();

        foreach (['V2', 'V3', 'V4'] as $title) {
            $article->update(['title' => $title]);
        }

        $this->assertSame(2, $article->audits()->count());
    }
}
