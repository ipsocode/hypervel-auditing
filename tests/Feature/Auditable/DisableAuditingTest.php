<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Tests\Feature\Auditable;

use Hypervel\Coroutine\Coroutine;
use Hypervel\Support\Facades\Concurrency;
use Ipsocode\Auditing\Tests\TestCase;
use RuntimeException;
use Workbench\App\Models\Article;
use Workbench\App\Models\AuditableUser;

use function Hypervel\Coroutine\parallel;

class DisableAuditingTest extends TestCase
{
    private function makeArticle(): Article
    {
        return Article::factory()->create();
    }

    public function testDisableAuditingPreventsAudits(): void
    {
        Article::disableAuditing();

        $this->assertTrue(Article::isAuditingDisabled());

        $article = $this->makeArticle();

        $this->assertSame(0, $article->audits()->count());
    }

    public function testEnableAuditingResumesAudits(): void
    {
        Article::disableAuditing();
        Article::enableAuditing();

        $this->assertFalse(Article::isAuditingDisabled());

        $article = $this->makeArticle();

        $this->assertSame(1, $article->audits()->count());
    }

    public function testWithoutAuditingSuppressesThenRestores(): void
    {
        $article = Article::withoutAuditing(fn () => $this->makeArticle());

        $this->assertSame(0, $article->audits()->count());
        $this->assertFalse(Article::isAuditingDisabled());
    }

    public function testWithoutAuditingGloballySuppressesUnrelatedModelsToo(): void
    {
        $user = null;

        Article::withoutAuditing(function () use (&$user) {
            $this->assertTrue(Article::isAuditingDisabled());

            // A model that never opened a scope of its own is covered by the
            // global variant — that is the whole difference from the default.
            $user = AuditableUser::factory()->create();
        }, true);

        $this->assertSame(0, $user->audits()->count());
        $this->assertFalse(Article::isAuditingDisabled());
        $this->assertFalse(AuditableUser::isAuditingDisabled());
    }

    public function testWithoutAuditingWithoutGloballyLeavesOtherModelsAudited(): void
    {
        $user = null;

        Article::withoutAuditing(function () use (&$user) {
            $user = AuditableUser::factory()->create();
        });

        $this->assertSame(1, $user->audits()->count());
    }

    public function testNestedScopeDoesNotCancelAnOuterGlobalDisable(): void
    {
        $user = null;

        Article::withoutAuditing(function () use (&$user) {
            // Inner scope is non-global; leaving it must not re-enable auditing
            // for the models the outer global scope covers.
            Article::withoutAuditing(fn () => null);

            $user = AuditableUser::factory()->create();
        }, true);

        $this->assertSame(0, $user->audits()->count());
    }

    public function testScopeIsRestoredWhenTheCallbackThrows(): void
    {
        try {
            Article::withoutAuditing(function () {
                throw new RuntimeException('boom');
            });
        } catch (RuntimeException) {
        }

        $this->assertFalse(Article::isAuditingDisabled());
        $this->assertSame(1, $this->makeArticle()->audits()->count());
    }

    public function testWithoutAuditingIsScopedToTheCurrentCoroutine(): void
    {
        // A withoutAuditing() scope belongs to its coroutine: a concurrent
        // request on the same worker keeps auditing while the scope is open.
        $inner = null;

        parallel([
            function () {
                Article::withoutAuditing(function () {
                    // Hold the scope open long enough for the sibling coroutine
                    // to run entirely inside it.
                    Coroutine::sleep(0.05);
                });
            },
            function () use (&$inner) {
                Coroutine::sleep(0.01);
                $inner = $this->makeArticle();
            },
        ]);

        $this->assertSame(1, $inner->audits()->count());
    }

    public function testAChildCoroutineInheritsTheScopeOnlyWhenItCopiesContext(): void
    {
        $fresh = $copied = $concurrent = null;

        Article::withoutAuditing(function () use (&$fresh, &$copied, &$concurrent) {
            // A child starts with a fresh context unless told to copy it.
            parallel([function () use (&$fresh) {
                $fresh = $this->makeArticle();
            }]);

            parallel([function () use (&$copied) {
                $copied = $this->makeArticle();
            }], copyContext: true);

            // The Concurrency facade's coroutine driver copies it by default.
            [$concurrent] = Concurrency::run([fn () => $this->makeArticle()]);
        });

        $this->assertSame(1, $fresh->audits()->count());
        $this->assertSame(0, $copied->audits()->count());
        $this->assertSame(0, $concurrent->audits()->count());
    }
}
