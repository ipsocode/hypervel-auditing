<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Tests\Feature\Console;

use Hypervel\Support\Facades\Config;
use Ipsocode\Auditing\Console\InstallCommand;
use Ipsocode\Auditing\Tests\TestCase;

class InstallCommandTest extends TestCase
{
    private string $configDir;

    protected function setUp(): void
    {
        parent::setUp();

        // Isolate the config file the command writes so it never touches the
        // shared workbench config directory.
        $this->configDir = sys_get_temp_dir() . '/audit-cfg-' . uniqid();
        mkdir($this->configDir, 0755, true);
        $this->app->useConfigPath($this->configDir);
    }

    protected function tearDown(): void
    {
        // Tests here nest config directories and even make `auditing.php` a
        // directory, so remove the whole tree: a leftover outlives the suite.
        $this->deleteTree($this->configDir);

        parent::tearDown();
    }

    public function testPromptsForCustomNamesWhenTablesAlreadyExist(): void
    {
        // RefreshDatabase has created the default "audits"/"audit_details"
        // tables, so the defaults collide and the command must prompt.
        $this->artisan('auditing:install')
            ->expectsQuestion(InstallCommand::AUDITS_QUESTION, 'custom_audits')
            ->expectsQuestion(InstallCommand::AUDIT_DETAILS_QUESTION, 'custom_audit_details')
            ->assertExitCode(0);

        $this->assertSame('custom_audits', config('auditing.tables.audits'));
        $this->assertSame('custom_audit_details', config('auditing.tables.audit_details'));

        // The chosen tables do not exist yet, so migrations stay on.
        $this->assertTrue(config('auditing.run_migrations'));
    }

    public function testAdoptingExistingTablesDisablesMigrations(): void
    {
        // Keeping the existing (colliding) names means the tables are adopted,
        // so the bundled create migrations should be turned off.
        $this->artisan('auditing:install')
            ->expectsQuestion(InstallCommand::AUDITS_QUESTION, 'audits')
            ->expectsQuestion(InstallCommand::AUDIT_DETAILS_QUESTION, 'audit_details')
            ->assertExitCode(0);

        $this->assertSame('audits', config('auditing.tables.audits'));
        $this->assertFalse(config('auditing.run_migrations'));

        $written = require $this->configDir . '/auditing.php';
        $this->assertFalse($written['run_migrations']);
    }

    public function testDoesNotPromptWhenTablesAreFree(): void
    {
        Config::set('auditing.tables.audits', 'fresh_audits');
        Config::set('auditing.tables.audit_details', 'fresh_details');

        $this->artisan('auditing:install')
            ->assertExitCode(0);

        $this->assertSame('fresh_audits', config('auditing.tables.audits'));
        $this->assertSame('fresh_details', config('auditing.tables.audit_details'));
    }

    public function testForcedNamesAreAppliedNonInteractively(): void
    {
        $this->artisan('auditing:install', [
            '--audits-table' => 'forced_audits',
            '--audit-details-table' => 'forced_details',
        ])->assertExitCode(0);

        $this->assertSame('forced_audits', config('auditing.tables.audits'));
        $this->assertSame('forced_details', config('auditing.tables.audit_details'));
    }

    public function testChosenNamesArePersistedToThePublishedConfigFile(): void
    {
        $this->artisan('auditing:install', [
            '--audits-table' => 'forced_audits',
            '--audit-details-table' => 'forced_details',
        ])->assertExitCode(0);

        $path = $this->configDir . '/auditing.php';
        $this->assertFileExists($path);

        $written = require $path;
        $this->assertSame('forced_audits', $written['tables']['audits']);
        $this->assertSame('forced_details', $written['tables']['audit_details']);
        $this->assertTrue($written['run_migrations']);
    }

    public function testWarnsInsteadOfClaimingSuccessWhenTheConfigKeysAreNotFound(): void
    {
        // A reformatted config file, or one without these keys, gives the
        // line-anchored rewrite nothing to match; reporting success would leave
        // the operator with a name that reverts on the next boot.
        file_put_contents(
            $this->configDir . '/auditing.php',
            "<?php\n\nreturn ['enabled' => true, 'driver' => 'audit_details'];\n"
        );

        $this->artisan('auditing:install --audits-table=forced_audits --audit-details-table=forced_details')
            ->expectsOutputToContain('Could not update')
            ->assertSuccessful();

        $this->assertStringNotContainsString(
            'forced_audits',
            (string) file_get_contents($this->configDir . '/auditing.php')
        );
    }

    public function testCollisionsAreCheckedOnTheConfiguredAuditConnection(): void
    {
        // The audit tables live on `auditing.connection`. A table on any other
        // connection is no collision, and adopting it would point the package
        // at tables that do not exist where it writes.
        config([
            'database.connections.audit_db' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
            'auditing.connection' => 'audit_db',
        ]);

        // `audits`/`audit_details` exist on the default connection only, so on
        // `audit_db` the default names are free and nothing should be prompted.
        $this->artisan('auditing:install')
            ->expectsOutputToContain('Auditing will use tables [audits] and [audit_details].')
            ->assertSuccessful();

        $this->assertTrue(Config::get('auditing.run_migrations'));
    }

    public function testPublishesTheConfigWhenTheConfigDirectoryDoesNotExist(): void
    {
        // An application that has never published any config has no config
        // directory, so the command has to create it before copying the stub.
        $nested = $this->configDir . '/app/config';
        $this->app->useConfigPath($nested);

        $this->assertDirectoryDoesNotExist($nested);

        $this->artisan('auditing:install', [
            '--audits-table' => 'nested_audits',
            '--audit-details-table' => 'nested_details',
        ])->assertSuccessful();

        $written = require $nested . '/auditing.php';
        $this->assertSame('nested_audits', $written['tables']['audits']);
        $this->assertSame('nested_details', $written['tables']['audit_details']);
    }

    public function testWarnsInsteadOfClaimingSuccessWhenTheConfigFileCannotBeWritten(): void
    {
        // A config path that is not a regular file can never be rewritten. Using
        // a directory reaches that branch for any user; permission bits would
        // not, since the suite runs as root and root ignores them.
        $path = $this->configDir . '/auditing.php';
        mkdir($path, 0755, true);

        $this->artisan('auditing:install', [
            '--audits-table' => 'blocked_audits',
            '--audit-details-table' => 'blocked_details',
        ])->expectsOutputToContain('Could not write')->assertSuccessful();

        $this->assertFalse(is_file($path));
        $this->assertSame([], array_values(array_diff(scandir($path), ['.', '..'])));

        // The names still apply to the running process, they just do not survive
        // a reboot — which is exactly what the warning tells the operator.
        $this->assertSame('blocked_audits', Config::get('auditing.tables.audits'));
        $this->assertSame('blocked_details', Config::get('auditing.tables.audit_details'));
    }

    private function deleteTree(string $directory): void
    {
        $entries = @scandir($directory);

        if ($entries === false) {
            return;
        }

        foreach (array_diff($entries, ['.', '..']) as $entry) {
            $path = $directory . '/' . $entry;

            is_dir($path) ? $this->deleteTree($path) : @unlink($path);
        }

        @rmdir($directory);
    }
}
