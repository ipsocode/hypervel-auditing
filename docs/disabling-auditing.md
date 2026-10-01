# Disabling auditing

How to stop audits from being written: around one callback, for a whole
worker, or through configuration.

## Around a callback: `withoutAuditing()`

```php
Article::withoutAuditing(fn () => $article->update(['title' => 'Quietly']));

// Every auditable model, not only Article and its subclasses:
Article::withoutAuditing(function () use ($article, $author) {
    $article->update(['title' => 'Quietly']);
    $author->update(['name' => 'Also quietly']);
}, globally: true);
```

`withoutAuditing()` returns whatever the callback returns. Without `globally`,
the scope covers the class it is called on and its subclasses; other models
keep auditing. With `globally: true` it covers every auditable model.

The scope is stored in the coroutine context, so it applies to the coroutine
that opened it and nothing else: concurrent requests on the same worker keep
auditing while the callback runs. Console commands and tests run in a
coroutine too and get the same isolation; only code running outside any
coroutine, such as a command that sets `$coroutine = false`, falls back to
storage shared by the worker.

Each call saves the scope it found and puts it back when the callback returns
or throws. Leaving an inner scope therefore never re-enables auditing for an
enclosing one:

```php
Article::withoutAuditing(function () use ($author) {
    Article::withoutAuditing(fn () => null);   // inner scope, Article only

    $author->update(['name' => 'Quietly']);    // still covered by the outer global scope
}, globally: true);
```

The scope suppresses every kind of audit for the models it covers: model
events, the [relationship helpers](relationship-auditing.md), which still
write the relation, and [manual audits](manual-audits.md), whose `log()`
returns `null`. With [queued auditing](queued-auditing.md) on, the check runs
on the request before anything is dispatched, so a suppressed audit never
reaches the queue.

### Child coroutines

A child coroutine starts with an empty context unless it is told to copy its
parent's. Work fanned out from inside the callback is audited as usual unless
the child copies the context:

| Started with | Inherits the scope |
|---|---|
| `parallel()`, `go()`, `co()` | Only with `copyContext: true` |
| `Coroutine::create()` | No; `Coroutine::fork()` is the copying form |
| `Concurrency::run()` | Yes, on the default `coroutine` driver |
| `Concurrency::defer()` | No: its tasks start after the request or command has finished, when the scope has closed |

```php
use function Hypervel\Coroutine\parallel;

Article::withoutAuditing(function () use ($articles) {
    parallel(
        $articles->map(fn ($article) => fn () => $article->update(['status' => 'archived']))->all(),
        copyContext: true,
    );
});
```

Pass `true` rather than a list of context keys. The scope is stored under one
key per model class, plus one for `globally`, so the caller cannot know in
advance which keys are set.

## For the whole worker: `disableAuditing()`

```php
Article::disableAuditing();
Article::enableAuditing();

Article::$auditingDisabled = true;   // the flag disableAuditing() sets

// Every auditable model:
\Ipsocode\Auditing\Models\Audit::$auditingGloballyDisabled = true;
```

These are static properties. A worker serves many requests at once, and all
of them share these flags until something resets them, so calling
`disableAuditing()` from request code switches auditing off for every
concurrent request on that worker. Set them at boot or in tests only, and use
`withoutAuditing()` for anything request-scoped. In tests,
[`Testing\TestState`](testing.md) resets every flag after each test.

Two details are easy to miss:

- `$auditingDisabled` is declared by the `Auditable` trait, so the class that
  uses the trait and all of its subclasses share one flag. Disabling a
  subclass disables its parent and its siblings too.
- The global flag is always read from `Ipsocode\Auditing\Models\Audit`, even
  when `auditing.implementation` names another class.

## Through configuration: `auditing.enabled` and `auditing.console`

`auditing.enabled` (env `AUDITING_ENABLED`, default `true`) is the master
switch. `auditing.console` (default `false`) additionally gates model audits
in a console process: an Artisan command, a seeder, a `queue:work` worker.
Requests served by the HTTP server do not count as console.
`Article::isAuditingEnabled()` returns the combined answer for the current
process.

Each audit path reads the two keys at a different point:

| Path | `auditing.enabled` | `auditing.console` |
|---|---|---|
| Model events, written inline | Once, when the model class boots in the worker | Once, at the same point |
| Model events, queued | At boot, and again when the job runs | At boot only |
| Relationship helpers | Every time, by `Listeners\RecordCustomAudit` | Not read |
| Manual audits (`Auditor::on()`) | Not read | Not read |

At boot, the trait registers the model's observer only when auditing is
enabled for the process, and a class boots once per worker. This means:

- A runtime change reaches relationship audits and the jobs that write queued
  audits, because both read the key each time. A model class that has already
  booted keeps writing its inline audits until the worker restarts.
- A model class that boots while auditing is off records no model events for
  the rest of the worker's life, even after the switch is turned back on.
- Queued audits handed over from a request are written on a `queue:work`
  worker whatever `auditing.console` says, but models saved by jobs on that
  worker are audited only when it is `true`.
- Manual audits ignore both keys. Wrap them in `withoutAuditing()`, or check
  the setting before calling `log()`.

To stop recording particular events rather than everything, narrow
`auditing.events` or the model's `$auditEvents`; see
[Recording](recording.md).

## Related

- [Recording](recording.md)
- [Coroutines](coroutines.md)
- [Testing](testing.md)
- [Configuration](configuration.md)
- [Queued auditing](queued-auditing.md)
- [Relationship auditing](relationship-auditing.md)
- [Manual audits](manual-audits.md)
