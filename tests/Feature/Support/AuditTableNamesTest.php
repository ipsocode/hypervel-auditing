<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Tests\Feature\Support;

use Hypervel\Support\Facades\Config;
use Ipsocode\Auditing\Models\Audit;
use Ipsocode\Auditing\Models\AuditDetail;
use Ipsocode\Auditing\Support\AuditTables;
use Ipsocode\Auditing\Tests\TestCase;

class AuditTableNamesTest extends TestCase
{
    public function testDefaultTableNames(): void
    {
        $this->assertSame('audits', AuditTables::audits());
        $this->assertSame('audit_details', AuditTables::auditDetails());
    }

    public function testCustomTableNamesFromConfig(): void
    {
        Config::set('auditing.tables.audits', 'activity');
        Config::set('auditing.tables.audit_details', 'activity_changes');

        $this->assertSame('activity', AuditTables::audits());
        $this->assertSame('activity_changes', AuditTables::auditDetails());
    }

    public function testModelsHonorConfiguredTableNames(): void
    {
        Config::set('auditing.tables.audits', 'activity');
        Config::set('auditing.tables.audit_details', 'activity_changes');

        $this->assertSame('activity', (new Audit)->getTable());
        $this->assertSame('activity_changes', (new AuditDetail)->getTable());
    }
}
