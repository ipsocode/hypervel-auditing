# Installation

This page covers installing the package, naming its two tables, adopting tables
you already have, and publishing the config file and migrations.

## Install the package

```sh
composer config repositories.hypervel-auditing vcs https://github.com/ipsocode/hypervel-auditing
composer require ipsocode/hypervel-auditing
php artisan auditing:install
php artisan migrate
```

The package is installed from its Git repository and builds on development
releases of Hypervel 0.4, so your application's `composer.json` needs the
stability settings described in the [README](../README.md#installation).

There is nothing to register by hand. Hypervel reads `extra.hypervel` from the
package's `composer.json` and loads `AuditingServiceProvider` and the `Auditor`
facade alias from it. The provider merges the package's config defaults into
`auditing`, binds the auditor, and registers the bundled migrations while
`auditing.run_migrations` is true. In a console process it also registers the
`auditing:install` and `auditing:prune` commands and the two publish tags.

## Name the tables: `auditing:install`

`auditing:install` does more than publish the config. It settles what the two
tables are called, writes that into `config/auditing.php`, and decides whether
the bundled migrations still have to run.

It starts from the configured names: `audits` and `audit_details`, unless
`AUDITING_TABLE` or `AUDITING_DETAILS_TABLE` say otherwise. It looks for them on
the audit connection, `auditing.connection`, which is the default connection
when left null.

### Interactively

```sh
php artisan auditing:install
```

When neither table exists, the names are kept without a question. When either
one exists, the command warns `These audit tables already exist: …` and asks
for both names, offering the current ones as defaults (`Name for the audits
table`, then `Name for the audit details table`). It keeps asking while either
answer names a table that exists. Answering with the same two names it offered
ends the loop: you are keeping those tables on purpose, and
[What it decides](#what-it-decides) shows what follows.

### Non-interactively

```sh
php artisan auditing:install --audits-table=activity_audits \
                             --audit-details-table=activity_audit_details
```

Passing either option skips the prompts, which makes the command usable in a
deploy script. An option you leave out keeps the configured name. Names passed
this way are used whether or not the tables exist.

### What it decides

| The chosen tables | `run_migrations` | What `php artisan migrate` then does |
|---|---|---|
| Neither exists | `true` | Creates both |
| One exists | `true` | Creates the missing table and adds any missing columns to the other |
| Both exist | `false` | Nothing: both tables are [adopted](#adopting-existing-tables) as they are |

### What it writes

The names and the flag apply to the running process at once, and are written to
`config/auditing.php`. If that file does not exist the command publishes it
first, creating the config directory when needed. It then rewrites three
entries, each found by its line: `'audits' => …`, `'audit_details' => …` and
`'run_migrations' => …`. Each becomes a literal value, so once the command has
run, the `AUDITING_TABLE`, `AUDITING_DETAILS_TABLE` and
`AUDITING_RUN_MIGRATIONS` environment variables are ignored. If you edit the
file by hand, keep each of those three entries on a line of its own so the
command can find it next time.

The command exits successfully even when it could not update the file, so read
its output for these two warnings:

- `Could not write [<path>]; set the audit config manually.` The file could not
  be created, or is not writable. The chosen names last only as long as that
  process.
- `Could not update [<key>] in [<path>]; set it manually.` The file exists, but
  an entry was not found on a line of its own, usually because the file was
  reformatted. The entries named were left unchanged.

You can also skip the command and set the names yourself, through
`AUDITING_TABLE` and `AUDITING_DETAILS_TABLE`, or under `tables` in a published
`config/auditing.php`. The bundled migrations read the same keys, so they create
the tables under those names.

## Adopting existing tables

Whenever both chosen tables already exist, whether you kept them at the prompt
or named them with the options, the command adopts them:
`auditing.run_migrations` becomes `false` and the service provider stops
registering the bundled migrations.

Nothing checks an adopted table. It needs every column the driver writes, under
the names it writes them: [Schema](schema.md) lists them, including the causer
columns named after `auditing.user.morph_prefix` and one column per configured
resolver. To have the package add the standard columns that are missing, set
`run_migrations` back to `true` for a single `migrate`: on a table that exists,
the bundled migrations only add the columns they define and the table lacks.
A column for a resolver or a `transformAudit()` key of your own is not among
them; add it yourself. The migrations never change or drop an existing column,
add no index beyond the one that comes with a missing `batch_uuid` column, and
never add the foreign key.

An adopted `audit_details` table may have no cascading foreign key into
`audits`. `auditing:prune` does not need one, because it deletes the detail rows
itself (see [Retention](retention.md)). The per-model threshold does: it deletes
`audits` rows and leaves their details to the cascade. Without the foreign key,
the details of audits it removes stay behind.

## Publishing

```sh
php artisan vendor:publish --tag=auditing-config
php artisan vendor:publish --tag=auditing-migrations
```

`auditing-config` copies the config file to `config/auditing.php`. You only need
it to change a default; until then the package's own values apply. A published
file is still merged with the package defaults, as
[Configuration](configuration.md) explains.

`auditing-migrations` copies the two migrations into `database/migrations` for
editing, for example to change the key columns for models with string keys.
After publishing them, set `auditing.run_migrations` to `false`, so that
`migrate` runs your copy and not the bundled one as well.

## Make a model auditable

```php
use Hypervel\Database\Eloquent\Model;
use Ipsocode\Auditing\Auditable;
use Ipsocode\Auditing\Contracts\Auditable as AuditableContract;

class Article extends Model implements AuditableContract
{
    use Auditable;
}
```

The trait registers the model's audit observer when the class boots and
implements everything the contract declares. The interface is not optional: the
observer the trait registers type-hints `Contracts\Auditable`, as do the auditor
and the drivers, so a model that uses the trait without implementing it throws a
`TypeError` the first time one is created, read, updated, deleted or restored.
From here, [Recording](recording.md) covers which events and attributes are
audited.

## Related

- [Configuration](configuration.md)
- [Schema](schema.md)
- [Recording](recording.md)
- [Retention](retention.md)
- [Audit model](audit-model.md)
