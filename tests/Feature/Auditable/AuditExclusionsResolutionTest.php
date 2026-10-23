<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Tests\Feature\Auditable;

use Hypervel\Support\Facades\Config;
use Ipsocode\Auditing\Facades\Auditor;
use Ipsocode\Auditing\Tests\TestCase;
use Workbench\App\Models\Article;

/**
 * Only the getters that read the exclusions resolve them: a custom or
 * `retrieved` audit never does.
 */
class AuditExclusionsResolutionTest extends TestCase
{
    private function countingArticle(): Article
    {
        $article = new class extends Article {
            protected ?string $table = 'articles';

            public int $resolutions = 0;

            protected function resolveAuditExclusions(): void
            {
                ++$this->resolutions;

                parent::resolveAuditExclusions();
            }
        };

        $article->forceFill(['title' => 'V1', 'content' => 'body'])->saveQuietly();

        return $article;
    }

    public function testAManualAuditResolvesNoExclusions(): void
    {
        $article = $this->countingArticle();

        Auditor::on($article)->as('exported')->with(['format' => 'pdf'])->log();

        $this->assertSame(0, $article->resolutions);
    }

    public function testARetrievedAuditResolvesNoExclusions(): void
    {
        Config::set('auditing.events', ['created', 'updated', 'deleted', 'restored', 'retrieved']);

        $article = $this->countingArticle();

        $article->setAuditEvent('retrieved');
        $article->toAudit();

        $this->assertSame(0, $article->resolutions);
    }

    public function testAnUpdatedAuditResolvesExclusionsOnce(): void
    {
        $article = $this->countingArticle();

        $article->setAuditEvent('updated');
        $article->title = 'V2';
        $article->toAudit();

        $this->assertSame(1, $article->resolutions);
    }
}
