<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Tests\Unit\Redactors;

use Hypervel\Foundation\Testing\Attributes\UnitTest;
use Ipsocode\Auditing\Redactors\LeftRedactor;
use Ipsocode\Auditing\Redactors\RightRedactor;
use Ipsocode\Auditing\Tests\Unit\TestCase;

class RightRedactorTest extends TestCase
{
    #[UnitTest]
    public function testItKeepsTheLeadingTenthAndMasksTheRest(): void
    {
        // 14 characters, so a tenth rounds up to 2 kept characters.
        $this->assertSame('He############', RightRedactor::redact('Hello world!!!'));
    }

    #[UnitTest]
    public function testItPreservesTheOriginalLength(): void
    {
        $this->assertSame(20, strlen(RightRedactor::redact(str_repeat('a', 20))));
    }

    #[UnitTest]
    public function testItStillMasksASingleCharacter(): void
    {
        $this->assertSame('#', RightRedactor::redact('a'));
    }

    #[UnitTest]
    public function testItReturnsAnEmptyStringForAnEmptyValue(): void
    {
        $this->assertSame('', RightRedactor::redact(''));
    }

    #[UnitTest]
    public function testItCoercesNonStringValues(): void
    {
        $this->assertSame('1####', RightRedactor::redact(12345));
        $this->assertSame('#', RightRedactor::redact(true));
    }

    #[UnitTest]
    public function testItMasksTheOppositeEndFromTheLeftRedactor(): void
    {
        // The pair differ only in which end survives, so a copy-paste slip
        // between the two fails here.
        $value = 'correct horse battery';

        $this->assertSame('cor##################', RightRedactor::redact($value));
        $this->assertSame('##################ery', LeftRedactor::redact($value));
    }
}
