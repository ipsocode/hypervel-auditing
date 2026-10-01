<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Tests\Feature\Listeners;

use Hypervel\Support\Facades\Config;
use Ipsocode\Auditing\Tests\TestCase;
use Workbench\App\Models\Article;
use Workbench\App\Models\Category;

/**
 * `auditing.enabled` is a runtime switch: `AuditingServiceProvider` registers
 * `RecordCustomAudit` and `ProcessDispatchAudit` unconditionally and both read
 * the config when they run, so toggling it after boot takes effect.
 */
class RuntimeEnabledToggleTest extends TestCase
{
    public function testRecordCustomAuditRespectsTheSwitchBeingToggledAfterBoot(): void
    {
        $article = Article::factory()->create();
        $categories = Category::factory()->count(2)->create();

        Config::set('auditing.enabled', false);

        $article->auditAttach('categories', $categories[0]->getKey());

        $this->assertSame(0, $article->audits()->where('event', 'attach')->count());

        Config::set('auditing.enabled', true);

        $article->auditAttach('categories', $categories[1]->getKey());

        $this->assertSame(1, $article->audits()->where('event', 'attach')->count());
    }

    public function testProcessDispatchAuditRespectsTheSwitchBeingToggledAfterBoot(): void
    {
        Config::set('auditing.queue.enable', true);

        $article = Article::factory()->create(['title' => 'V1']);

        Config::set('auditing.enabled', false);

        $article->update(['title' => 'V2']);

        $this->assertSame(0, $article->audits()->where('event', 'updated')->count());

        Config::set('auditing.enabled', true);

        $article->update(['title' => 'V3']);

        $this->assertSame(1, $article->audits()->where('event', 'updated')->count());
    }
}
