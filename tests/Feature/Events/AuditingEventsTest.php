<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Tests\Feature\Events;

use Hypervel\Support\Facades\Config;
use Hypervel\Support\Facades\Event;
use Ipsocode\Auditing\Events\Audited;
use Ipsocode\Auditing\Events\Auditing;
use Ipsocode\Auditing\Tests\TestCase;
use Workbench\App\Models\Article;

class AuditingEventsTest extends TestCase
{
    private function makeArticle(): Article
    {
        return Article::factory()->create();
    }

    public function testAuditingAndAuditedEventsFireAroundAnAudit(): void
    {
        $auditing = 0;
        $audited = 0;

        Event::listen(Auditing::class, function () use (&$auditing) {
            ++$auditing;

            return null; // do not halt
        });
        Event::listen(Audited::class, function () use (&$audited) {
            ++$audited;
        });

        $article = $this->makeArticle();

        $this->assertSame(1, $auditing);
        $this->assertSame(1, $audited);
        $this->assertSame(1, $article->audits()->count());
    }

    public function testReturningFalseFromAuditingHaltsTheAudit(): void
    {
        Event::listen(Auditing::class, fn () => false);

        $article = $this->makeArticle();

        $this->assertSame(0, $article->audits()->count());
    }

    public function testEmptyValuesGuardSkipsEmptyAudits(): void
    {
        Config::set('auditing.events', ['created', 'updated', 'deleted', 'restored', 'retrieved']);
        Config::set('auditing.allowed_empty_values', []);
        Config::set('auditing.empty_values', false);

        $article = $this->makeArticle();

        // Retrieval produces an empty (no old/new) audit, which the guard drops.
        Article::findOrFail($article->getKey());

        $this->assertSame(0, $article->audits()->where('event', 'retrieved')->count());

        Config::set('auditing.empty_values', true);
        Article::findOrFail($article->getKey());

        $this->assertSame(1, $article->audits()->where('event', 'retrieved')->count());
    }
}
