<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Tests\Feature\Drivers;

use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\Config;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;
use Ipsocode\Auditing\Support\AuditTables;
use Ipsocode\Auditing\Tests\TestCase;
use Workbench\App\Models\Article;

class ThresholdTest extends TestCase
{
    private function articleWithManyAudits(): Article
    {
        $article = Article::factory()->create();

        foreach (['V2', 'V3', 'V4', 'V5'] as $title) {
            $article->update(['title' => $title]);
        }

        return $article;
    }

    public function testAuditsAreNotPrunedWhenThresholdIsZero(): void
    {
        $article = $this->articleWithManyAudits();

        $this->assertSame(5, $article->audits()->count());
    }

    public function testThresholdCapsTheNumberOfRetainedAudits(): void
    {
        Config::set('auditing.threshold', 2);

        $article = $this->articleWithManyAudits();

        $this->assertSame(2, $article->audits()->count());
    }

    public function testThresholdPruningLeavesNoOrphanedDetailsWithoutTheCascadingForeignKey(): void
    {
        // A table adopted by `auditing:install` has no foreign key to cascade.
        Schema::drop(AuditTables::auditDetails());
        Schema::create(AuditTables::auditDetails(), function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('audit_id');
            $table->string('field');
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->timestamp('created_at', 3)->nullable();
        });

        Config::set('auditing.threshold', 2);

        $article = $this->articleWithManyAudits();

        $survivors = $article->audits()->pluck('id')->all();

        $this->assertCount(2, $survivors);
        $this->assertSame(
            0,
            DB::table(AuditTables::auditDetails())
                ->whereNotIn('audit_id', DB::table(AuditTables::audits())->select('id'))
                ->count()
        );
        $this->assertEqualsCanonicalizing(
            $survivors,
            DB::table(AuditTables::auditDetails())->distinct()->pluck('audit_id')->all()
        );
    }
}
