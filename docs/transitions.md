# Transitions

`transitionTo()` fills a model with the values one of its audits recorded,
either the state after the change or the state before it, so you can inspect
the result or save it.

```php
$audit = $article->audits()->where('event', 'updated')->latest()->first();

$article->transitionTo($audit);        // the values after the change
// or
$article->transitionTo($audit, true);  // the values before it

$article->save();
```

## What it changes

`transitionTo()` reads the audit's `getModified()` and sets each recorded
attribute with `setAttribute()`, taking the `new` side by default and the `old`
side when the second argument is `true`. Attributes the audit did not record
keep their current values. Nothing is saved: inspect the pending change with
`getDirty()` before deciding.

```php
$article->transitionTo($audit, true);

$article->getDirty();   // ['title' => 'Draft title']
```

It returns the model, so it can be chained.

A side the event does not have changes nothing. Asking a `created` or
`restored` audit for its old values, a `deleted` audit for its new values, or a
`retrieved` audit for either leaves the model as it was.

Saving afterwards is an ordinary update, recorded as an `updated` audit like any
other, so reverting a change leaves its own entry in the trail.

## Values

The values set are the ones `getModified()` returns (see
[Reading audits](reading-audits.md)): decoded where an encoder applies, then
passed through the attribute's casts and accessors, with dates as serialized
strings. They go back in through `setAttribute()`, so set mutators and casts
run on them like on any assignment. A date or a cast value round-trips this
way; a get accessor that rewrites the value itself, rather than only its type,
writes its rewritten form back. Set such attributes from `old_values` or
`new_values`, which hold the stored text, instead.

Decoding and formatting go through the audit's `auditable` relation. A model the
relation cannot find, such as a soft-deleted one, is filled with the stored text
as it is, encoded values included.

## When it throws

Every check runs before any attribute is set, so when `transitionTo()` throws,
the model is unchanged. It throws an `AuditableTransitionException`, a subclass
of `AuditingException`, in these cases, checked in this order:

| Case | Message |
|---|---|
| The audit belongs to another model class: its `auditable_type` is not this model's morph class | `Expected Auditable type App\Models\Article, got App\Models\Post instead` |
| The audit belongs to another record. The keys are compared strictly, so an integer key and the same number as a string also differ | `Expected Auditable id (integer)7, got (integer)5 instead` |
| The model has a [redactor](attribute-modifiers.md) registered for any attribute, whether or not the audit holds it | `Cannot transition states when an AttributeRedactor is set` |
| The audit holds attributes the model does not have | `Incompatibility between [App\Models\Article:7] and [Ipsocode\Auditing\Models\Audit:42]` |

The last case compares the audit's fields with the model's loaded attributes,
so it fires for a column dropped or renamed since the audit was written, for a
model fetched with a narrowed `select()`, and for any
[pivot](relationship-auditing.md) or [manual](manual-audits.md) audit, whose
fields are relation and property names. `getIncompatibilities()` names the
fields; it is empty for the other three cases.

```php
use Ipsocode\Auditing\Exceptions\AuditableTransitionException;

try {
    $article->transitionTo($audit, true);
} catch (AuditableTransitionException $exception) {
    $missing = $exception->getIncompatibilities();   // ['subtitle']
}
```

## Related

- [Reading audits](reading-audits.md)
- [Attribute modifiers](attribute-modifiers.md)
- [Recording](recording.md)
- [Schema](schema.md)
