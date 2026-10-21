<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Tests\Feature\Drivers;

use Ipsocode\Auditing\Tests\TestCase;
use Workbench\App\Models\Article;

/**
 * A float is stored as the string that reads back as the same float, so a change
 * the default `precision` cast would flatten ('0.3' for 0.1 + 0.2) is not lost.
 */
class AuditFloatPrecisionTest extends TestCase
{
    public function testAFloatTheCastWouldRoundIsStoredAtFullPrecision(): void
    {
        $value = 0.1 + 0.2;

        $article = Article::create(['title' => 'Prices', 'content' => $value, 'reviewed' => false]);
        $article->update(['content' => 0.5]);

        $audit = $article->audits()->where('event', 'updated')->sole();

        $old = $audit->details()->where('field', 'content')->sole()->old_value;

        $this->assertSame('0.30000000000000004', $old);
        $this->assertSame($value, (float) $old);
        $this->assertNotSame('0.3', $old);
    }

    public function testAFloatTheCastKeepsIsStoredUnchanged(): void
    {
        $article = Article::create(['title' => 'Prices', 'content' => 4.5, 'reviewed' => false]);

        $audit = $article->audits()->where('event', 'created')->sole();

        $this->assertSame('4.5', $audit->details()->where('field', 'content')->sole()->new_value);
    }
}
