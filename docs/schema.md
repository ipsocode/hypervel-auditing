# Schema

The bundled `audit_details` driver stores each audit as one `audits` row of
metadata plus one `audit_details` row per recorded field; this page describes
both tables, how values are stored, and how the migrations treat a table that
already exists.

## The two tables

```
audits                                  audit_details
------------------------------------    -----------------------------------
id                                      id
user_type, user_id      (morph)         audit_id      -> audits.id, cascade
event                   created|...     field         column or property name
auditable_type, auditable_id  (morph)   old_value     nullable text
url, ip_address, user_agent             new_value     nullable text
tags                                    created_at
batch_uuid              nullable, indexed
created_at, updated_at
```

### `audits`

| Column | Type | Holds |
|---|---|---|
| `id` | auto-incrementing unsigned big integer | |
| `user_type`, `user_id` | string, unsigned big integer; both nullable | The causer, or null. The `user` prefix is `auditing.user.morph_prefix`. Indexed together |
| `event` | string | The event name: `created`, `updated`, `deleted`, `restored`, `retrieved`, a pivot helper's `attach`/`detach`/`sync`, or the name a mapped or manual audit was given |
| `auditable_type`, `auditable_id` | string, unsigned big integer | The audited model. Indexed together |
| `url` | text, nullable | Filled by the `url` resolver |
| `ip_address` | IP address (`varchar(45)` on MySQL), nullable | Filled by the `ip_address` resolver |
| `user_agent` | string of 1023, nullable | Filled by the `user_agent` resolver |
| `tags` | string, nullable | `generateTags()` joined with commas; null when there are none |
| `batch_uuid` | UUID, nullable, indexed | The batch the audit was written in; see [Batches](batches.md) |
| `created_at`, `updated_at` | timestamp with millisecond precision, nullable | |

Each key of `auditing.resolvers` is written to the column of the same name, so a
resolver you add needs its own column (see [Resolvers](resolvers.md)).

`auditable_id` and `user_id` are unsigned big integers. Models keyed by UUIDs,
ULIDs or other strings need the migrations
[published](installation.md#publishing) and those columns changed.

### `audit_details`

| Column | Type | Holds |
|---|---|---|
| `id` | auto-incrementing unsigned big integer | |
| `audit_id` | unsigned big integer, indexed | The owning audit; a foreign key to `audits.id` with `ON DELETE CASCADE` |
| `field` | string | The attribute, relation or manual-audit property name |
| `old_value` | text, nullable | The value before the event |
| `new_value` | text, nullable | The value after it |
| `created_at` | timestamp with millisecond precision, nullable | Detail rows are never updated, so there is no `updated_at` |

The table names come from `auditing.tables.audits` and
`auditing.tables.audit_details`, and both tables live on `auditing.connection`.
The foreign key is why they cannot be split across connections.

## How an audit is written

The driver writes the `audits` row, then every detail row in a single
`insert()`: one round trip, however many fields changed. Both run in one
transaction on the audit connection, so an audit is never left with only part of
its details.

There is one detail row for each field that appears on either side of the
audit, with the field's old and new value side by side. Because the rows go
through a raw `insert()`, they bypass the `AuditDetail` model: no `creating` or
`created` events fire for it, and its `$fillable` is not consulted.
To react to a written audit, listen for `Events\Audited` (see
[Events](events.md)).

An audit with nothing on either side, such as a `retrieved` audit, is an
`audits` row with no details at all.

## How values are stored

Both value columns are text. Each value is reduced to a string on the way in,
after any [attribute modifier](attribute-modifiers.md) has run:

| Value | Stored as |
|---|---|
| `null` | `NULL` |
| A string | As it is |
| An int or a float | Its string form: `'42'`, `'4.5'` |
| `true`, `false` | `'1'`, `''` |
| A backed enum | Its value |
| A pure enum | Its name |
| An object with `__toString()` | Its string form |
| Anything else, such as an array | JSON: `'{"a":1}'`, or `NULL` when `json_encode()` fails |

A model attribute holding an array reaches the driver only when
`auditing.allowed_array_values` is on, and one holding an object only when the
object is stringable or an enum (see [Recording](recording.md)). Pivot and
manual audits are not filtered that way, so their arrays are stored as JSON.

Reading an audit back with `getModified()` runs each stored string through the
model's casts and accessors again; see [Reading audits](reading-audits.md).

## `old_values` and `new_values`

The table has no `old_values` or `new_values` column: `Models\Audit` builds
both from the detail rows, as accessors keyed by field.

```php
$audit->old_values;  // ['title' => 'Draft title']
$audit->new_values;  // ['title' => 'Revised title']
```

They return the text exactly as stored, with no decoding or casting.

A detail row always has both value columns, so a side the event never had is
stored as `NULL`, and that `NULL` looks the same as an attribute that really was
null. The event name resolves it. `Models\Audit` returns `[]` for the old side
of every event in `$eventsWithoutOldValues` (`created`, `restored`,
`retrieved`), and for the new side of every event in `$eventsWithoutNewValues`
(`deleted`, `retrieved`). `getModified()` leaves the missing side out the same
way.

If you record other events with only one side, for example a manual
`published` audit with no old values, list them in a subclass and point
`auditing.implementation` at it (see [Audit model](audit-model.md)):

```php
use Ipsocode\Auditing\Models\Audit;

class ActivityAudit extends Audit
{
    protected array $eventsWithoutOldValues = ['created', 'restored', 'retrieved', 'published'];
}
```

## Querying

Because each field is a row, you can ask which audits touched a field without
decoding anything:

```php
use Ipsocode\Auditing\Models\Audit;
use Ipsocode\Auditing\Models\AuditDetail;

$emailChanges = Audit::whereHas('details', fn ($query) => $query->where('field', 'email'))->get();

$details = AuditDetail::where('field', 'status')->where('new_value', 'archived')->get();
```

Stored values are the modified and normalized text, so a query on an encoded or
redacted field matches the stored form, not the model's value.

## The migrations

While `auditing.run_migrations` is true, the service provider registers both
migrations from the package's own directory, so `migrate` runs them in place.
`down()` drops the table.

Each migration first checks whether its table exists. If it does, because the
table was adopted, renamed, or the migration is running again, the migration
adds whichever expected columns are missing (names compared case-insensitively)
and leaves the rest of the table alone. It does not change existing columns,
adds no index other than the one a missing `batch_uuid` column brings with it,
and never adds the foreign key.
[Installation](installation.md#adopting-existing-tables) covers what that means
for an adopted table.

## Related

- [Installation](installation.md)
- [Recording](recording.md)
- [Reading audits](reading-audits.md)
- [Audit model](audit-model.md)
- [Drivers](drivers.md)
- [Retention](retention.md)
- [Configuration](configuration.md)
