<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Tests\Feature\Testing;

use Hypervel\Context\CoroutineContext;
use Ipsocode\Auditing\Models\Audit;
use Ipsocode\Auditing\Support\ContextKeys;
use Ipsocode\Auditing\Testing\TestState;
use Ipsocode\Auditing\Tests\TestCase;
use WeakMap;
use Workbench\App\Models\Article;

/**
 * The package's worker-lifetime state is reset between tests through
 * `extra.hypervel.test-state`, which consuming applications pick up too. If it
 * stops working, one test disabling auditing silently disables it for every
 * later test in the same worker.
 */
class TestStateTest extends TestCase
{
    public function testFlushStateReEnablesAuditingEverywhere(): void
    {
        // Assigned directly, as the public flags allow: flushState() has to
        // reset them however they were set.
        Article::$auditingDisabled = true;
        Audit::$auditingGloballyDisabled = true;

        TestState::flushState();

        $this->assertFalse(Article::$auditingDisabled);
        $this->assertFalse(Audit::$auditingGloballyDisabled);
        $this->assertFalse(Article::isAuditingDisabled());
    }

    public function testFlushStateClearsAScopedDisableLeftInContext(): void
    {
        CoroutineContext::set(ContextKeys::disabledFor(Article::class), true);
        CoroutineContext::set(ContextKeys::DISABLED_GLOBALLY, true);

        $this->assertTrue(Article::isAuditingDisabled());

        TestState::flushState();

        $this->assertFalse(Article::isAuditingDisabled());
    }

    public function testFlushStateClearsInFlightRestoreMarkers(): void
    {
        CoroutineContext::set(ContextKeys::RESTORING, new WeakMap);

        TestState::flushState();

        $this->assertFalse(CoroutineContext::has(ContextKeys::RESTORING));
    }

    public function testAuditingWorksAgainAfterAFlush(): void
    {
        Article::$auditingDisabled = true;

        TestState::flushState();

        $article = Article::factory()->create();

        $this->assertSame(1, $article->audits()->count());
    }

    public function testThePackageStillAdvertisesItsTestStateRegistrar(): void
    {
        // Losing this key means consuming applications stop getting the cleanup,
        // with nothing in this package's own suite to notice.
        $composer = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/composer.json'), true);

        $this->assertContains(TestState::class, $composer['extra']['hypervel']['test-state']);
    }
}
