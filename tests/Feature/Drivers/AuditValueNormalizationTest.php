<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Tests\Feature\Drivers;

use Hypervel\Support\Facades\Config;
use Ipsocode\Auditing\Tests\TestCase;
use stdClass;
use Workbench\App\Models\Article;
use Workbench\App\Support\Money;

/**
 * The end-to-end half of value normalization: which attributes survive
 * resolveAuditExclusions() at all, and what a surviving value looks like once it
 * has been through the driver. The reduction itself is unit-tested in
 * {@see \Ipsocode\Auditing\Tests\Unit\Drivers\NormalizeValueTest}.
 */
class AuditValueNormalizationTest extends TestCase
{
    public function testAStringableAttributeIsRecordedEndToEnd(): void
    {
        $article = Article::factory()->create();

        $article->title = new Money('12.50', 'USD');
        $article->save();

        $detail = $article->audits()->where('event', 'updated')->sole()
            ->details->firstWhere('field', 'title');

        $this->assertSame('12.50 USD', $detail->new_value);
    }

    public function testArrayAttributesAreExcludedUnlessExplicitlyAllowed(): void
    {
        $article = Article::factory()->make();

        // No cast is declared for `meta`, so the array stays an array in the
        // attribute bag — the shape `auditing.allowed_array_values` gates.
        $article->setAttribute('meta', ['a' => 1]);
        $article->setAuditEvent('created');

        $this->assertArrayNotHasKey('meta', $article->toAudit()['new_values']);

        Config::set('auditing.allowed_array_values', true);

        $this->assertSame(['a' => 1], $article->toAudit()['new_values']['meta']);
    }

    public function testObjectsWithoutAStringFormAreExcludedFromTheAudit(): void
    {
        $article = Article::factory()->make();

        $article->setAttribute('handle', new stdClass);
        $article->setAuditEvent('created');

        $this->assertArrayNotHasKey('handle', $article->toAudit()['new_values']);
    }
}
