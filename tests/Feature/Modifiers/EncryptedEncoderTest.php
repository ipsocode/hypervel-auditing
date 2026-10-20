<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Tests\Feature\Modifiers;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Support\Facades\Crypt;
use Ipsocode\Auditing\Encoders\EncryptedEncoder;
use Ipsocode\Auditing\Tests\TestCase;
use Workbench\App\Models\EncryptedArticle;

class EncryptedEncoderTest extends TestCase
{
    private const KEY = 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=';

    protected function defineEnvironment(Application $app): void
    {
        parent::defineEnvironment($app);

        $app->get('config')->set('app.cipher', 'aes-256-cbc');
        $app->get('config')->set('app.key', self::KEY);
    }

    public function testRoundTrip(): void
    {
        $encoded = EncryptedEncoder::encode('secret');

        $this->assertNotSame('secret', $encoded);
        $this->assertSame('secret', EncryptedEncoder::decode($encoded));
    }

    public function testNullPassesThroughBothWays(): void
    {
        $this->assertNull(EncryptedEncoder::encode(null));
        $this->assertNull(EncryptedEncoder::decode(null));
    }

    public function testAValueThatIsNotCiphertextReadsBackAsStored(): void
    {
        $this->assertSame('plain', EncryptedEncoder::decode('plain'));
    }

    public function testAValueEncryptedUnderAnotherKeyReadsBackAsStored(): void
    {
        $encoded = EncryptedEncoder::encode('secret');

        $this->app->get('config')->set('app.key', 'base64:' . base64_encode(str_repeat('b', 32)));
        $this->app->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');

        $this->assertSame($encoded, EncryptedEncoder::decode($encoded));
    }

    public function testTheAuditHoldsCiphertextAndReadsBackPlain(): void
    {
        $article = EncryptedArticle::create([
            'title' => 'Title',
            'content' => 'Original content',
            'reviewed' => false,
        ]);

        $article->update(['content' => 'Updated content']);

        $audit = $article->audits()->where('event', 'updated')->sole();
        $detail = $audit->details()->where('field', 'content')->sole();

        $this->assertNotSame('Updated content', $detail->new_value);
        $this->assertSame('Updated content', Crypt::decryptString($detail->new_value));
        $this->assertNotSame('Original content', $detail->old_value);

        $this->assertSame([
            'new' => 'Updated content',
            'old' => 'Original content',
        ], $audit->getModified()['content']);
    }
}
