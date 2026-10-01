<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Tests\Unit\Exceptions;

use Hypervel\Foundation\Testing\Attributes\UnitTest;
use Ipsocode\Auditing\Exceptions\AuditableTransitionException;
use Ipsocode\Auditing\Exceptions\AuditingException;
use Ipsocode\Auditing\Tests\Unit\TestCase;
use RuntimeException;

class AuditableTransitionExceptionTest extends TestCase
{
    #[UnitTest]
    public function testItCarriesTheIncompatibleAttributeNames(): void
    {
        // The attribute list is why this exception exists: a caller needs to
        // know *which* attributes the audit and the model disagree about.
        $exception = new AuditableTransitionException('Incompatibility', ['ghost', 'phantom']);

        $this->assertSame(['ghost', 'phantom'], $exception->getIncompatibilities());
        $this->assertSame('Incompatibility', $exception->getMessage());
    }

    #[UnitTest]
    public function testItDefaultsToNoIncompatibilities(): void
    {
        // The type and id mismatches throw with a message only.
        $this->assertSame([], (new AuditableTransitionException('Wrong type'))->getIncompatibilities());
    }

    #[UnitTest]
    public function testItIsCatchableAsAnAuditingException(): void
    {
        // Consumers guard the whole package with one catch block.
        $this->assertInstanceOf(AuditingException::class, new AuditableTransitionException);
    }

    #[UnitTest]
    public function testItPreservesCodeAndPreviousException(): void
    {
        $previous = new RuntimeException('root cause');

        $exception = new AuditableTransitionException('boom', [], 42, $previous);

        $this->assertSame(42, $exception->getCode());
        $this->assertSame($previous, $exception->getPrevious());
    }
}
