<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Tests\Feature\Auditable;

use Ipsocode\Auditing\Exceptions\AuditingException;
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
}
