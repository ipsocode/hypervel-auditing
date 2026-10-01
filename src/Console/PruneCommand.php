<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Console;

use Hypervel\Console\Command;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Relations\Relation;
use Hypervel\Support\Carbon;
use Hypervel\Support\Facades\Config;
use Ipsocode\Auditing\Contracts\Audit;
use Ipsocode\Auditing\Models\Audit as AuditModel;
use Ipsocode\Auditing\Support\AuditTables;

/**
 * Date-based retention; the per-model `threshold` is the count-based axis.
 * Nothing is deleted by age until this command runs, so schedule it.
 */
class PruneCommand extends Command
{
    protected ?string $signature = 'auditing:prune
        {--days= : Delete audits older than this many days (default: auditing.delete_records_older_than_days)}
        {--model= : Only prune audits for this auditable type, by class name or morph alias}
        {--chunk=1000 : How many audits to delete per round}
        {--dry-run : Report what would be deleted without deleting anything}';

    protected string $description = 'Delete audits older than the configured retention period';

    public function handle(): int
    {
        $days = $this->option('days') ?? Config::get('auditing.delete_records_older_than_days', 365);

        // Without this, `--days=nonsense` casts to 0 and quietly deletes the
        // whole trail.
        if (! is_numeric($days) || (int) $days < 0) {
            $this->error(sprintf('Retention must be a non-negative number of days, got [%s].', $days));

            return self::FAILURE;
        }

        $cutoff = Carbon::now()->subDays((int) $days);
        $query = $this->query($cutoff);

        if ($this->option('dry-run')) {
            $this->info(sprintf(
                '%d audits older than %s would be deleted.',
                $query->count(),
                $cutoff->toDateTimeString()
            ));

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%d audits older than %s deleted.',
            $this->prune($query, (int) $this->option('chunk')),
            $cutoff->toDateTimeString()
        ));

        return self::SUCCESS;
    }

    /**
     * The audits eligible for deletion, queried through `auditing.implementation`
     * so the connection, table and any custom overrides match where audits are
     * written.
     */
    private function query(Carbon $cutoff): Builder
    {
        $audit = $this->audit();

        $query = $audit->newQuery()->where($audit->getCreatedAtColumn(), '<', $cutoff);

        if (($model = $this->option('model')) !== null) {
            // Accepts either spelling: a morph alias passes through unchanged,
            // and a class name is translated to whatever the morph map calls it
            // — which is what `auditable_type` actually holds.
            $query->where('auditable_type', Relation::getMorphAlias($model));
        }

        return $query;
    }

    /**
     * Delete the matched audits a chunk at a time: a first sweep can match
     * millions of rows, and one delete over all of them holds locks throughout.
     */
    private function prune(Builder $query, int $chunk): int
    {
        $audit = $this->audit();
        $key = $audit->getKeyName();
        $chunk = max(1, $chunk);
        $deleted = 0;

        while (($ids = (clone $query)->orderBy($key)->limit($chunk)->pluck($key)->all()) !== []) {
            // Details first, and explicitly: the bundled migration cascades, but
            // tables adopted by `auditing:install` may lack ON DELETE CASCADE.
            $audit->getConnection()
                ->table(AuditTables::auditDetails())
                ->whereIn('audit_id', $ids)
                ->delete();

            $deleted += (int) $audit->newQuery()->whereIn($key, $ids)->delete();
        }

        return $deleted;
    }

    private function audit(): Audit
    {
        $class = Config::get('auditing.implementation', AuditModel::class);

        return new $class;
    }
}
