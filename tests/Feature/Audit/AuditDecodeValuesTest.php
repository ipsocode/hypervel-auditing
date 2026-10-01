<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Tests\Feature\Audit;

use Hypervel\Support\Carbon;
use Ipsocode\Auditing\Redactors\RightRedactor;
use Ipsocode\Auditing\Tests\TestCase;
use Workbench\App\Models\NullableEncodedArticle;
use Workbench\App\Models\RedactedArticle;

/**
 * Reading an Audit back runs every recorded value through the auditable's
 * attribute modifiers in reverse. Only encoders can be reversed, and only a
 * value that is actually there.
 */
class AuditDecodeValuesTest extends TestCase
{
    public function testANullRecordedValueIsReportedAsNullRatherThanDecoded(): void
    {
        $article = NullableEncodedArticle::create([
            'title' => 'Title',
            'content' => 'Body',
            'published_at' => null,
        ]);

        $article->update(['published_at' => '2026-01-02 03:04:05']);

        $modified = $article->audits()->where('event', 'updated')->sole()->getModified();

        $this->assertNull($modified['published_at']['old']);
        // The encoder really is mapped onto this attribute: the new side was
        // stored Base64-encoded and comes back decoded and cast.
        $this->assertSame(
            '2026-01-02 03:04:05',
            Carbon::parse($modified['published_at']['new'])->format('Y-m-d H:i:s')
        );
    }

    public function testRedactedValuesAreReportedExactlyAsStored(): void
    {
        $article = RedactedArticle::create([
            'title' => 'Title',
            'content' => 'Original body',
        ]);

        $article->update(['content' => 'Hello world!!!']);

        $audit = $article->audits()->where('event', 'updated')->sole();

        // Redaction is one-way, so both sides survive the read untouched.
        $this->assertSame([
            'new' => RightRedactor::redact('Hello world!!!'),
            'old' => RightRedactor::redact('Original body'),
        ], $audit->getModified()['content']);

        // An unredacted round trip cannot satisfy this: the audit never held the
        // plain text in the first place.
        $this->assertSame('He############', $audit->getModified()['content']['new']);
    }
}
