<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Tests\Feature\Auditable;

use Hypervel\Support\Facades\Config;
use Ipsocode\Auditing\Exceptions\AuditingException;
use Ipsocode\Auditing\Tests\TestCase;
use Workbench\App\Models\Article;
use Workbench\App\Models\CustomEventArticle;

/**
 * `$auditEvents` is the documented way to remap which Eloquent events are
 * audited and which method supplies the attributes for each.
 */
class AuditEventMappingTest extends TestCase
{
    public function testAStringKeyRoutesTheEventToTheNamedGetter(): void
    {
        $article = CustomEventArticle::create([
            'title' => 'Headline',
            'content' => 'Body',
            'reviewed' => false,
        ]);

        $audit = $article->audits()->where('event', 'created')->sole();

        // getCreatedHeadlineAttributes() records the title only.
        $this->assertSame(['title' => 'Headline'], $audit->new_values);
    }

    public function testAWildcardEntryMatchesEveryEventItCovers(): void
    {
        $article = CustomEventArticle::create([
            'title' => 'V1',
            'content' => 'Body',
            'reviewed' => false,
        ]);

        // '*ted' covers updated and deleted through the conventional getters.
        $article->update(['title' => 'V2']);
        $article->delete();

        $this->assertSame(['title' => 'V1'], $article->audits()->where('event', 'updated')->sole()->old_values);
        $this->assertSame('V2', $article->audits()->where('event', 'deleted')->sole()->old_values['title']);
    }

    public function testEventPatternsMatchExactlyWhatTheUnanchoredRegexMatches(): void
    {
        $article = new class extends Article {
            protected ?string $table = 'articles';

            public array $auditEvents = [];
        };

        // Plain names take a substring fast path instead of the regex; both
        // paths must give the regex's answer.
        $cases = [
            ['updated', 'updated'],
            ['created', 'updated'],
            ['date', 'updated'],
            ['updated', 'reupdated'],
            ['up.ated', 'updated'],
            ['up.ated', 'up-ated'],
            ['*ted', 'deleted'],
            ['*ted', 'restored'],
            ['^retrieved$', 'retrieved'],
        ];

        foreach ($cases as [$pattern, $event]) {
            $article->auditEvents = [$pattern];

            $this->assertSame(
                (bool) preg_match(sprintf('/%s/', preg_replace('/\*+/', '.*', $pattern)), $event),
                $article->setAuditEvent($event)->getAuditEvent() !== null,
                "pattern [{$pattern}] against event [{$event}]"
            );
        }
    }

    public function testAnEventOutsideTheConfiguredListIsNotAudited(): void
    {
        Config::set('auditing.events', ['created']);

        $article = Article::factory()->create();
        $article->update(['title' => 'V2']);

        $this->assertSame(['created'], $article->audits()->pluck('event')->all());
    }

    public function testSettingAnUnauditableEventLeavesTheEventUnset(): void
    {
        $article = new Article(['title' => 'T', 'content' => 'B', 'reviewed' => false]);

        $article->setAuditEvent('exploded');

        $this->assertNull($article->getAuditEvent());
        $this->assertFalse($article->readyForAuditing());
    }

    public function testToAuditRefusesToRunWithoutAValidEvent(): void
    {
        $article = new Article(['title' => 'T', 'content' => 'B', 'reviewed' => false]);

        $this->expectException(AuditingException::class);
        $this->expectExceptionMessage('A valid audit event has not been set');

        $article->toAudit();
    }

    public function testAnEventWithNoMatchingGetterIsReported(): void
    {
        $article = new class extends Article {
            protected ?string $table = 'articles';

            protected array $auditEvents = ['exploded'];
        };

        $article->setAuditEvent('exploded');

        $this->expectException(AuditingException::class);
        $this->expectExceptionMessage('getExplodedEventAttributes() method missing');

        $article->toAudit();
    }
}
