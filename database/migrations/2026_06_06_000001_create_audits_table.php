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
        $table = AuditTables::audits();
        $morphPrefix = config('auditing.user.morph_prefix', 'user');

        if (! $schema->hasTable($table)) {
            $schema->create($table, function (Blueprint $table) use ($morphPrefix) {
                foreach ($this->columns($morphPrefix) as $define) {
                    $define($table);
                }

                $table->index([$morphPrefix . '_id', $morphPrefix . '_type']);
                // Replicates the index morphs() adds for the auditable_* columns.
                $table->index(['auditable_type', 'auditable_id']);
            });

            return;
        }

        // The table exists already (adopted, renamed, or a re-run): reconcile
        // it instead of aborting the migrate.
        $this->reconcile($schema, $table, $morphPrefix);
    }

    public function down(): void
    {
        $connection = config('auditing.connection', config('database.default'));

        Schema::connection($connection)->drop(AuditTables::audits());
    }

    /**
     * The expected columns, keyed by name so reconcile() can tell which are
     * missing, in the order create() adds them.
     *
     * @return array<string, callable(Blueprint): mixed>
     */
    private function columns(string $morphPrefix): array
    {
        return [
            'id' => fn (Blueprint $t) => $t->bigIncrements('id'),
            $morphPrefix . '_type' => fn (Blueprint $t) => $t->string($morphPrefix . '_type')->nullable(),
            $morphPrefix . '_id' => fn (Blueprint $t) => $t->unsignedBigInteger($morphPrefix . '_id')->nullable(),
            // Free-form: a model event ("created", "restored"), a relation
            // event ("attach", "sync") or a manual audit's own name.
            'event' => fn (Blueprint $t) => $t->string('event'),
            'auditable_type' => fn (Blueprint $t) => $t->string('auditable_type'),
            'auditable_id' => fn (Blueprint $t) => $t->unsignedBigInteger('auditable_id'),
            'url' => fn (Blueprint $t) => $t->text('url')->nullable(),
            'ip_address' => fn (Blueprint $t) => $t->ipAddress('ip_address')->nullable(),
            'user_agent' => fn (Blueprint $t) => $t->string('user_agent', 1023)->nullable(),
            'tags' => fn (Blueprint $t) => $t->string('tags')->nullable(),
            // Groups the audits written inside one Auditor::withinBatch() scope:
            // null outside a batch, and indexed to fetch a batch back by id.
            'batch_uuid' => fn (Blueprint $t) => $t->uuid('batch_uuid')->nullable()->index(),
            'created_at' => fn (Blueprint $t) => $t->timestamp('created_at', 3)->nullable(),
            'updated_at' => fn (Blueprint $t) => $t->timestamp('updated_at', 3)->nullable(),
        ];
    }

    /**
     * Add the expected columns a pre-existing table lacks; existing columns
     * are left alone.
     */
    private function reconcile(Builder $schema, string $table, string $morphPrefix): void
    {
        $existing = array_map('strtolower', $schema->getColumnListing($table));

        $missing = array_filter(
            $this->columns($morphPrefix),
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
