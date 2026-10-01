<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Console;

use Hypervel\Console\Command;
use Hypervel\Support\Facades\Config;
use Hypervel\Support\Facades\Schema;
use Ipsocode\Auditing\Support\AuditTables;

class InstallCommand extends Command
{
    protected ?string $signature = 'auditing:install
        {--audits-table= : Use this audits (metadata) table name non-interactively}
        {--audit-details-table= : Use this audit details table name non-interactively}';

    protected string $description = 'Choose the audit table names, prompting for custom ones if the tables already exist';

    // Both prompts are asked while either chosen table name already exists.
    public const AUDITS_QUESTION = 'Name for the audits table';

    public const AUDIT_DETAILS_QUESTION = 'Name for the audit details table';

    public function handle(): int
    {
        $audits = (string) ($this->option('audits-table') ?? '');
        $details = (string) ($this->option('audit-details-table') ?? '');

        $forced = $audits !== '' || $details !== '';

        $audits = $audits !== '' ? $audits : AuditTables::audits();
        $details = $details !== '' ? $details : AuditTables::auditDetails();

        if (! $forced) {
            [$audits, $details] = $this->resolveFreeTableNames($audits, $details);
        }

        // If both chosen tables already exist, the user is adopting them, so the
        // bundled create migrations must not run.
        $runMigrations = count($this->collisions($audits, $details)) < 2;

        $this->persist($audits, $details, $runMigrations);

        $this->info(sprintf('Auditing will use tables [%s] and [%s].', $audits, $details));
        $this->line($runMigrations
            ? 'Run `php artisan migrate` to create them.'
            : 'Adopting existing tables — the bundled migrations will not run.');

        return self::SUCCESS;
    }

    /**
     * Prompt for custom table names until neither collides with an existing
     * table, or the user keeps the current names by answering with the defaults.
     *
     * @return array{0: string, 1: string}
     */
    private function resolveFreeTableNames(string $audits, string $details): array
    {
        while (($collisions = $this->collisions($audits, $details)) !== []) {
            $this->warn(sprintf('These audit tables already exist: %s', implode(', ', $collisions)));

            $newAudits = trim((string) $this->ask(self::AUDITS_QUESTION, $audits));
            $newDetails = trim((string) $this->ask(self::AUDIT_DETAILS_QUESTION, $details));

            // The user kept the existing (colliding) names on purpose.
            if ($newAudits === $audits && $newDetails === $details) {
                break;
            }

            $audits = $newAudits;
            $details = $newDetails;
        }

        return [$audits, $details];
    }

    /**
     * The given table names that already exist.
     *
     * @return array<int, string>
     */
    private function collisions(string ...$tables): array
    {
        // The tables live on `auditing.connection`, not necessarily the default:
        // checking the wrong one reports collisions that are not there.
        $schema = Schema::connection(Config::get('auditing.connection'));

        return array_values(array_filter(
            $tables,
            static fn (string $table): bool => $schema->hasTable($table),
        ));
    }

    /**
     * Apply the names for the current process and persist them to the
     * published config file (publishing it first if needed).
     */
    private function persist(string $audits, string $details, bool $runMigrations): void
    {
        Config::set('auditing.tables.audits', $audits);
        Config::set('auditing.tables.audit_details', $details);
        Config::set('auditing.run_migrations', $runMigrations);

        $this->writeConfig([
            'audits' => "'" . addslashes($audits) . "'",
            'audit_details' => "'" . addslashes($details) . "'",
            'run_migrations' => $runMigrations ? 'true' : 'false',
        ]);
    }

    /**
     * Rewrite matching config entries in the published config file.
     *
     * @param array<string, string> $replacements config key => PHP literal
     */
    private function writeConfig(array $replacements): void
    {
        $path = config_path('auditing.php');

        if (! is_file($path)) {
            $this->publishConfig($path);
        }

        if (! is_file($path) || ! is_writable($path)) {
            $this->warn(sprintf('Could not write [%s]; set the audit config manually.', $path));

            return;
        }

        $contents = (string) file_get_contents($path);
        $unmatched = [];

        foreach ($replacements as $key => $literal) {
            $contents = (string) preg_replace_callback(
                '/^(\s*)\'' . preg_quote($key, '/') . '\'\s*=>.*$/m',
                static fn (array $m): string => $m[1] . "'" . $key . "' => " . $literal . ',',
                $contents,
                1,
                $count,
            );

            if ($count === 0) {
                $unmatched[] = $key;
            }
        }

        file_put_contents($path, $contents);

        // A reformatted config file, or one without these keys, gives the
        // line-anchored pattern nothing to match; reporting success would leave
        // the operator with a name that reverts on the next boot.
        if ($unmatched !== []) {
            $this->warn(sprintf(
                'Could not update %s in [%s]; set %s manually.',
                implode(', ', array_map(static fn (string $k): string => "[{$k}]", $unmatched)),
                $path,
                count($unmatched) === 1 ? 'it' : 'them'
            ));

            return;
        }

        $this->info(sprintf('Updated [%s].', $path));
    }

    /**
     * Copy the package config stub to the application's config path.
     */
    private function publishConfig(string $path): void
    {
        $stub = dirname(__DIR__, 2) . '/config/auditing.php';
        $directory = dirname($path);

        if (! is_dir($directory)) {
            @mkdir($directory, 0755, true);
        }

        if (is_dir($directory) && is_writable($directory)) {
            @copy($stub, $path);
        }
    }
}
