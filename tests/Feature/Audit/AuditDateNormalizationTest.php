<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Tests\Feature\Audit;

use Ipsocode\Auditing\Models\AuditDetail;
use Ipsocode\Auditing\Tests\TestCase;
use Workbench\App\Models\Article;
use Workbench\App\Models\CustomDateFormatArticle;

/**
 * `audit_details` stores every value as text, so a datetime comes back in
 * whatever shape was written: by this package, into an adopted table, or by an
 * import. The Audit trait pins known shapes to UTC before the auditable's cast
 * runs, and stays readable when it recognises none of them.
 */
class AuditDateNormalizationTest extends TestCase
{
    public function testANullSideIsLeftAloneRatherThanParsed(): void
    {
        $article = Article::factory()->create();

        $article->update(['published_at' => '2026-01-02 03:04:05']);

        $modified = $article->audits()->where('event', 'updated')->sole()->getModified();

        $this->assertNull($modified['published_at']['old']);
        $this->assertSame('2026-01-02T03:04:05.000000Z', $modified['published_at']['new']);
    }

    public function testADateOnlyValueIsPinnedToTheStartOfTheDay(): void
    {
        $article = Article::factory()->create(['published_at' => '2026-01-02 03:04:05']);

        $article->update(['published_at' => '2026-02-03 04:05:06']);

        $modified = $this->modifiedAfterRewriting($article, 'old_value', '2026-03-04');

        // Without startOfDay() a date-only value would inherit the current
        // wall-clock time from Carbon::createFromFormat().
        $this->assertSame('2026-03-04T00:00:00.000000Z', $modified['published_at']['old']);
    }

    public function testAValueInTheModelsOwnDateFormatIsReadWithThatFormat(): void
    {
        $article = $this->customFormatArticle();

        $modified = $this->modifiedAfterRewriting($article, 'old_value', '05/06/2026 07:08:09');

        // `d/m/Y H:i:s`, so the leading 05 is the day: 5 June, not 6 May.
        $this->assertSame('2026-06-05T07:08:09.000000Z', $modified['published_at']['old']);
    }

    public function testAValueMatchingNoKnownFormatIsHandedBackUntouched(): void
    {
        $article = $this->customFormatArticle();

        // An ISO-8601 value with a `T` separator matches neither recognised
        // shape nor `d/m/Y H:i:s`. Parsing it against the model's format throws;
        // the trait swallows that so one odd row cannot fail getModified().
        $modified = $this->modifiedAfterRewriting($article, 'old_value', '2026-06-05T07:08:09+00:00');

        $this->assertSame('2026-06-05T07:08:09.000000Z', $modified['published_at']['old']);
    }

    /**
     * An article with a `d/m/Y H:i:s` date format and one recorded update.
     */
    private function customFormatArticle(): CustomDateFormatArticle
    {
        $article = CustomDateFormatArticle::create([
            'title' => 'Title',
            'content' => 'Body',
            'published_at' => '2026-01-02 03:04:05',
        ]);

        $article->update(['published_at' => '2026-02-03 04:05:06']);

        return $article;
    }

    /**
     * Overwrite one side of the recorded `published_at` change with a shape
     * Eloquent never writes but an adopted table or an import can hold, and
     * re-read the audit so its details are not stale.
     *
     * @return array<string,array<string,mixed>>
     */
    private function modifiedAfterRewriting(Article $article, string $column, string $value): array
    {
        $audit = $article->audits()->where('event', 'updated')->sole();

        AuditDetail::query()
            ->where('audit_id', $audit->getKey())
            ->where('field', 'published_at')
            ->update([$column => $value]);

        return $article->audits()->where('event', 'updated')->sole()->getModified();
    }
}
