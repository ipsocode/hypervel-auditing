<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Tests\Unit\Support;

use Hypervel\Context\CoroutineContext;
use Hypervel\Foundation\Testing\Attributes\UnitTest;
use InvalidArgumentException;
use Ipsocode\Auditing\Support\AuditBatch;
use Ipsocode\Auditing\Support\ContextKeys;
use Ipsocode\Auditing\Tests\Unit\TestCase;
use RuntimeException;

/**
 * The open batch is coroutine-scoped state, so its scoping rules — nesting,
 * restoring, and surviving a throw — are the whole of the class.
 */
class AuditBatchTest extends TestCase
{
    protected function tearDown(): void
    {
        CoroutineContext::forget(ContextKeys::BATCH);

        parent::tearDown();
    }

    #[UnitTest]
    public function testThereIsNoBatchOutsideOfWithin(): void
    {
        $this->assertNull(AuditBatch::current());
    }

    #[UnitTest]
    public function testWithinOpensAGeneratedBatchAndClosesItAgain(): void
    {
        $inside = AuditBatch::within(fn () => AuditBatch::current());

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            (string) $inside
        );
        $this->assertNull(AuditBatch::current());
    }

    #[UnitTest]
    public function testWithinReturnsTheCallbackResult(): void
    {
        $this->assertSame('done', AuditBatch::within(fn () => 'done'));
    }

    #[UnitTest]
    public function testAnExplicitIdIsUsedAsGiven(): void
    {
        $uuid = '0197c0de-0000-7000-8000-000000000042';

        $this->assertSame($uuid, AuditBatch::within(fn () => AuditBatch::current(), $uuid));
    }

    #[UnitTest]
    public function testAnExplicitIdThatIsNotAUuidIsRejectedBeforeTheCallbackRuns(): void
    {
        $ran = false;

        try {
            AuditBatch::within(function () use (&$ran) {
                $ran = true;
            }, 'request-42');

            $this->fail('A non-UUID batch id should have been rejected.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('request-42', $e->getMessage());
        }

        $this->assertFalse($ran);
        $this->assertNull(AuditBatch::current());
    }

    #[UnitTest]
    public function testANestedCallJoinsTheEnclosingBatch(): void
    {
        [$outer, $inner] = AuditBatch::within(function () {
            $outer = AuditBatch::current();

            return [$outer, AuditBatch::within(fn () => AuditBatch::current())];
        });

        $this->assertSame($outer, $inner);
    }

    #[UnitTest]
    public function testANestedCallWithItsOwnIdRestoresTheEnclosingBatch(): void
    {
        $seen = AuditBatch::within(function () {
            AuditBatch::within(fn () => null, '0197c0de-0000-7000-8000-00000000000a');

            return AuditBatch::current();
        }, '0197c0de-0000-7000-8000-00000000000b');

        $this->assertSame('0197c0de-0000-7000-8000-00000000000b', $seen);
    }

    #[UnitTest]
    public function testTheBatchIsClosedWhenTheCallbackThrows(): void
    {
        try {
            AuditBatch::within(function () {
                throw new RuntimeException('boom');
            });
        } catch (RuntimeException) {
        }

        $this->assertNull(AuditBatch::current());
    }

    #[UnitTest]
    public function testAnEmptyBatchIdReadsAsNoBatch(): void
    {
        // Nothing generates one, but the context is public storage and a
        // consumer clearing it by writing "" must not stamp empty strings onto
        // every audit that follows.
        CoroutineContext::set(ContextKeys::BATCH, '');

        $this->assertNull(AuditBatch::current());
    }
}
