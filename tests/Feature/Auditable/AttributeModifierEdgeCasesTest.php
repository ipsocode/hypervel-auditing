<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Tests\Feature\Auditable;

use Ipsocode\Auditing\Tests\TestCase;
use Workbench\App\Models\NullableModifierArticle;

/**
 * Modifiers receive raw attribute values, so they see nulls and non-strings —
 * and they run inside the model's save path, where a TypeError does not merely
 * spoil the audit, it aborts the write the application asked for.
 */
class AttributeModifierEdgeCasesTest extends TestCase
{
    public function testCreatingAModelWithANullModifiedAttributeStillPersists(): void
    {
        $article = NullableModifierArticle::create([
            'title' => 'Title',
            'content' => 'Body',
            'published_at' => null,
        ]);

        $this->assertTrue($article->exists);
        $this->assertDatabaseHas('articles', ['id' => $article->getKey(), 'title' => 'Title']);
    }

    public function testANullModifiedAttributeIsRecordedAsNullRatherThanRedacted(): void
    {
        $article = NullableModifierArticle::create([
            'title' => 'Title',
            'content' => 'Body',
            'published_at' => null,
        ]);

        $values = $article->audits()->sole()->details->pluck('new_value', 'field');

        $this->assertNull($values['published_at']);
        // The non-null attribute is still modified as configured.
        $this->assertSame(base64_encode('Body'), $values['content']);
    }

    public function testUpdatingFromANullValueRecordsBothSides(): void
    {
        $article = NullableModifierArticle::create([
            'title' => 'Title',
            'content' => 'Body',
            'published_at' => null,
        ]);

        $article->update(['published_at' => '2026-01-02 03:04:05']);

        $detail = $article->audits()->where('event', 'updated')->sole()
            ->details->firstWhere('field', 'published_at');

        $this->assertNull($detail->old_value);
        // '2026-01-02 03:04:05' is 19 characters; a tenth rounds up to 2.
        $this->assertSame('#################05', $detail->new_value);
    }
}
