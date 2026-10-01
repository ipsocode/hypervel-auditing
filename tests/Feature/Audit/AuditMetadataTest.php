<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Tests\Feature\Audit;

use Ipsocode\Auditing\Models\Audit;
use Ipsocode\Auditing\Tests\TestCase;
use Workbench\App\Models\Article;
use Workbench\App\Models\User;

class AuditMetadataTest extends TestCase
{
    private function article(): Article
    {
        return Article::factory()->create();
    }

    private function user(): User
    {
        return User::factory()->create([
            'name' => 'Ada',
            'email' => 'ada@example.test',
            'password' => 'super-secret-hash',
        ]);
    }

    public function testMetadataForAGuestAuditCarriesTheResolverData(): void
    {
        $metadata = $this->article()->audits()->sole()->getMetadata();

        $this->assertSame('created', $metadata['audit_event']);
        $this->assertNull($metadata['user_id']);
        $this->assertNull($metadata['user_type']);
        $this->assertArrayHasKey('audit_ip_address', $metadata);
        $this->assertArrayHasKey('audit_user_agent', $metadata);
        $this->assertArrayHasKey('audit_url', $metadata);
        $this->assertNotNull($metadata['audit_created_at']);
    }

    public function testMetadataForAnAuthenticatedAuditIncludesTheUsersAttributes(): void
    {
        $user = $this->user();
        $this->actingAs($user);

        $metadata = $this->article()->audits()->sole()->getMetadata();

        $this->assertSame($user->getKey(), $metadata['user_id']);
        $this->assertSame($user->getMorphClass(), $metadata['user_type']);
        $this->assertSame('Ada', $metadata['user_name']);
        $this->assertSame('ada@example.test', $metadata['user_email']);
    }

    public function testHiddenUserAttributesStayOutOfTheMetadata(): void
    {
        // User attributes come through getArrayableAttributes(), which honours
        // $hidden, so a password hash never reaches an audit's metadata.
        $this->actingAs($this->user());

        $metadata = $this->article()->audits()->sole()->getMetadata();

        $this->assertArrayNotHasKey('user_password', $metadata);
        $this->assertArrayNotHasKey('user_remember_token', $metadata);
        $this->assertStringNotContainsString('super-secret-hash', json_encode($metadata));
    }

    public function testMetadataCanBeReturnedAsJson(): void
    {
        $audit = $this->article()->audits()->sole();

        $json = $audit->getMetadata(true);

        $this->assertJson($json);
        $this->assertSame($audit->getMetadata()['audit_event'], json_decode($json, true)['audit_event']);
    }

    public function testMetadataResolvesOnAnAuditWithoutTimestamps(): void
    {
        // resolveData() serializes both timestamp columns, and both are
        // nullable — an Audit that has not been persisted has neither.
        $metadata = (new Audit)->getMetadata();

        $this->assertNull($metadata['audit_created_at']);
        $this->assertNull($metadata['audit_updated_at']);
        $this->assertNull($metadata['audit_event']);
    }

    public function testGetDataValueReturnsNullForAnUnknownKey(): void
    {
        $audit = $this->article()->audits()->sole();
        $audit->resolveData();

        $this->assertNull($audit->getDataValue('not_a_key'));
    }

    public function testDateAttributesAreSerializedConsistentlyOnBothSides(): void
    {
        $article = Article::factory()->create(['published_at' => '2026-01-01 10:00:00']);

        $article->update(['published_at' => '2026-02-02 11:00:00']);

        $modified = $article->audits()->where('event', 'updated')->sole()->getModified();

        $this->assertSame('2026-01-01T10:00:00.000000Z', $modified['published_at']['old']);
        $this->assertSame('2026-02-02T11:00:00.000000Z', $modified['published_at']['new']);
    }
}
