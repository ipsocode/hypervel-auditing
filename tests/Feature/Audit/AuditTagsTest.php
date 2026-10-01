<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Tests\Feature\Audit;

use Ipsocode\Auditing\Tests\TestCase;
use Workbench\App\Models\Article;
use Workbench\App\Models\TaggedArticle;

class AuditTagsTest extends TestCase
{
    /**
     * @param class-string<Article> $model
     */
    private function auditFor(string $model): \Ipsocode\Auditing\Contracts\Audit
    {
        return $model::create([
            'title' => 'Title',
            'content' => 'Body',
            'reviewed' => false,
        ])->audits()->sole();
    }

    public function testGetTagsReturnsAnEmptyArrayWhenTheAuditHasNoTags(): void
    {
        // generateTags() returns [] by default, so toAudit() writes NULL into a
        // nullable column — the common case for every model that does not tag.
        $audit = $this->auditFor(Article::class);

        $this->assertNull($audit->tags);
        $this->assertSame([], $audit->getTags());
    }

    public function testGetTagsSplitsGeneratedTags(): void
    {
        $audit = $this->auditFor(TaggedArticle::class);

        $this->assertSame('php,audit', $audit->tags);
        $this->assertSame(['php', 'audit'], $audit->getTags());
    }

    public function testGetTagsDropsEmptySegments(): void
    {
        $audit = $this->auditFor(Article::class);
        $audit->tags = 'php,,audit,';

        $this->assertSame(['php', 'audit'], $audit->getTags());
    }

    public function testMetadataExposesTheTagsColumn(): void
    {
        $this->assertNull($this->auditFor(Article::class)->getMetadata()['audit_tags']);
        $this->assertSame('php,audit', $this->auditFor(TaggedArticle::class)->getMetadata()['audit_tags']);
    }
}
