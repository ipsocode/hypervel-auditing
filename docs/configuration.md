# Configuration

This page lists every key in `config/auditing.php` with its default and what it
controls, and explains how your values combine with the package's.

## Where the values come from

The package ships its own `config/auditing.php` and merges it under `auditing`.
Publish a copy to change anything (`php artisan vendor:publish
--tag=auditing-config`, or let `auditing:install` do it; see
[Installation](installation.md)).

A key you set replaces the package's value for that key, with four exceptions:
`resolvers`, `user`, `queue` and `tables` merge entry by entry. A config file
that sets only `resolvers.ip_address` keeps the package's `user_agent` and `url`
resolvers, and one that sets only `queue.enable` keeps the package's
`connection`, `queue` and `delay`. The merge goes one level deep, so a
`user.guards` list you set replaces the package's list. Every other array, such
as `events` or `exclude`, is replaced whole.

Several keys can also be set per model, on the model itself; the table in
[Recording](recording.md#per-model-properties) lists them.

## Reference

| Key | Default | Meaning |
|---|---|---|
| `enabled` | `env('AUDITING_ENABLED', true)` | Master switch; see [Switches read at boot](#switches-read-at-boot) |
| `implementation` | `Models\Audit::class` | The model audits are written, read and pruned through; see [Audit model](audit-model.md) |
| `user.morph_prefix` | `'user'` | Names the causer columns, `user_type` and `user_id` by default |
| `user.guards` | `['web', 'sanctum']` | Guards the bundled user resolver tries in order; a guard that is not configured is skipped |
| `user.resolver` | `Resolvers\UserResolver::class` | The `Contracts\UserResolver` that finds the causer; see [Resolvers](resolvers.md) |
| `resolvers.ip_address` | `Resolvers\IpAddressResolver::class` | Fills `ip_address` |
| `resolvers.user_agent` | `Resolvers\UserAgentResolver::class` | Fills `user_agent` |
| `resolvers.url` | `Resolvers\UrlResolver::class` | Fills `url`: the request URL, or the command line in a console process |
| `events` | `['created', 'updated', 'deleted', 'restored']` | Events that are audited; `retrieved` is the fifth available. See [Recording](recording.md) |
| `strict` | `false` | Also exclude `$hidden`, and everything outside a non-empty `$visible` |
| `exclude` | `[]` | Attributes never audited; a model's `$auditExclude` replaces it |
| `empty_values` | `true` | Write an audit even when both its sides are empty |
| `allowed_empty_values` | `['retrieved']` | Events written even when `empty_values` is `false` |
| `allowed_array_values` | `false` | Audit attributes holding a PHP array, stored as JSON |
| `timestamps` | `false` | Audit the created-at, updated-at and deleted-at columns |
| `threshold` | `0` | The most audits kept per record; `0` keeps them all. See [Retention](retention.md) |
| `delete_records_older_than_days` | `365` | The age `auditing:prune` deletes from when `--days` is not given |
| `driver` | `'audit_details'` | A driver name or class; see [Drivers](drivers.md) |
| `connection` | `null` | The connection both audit tables live on; null means the default connection |
| `tables.audits` | `env('AUDITING_TABLE', 'audits')` | The metadata table |
| `tables.audit_details` | `env('AUDITING_DETAILS_TABLE', 'audit_details')` | The per-field table |
| `run_migrations` | `env('AUDITING_RUN_MIGRATIONS', true)` | Register the bundled migrations; read when the service provider boots |
| `queue.enable` | `false` | Dispatch model audits as queued jobs; `queue.connection` decides where they run |
| `queue.connection` | `'sync'` | The queue connection: `sync` writes at once on the same coroutine, `deferred` after the response, `background` in a new coroutine, or a persistent queue such as `redis` |
| `queue.queue` | `'default'` | The queue name |
| `queue.delay` | `0` | Seconds to delay the write; `0` means none |
| `console` | `false` | Audit model events in console processes; see [Switches read at boot](#switches-read-at-boot) |

The `queue` keys are explained in [Queued auditing](queued-auditing.md).

A resolver entry set to `null` is skipped, which is how you stop filling a
column:

```php
'resolvers' => [
    'user_agent' => null,
],
```

Each resolver key names an `audits` column, so a resolver you add needs one.
A class configured as a resolver or user resolver that does not implement the
matching contract throws an `AuditingException` whenever an audit needs it.

## Switches read at boot

`enabled` and `console` are read when each model class boots. The class
registers its audit observer only if `enabled` is true and, in a console
process, `console` is true too. Artisan commands, `queue:work` included, are
console processes; the HTTP server is not. A class that boots with either
switch off records no model events until the process restarts.

After boot, `enabled` is still read on every audit by the two listeners that
write [pivot audits](relationship-auditing.md) and
[queued model audits](queued-auditing.md), so turning it off at runtime stops
those two paths at once. It does not stop inline model audits of classes that
have already booted, and [manual audits](manual-audits.md) never read it.
To pause auditing for a stretch of code, use `withoutAuditing()`; see
[Disabling auditing](disabling-auditing.md).

## The audit connection

Both audit tables live on `connection`, and must: `audit_details` has a foreign
key into `audits`. The Audit model, the detail model, the migrations,
`auditing:install` and `auditing:prune` all use it.

The driver writes each audit in a transaction on that connection. When it is
also the connection your model writes on, an inline audit joins any transaction
the model's write is in, and rolls back with it. When it is a different
connection, the two transactions are independent: the audit commits on its own,
and if the transaction on the model's connection later rolls back, the audit
remains, recording a change that never happened. Keep the audit tables on the
model's connection unless you can accept that.

## Environment variables

| Variable | Key |
|---|---|
| `AUDITING_ENABLED` | `enabled` |
| `AUDITING_TABLE` | `tables.audits` |
| `AUDITING_DETAILS_TABLE` | `tables.audit_details` |
| `AUDITING_RUN_MIGRATIONS` | `run_migrations` |

`auditing:install` writes literal values over the last three in your published
config file, after which those variables are ignored.

## Related

- [Installation](installation.md)
- [Recording](recording.md)
- [Disabling auditing](disabling-auditing.md)
- [Queued auditing](queued-auditing.md)
- [Resolvers](resolvers.md)
- [Drivers](drivers.md)
- [Retention](retention.md)
- [Audit model](audit-model.md)
- [Schema](schema.md)
