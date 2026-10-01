<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Tests\Unit\Encoders;

use Hypervel\Foundation\Testing\Attributes\UnitTest;
use Ipsocode\Auditing\Encoders\Base64Encoder;
use Ipsocode\Auditing\Tests\Unit\TestCase;

class Base64EncoderTest extends TestCase
{
    #[UnitTest]
    public function testItRoundTripsAString(): void
    {
        $this->assertSame('SGVsbG8gd29ybGQ=', Base64Encoder::encode('Hello world'));
        $this->assertSame('Hello world', Base64Encoder::decode('SGVsbG8gd29ybGQ='));
    }

    #[UnitTest]
    public function testItLeavesNullAlone(): void
    {
        // base64_encode() rejects null under strict types, and a null must
        // stay null rather than become an encoded "".
        $this->assertNull(Base64Encoder::encode(null));
        $this->assertNull(Base64Encoder::decode(null));
    }

    #[UnitTest]
    public function testItCoercesNonStringValues(): void
    {
        // Numeric and boolean columns arrive unconverted.
        $this->assertSame(base64_encode('42'), Base64Encoder::encode(42));
        $this->assertSame(base64_encode('1'), Base64Encoder::encode(true));
        $this->assertSame('42', Base64Encoder::decode(Base64Encoder::encode(42)));
    }

    #[UnitTest]
    public function testItRoundTripsBinarySafely(): void
    {
        $binary = random_bytes(32);

        $this->assertSame($binary, Base64Encoder::decode(Base64Encoder::encode($binary)));
    }
}
