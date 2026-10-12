<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Tests\Unit\Support;

use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Foundation\Testing\Attributes\UnitTest;
use Ipsocode\Auditing\Support\AuditCauser;
use Ipsocode\Auditing\Support\ContextKeys;
use Ipsocode\Auditing\Tests\Unit\TestCase;
use Mockery;
use RuntimeException;

/**
 * The open causer scope is coroutine-scoped state, so nesting, restoring and
 * surviving a throw are the whole of the class.
 */
class AuditCauserTest extends TestCase
{
    protected function tearDown(): void
    {
        CoroutineContext::forget(ContextKeys::CAUSER);

        parent::tearDown();
    }

    private function user(): Authenticatable
    {
        return Mockery::mock(Authenticatable::class);
    }

    #[UnitTest]
    public function testThereIsNoScopeOutsideOfActing(): void
    {
        $this->assertFalse(AuditCauser::isScoped());
        $this->assertNull(AuditCauser::current());
    }

    #[UnitTest]
    public function testActingOpensAScopeReturnsTheResultAndClosesIt(): void
    {
        $user = $this->user();

        $result = AuditCauser::acting($user, fn () => [AuditCauser::isScoped(), AuditCauser::current()]);

        $this->assertSame([true, $user], $result);
        $this->assertFalse(AuditCauser::isScoped());
    }

    #[UnitTest]
    public function testANullScopeIsDistinctFromNoScope(): void
    {
        $inside = AuditCauser::acting(null, fn () => [AuditCauser::isScoped(), AuditCauser::current()]);

        $this->assertSame([true, null], $inside);
    }

    #[UnitTest]
    public function testAnInnerScopeReplacesTheCauserAndTheOuterOneComesBack(): void
    {
        $outer = $this->user();
        $inner = $this->user();

        $seen = AuditCauser::acting($outer, function () use ($inner) {
            $during = AuditCauser::acting($inner, fn () => AuditCauser::current());

            return [$during, AuditCauser::current()];
        });

        $this->assertSame([$inner, $outer], $seen);
    }

    #[UnitTest]
    public function testTheOuterScopeComesBackWhenTheInnerCallbackThrows(): void
    {
        $outer = $this->user();

        $seen = AuditCauser::acting($outer, function () {
            try {
                AuditCauser::acting($this->user(), fn () => throw new RuntimeException('boom'));
            } catch (RuntimeException) {
            }

            return AuditCauser::current();
        });

        $this->assertSame($outer, $seen);
        $this->assertFalse(AuditCauser::isScoped());
    }

    #[UnitTest]
    public function testAMalformedContextValueIsNoScope(): void
    {
        CoroutineContext::set(ContextKeys::CAUSER, 'nope');

        $this->assertFalse(AuditCauser::isScoped());
    }
}
