# Reading audits

This page covers fetching a model's audits and the methods that turn a stored
audit back into values, metadata and tags.

## Fetching audits

`audits()` is a morph-many relation on the class in `auditing.implementation`
(`Models\Audit` by default), so it takes any query you would write against a
relation:

```php
$audit = $article->audits()->latest()->first();

$updates = $article->audits()
    ->where('event', 'updated')
    ->with(['details', 'user'])
    ->get();
```

An audit has three relations of its own:

| Relation | Returns |
|---|---|
| `details` | Its `audit_details` rows, one per field |
| `user` | The causer, through the `user_type`/`user_id` columns (named after `auditing.user.morph_prefix`), or null |
| `auditable` | The audited model, or null when it cannot be found |

`old_values`, `new_values`, `getModified()` and `getMetadata()` read `details`,
`user` and `auditable`, so eager-load them when you read many audits at once.

To find audits by anything other than their subject, query the audit model
directly. See [Schema](schema.md#querying) for queries on fields and values,
and [Batches](batches.md) for `batch_uuid`.

## `getModified()`

`getModified()` returns each recorded field with the sides its event has:

```php
$audit->getModified();
// ['title' => ['new' => 'Revised title', 'old' => 'Draft title']]
```

A `created` or `restored` audit has only `new` entries, a `deleted` audit only
`old` ones, and a `retrieved` audit returns `[]`.

Values come back the way the model would present them, not as the stored text.
For each value, in order:

1. An [encoder](attribute-modifiers.md) registered for the attribute decodes
   it. Redacted values stay masked.
2. A get accessor, of either style, formats it, and nothing further is applied.
3. Otherwise an `AsArrayObject` cast rebuilds its `ArrayObject` from the stored
   JSON, or an empty one if the JSON does not decode.
4. Otherwise any other cast runs as it does on the model; class casts return
   their objects. Before a `datetime` cast, and for the model's created-at and
   updated-at columns, the stored text is read as UTC: `Y-m-d` is taken as
   midnight, and `Y-m-d H:i:s` and the model's date format are recognised.
   Text in no recognised shape is handed on as it is.

A date that comes out as a `DateTimeInterface` is then serialized with the
model's `serializeDate()`, ISO 8601 by default:

```php
$audit->getModified()['published_at'];
// ['new' => '2026-02-02T11:00:00.000000Z', 'old' => '2026-01-01T10:00:00.000000Z']
```

All of this goes through the audit's `auditable` relation. When it finds no
model, because the record was deleted, or is soft-deleted and filtered out by
its scope, every field value comes back as stored text, still encoded where an
encoder applies.

Pass `true` to get JSON instead of an array. The two optional arguments after it
are handed to `json_encode()` as its flags and depth. If encoding fails, for
example on a string that is not valid UTF-8, the whole result is `'{}'`.

```php
$audit->getModified(true, JSON_PRETTY_PRINT);
```

Reading an audit does not disturb the model it formats against. Casting a
historical value through a class cast caches the result on that model, and a
later `save()` would write the cache back; the cache entry is saved and
restored around each cast, so reading an audit and then saving the model writes
only the model's own values.

## `getMetadata()`

`getMetadata()` returns everything about the audit except the field values,
with the same `true` / flags / depth arguments for JSON:

| Key | Value |
|---|---|
| `audit_id` | The audit's key |
| `audit_event` | The event name |
| `audit_tags` | The raw `tags` column, comma-separated, or null |
| `audit_batch_uuid` | The batch id, or null |
| `audit_created_at`, `audit_updated_at` | The audit's own timestamps, serialized, or null |
| `user_id`, `user_type` | The causer's key and morph type, or null; always under these names, whatever the morph prefix |
| `audit_<name>` | One per key in `auditing.resolvers`: `audit_ip_address`, `audit_user_agent`, `audit_url` by default |
| `user_<attribute>` | One per arrayable attribute of the causer, when there is one |

The `user_*` attributes come from the causer's `getArrayableAttributes()`, so
anything in its `$hidden`, a password hash for instance, is left out. They are
formatted through the causer's casts and accessors, the way field values are
formatted through the subject's.

## `getTags()`

```php
$audit->tags;       // 'billing,nightly'
$audit->getTags();  // ['billing', 'nightly']
```

`getTags()` splits the `tags` column on commas and drops empty pieces, so an
audit without tags gives `[]`. The tags are whatever the model's
`generateTags()` returned when the audit was written (see
[Recording](recording.md#generatetags)).

## `old_values` and `new_values`

The two accessors return the stored text keyed by field, without decoding or
casting; [Schema](schema.md#old_values-and-new_values) describes them. Use them
when you want the stored form, and `getModified()` when you want the model's.

## The flattened form

`resolveData()` flattens an audit into one array: the metadata keys above, then
`new_<field>` and `old_<field>` for every recorded value. `getDataValue($key)`
returns one entry of it, decoded and formatted as `getModified()` does except
that dates stay `DateTimeInterface` objects, or null for a key that is not
there.

```php
$audit->resolveData();
$audit->getDataValue('new_title');   // 'Revised title'
$audit->getDataValue('user_email');  // the causer's email
```

`getModified()` and `getMetadata()` build this array on first use and keep it on
the audit instance. Call `resolveData()` again after changing the audit or its
relations in memory.

## Related

- [Recording](recording.md)
- [Schema](schema.md)
- [Attribute modifiers](attribute-modifiers.md)
- [Transitions](transitions.md)
- [Batches](batches.md)
- [Resolvers](resolvers.md)
- [Audit model](audit-model.md)
