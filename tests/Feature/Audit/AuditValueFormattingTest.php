<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Tests\Feature\Audit;

use Hypervel\Database\Eloquent\Casts\ArrayObject;
use Ipsocode\Auditing\Tests\TestCase;
use Workbench\App\Models\ArrayObjectArticle;
use Workbench\App\Models\MutatorArticle;

/**
 * Detail rows hold the raw column values, so reading an audit has to put each
 * one back through the auditable's own read side — accessors of both flavours,
 * and the AsArrayObject cast, which stores JSON the cast cannot be handed.
 */
class AuditValueFormattingTest extends TestCase
{
    public function testClassicGetMutatorIsAppliedToBothSides(): void
    {
        $article = MutatorArticle::create([
            'title' => 'First',
            'content' => 'Body',
            'reviewed' => false,
        ]);

        $article->update(['title' => 'Second']);

        $audit = $article->audits()->where('event', 'updated')->sole();

        $detail = $audit->details()->where('field', 'title')->sole();
        $this->assertSame('First', $detail->old_value);
        $this->assertSame('Second', $detail->new_value);

        $this->assertSame([
            'new' => 'mutated:Second',
            'old' => 'mutated:First',
        ], $audit->getModified()['title']);
    }

    public function testAttributeObjectMutatorIsAppliedToBothSides(): void
    {
        $article = MutatorArticle::create([
            'title' => 'Title',
            'content' => 'Original',
            'reviewed' => false,
        ]);

        $article->update(['content' => 'Updated']);

        $audit = $article->audits()->where('event', 'updated')->sole();

        $this->assertSame([
            'new' => 'attr:Updated',
            'old' => 'attr:Original',
        ], $audit->getModified()['content']);
    }

    public function testCreatedAuditFormatsTheNewSideThroughTheMutators(): void
    {
        $article = MutatorArticle::create([
            'title' => 'Title',
            'content' => 'Body',
            'reviewed' => false,
        ]);

        $modified = $article->audits()->where('event', 'created')->sole()->getModified();

        $this->assertSame(['new' => 'mutated:Title'], $modified['title']);
        $this->assertSame(['new' => 'attr:Body'], $modified['content']);
    }

    public function testArrayObjectCastRebuildsTheRecordedJson(): void
    {
        $article = ArrayObjectArticle::create([
            'title' => 'Title',
            'content' => ['body' => 'v1', 'words' => 2],
            'reviewed' => false,
        ]);

        $article->update(['content' => ['body' => 'v2', 'words' => 3]]);

        $audit = $article->audits()->where('event', 'updated')->sole();

        $detail = $audit->details()->where('field', 'content')->sole();
        $this->assertSame('{"body":"v2","words":3}', $detail->new_value);

        $modified = $audit->getModified();

        $this->assertInstanceOf(ArrayObject::class, $modified['content']['new']);
        $this->assertSame(['body' => 'v2', 'words' => 3], $modified['content']['new']->toArray());
        $this->assertSame(['body' => 'v1', 'words' => 2], $modified['content']['old']->toArray());
    }

    public function testArrayObjectCastYieldsAnEmptyArrayObjectForUndecodableJson(): void
    {
        $article = ArrayObjectArticle::create([
            'title' => 'Title',
            'content' => ['body' => 'v1'],
            'reviewed' => false,
        ]);

        // A row written before the cast existed holds a bare string, which
        // json_decode() cannot turn into an array.
        $article->audits()->where('event', 'created')->sole()
            ->details()->where('field', 'content')->sole()
            ->update(['new_value' => 'plain text']);

        $audit = $article->audits()->where('event', 'created')->sole()->fresh();

        $content = $audit->getModified()['content']['new'];

        $this->assertInstanceOf(ArrayObject::class, $content);
        $this->assertSame([], $content->toArray());
    }
}
