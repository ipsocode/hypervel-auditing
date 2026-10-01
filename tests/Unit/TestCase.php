<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Tests\Unit;

use Hypervel\Testbench\TestCase as BaseTestCase;

/**
 * Base for tests of pure logic: the redactors, the encoder, value normalization,
 * the exceptions. Every method carries `#[UnitTest]`, so no Testbench
 * application is booted and anything that reaches for a facade or the container
 * fails outright.
 */
abstract class TestCase extends BaseTestCase
{
}
