<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Tests\Unit\Drivers;

use Hypervel\Foundation\Testing\Attributes\UnitTest;
use Ipsocode\Auditing\Drivers\AuditDetails;
use Ipsocode\Auditing\Tests\Unit\TestCase;
use Workbench\App\Enums\Priority;
use Workbench\App\Enums\Status;
use Workbench\App\Support\Money;

/**
 * `audit_details.old_value` / `new_value` are single text columns, so every
 * attribute value has to reduce to a string — or to null, never to false, which
 * the driver's own `?string` return type forbids.
 */
class NormalizeValueTest extends TestCase
{
    private function normalize(mixed $value): ?string
    {
        return (new class extends AuditDetails {
            public function reduce(mixed $value): ?string
            {
                return $this->normalizeValue($value);
            }
        })->reduce($value);
    }

    #[UnitTest]
    public function testStringsAndNullsPassThroughUntouched(): void
    {
        $this->assertSame('hello', $this->normalize('hello'));
        $this->assertNull($this->normalize(null));
    }

    #[UnitTest]
    public function testScalarsBecomeStrings(): void
    {
        $this->assertSame('42', $this->normalize(42));
        $this->assertSame('4.5', $this->normalize(4.5));
        $this->assertSame('1', $this->normalize(true));
        $this->assertSame('', $this->normalize(false));
    }

    #[UnitTest]
    public function testBackedEnumsReduceToTheirValue(): void
    {
        $this->assertSame('high', $this->normalize(Priority::High));
    }

    #[UnitTest]
    public function testPureEnumsReduceToTheirName(): void
    {
        // json_encode() returns false for a pure enum.
        $this->assertSame('Draft', $this->normalize(Status::Draft));
    }

    #[UnitTest]
    public function testStringableObjectsUseTheirOwnStringForm(): void
    {
        // Flattening a value object to "{}" would record an audit entry that
        // says nothing about what actually changed.
        $this->assertSame('12.50 USD', $this->normalize(new Money('12.50', 'USD')));
    }

    #[UnitTest]
    public function testArraysAreStoredAsJson(): void
    {
        $this->assertSame('{"a":1}', $this->normalize(['a' => 1]));
    }

    #[UnitTest]
    public function testUnencodableValuesBecomeNullRatherThanFalse(): void
    {
        $handle = fopen('php://memory', 'r');

        try {
            $this->assertNull($this->normalize([$handle]));
        } finally {
            fclose($handle);
        }
    }
}
