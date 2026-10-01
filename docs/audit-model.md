# Audit model

How to replace the model that audits are written, read and pruned through,
either with a subclass of `Models\Audit` or with a class of your own.

## What the class is used for

`auditing.implementation` names the class, `Models\Audit` by default. It is
read wherever the package touches the `audits` table:

- `$model->audits()` is a morph-many relation on it, so every audit you query
  through a model comes back as an instance of it.
- The bundled [driver](drivers.md) writes each audit through it: it creates
  the row with `create()`, opens its transaction on the class's connection, and
  writes the detail rows through the class's `details()` relation.
- The per-model [threshold](retention.md) deletes through `$model->audits()`,
  so it works on the class's table and primary key.
- `auditing:prune` builds its query from a fresh instance, so it uses the
  class's connection, table, primary key and created-at column.

Change the key in your published config:

```php
// config/auditing.php
'implementation' => App\Models\ActivityAudit::class,
```

## Extending `Models\Audit`

A subclass keeps everything the package relies on and adds what your
application needs, such as query scopes or relations:

```php
namespace App\Models;

use Hypervel\Database\Eloquent\Attributes\Scope;
use Hypervel\Database\Eloquent\Builder;
use Ipsocode\Auditing\Models\Audit;

class ActivityAudit extends Audit
{
    #[Scope]
    protected function touching(Builder $query, string $field): void
    {
        $query->whereHas('details', fn (Builder $details) => $details->where('field', $field));
    }
}
```

```php
$article->audits()->touching('title')->get();   // ActivityAudit instances
```

A subclass is also where events that record only one side are declared; see
[Schema](schema.md) for `$eventsWithoutOldValues` and
`$eventsWithoutNewValues`.

The class name does not matter to the package. `details()` names its foreign
key, `audit_id`, explicitly, so a class called `ActivityAudit` still finds its
detail rows. If you override `details()`, keep that argument:

```php
public function details(): HasMany
{
    return $this->hasMany(AuditDetail::class, 'audit_id');
}
```

The table and connection come from configuration, not from the class. The
`Audit` trait that `Models\Audit` uses defines `getTable()` and
`getConnectionName()` to return `auditing.tables.audits` and
`auditing.connection`, so a `$table` or `$connection` property on a subclass
has no effect. Change those keys instead: the detail model, the migrations,
`auditing:install` and `auditing:prune` all read them too.

## A class of your own

Any Eloquent model that implements `Contracts\Audit` can be the
implementation. To work with the bundled driver it needs:

- **The `Ipsocode\Auditing\Audit` trait**, or its equivalent. It provides
  everything the contract declares: the `auditable` and `user` relations, the
  table and connection from configuration, `resolveData()`,
  `getDataValue()`, `getMetadata()` and `getModified()`, plus `getTags()`.
- **Mass assignment of the whole payload.** The driver creates the row with
  `create()`, passing every payload key except the two sides, resolver keys
  included, so declare `protected array $guarded = [];` as `Models\Audit`
  does.
- **A `details()` relation** to the `audit_details` table, keyed by
  `audit_id`, as shown above. Without it the driver throws
  `AuditingException` ("The audit implementation … must expose a details()
  relation to use the audit_details driver"), and the transaction it was
  writing in rolls back, so no `audits` row is left behind.
- **`old_values` and `new_values` accessors** that return arrays keyed by
  field. `resolveData()` reads them to build `getModified()`; without them
  `getModified()` returns `[]`. `Models\Audit` builds them from the detail
  rows and returns `[]` for the side an event never had.

Each of these is something `Models\Audit` already does, which is why extending
it is the simpler choice. A [custom driver](drivers.md) sets its own
requirements: the `details()` relation is the bundled driver's.

## What stays on `Models\Audit`

Two things refer to `Models\Audit` whatever the implementation is:

- `Models\Audit::$auditingGloballyDisabled`, the worker-wide switch that turns
  off auditing for every model, is read from `Models\Audit` itself; see
  [Disabling auditing](disabling-auditing.md).
- `AuditDetail::audit()`, the inverse of `details()`, returns a
  `Models\Audit` for the same row, not an instance of your class.

## Related

- [Schema](schema.md)
- [Drivers](drivers.md)
- [Reading audits](reading-audits.md)
- [Retention](retention.md)
- [Configuration](configuration.md)
- [Disabling auditing](disabling-auditing.md)
