# hypervel-auditing

Audit changes to your Eloquent models on [Hypervel](https://github.com/hypervel/components).

> [!WARNING]
> **Development only — do not use this package in production until Hypervel 0.4
> is released.**
>
> It is built for Hypervel 0.4, which has no release yet: 0.4 exists only as the
> `0.4.x-dev` branch of [`hypervel/components`](https://github.com/hypervel/components),
> and this package is developed and tested against that moving branch. Until 0.4
> ships, anything here can change without a deprecation period — the API, the
> configuration and the database schema included. Use it to evaluate or to build
> against Hypervel 0.4, and pin the version you tested.

```php
$article->update(['title' => 'Revised title']);

$article->audits()->latest()->first()->getModified();
// ['title' => ['old' => 'Draft title', 'new' => 'Revised title']]
```

## What this is

A port of [`owen-it/laravel-auditing`](https://github.com/owen-it/laravel-auditing) to
Hypervel — the same class topology (an `Auditable` trait, an `Auditor` manager,
resolvers, redactors, encoders, `AuditableTransitionException`), so upstream's
documentation and mental model mostly carry over.

**It is not a drop-in replacement.** Two things differ enough to break an
assumption before you write a line of code:

1. **There are no `old_values` / `new_values` columns.** Audits are stored
   normalized across two tables — see [Schema](docs/schema.md).
2. **Auditing is coroutine-aware.** On a long-lived Swoole worker, "process
   state" is shared by every concurrent request. Anything you plug in has rules
   to honour — see [Coroutines](docs/coroutines.md).

[Differences from `owen-it/laravel-auditing`](#differences-from-owen-itlaravel-auditing)
lists the rest.

## Requirements

- PHP 8.4 or newer (CI runs 8.4 and 8.5)
- Hypervel 0.4, which today means `hypervel/components` at `0.4.x-dev`. The
  package requires `hypervel/contracts`, `hypervel/support`, `hypervel/database`
  and `hypervel/reflection` `^0.4`; `hypervel/components` provides all four.

## Installation

The package is not on Packagist, so add this repository to your application's
Composer repositories first:

```sh
composer config repositories.hypervel-auditing vcs https://github.com/ipsocode/hypervel-auditing
composer require ipsocode/hypervel-auditing
php artisan auditing:install
php artisan migrate
```

Hypervel 0.4 is only available as a dev branch, so your application's
`composer.json` must already allow it: `"minimum-stability": "dev"` together
with `"prefer-stable": true`. Tags are not re-tested as `0.4.x-dev` moves on,
and neither is `main` between changes: each change is tested against the
`0.4.x-dev` of its day before it merges. To pick up changes as they land,
require `ipsocode/hypervel-auditing:dev-main` instead. Each release's notes,
breaking changes first, are on the
[Releases](https://github.com/ipsocode/hypervel-auditing/releases) page.

`auditing:install` picks the two table names and writes them to
`config/auditing.php`; [Installation](docs/installation.md) explains the
prompts, the non-interactive options, adopting existing tables and the publish
tags.

Then make a model auditable:

```php
use Hypervel\Database\Eloquent\Model;
use Ipsocode\Auditing\Auditable;
use Ipsocode\Auditing\Contracts\Auditable as AuditableContract;

class Article extends Model implements AuditableContract
{
    use Auditable;
}
```

Both parts are required: the trait supplies the behaviour, the interface is what
the `Auditor` and the driver type-hint against.

## Usage at a glance

```php
use Ipsocode\Auditing\Facades\Auditor;

// Creates, updates, deletes and restores are audited by default.
$article->update(['title' => 'Revised title']);
$article->audits()->latest()->first()->getModified();

// Skip auditing Article models for one callback, on the current coroutine only.
Article::withoutAuditing(fn () => $article->update(['title' => 'Quietly']));

// Record an event that has no column behind it.
Auditor::on($article)->as('exported')->with(['format' => 'pdf'])->log();

// Stamp every audit written inside the callback with the same batch_uuid.
Auditor::withinBatch(function () use ($article, $author) {
    $article->save();
    $author->save();
});
```

```sh
php artisan auditing:prune   # delete audits past the retention period (365 days by default)
```

In a console process (an Artisan command, a seeder, a queue worker, a test
run) model events are audited only once `auditing.console` is `true`; see
[Recording](docs/recording.md#when-a-model-is-audited).

The examples above are covered in full in
[Reading audits](docs/reading-audits.md),
[Disabling auditing](docs/disabling-auditing.md),
[Manual audits](docs/manual-audits.md), [Batches](docs/batches.md) and
[Retention](docs/retention.md).

## Documentation

| Page | Covers |
|---|---|
| [Installation](docs/installation.md) | Table names, adopting existing tables, publishing the config and migrations |
| [Schema](docs/schema.md) | The `audits` and `audit_details` tables and how values are stored |
| [Recording](docs/recording.md) | Which events and attributes are audited; per-model properties and hooks |
| [Attribute modifiers](docs/attribute-modifiers.md) | Redactors and encoders for sensitive attributes |
| [Reading audits](docs/reading-audits.md) | `getModified()`, `getMetadata()`, tags and the audit's relations |
| [Disabling auditing](docs/disabling-auditing.md) | `withoutAuditing()`, the worker-wide switches, `auditing.enabled` and `auditing.console` |
| [Relationship auditing](docs/relationship-auditing.md) | Audited attach, detach and sync on many-to-many relations |
| [Manual audits](docs/manual-audits.md) | `Auditor::on()` for events with no column behind them |
| [Batches](docs/batches.md) | Grouping the audits of one operation under a `batch_uuid` |
| [Retention](docs/retention.md) | The per-row threshold and `auditing:prune` |
| [Queued auditing](docs/queued-auditing.md) | Writing model audits through a queue connection, and what crosses it |
| [Events](docs/events.md) | The events dispatched around an audit, and vetoing one |
| [Drivers](docs/drivers.md) | The `audit_details` driver and writing your own |
| [Resolvers](docs/resolvers.md) | How the causer, `url`, `ip_address` and `user_agent` are filled; custom resolvers |
| [Audit model](docs/audit-model.md) | Extending or replacing the `Audit` model |
| [Transitions](docs/transitions.md) | Filling a model with an audit's old or new values |
| [Testing](docs/testing.md) | Recording audits in tests, the state reset after each test, and asserting on audits |
| [Coroutines](docs/coroutines.md) | The coroutine-safety rules the package follows, and that extensions must follow |
| [Configuration](docs/configuration.md) | Every `config/auditing.php` key and its default |

## Differences from `owen-it/laravel-auditing`

| | `owen-it/laravel-auditing` | This package |
|---|---|---|
| Storage | `old_values` / `new_values` JSON columns on `audits` | Normalized: `audits` + one `audit_details` row per field |
| Drivers | `Database` driver | `audit_details` driver; no `Database` driver |
| `old_values` / `new_values` | Columns | Accessors, built from the detail rows |
| `auditing:install` | Publishes the config | Chooses and persists the table names, can adopt existing tables |
| Driver generator | `make:audit-driver` | None |
| Retention | Threshold only | Threshold plus `auditing:prune` |
| Batches | None | `Auditor::withinBatch()`, `batch_uuid` column |
| Manual audits | Hand-assembled `AuditCustom` dispatch | `Auditor::on(...)->as(...)->log()` |
| `withoutAuditing()` | Static flag | Coroutine-scoped |
| Table names | Fixed | `auditing.tables.*` |

## Contributing

The development setup, the checks CI runs, the coroutine-safety rules every
change is held to, and how releases are cut are in
[CONTRIBUTING.md](CONTRIBUTING.md). Report security issues privately, as
described in [SECURITY.md](.github/SECURITY.md), rather than in a public issue.

## Credits

A port of [`owen-it/laravel-auditing`](https://github.com/owen-it/laravel-auditing)
by Antério Vieira, Quetzy Garcia, Raphael França and its contributors, to
[Hypervel](https://github.com/hypervel/components).

## License

MIT. See [LICENSE](LICENSE), which carries the copyright notices of this package
and of `owen-it/laravel-auditing`.
