# Recording

This page explains which model events produce an audit, which attributes each
audit holds, and the per-model properties and hooks that change either.

## When a model is audited

A model class decides once, when it boots, whether to register its audit
observer: it does so only if `auditing.enabled` is true and, in a console
process, `auditing.console` is true as well. Artisan commands run as console
processes, `db:seed` and `queue:work` included; the HTTP server, although
started from Artisan, does not. A class that boots while either switch is off
records no model events for the rest of that process, even if the switch is
turned on later.

Once registered, the observer handles the five model events below, audits the
ones enabled for the model, and writes each audit inline, on the coroutine that
fired the event, unless [queued auditing](queued-auditing.md) is on. To suppress auditing for one
callback or one request, use `withoutAuditing()`; see
[Disabling auditing](disabling-auditing.md).

## Events

| Event | Old side | New side | Audited by default |
|---|---|---|---|
| `created` | none | every audited attribute | yes |
| `updated` | each changed attribute, before | each changed attribute, after | yes |
| `deleted` | every audited attribute | none | yes |
| `restored` | none | every audited attribute | yes |
| `retrieved` | none | none | no |

`auditing.events` lists the events that are audited. Restoring a soft-deleted
model also fires `updated`; the observer marks the model while the restore runs
and skips that `updated`, so a restore is recorded once, as `restored`.

`increment()`, `decrement()` and their `Each` forms fire `updated` as well, and
are recorded like a save: the changed columns and any extra columns passed with
them. The model is clean afterwards, so a later `save()` records nothing more.

### `retrieved`

`retrieved` fires for every model hydrated from a query: a query that returns
500 rows writes 500 audits, each a single `audits` row of metadata (causer,
URL, IP address, user agent, tags, batch) with no detail rows. With queued
auditing on, it also dispatches 500 jobs. Turn it on only where a read trail is
the point, and prefer one model's `$auditEvents` to the global list:

```php
protected array $auditEvents = ['created', 'updated', 'deleted', 'restored', 'retrieved'];
```

A `retrieved` audit always has two empty sides. `retrieved` is in
`auditing.allowed_empty_values` by default, so its audits are written even when
`auditing.empty_values` is off.

### Empty audits

With `auditing.empty_values` on, the default, an audit is written even when
both sides end up empty after filtering. Saving a model whose only change is to
an attribute that is not audited, such as `touch()` while timestamps are
excluded, writes an `updated` audit with no detail rows. Set `empty_values` to
`false` to skip those; events listed in `allowed_empty_values` are written
either way.

## Choosing events per model

`$auditEvents` replaces `auditing.events` for one model; the two lists are not
merged. Each entry takes one of two forms:

```php
protected array $auditEvents = [
    'deleted',                             // attributes from getDeletedEventAttributes()
    'created' => 'createdAuditAttributes', // attributes from createdAuditAttributes()
];
```

A plain entry uses the getter named after the event that fired,
`get{Event}EventAttributes()`, which the trait defines for the five model
events. A keyed entry names the method that supplies the attributes for every
event the key matches.

### How an entry matches an event

Entries are tried in order, and the first match decides the getter:

- An entry with no regex metacharacters matches any event name that contains
  it. `'date'` matches `updated`.
- Any other entry is an unanchored regular expression in which `*` stands for
  any run of characters. `'*ted'` matches `created`, `updated` and `deleted`,
  but not `restored`.
- Anchor an entry for an exact match: `'^updated$'`.

An event that no entry matches is not audited. An entry that matches but whose
getter does not exist on the model throws an `AuditingException` (`Unable to
handle "<event>" event, <getter>() method missing`) when the audit is built.

### A custom getter

A getter returns a two-element array, the old side then the new side, each keyed
by field name. Return raw attribute values, as the trait's own getters do, so
that reading the audit back can apply the model's casts once:

```php
protected function createdAuditAttributes(): array
{
    return [[], ['title' => $this->getAttributes()['title']]];
}
```

The exclusions listed under
[Which attributes are recorded](#which-attributes-are-recorded) are resolved
before the getter runs but are not applied to what it returns. Call
`$this->isAttributeAuditable($attribute)` to honour `$auditExclude`,
`$auditInclude`, strict mode and the timestamp setting.
[Attribute modifiers](attribute-modifiers.md) still apply to the values it
returns.

### Events the observer never fires

The observer reacts only to the five events above. To audit a domain event of
your own through a getter, map it and hand the model to the auditor yourself:

```php
use Ipsocode\Auditing\Facades\Auditor;

protected array $auditEvents = [
    'created', 'updated', 'deleted', 'restored',
    'published' => 'publishedAuditAttributes',
];

public function publish(): void
{
    $this->update(['published_at' => now()]);   // audited as `updated`

    $this->setAuditEvent('published');
    Auditor::execute($this);                     // audited as `published`
}

protected function publishedAuditAttributes(): array
{
    return [[], ['published_at' => $this->getAttributes()['published_at']]];
}
```

`setAuditEvent()` leaves the event unset when no entry matches it, and
`Auditor::execute()` then writes nothing. For a one-off event with no model
attribute behind it, a [manual audit](manual-audits.md) is simpler.

## Which attributes are recorded

For each audit the trait builds an exclusion list, then checks every attribute
against it and against the include list:

1. **Excluded attributes.** `$auditExclude` when the model declares it,
   otherwise `auditing.exclude`. A declared `$auditExclude` replaces the config
   list; it is not merged with it.
2. **Strict mode.** When `$auditStrict` (or `auditing.strict`) is true, every
   attribute in `$hidden` is excluded, and when the model declares a non-empty
   `$visible`, so is every attribute outside it.
3. **Timestamps.** Unless `$auditTimestamps` (or `auditing.timestamps`) is
   true, the created-at and updated-at columns are excluded, and so is the
   deleted-at column of a soft-deleting model.
4. **Values that do not fit a column.** An attribute holding an array is
   excluded unless `auditing.allowed_array_values` is true. One holding an
   object is excluded unless the object has `__toString()` or is an enum.
5. **Included attributes.** When `$auditInclude` is non-empty, only the
   attributes it lists are audited.

An excluded attribute stays out even when `$auditInclude` lists it: the
exclusion list is checked first.

The values checked and recorded are the model's raw attributes, as they would be
written to the database. An attribute with an `array`, `json` or `AsArrayObject`
cast already holds a JSON string, so it is recorded whatever
`allowed_array_values` says; the array rule applies to an attribute with no cast
that was assigned an array. [Schema](schema.md#how-values-are-stored) shows how
each value is stored.

Columns a model names in `#[Refreshes]` (or `$refreshes`) are read back from
the database after each write, before `created` or `updated` fires, so the audit
records what the row holds. A `created` audit includes such a column's database
default even when `create()` was not given it, and an `updated` audit includes a
change a trigger made to it.

## Per-model properties

Each property overrides the config key beside it for one model. Declare the
ones you need on the model:

| Property | Config key | Effect |
|---|---|---|
| `protected array $auditExclude` | `exclude` | Attributes never audited |
| `protected array $auditInclude` | none | When non-empty, the only attributes audited |
| `protected array $auditEvents` | `events` | Which events are audited, and their getters |
| `protected bool $auditStrict` | `strict` | Also exclude `$hidden`, and everything outside a non-empty `$visible` |
| `protected bool $auditTimestamps` | `timestamps` | Audit the timestamp columns |
| `protected string $auditDriver` | `driver` | The [driver](drivers.md) that writes this model's audits |
| `protected int $auditThreshold` | `threshold` | Keep at most this many audits per record; see [Retention](retention.md) |
| `protected array $attributeModifiers` | none | Redactors and encoders per attribute; see [Attribute modifiers](attribute-modifiers.md) |
| `public array $auditEventSerializedProperties` | none | Extra properties carried to a queued audit; see [Queued auditing](queued-auditing.md) |

`$auditEventSerializedProperties` is the one that must be `public`: it is read
from outside the model, where a `protected` declaration would be silently
ignored.

```php
use Hypervel\Database\Eloquent\Model;
use Ipsocode\Auditing\Auditable;
use Ipsocode\Auditing\Contracts\Auditable as AuditableContract;
use Ipsocode\Auditing\Encoders\Base64Encoder;
use Ipsocode\Auditing\Redactors\RightRedactor;

class Article extends Model implements AuditableContract
{
    use Auditable;

    protected array $auditExclude = ['internal_notes'];

    protected int $auditThreshold = 100;

    protected array $attributeModifiers = [
        'email' => RightRedactor::class,
        'ssn' => Base64Encoder::class,
    ];
}
```

## Hooks

### `transformAudit()`

`transformAudit()` receives the whole payload just before the driver writes it
and returns what is written. It runs for every audit built from the model:
model events, [pivot helpers](relationship-auditing.md) and
[manual audits](manual-audits.md).

The payload holds `old_values` and `new_values` (with attribute modifiers
already applied to a model event's values), `event`, `auditable_id`,
`auditable_type`, the causer's `user_id` and `user_type` (named after
`auditing.user.morph_prefix`), `tags`, `batch_uuid`, and one entry per
configured resolver (`ip_address`, `user_agent`, `url`). The bundled driver
writes every key except the two value arrays to an `audits` column, so a key you
add needs a column:

```php
public function transformAudit(array $data): array
{
    $data['tenant_id'] = $this->tenant_id;   // requires a tenant_id column on audits

    return $data;
}
```

### `generateTags()`

`generateTags()` returns a list of strings, joined with commas into the `tags`
column; an empty list stores null. Read them back with `getTags()` (see
[Reading audits](reading-audits.md)).

```php
public function generateTags(): array
{
    return $this->is_featured ? ['featured', 'homepage'] : [];
}
```

A tag must not contain a comma, since `getTags()` splits on commas, and the
joined list has to fit the `tags` column, a string of 255 characters in the
bundled migration.

## Related

- [Attribute modifiers](attribute-modifiers.md)
- [Reading audits](reading-audits.md)
- [Disabling auditing](disabling-auditing.md)
- [Manual audits](manual-audits.md)
- [Relationship auditing](relationship-auditing.md)
- [Queued auditing](queued-auditing.md)
- [Configuration](configuration.md)
- [Schema](schema.md)
