<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Tests\Feature\Auditable;

use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Support\Facades\Event;
use Ipsocode\Auditing\Events\AuditCustom;
use Ipsocode\Auditing\Exceptions\AuditingException;
use Ipsocode\Auditing\Tests\TestCase;
use RuntimeException;
use Workbench\App\Models\Article;
use Workbench\App\Models\Category;

/**
 * The pivot helpers are the only way to get a relationship change into the
 * audit trail — Eloquent fires no model event for attach/detach/sync.
 */
class RelationshipAuditHelpersTest extends TestCase
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

    /**
     * @return array<string, mixed>
     */
    private function soleAudit(string $event): array
    {
        $audit = $this->article->audits()->where('event', $event)->sole();

        return [
            'old' => $audit->old_values,
            'new' => $audit->new_values,
        ];
    }

    public function testAuditSyncWithoutDetachingKeepsExistingRelations(): void
    {
        $this->article->categories()->attach($this->categories['php']->getKey());

        $changes = $this->article->auditSyncWithoutDetaching('categories', [$this->categories['audit']->getKey()]);

        $this->assertSame([], $changes['detached']);
        $this->assertSame(2, $this->article->categories()->count());
        $this->assertNotEmpty($this->soleAudit('sync')['new']);
    }

    public function testAuditSyncWithPivotValuesAcceptsAScalarId(): void
    {
        $this->article->auditSyncWithPivotValues('categories', $this->categories['php']->getKey(), []);

        $this->assertSame(1, $this->article->categories()->count());
    }

    public function testAuditSyncWithPivotValuesAcceptsAModel(): void
    {
        $this->article->auditSyncWithPivotValues('categories', $this->categories['php'], []);

        $this->assertSame([$this->categories['php']->getKey()], $this->article->categories()->pluck('categories.id')->all());
    }

    public function testAuditSyncWithPivotValuesAcceptsAnEloquentCollection(): void
    {
        $ids = new EloquentCollection([$this->categories['php'], $this->categories['audit']]);

        $this->article->auditSyncWithPivotValues('categories', $ids, []);

        $this->assertSame(2, $this->article->categories()->count());
    }

    public function testAuditSyncWithPivotValuesAcceptsAnEmptyEloquentCollection(): void
    {
        $this->article->categories()->attach($this->categories['php']->getKey());

        $this->article->auditSyncWithPivotValues('categories', new EloquentCollection, []);

        $this->assertSame(0, $this->article->categories()->count());
    }

    public function testAuditSyncWithPivotValuesAcceptsASupportCollection(): void
    {
        $ids = collect([$this->categories['php']->getKey(), $this->categories['testing']->getKey()]);

        $this->article->auditSyncWithPivotValues('categories', $ids, []);

        $this->assertSame(2, $this->article->categories()->count());
    }

    public function testAClosureCanConstrainTheRelationshipQuery(): void
    {
        $this->article->categories()->attach($this->categories['php']->getKey());

        $applied = false;

        $this->article->auditAttach(
            'categories',
            $this->categories['audit']->getKey(),
            [],
            true,
            ['*'],
            function ($relation) use (&$applied) {
                $applied = true;
                $relation->wherePivot('article_id', $this->article->getKey());
            }
        );

        $this->assertTrue($applied);
        $this->assertSame(2, $this->article->categories()->count());
    }

    public function testAThrowingClosureIsReportedAsAnAuditingException(): void
    {
        $this->expectException(AuditingException::class);
        $this->expectExceptionMessage('Invalid Closure for categories Relationship');

        $this->article->auditAttach(
            'categories',
            $this->categories['php']->getKey(),
            [],
            true,
            ['*'],
            fn () => throw new RuntimeException('bad closure')
        );
    }

    public function testAuditDetachReportsHowManyRowsItRemoved(): void
    {
        $this->article->categories()->attach([
            $this->categories['php']->getKey(),
            $this->categories['audit']->getKey(),
        ]);

        $this->assertSame(2, $this->article->auditDetach('categories'));
        $this->assertSame(0, $this->article->categories()->count());
    }

    public function testDetachingNothingReportsZero(): void
    {
        $this->assertSame(0, $this->article->auditDetach('categories'));
    }

    public function testANoOpSyncRecordsAnAuditWithNoDetails(): void
    {
        $this->article->auditSync('categories', [$this->categories['php']->getKey()]);
        $this->article->refresh();

        // Syncing the ids already attached changes nothing; with the default
        // `auditing.empty_values` the event is still recorded, but with no per-field
        // detail rows behind it.
        $this->article->auditSync('categories', [$this->categories['php']->getKey()]);

        $audits = $this->article->audits()->where('event', 'sync')->orderBy('id')->get();

        $this->assertCount(2, $audits);
        $this->assertGreaterThan(0, $audits->first()->details()->count());
        $this->assertSame(0, $audits->last()->details()->count());
    }

    public function testAnUnsupportedRelationshipMethodIsReported(): void
    {
        $this->expectException(AuditingException::class);
        $this->expectExceptionMessage('Relationship audits was not found or does not support method attach');

        $this->article->auditAttach('audits', 1);
    }

    /**
     * A throwing `AuditCustom` listener must not leave `isCustomEvent = true`
     * and the stale old/new values on the model, or its next real save would
     * write a corrupt custom audit. The reset happens in a `finally`.
     */
    public function testAThrowingAuditCustomListenerStillResetsTheCustomAuditState(): void
    {
        Event::listen(AuditCustom::class, function () {
            throw new RuntimeException('listener blew up');
        });

        try {
            $this->article->auditAttach('categories', $this->categories['php']->getKey());
            $this->fail('Expected the listener exception to propagate.');
        } catch (RuntimeException $e) {
            $this->assertSame('listener blew up', $e->getMessage());
        }

        $this->assertFalse($this->article->isCustomEvent);
        $this->assertSame([], $this->article->auditCustomOld);
        $this->assertSame([], $this->article->auditCustomNew);
        $this->assertNull($this->article->auditEvent);

        Event::forget(AuditCustom::class);

        $this->article->update(['title' => 'a clean update']);

        $audit = $this->article->audits()->where('event', 'updated')->sole();

        $this->assertSame(['title' => 'a clean update'], $audit->new_values);
    }
}
