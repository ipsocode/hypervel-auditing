<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Tests\Feature\Modifiers;

use Hypervel\Contracts\Foundation\Application;
use Ipsocode\Auditing\Exceptions\AuditingException;
use Ipsocode\Auditing\Redactors\HashRedactor;
use Ipsocode\Auditing\Tests\TestCase;
use Workbench\App\Models\HashedArticle;

class HashRedactorTest extends TestCase
{
    private const KEY = 'plain-test-key';

    protected function defineEnvironment(Application $app): void
    {
        parent::defineEnvironment($app);

        $app->get('config')->set('app.key', self::KEY);
    }

    public function testItIsAnHmacSha256OfTheValue(): void
    {
        $hash = HashRedactor::redact('secret');

        $this->assertSame(hash_hmac('sha256', 'secret', self::KEY), $hash);
        $this->assertSame(64, strlen($hash));
    }

    public function testEqualInputsGiveEqualHashesAndDifferentInputsDifferentOnes(): void
    {
        $this->assertSame(HashRedactor::redact('a'), HashRedactor::redact('a'));
        $this->assertNotSame(HashRedactor::redact('a'), HashRedactor::redact('b'));
    }

    public function testNonStringValuesAreCastToStringFirst(): void
    {
        $this->assertSame(hash_hmac('sha256', '42', self::KEY), HashRedactor::redact(42));
        $this->assertSame(hash_hmac('sha256', '1.5', self::KEY), HashRedactor::redact(1.5));
    }

    public function testABase64KeyIsUsedAsItsDecodedBytes(): void
    {
        $this->app->get('config')->set('app.key', 'base64:' . base64_encode('raw-bytes-key'));

        $this->assertSame(hash_hmac('sha256', 'secret', 'raw-bytes-key'), HashRedactor::redact('secret'));
    }

    public function testAnEmptyKeyThrows(): void
    {
        $this->app->get('config')->set('app.key', '');

        $this->expectException(AuditingException::class);

        HashRedactor::redact('secret');
    }

    public function testTheAuditHoldsTheHashAndAChangeBackStoresTheEarlierHashAgain(): void
    {
        $article = HashedArticle::create([
            'title' => 'Title',
            'content' => 'first',
            'reviewed' => false,
        ]);

        $article->update(['content' => 'second']);
        $article->update(['content' => 'first']);

        $detail = $article->audits()->where('event', 'updated')->latest('id')->first()
            ->details()->where('field', 'content')->sole();

        $this->assertSame(hash_hmac('sha256', 'first', self::KEY), $detail->new_value);
        $this->assertSame(hash_hmac('sha256', 'second', self::KEY), $detail->old_value);
    }
}
