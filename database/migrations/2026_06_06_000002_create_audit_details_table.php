<?php

declare(strict_types=1);

use Hypervel\Database\Migrations\Migration;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Database\Schema\Builder;
use Hypervel\Support\Facades\Schema;
use Ipsocode\Auditing\Support\AuditTables;

return new class extends Migration {
    public function up(): void
    {
        $connection = config('auditing.connection', config('database.default'));
        $schema = Schema::connection($connection);
        $table = AuditTables::auditDetails();
        $auditsTable = AuditTables::audits();

        if (! $schema->hasTable($table)) {
            $schema->create($table, function (Blueprint $table) use ($auditsTable) {
                foreach ($this->columns() as $define) {
                    $define($table);
                }

                // foreignId('audit_id')->constrained() split into its column +
                // constraint so the column definition is shared with reconcile().
                $table->foreign('audit_id')->references('id')->on($auditsTable)->cascadeOnDelete();
                $table->index('audit_id');
            });

            return;
        }

        // The table exists already (adopted, renamed, or a re-run): reconcile
        // it instead of aborting the migrate. No foreign key is added to it.
        $this->reconcile($schema, $table);
    }

    public function down(): void
    {
        $connection = config('auditing.connection', config('database.default'));

        Schema::connection($connection)->drop(AuditTables::auditDetails());
    }

    /**
     * The expected columns, keyed by name so reconcile() can tell which are
     * missing, in the order create() adds them.
     *
     * @return array<string, callable(Blueprint): mixed>
     */
    private function columns(): array
    {
        return [
            'id' => fn (Blueprint $t) => $t->id(),
            'audit_id' => fn (Blueprint $t) => $t->unsignedBigInteger('audit_id'),
            'field' => fn (Blueprint $t) => $t->string('field'),
            'old_value' => fn (Blueprint $t) => $t->text('old_value')->nullable(),
            'new_value' => fn (Blueprint $t) => $t->text('new_value')->nullable(),
            'created_at' => fn (Blueprint $t) => $t->timestamp('created_at', 3)->nullable(),
        ];
    }

    /**
     * Add the expected columns a pre-existing table lacks; existing columns
     * are left alone.
     */
    private function reconcile(Builder $schema, string $table): void
    {
        $existing = array_map('strtolower', $schema->getColumnListing($table));

        $missing = array_filter(
            $this->columns(),
            static fn (callable $define, string $name): bool => ! in_array(strtolower($name), $existing, true),
            ARRAY_FILTER_USE_BOTH,
        );

        if ($missing === []) {
            return;
        }

        $schema->table($table, function (Blueprint $table) use ($missing) {
            foreach ($missing as $define) {
                $define($table);
            }
        });
    }
};
