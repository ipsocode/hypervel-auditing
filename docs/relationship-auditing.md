# Relationship auditing

How to record changes to a many-to-many relationship, which Eloquent writes
without firing a model event.

## The helpers

Call the helper on the parent model, naming the relationship method:

```php
$article->auditAttach('categories', $category);
$article->auditAttach('categories', [1, 2], ['featured' => true]);

$article->auditDetach('categories', $category);
$article->auditDetach('categories');                 // every attached row

$article->auditSync('categories', [1, 2, 3]);
$article->auditSyncWithoutDetaching('categories', [4]);
$article->auditSyncWithPivotValues('categories', [1, 2], ['featured' => true]);
```

Each helper performs the write through the relation and then records one
audit on the parent model:

| Helper | Performs | Event recorded | Returns |
|---|---|---|---|
| `auditAttach($relation, $id, $attributes = [], $touch = true, $columns = ['*'], $callback = null)` | `attach()` | `attach` | Nothing |
| `auditDetach($relation, $ids = null, $touch = true, $columns = ['*'], $callback = null)` | `detach()` | `detach` | The number of rows detached |
| `auditSync($relation, $ids, $detaching = true, $columns = ['*'], $callback = null)` | `sync()` | `sync` | `sync()`'s `attached` / `detached` / `updated` arrays |
| `auditSyncWithoutDetaching($relation, $ids, $columns = ['*'], $callback = null)` | `sync()` without detaching | `sync` | As `auditSync()` |
| `auditSyncWithPivotValues($relation, $ids, $values, $detaching = true, $columns = ['*'], $callback = null)` | `sync()` with `$values` on every row | `sync` | As `auditSync()` |

`auditSyncWithPivotValues()` accepts a model, an Eloquent collection, a
collection, an array or a single id.

The relationship method has to exist on the model and return a relation that
supports the operation, a `BelongsToMany` or `MorphToMany`. Anything else
throws `AuditingException` ("Relationship … was not found or does not support
method …").

## What the audit holds

The audit belongs to the parent model and has one detail row, whose field is
the relationship name. The helper reads the related rows before and after the
write; the old side holds the rows that were removed and the new side the rows
that were added, both stored as JSON:

```php
$audit = $article->audits()->where('event', 'attach')->latest()->first();

json_decode($audit->new_values['categories'], true);   // the rows attached
json_decode($audit->old_values['categories'], true);   // [] for an attach
```

`$columns` chooses which columns of the related rows are read and recorded.
The before/after comparison matches rows by their key, so keep the key in the
list.

A write that changes nothing, such as syncing the ids already attached,
records an audit with no detail rows. With `auditing.empty_values` set to
`false` it records nothing, unless the event is listed in
`auditing.allowed_empty_values`.

The model's attribute modifiers, `$auditInclude` and `$auditExclude` apply to
its own attributes only; they do not touch the relationship rows.

## Narrowing the relation with a callback

The last parameter takes a closure that receives the relation before anything
is read or written. A constraint added there narrows both the rows recorded
and what `detach()` or `sync()` acts on:

```php
$article->auditDetach(
    'categories',
    callback: fn ($relation) => $relation->wherePivot('featured', true),
);
```

Only a `Closure` is applied; any other value is ignored. A closure that throws
is reported as an `AuditingException` ("Invalid Closure for categories
Relationship").

## Auditable pivot models

When the relation goes through a custom pivot class (`->using(...)`) that is
itself auditable, `auditDetach()`, `auditSync()` and the two sync variants
write inside the pivot class's `withoutAuditing()`. The change is then
recorded once, on the parent, rather than once more per pivot row.

`auditAttach()` does not wrap its write, so the pivot also audits each row it
inserts. A pivot with a composite key has no single id for
`audits.auditable_id`, so that audit fails on insert and `auditAttach()`
throws. Wrap the call in the pivot class's `withoutAuditing()`, as the other
helpers do, to record only the parent's `attach` audit:

```php
// ArticleCategory is the pivot class the relation passes to ->using().
ArticleCategory::withoutAuditing(fn () => $article->auditAttach('categories', $category));
```

## When the audit is written

The helper dispatches `Events\AuditCustom`, and `Listeners\RecordCustomAudit`
passes the model straight to the `Auditor`. The audit is therefore written
inline, on the calling coroutine, before the helper returns, whatever
`auditing.queue.enable` says. Code that changes a relationship often goes on
to act on the change having been recorded, so the audit has to exist when the
call returns. When the caller's transaction is on the audit connection, the
audit also commits or rolls back with it. [Queued auditing](queued-auditing.md)
applies to model events only.

Along the way:

- `RecordCustomAudit` reads `auditing.enabled` on every call, so turning it
  off at runtime stops relationship audits. `auditing.console` is not read.
- A `withoutAuditing()` scope covering the parent model suppresses the audit.
  The relation is still written.
- `Events\Auditing` fires and can veto the audit; `Events\Audited` follows
  the write.
- The audit joins the batch open on the current coroutine.
- If an `AuditCustom` listener throws, the parent's custom-audit state is
  still reset, so its next save records an ordinary audit.

## Related

- [Events](events.md)
- [Manual audits](manual-audits.md)
- [Queued auditing](queued-auditing.md)
- [Disabling auditing](disabling-auditing.md)
- [Batches](batches.md)
- [Reading audits](reading-audits.md)
- [Schema](schema.md)
