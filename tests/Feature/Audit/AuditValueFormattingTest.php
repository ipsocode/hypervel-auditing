<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Tests\Feature\Audit;

use Hypervel\Database\Eloquent\Casts\ArrayObject;
use Hypervel\Support\Collection;
use Ipsocode\Auditing\Tests\TestCase;
use Workbench\App\Models\ArrayObjectArticle;
use Workbench\App\Models\DecodingCastArticle;
use Workbench\App\Models\MutatorArticle;

/**
 * Detail rows hold the raw column values, so reading an audit has to put each
 * one back through the auditable's own read side — accessors of both flavours
 * and casts, including those that decode the model's attributes rather than the
 * value they are handed.
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

    public function testCastsThatDecodeTheModelsAttributesFormatTheStoredValue(): void
    {
        $article = DecodingCastArticle::create([
            'title' => ['v' => 1],
            'content' => ['v' => 1],
            'reviewed' => false,
        ]);

        $article->update(['title' => ['v' => 2], 'content' => ['v' => 2]]);
        $article->update(['title' => ['v' => 3], 'content' => ['v' => 3]]);

        $modified = $article->audits()->where('event', 'updated')->orderBy('id')->first()->getModified();

        $this->assertInstanceOf(Collection::class, $modified['title']['old']);
        $this->assertSame(['v' => 1], $modified['title']['old']->all());
        $this->assertSame(['v' => 2], $modified['title']['new']->all());

        $this->assertInstanceOf(ArrayObject::class, $modified['content']['old']);
        $this->assertSame(['v' => 1], $modified['content']['old']->toArray());
        $this->assertSame(['v' => 2], $modified['content']['new']->toArray());
    }

    public function testAValueTheJsonCastCannotDecodeIsReturnedAsStored(): void
    {
        $article = ArrayObjectArticle::create([
            'title' => 'Title',
            'content' => ['body' => 'v1'],
            'reviewed' => false,
        ]);

        // A row written before the cast existed holds a bare string.
        $article->audits()->where('event', 'created')->sole()
            ->details()->where('field', 'content')->sole()
            ->update(['new_value' => 'plain text']);

        $audit = $article->audits()->where('event', 'created')->sole()->fresh();

        $this->assertSame('plain text', $audit->getModified()['content']['new']);
    }
}
