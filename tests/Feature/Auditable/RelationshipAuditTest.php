<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Tests\Feature\Auditable;

use Ipsocode\Auditing\Exceptions\AuditingException;
use Ipsocode\Auditing\Facades\Auditor;
use Ipsocode\Auditing\Support\AuditState;
use Ipsocode\Auditing\Tests\TestCase;
use Workbench\App\Models\Article;
use Workbench\App\Models\Category;

class RelationshipAuditTest extends TestCase
{
    private function article(): Article
    {
        return Article::factory()->create();
    }

    public function testAuditAttachRecordsACustomAudit(): void
    {
        $article = $this->article();
        $category = Category::factory()->create();

        $article->auditAttach('categories', $category->getKey());

        $audit = $article->audits()->where('event', 'attach')->sole();

        $this->assertSame(1, $audit->details()->where('field', 'categories')->count());
    }

    public function testAuditDetachRecordsACustomAudit(): void
    {
        $article = $this->article();
        $category = Category::factory()->create();

        $article->auditAttach('categories', $category->getKey());
        $article->auditDetach('categories', $category->getKey());

        $this->assertSame(1, $article->audits()->where('event', 'detach')->count());
    }

    public function testAuditSyncRecordsACustomAudit(): void
    {
        $article = $this->article();
        $first = Category::factory()->create();
        $second = Category::factory()->create();

        $article->auditAttach('categories', $first->getKey());
        $article->auditSync('categories', [$second->getKey()]);

        $this->assertSame(1, $article->audits()->where('event', 'sync')->count());
    }

    public function testAuditingAnUnknownRelationshipThrows(): void
    {
        $this->expectException(AuditingException::class);

        $this->article()->auditAttach('nonexistent', 1);
    }

    public function testARelationshipAuditInsideABatchIsFiledUnderTheOpenBatch(): void
    {
        $article = $this->article();
        $category = Category::factory()->create();
        $article->auditBatchUuid = 'a-stale-batch-uuid';

        $batch = Auditor::withinBatch(function () use ($article, $category) {
            $article->auditAttach('categories', $category->getKey());

            return Auditor::currentBatch();
        });

        $this->assertSame($batch, $article->audits()->where('event', 'attach')->sole()->batch_uuid);
        $this->assertSame('a-stale-batch-uuid', $article->auditBatchUuid);
    }

    public function testARelationshipAuditLeavesTheModelsInFlightAuditStateUnchanged(): void
    {
        $article = $this->article();
        $category = Category::factory()->create();

        $inFlight = [
            'auditEvent' => 'updated',
            'auditCustomOld' => ['old' => 1],
            'auditCustomNew' => ['new' => 2],
            'isCustomEvent' => true,
            'preloadedResolverData' => ['marker' => 'kept'],
            'auditBatchUuid' => 'a-pinned-batch-uuid',
        ];

        foreach ($inFlight as $property => $value) {
            $article->{$property} = $value;
        }

        $article->auditAttach('categories', $category->getKey());

        foreach (AuditState::PROPERTIES as $property) {
            $this->assertSame($inFlight[$property], $article->{$property}, $property);
        }
    }
}
