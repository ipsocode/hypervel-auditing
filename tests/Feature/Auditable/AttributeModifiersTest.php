<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Tests\Feature\Auditable;

use Ipsocode\Auditing\Exceptions\AuditingException;
use Ipsocode\Auditing\Redactors\RightRedactor;
use Ipsocode\Auditing\Tests\TestCase;
use Workbench\App\Models\BadModifierArticle;
use Workbench\App\Models\EncodedArticle;
use Workbench\App\Models\RedactedArticle;

class AttributeModifiersTest extends TestCase
{
    public function testRedactorRewritesTheStoredValue(): void
    {
        $content = 'Hello world';

        $article = RedactedArticle::create([
            'title' => 'Title',
            'content' => $content,
            'reviewed' => false,
        ]);

        $detail = $article->audits()
            ->where('event', 'created')
            ->sole()
            ->details()
            ->where('field', 'content')
            ->sole();

        $this->assertSame(RightRedactor::redact($content), $detail->new_value);
        $this->assertNotSame($content, $detail->new_value);
    }

    public function testEncoderStoresEncodedValueAndDecodesOnRead(): void
    {
        $article = EncodedArticle::create([
            'title' => 'Title',
            'content' => 'Original content',
            'reviewed' => false,
        ]);

        $article->update(['content' => 'Updated content']);

        $audit = $article->audits()->where('event', 'updated')->sole();

        $detail = $audit->details()->where('field', 'content')->sole();

        $this->assertSame(base64_encode('Updated content'), $detail->new_value);
        $this->assertSame(base64_encode('Original content'), $detail->old_value);

        $this->assertSame([
            'new' => 'Updated content',
            'old' => 'Original content',
        ], $audit->getModified()['content']);
    }

    public function testCreatedAuditReportsNoOldSideForEncodedAttributes(): void
    {
        // A created event has no old side; the new side still decodes.
        $article = EncodedArticle::create([
            'title' => 'Title',
            'content' => 'Body',
            'reviewed' => false,
        ]);

        $modified = $article->audits()->where('event', 'created')->sole()->getModified();

        $this->assertSame(['new' => 'Body'], $modified['content']);
        $this->assertArrayNotHasKey('old', $modified['content']);
    }

    public function testInvalidAttributeModifierThrows(): void
    {
        $this->expectException(AuditingException::class);

        BadModifierArticle::create([
            'title' => 'Title',
            'content' => 'Body',
            'reviewed' => false,
        ]);
    }
}
