<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Tests\Feature\Auditable;

use Ipsocode\Auditing\Exceptions\AuditableTransitionException;
use Ipsocode\Auditing\Tests\TestCase;
use Workbench\App\Models\Article;
use Workbench\App\Models\IncludeArticle;
use Workbench\App\Models\RedactedArticle;

class AuditTransitionTest extends TestCase
{
    private function articleWithUpdate(): Article
    {
        $article = Article::factory()->create(['title' => 'V1']);

        $article->update(['title' => 'V2']);

        return $article;
    }

    public function testTransitionToAppliesNewOrOldValues(): void
    {
        $article = $this->articleWithUpdate();
        $audit = $article->audits()->where('event', 'updated')->sole();

        $article->title = 'scratch';

        $article->transitionTo($audit);
        $this->assertSame('V2', $article->title);

        $article->transitionTo($audit, true);
        $this->assertSame('V1', $article->title);
    }

    public function testTransitionRejectsAuditForADifferentType(): void
    {
        $article = $this->articleWithUpdate();
        $audit = $article->audits()->where('event', 'updated')->sole();

        $other = IncludeArticle::create([
            'title' => 'Other',
            'content' => 'Body',
            'reviewed' => false,
        ]);

        $this->expectException(AuditableTransitionException::class);

        $other->transitionTo($audit);
    }

    public function testTransitionRejectsAuditForADifferentId(): void
    {
        $article = $this->articleWithUpdate();
        $audit = $article->audits()->where('event', 'updated')->sole();

        $another = Article::factory()->create();

        $this->expectException(AuditableTransitionException::class);

        $another->transitionTo($audit);
    }

    public function testTransitionRejectsModelsWithARedactor(): void
    {
        $article = RedactedArticle::create([
            'title' => 'Title',
            'content' => 'Body',
            'reviewed' => false,
        ]);

        $audit = $article->audits()->where('event', 'created')->sole();

        $this->expectException(AuditableTransitionException::class);

        $article->transitionTo($audit);
    }

    public function testTransitionReportsAttributeIncompatibilities(): void
    {
        $article = $this->articleWithUpdate();
        $audit = $article->audits()->where('event', 'updated')->sole();

        $audit->details()->create([
            'field' => 'ghost',
            'old_value' => 'a',
            'new_value' => 'b',
        ]);

        $audit = $article->audits()->where('event', 'updated')->sole();

        try {
            $article->transitionTo($audit);
            $this->fail('Expected AuditableTransitionException was not thrown.');
        } catch (AuditableTransitionException $exception) {
            $this->assertSame(['ghost'], $exception->getIncompatibilities());
        }
    }
}
