<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Tests\Feature\Models;

use Ipsocode\Auditing\Tests\TestCase;
use Workbench\App\Models\Article;
use Workbench\App\Models\TaggedArticle;

class AuditModelTest extends TestCase
{
    public function testOldAndNewValuesAccessorsAreBuiltFromDetails(): void
    {
        $article = Article::factory()->create(['title' => 'Original']);

        $article->update(['title' => 'Updated']);

        $audit = $article->audits()->where('event', 'updated')->sole();

        $this->assertSame(['title' => 'Original'], $audit->old_values);
        $this->assertSame(['title' => 'Updated'], $audit->new_values);
    }

    public function testGetModifiedReturnsPerAttributeOldNewMap(): void
    {
        $article = Article::factory()->create(['title' => 'Original', 'content' => 'Body']);

        $article->update(['title' => 'Updated', 'content' => 'New body']);

        $audit = $article->audits()->where('event', 'updated')->sole();

        $this->assertSame([
            'title' => ['new' => 'Updated', 'old' => 'Original'],
            'content' => ['new' => 'New body', 'old' => 'Body'],
        ], $audit->getModified());
    }

    public function testGetModifiedJsonEncodesTheModifiedMap(): void
    {
        $article = Article::factory()->create(['title' => 'Original']);

        $article->update(['title' => 'Updated']);

        $audit = $article->audits()->where('event', 'updated')->sole();

        $this->assertSame(
            ['title' => ['new' => 'Updated', 'old' => 'Original']],
            json_decode($audit->getModified(true), true)
        );
    }

    public function testGetModifiedCastsValuesUsingTheAuditable(): void
    {
        $article = Article::factory()->create();

        // Reload so the "old" value is the DB-typed value.
        $article = Article::findOrFail($article->getKey());
        $article->update(['reviewed' => true]);

        $audit = $article->audits()->where('event', 'updated')->sole();
        $modified = $audit->getModified();

        $this->assertSame(true, $modified['reviewed']['new']);
        $this->assertSame(false, $modified['reviewed']['old']);
    }

    public function testGetMetadataExposesAuditMetadata(): void
    {
        $article = Article::factory()->create();

        $audit = $article->audits()->where('event', 'created')->sole();
        $metadata = $audit->getMetadata();

        $this->assertSame('created', $metadata['audit_event']);
        $this->assertArrayHasKey('audit_id', $metadata);
        $this->assertArrayHasKey('audit_created_at', $metadata);
        $this->assertArrayHasKey('user_id', $metadata);
        $this->assertArrayHasKey('audit_url', $metadata);
        $this->assertArrayHasKey('audit_ip_address', $metadata);
        $this->assertArrayHasKey('audit_user_agent', $metadata);
    }

    public function testGetTagsSplitsTheTagsColumn(): void
    {
        $article = TaggedArticle::create([
            'title' => 'Title',
            'content' => 'Body',
            'reviewed' => false,
        ]);

        $audit = $article->audits()->where('event', 'created')->sole();

        $this->assertSame('php,audit', $audit->tags);
        $this->assertSame(['php', 'audit'], $audit->getTags());
    }
}
