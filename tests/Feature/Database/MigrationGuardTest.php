<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Tests\Feature\Database;

use Hypervel\Database\Migrations\Migration;
use Hypervel\Database\Migrations\Migrator;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\Schema;
use Ipsocode\Auditing\Tests\TestCase;

/**
 * The bundled migrations run in place from vendor/ on every migrate, so they
 * must be idempotent and never abort when the audit tables already exist (an
 * adopted table, a rename, or a re-run). A table missing a column the package
 * expects is reconciled by adding it.
 */
class MigrationGuardTest extends TestCase
{
    private function migration(string $file): Migration
    {
        return require dirname(__DIR__, 3) . '/database/migrations/' . $file;
    }

    public function testCreateAuditsMigrationIsIdempotent(): void
    {
        $this->assertTrue(Schema::hasTable('audits'));

        $this->migration('2026_06_06_000001_create_audits_table.php')->up();

        $this->assertTrue(Schema::hasTable('audits'));
    }

    public function testCreateAuditDetailsMigrationIsIdempotent(): void
    {
        $this->assertTrue(Schema::hasTable('audit_details'));

        $this->migration('2026_06_06_000002_create_audit_details_table.php')->up();

        $this->assertTrue(Schema::hasTable('audit_details'));
    }

    public function testCreateAuditsMigrationAddsAMissingColumn(): void
    {
        // A pre-existing audits table missing a column the package expects.
        Schema::table('audits', function (Blueprint $table): void {
            $table->dropColumn('tags');
        });
        $this->assertFalse(Schema::hasColumn('audits', 'tags'));

        $this->migration('2026_06_06_000001_create_audits_table.php')->up();

        $this->assertTrue(Schema::hasColumn('audits', 'tags'));
    }

    public function testCreateAuditDetailsMigrationAddsAMissingColumn(): void
    {
        Schema::table('audit_details', function (Blueprint $table): void {
            $table->dropColumn('new_value');
        });
        $this->assertFalse(Schema::hasColumn('audit_details', 'new_value'));

        $this->migration('2026_06_06_000002_create_audit_details_table.php')->up();

        $this->assertTrue(Schema::hasColumn('audit_details', 'new_value'));
    }

    public function testProviderRegistersTheBundledMigrationsByDefault(): void
    {
        $paths = array_map('realpath', $this->app->get(Migrator::class)->paths());

        $this->assertContains(
            realpath(dirname(__DIR__, 3) . '/database/migrations'),
            $paths,
        );
    }
}
