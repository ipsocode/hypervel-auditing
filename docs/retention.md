# Retention

How to keep the audit trail from growing without bound: by capping the number
of audits per model row, by age, or both.

The two limits are independent and both are off by default. A count limit
bounds each row's history but not its age: a row that rarely changes keeps
audits from years ago while a busy one cycles through its allowance in days.
An age limit bounds the whole trail but not a busy row. Use either, or both.

## By count: the threshold

```php
// config/auditing.php
'threshold' => 100,
```

or per model:

```php
class Article extends Model implements AuditableContract
{
    use Auditable;

    protected int $auditThreshold = 100;
}
```

The model property wins over the config key. `0`, the default, means no
limit.

After every audit it writes, the `Auditor` calls the driver's `prune()` for
that model, so the limit is enforced as audits are written, with nothing to
schedule. It applies to every audit of the row: model events,
[relationship audits](relationship-auditing.md) and
[manual audits](manual-audits.md) alike.

The bundled `audit_details` driver keeps the newest audits of that one row
(same `auditable_type` and `auditable_id`) and deletes the rest in a single
statement. Newest means latest `created_at`, with the primary key breaking
ties between audits written in the same second, so the survivors are the last
ones written.

This delete removes `audits` rows only. Their detail rows go through the
`ON DELETE CASCADE` on the `audit_details` foreign key, which the bundled
migration creates. A table adopted with `auditing:install` may lack that
constraint, and there the threshold leaves orphaned detail rows behind: add
the constraint before relying on the threshold. `auditing:prune` deletes
detail rows itself.

A [custom driver](drivers.md) decides what `prune()` does. The `Auditor` calls
it after every write either way.

## By age: `auditing:prune`

```sh
php artisan auditing:prune                                # older than auditing.delete_records_older_than_days
php artisan auditing:prune --days=540
php artisan auditing:prune --days=90 --model='App\Models\Article'
php artisan auditing:prune --days=90 --model=article      # a morph alias works too
php artisan auditing:prune --dry-run
php artisan auditing:prune --chunk=5000
```

| Option | Effect |
|---|---|
| `--days=` | Deletes audits created more than this many days ago. Defaults to `auditing.delete_records_older_than_days` (365). `0` means everything written before the command started. |
| `--model=` | Only audits of this auditable type, given as a class name or a morph alias. |
| `--chunk=` | How many audits each round deletes. Defaults to 1000. |
| `--dry-run` | Reports how many audits would be deleted and deletes nothing. |

A `--days` value that is not a non-negative number fails with exit code 1 and
deletes nothing, rather than being read as `0`.

The command works through the configured `auditing.implementation`, so it uses
the same connection and table names as the rest of the package. Each round
selects the next chunk of matching ids, deletes their detail rows, then the
audits themselves, and repeats until nothing matches. A first run over a large
trail therefore does not hold locks for the whole sweep. Detail rows are deleted
explicitly because an adopted table may have no cascading foreign key.

It reports the outcome:

```text
1250 audits older than 2025-04-01 09:30:00 deleted.
```

### Scheduling it

Nothing is deleted until the command runs. Schedule it alongside your
application's other scheduled commands, for example in `routes/console.php`:

```php
use Hypervel\Support\Facades\Schedule;

Schedule::command('auditing:prune')->daily();
```

## Related

- [Configuration](configuration.md)
- [Drivers](drivers.md)
- [Schema](schema.md)
- [Installation](installation.md)
- [Audit model](audit-model.md)
