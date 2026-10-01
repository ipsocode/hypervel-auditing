<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Tests\Feature\Auditable;

use Hypervel\Database\QueryException;
use Ipsocode\Auditing\Models\Audit;
use Ipsocode\Auditing\Tests\TestCase;
use Workbench\App\Models\Article;
use Workbench\App\Models\AuditableCategoryPivot;
use Workbench\App\Models\Category;
use Workbench\App\Models\PivotAuditedArticle;

/**
 * auditDetach() and auditSync() with a Closure that constrains the relationship
 * before the write, and through a pivot that is itself Auditable, where the
 * helpers wrap the write in withoutAuditing().
 */
class RelationshipPivotAuditTest extends TestCase
{
    private Article $article;

    /** @var array<string, Category> */
    private array $categories = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->article = Article::factory()->create();

        foreach (['php', 'testing', 'audit'] as $name) {
            $this->categories[$name] = Category::factory()->create(['name' => $name]);
        }
    }

    private function pivotArticle(): PivotAuditedArticle
    {
        return PivotAuditedArticle::create([
            'title' => 'An article related through an auditable pivot',
            'content' => 'Content.',
        ]);
    }

    private function pivotAuditCount(): int
    {
        return Audit::where('auditable_type', AuditableCategoryPivot::class)->count();
    }

    /**
     * @return list<int>
     */
    private function categoryIdsOf(Article $article): array
    {
        return $article->categories()->orderBy('categories.id')->pluck('categories.id')->all();
    }

    public function testAClosureNarrowsWhatAuditDetachRemoves(): void
    {
        $this->article->categories()->attach([
            $this->categories['php']->getKey(),
            $this->categories['testing']->getKey(),
        ]);

        $applied = false;

        // No ids: what gets detached is decided entirely by the closure's
        // constraint, so the surviving row proves the closure reached the query.
        $detached = $this->article->auditDetach('categories', null, true, ['*'], function ($relation) use (&$applied) {
            $applied = true;
            $relation->wherePivot('category_id', $this->categories['php']->getKey());
        });

        $this->assertTrue($applied);
        $this->assertSame(1, $detached);
        $this->assertSame([$this->categories['testing']->getKey()], $this->categoryIdsOf($this->article));
    }

    public function testAClosureNarrowsWhatAuditSyncDetaches(): void
    {
        $this->article->categories()->attach([
            $this->categories['php']->getKey(),
            $this->categories['testing']->getKey(),
        ]);

        $applied = false;

        $changes = $this->article->auditSync('categories', [$this->categories['audit']->getKey()], true, ['*'], function ($relation) use (&$applied) {
            $applied = true;
            $relation->wherePivot('category_id', $this->categories['php']->getKey());
        });

        $this->assertTrue($applied);
        // A full sync would have detached `testing` too; the closure hid it from
        // the relationship, so sync never saw it as attached.
        $this->assertSame([$this->categories['php']->getKey()], $changes['detached']);
        $this->assertSame([$this->categories['audit']->getKey()], $changes['attached']);
        $this->assertSame([
            $this->categories['testing']->getKey(),
            $this->categories['audit']->getKey(),
        ], $this->categoryIdsOf($this->article));
    }

    public function testAnAuditablePivotAuditsItsOwnWritesWhenNothingSuppressesIt(): void
    {
        // Control: without the helpers' withoutAuditing() wrapper the pivot
        // audits itself, and its composite key leaves `audits.auditable_id`
        // null, so the insert is rejected.
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('audits.auditable_id');

        $this->pivotArticle()->categories()->attach($this->categories['php']->getKey());
    }

    public function testAuditDetachThroughAnAuditablePivotRecordsOnlyTheRelationAudit(): void
    {
        $article = $this->pivotArticle();
        $article->auditSync('categories', [$this->categories['php']->getKey()]);

        $detached = $article->auditDetach('categories', $this->categories['php']->getKey());

        $this->assertSame(1, $detached);
        $this->assertSame([], $this->categoryIdsOf($article));
        $this->assertSame(1, $article->audits()->where('event', 'detach')->count());
        $this->assertSame(0, $this->pivotAuditCount());
    }

    public function testAuditSyncThroughAnAuditablePivotRecordsOnlyTheRelationAudit(): void
    {
        $article = $this->pivotArticle();

        $attaching = $article->auditSync('categories', [$this->categories['php']->getKey()]);
        // The second sync detaches as well as attaches, so both sides of the
        // wrapped write are exercised.
        $swapping = $article->auditSync('categories', [$this->categories['audit']->getKey()]);

        $this->assertSame([$this->categories['php']->getKey()], $attaching['attached']);
        $this->assertSame([$this->categories['php']->getKey()], $swapping['detached']);
        $this->assertSame([$this->categories['audit']->getKey()], $this->categoryIdsOf($article));

        $syncAudits = $article->audits()->where('event', 'sync')->orderBy('id')->get();

        $this->assertCount(2, $syncAudits);
        $this->assertSame(1, $syncAudits->last()->details()->where('field', 'categories')->count());
        $this->assertSame(0, $this->pivotAuditCount());
    }
}
