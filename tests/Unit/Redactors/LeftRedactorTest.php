<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Tests\Unit\Redactors;

use Hypervel\Foundation\Testing\Attributes\UnitTest;
use Ipsocode\Auditing\Redactors\LeftRedactor;
use Ipsocode\Auditing\Tests\Unit\TestCase;

class LeftRedactorTest extends TestCase
{
    #[UnitTest]
    public function testItKeepsTheTrailingTenthAndMasksTheRest(): void
    {
        // 14 characters, so a tenth rounds up to 2 kept characters.
        $this->assertSame('############!!', LeftRedactor::redact('Hello world!!!'));
    }

    #[UnitTest]
    public function testItPreservesTheOriginalLength(): void
    {
        $this->assertSame(20, strlen(LeftRedactor::redact(str_repeat('a', 20))));
    }

    #[UnitTest]
    public function testItStillMasksASingleCharacter(): void
    {
        // A tenth of one character rounds up to one, so the "keep a tenth" rule
        // would otherwise reveal the whole value.
        $this->assertSame('#', LeftRedactor::redact('a'));
    }

    #[UnitTest]
    public function testItReturnsAnEmptyStringForAnEmptyValue(): void
    {
        $this->assertSame('', LeftRedactor::redact(''));
    }

    #[UnitTest]
    public function testItCoercesNonStringValues(): void
    {
        // Raw attribute values reach the redactors unconverted, so an int or a
        // bool must not fatal on strlen().
        $this->assertSame('####5', LeftRedactor::redact(12345));
        $this->assertSame('#', LeftRedactor::redact(true));
    }
}
