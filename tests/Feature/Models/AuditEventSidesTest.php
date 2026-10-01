<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Tests\Feature\Models;

use Hypervel\Support\Facades\Config;
use Ipsocode\Auditing\Tests\TestCase;
use Workbench\App\Models\Article;

/**
 * `audit_details` stores a side the event never had as NULL, the same as a
 * genuine null. The Audit model rebuilds the distinction from the event name;
 * without it a `created` audit reports an all-null old state, and transitioning
 * to it wipes the model, primary key included.
 */
class AuditEventSidesTest extends TestCase
{
    private function article(): Article
    {
        return Article::factory()->create([
            'title' => 'V1',
            'content' => 'Body',
        ]);
    }

    public function testCreatedAuditHasNoOldSide(): void
    {
        $audit = $this->article()->audits()->where('event', 'created')->sole();

        $this->assertSame([], $audit->old_values);
        $this->assertSame('V1', $audit->new_values['title']);

        foreach ($audit->getModified() as $attribute => $sides) {
            $this->assertArrayNotHasKey('old', $sides, "[{$attribute}] should have no old side");
        }
    }

    public function testDeletedAuditHasNoNewSide(): void
    {
        $article = $this->article();
        $article->delete();

        $audit = $article->audits()->where('event', 'deleted')->sole();

        $this->assertSame([], $audit->new_values);
        $this->assertSame('V1', $audit->old_values['title']);
    }

    public function testRestoredAuditHasNoOldSide(): void
    {
        $article = $this->article();
        $article->delete();
        $article->restore();

        $audit = $article->audits()->where('event', 'restored')->sole();

        $this->assertSame([], $audit->old_values);
        $this->assertSame('V1', $audit->new_values['title']);
    }

    public function testUpdatedAuditKeepsGenuineNullsOnBothSides(): void
    {
        // A real null must survive: the event-based reconstruction must not be
        // mistaken for "drop every null".
        $article = $this->article();
        $article->update(['published_at' => '2026-01-02 03:04:05']);

        $audit = $article->audits()->where('event', 'updated')->sole();

        $this->assertArrayHasKey('published_at', $audit->old_values);
        $this->assertNull($audit->old_values['published_at']);
        $this->assertNotNull($audit->new_values['published_at']);
    }

    public function testTransitioningToACreatedAuditWithOldValuesLeavesTheModelIntact(): void
    {
        $article = $this->article();
        $audit = $article->audits()->where('event', 'created')->sole();

        $key = $article->getKey();

        $article->transitionTo($audit, true);

        // Nothing to roll back to: the model — primary key included — must be
        // left exactly as it was, not nulled out into an unsaveable state.
        $this->assertSame($key, $article->getKey());
        $this->assertSame('V1', $article->title);
        $this->assertSame('Body', $article->content);
    }

    public function testTransitioningToADeletedAuditWithNewValuesLeavesTheModelIntact(): void
    {
        $article = $this->article();
        $article->delete();

        $audit = $article->audits()->where('event', 'deleted')->sole();
        $key = $article->getKey();

        $article->transitionTo($audit);

        $this->assertSame($key, $article->getKey());
        $this->assertSame('V1', $article->title);
    }

    public function testRetrievedAuditRecordsMetadataOnly(): void
    {
        Config::set('auditing.events', ['created', 'updated', 'deleted', 'restored', 'retrieved']);

        $article = $this->article();
        Article::query()->findOrFail($article->getKey());

        $audit = $article->audits()->where('event', 'retrieved')->sole();

        $this->assertSame([], $audit->old_values);
        $this->assertSame([], $audit->new_values);
        $this->assertSame([], $audit->getModified());
        $this->assertSame(0, $audit->details()->count());
    }
}
