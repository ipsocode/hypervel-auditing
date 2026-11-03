<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Tests\Unit\Support;

use Hypervel\Foundation\Testing\Attributes\UnitTest;
use Ipsocode\Auditing\Exceptions\AuditingException;
use Ipsocode\Auditing\Models\Audit;
use Ipsocode\Auditing\Support\AuditColumns;
use Ipsocode\Auditing\Tests\Unit\TestCase;

class AuditColumnsTest extends TestCase
{
    #[UnitTest]
    public function testItReturnsTheKeyAndCreatedAtColumns(): void
    {
        $audit = new Audit;

        $this->assertSame('id', AuditColumns::key($audit));
        $this->assertSame('created_at', AuditColumns::createdAt($audit));
    }

    #[UnitTest]
    public function testAnAuditModelWithoutAPrimaryKeyIsRejected(): void
    {
        $audit = new class extends Audit {
            protected ?string $primaryKey = null;
        };

        $this->expectException(AuditingException::class);
        $this->expectExceptionMessage('must have a primary key');

        AuditColumns::key($audit);
    }

    #[UnitTest]
    public function testAnAuditModelWithoutACreatedAtColumnIsRejected(): void
    {
        $audit = new class extends Audit {
            public const ?string CREATED_AT = null;
        };

        $this->expectException(AuditingException::class);
        $this->expectExceptionMessage('must have a created-at column');

        AuditColumns::createdAt($audit);
    }
}
