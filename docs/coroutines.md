# Coroutines

The rules this package follows to stay correct on a long-lived Hypervel
worker, and that anything you plug into it (a driver, resolver, attribute
modifier or listener) has to follow too.

A Hypervel worker is one process that lives for many requests and serves them
concurrently, each request in its own coroutine. Static properties, container
singletons and anything else held by the process are shared by all of them
for as long as the worker runs, and so are object ids, which PHP hands out
again once an object is freed. Each rule below names the file where the
package applies it.

## Request-scoped state lives in the coroutine context

Everything the package tracks per request is stored in
`Hypervel\Context\CoroutineContext`, under the keys defined in
`src/Support/ContextKeys.php`:

| State | Written by |
|---|---|
| `withoutAuditing()` scopes, one per model class plus one for `globally: true` | `src/Auditable.php` |
| The open audit batch | `src/Support/AuditBatch.php` |
| The models whose restore is in flight | `src/AuditableObserver.php` |

Kept in a static instead, a `withoutAuditing()` scope would switch auditing off
for every request the worker is handling while the callback runs, and a batch
id would put the audits of two concurrent requests into one batch.

Console commands and test methods run inside a coroutine as well, so they get
the same isolation. Code that runs outside any coroutine, such as a command
that sets `$coroutine = false` or a test's `setUp()`, reads and writes a single
store shared by the worker.

The keys all start with `__auditing.`, which keeps them apart from the keys
an application stores; do not write to them from application code. State of
your own that must be per request belongs in the context too, under a prefix
of your own.

## Child coroutines start with an empty context

A scope or a batch belongs to the coroutine that opened it. Work handed to
`go()`, `co()`, `parallel()` or `Coroutine::create()` runs in a new coroutine
that starts with an empty context unless the parent's is copied into it, so
it is audited as if no scope or batch were open. How to carry them across is
covered in [Batches](batches.md#child-coroutines) and
[Disabling auditing](disabling-auditing.md#child-coroutines).

## Restore what you found, in a `finally`

A scope that clears its state on the way out, instead of restoring what was
there before, re-opens whatever enclosed it: an inner `withoutAuditing()` would
re-enable auditing for an outer scope that is still open, and an inner batch
would close the outer one. Both save the value they found and put it back in a
`finally`, so a callback that throws restores it too:

- `withoutAuditing()` in `src/Auditable.php`.
- `AuditBatch::within()` in `src/Support/AuditBatch.php`, behind
  `Auditor::withinBatch()`.

State written onto a model for one audit is handled the same way, because the
caller's model may be in the middle of an audit of its own:

- `PendingAudit::log()` in `src/PendingAudit.php` saves the model's
  custom-audit properties before a [manual audit](manual-audits.md) and
  restores them afterwards.
- The relationship helpers in `src/Auditable.php` reset that state in a
  `finally` once their audit has been dispatched.
- The observer in `src/AuditableObserver.php` removes a model's restore marker
  in a `finally`, so a listener that throws out of the `restored` audit does
  not leave the model marked.

## Track objects with weak references, not object ids

Restoring a soft-deleted model fires `updated` as well as `restored`, and the
observer in `src/AuditableObserver.php` marks the model while the restore is in
flight so that the `updated` audit is skipped. The marks are kept in a
`WeakMap` keyed by the model.

A restore that a `restoring` listener vetoes never reaches `restored`, so its
mark is never removed explicitly. A `WeakMap` entry goes away with its model.
An `spl_object_id()` key would outlive it, and PHP reuses ids, so a later,
unrelated model could pick up the mark and lose its `updated` audit. Because
the map itself lives in the coroutine context, concurrent restores of
different models on the same worker do not see each other's marks either.

## Shared instances hold no request state

Some objects are created once and then used by every request on the worker:

- The `Auditor` is a container singleton, registered in
  `src/AuditingServiceProvider.php`, and `src/Auditor.php`, through Hypervel's
  `Manager`, keeps the first instance of each driver it creates. A driver must
  not keep per-request state on itself; see
  [Drivers](drivers.md#one-instance-per-worker).
- `bootAuditable()` in `src/Auditable.php` registers one observer instance per
  model class, shared by every coroutine. It holds nothing per request: its
  only per-request state, the restore marks, is in the context.
- Resolvers, user resolvers, redactors and encoders are called statically, by
  `src/Auditable.php` when an audit is built and by `src/Audit.php` when an
  encoded value is decoded. A static property on one of them is shared by
  every request; see [Resolvers](resolvers.md) and
  [Attribute modifiers](attribute-modifiers.md#writing-a-modifier-safely).

## Worker-wide switches are for boot and tests

`Auditable::$auditingDisabled`, set by `disableAuditing()` in
`src/Auditable.php`, and `Models\Audit::$auditingGloballyDisabled` in
`src/Models/Audit.php` are static properties. Set from a request, either one
turns auditing off for every concurrent request on that worker until something
turns it back on. Set them at boot or in tests, and use `withoutAuditing()`
for anything request-scoped; see [Disabling auditing](disabling-auditing.md).

## A model class boots once per worker

`bootAuditable()` in `src/Auditable.php` runs when a model class first boots
in a worker, which happens once, in whichever coroutine first constructs the
model. It decides then whether to register the audit observer, from
`auditing.enabled` and, in a console process, `auditing.console`, and that
decision holds for the life of the worker; see
[Configuration](configuration.md#switches-read-at-boot).

Hypervel marks a class booted only once its `boot()` method has returned, so
constructing the model from inside `boot()`, which is where trait boot methods
such as `bootAuditable()` run, throws a `LogicException`. `bootAuditable()`
registers the observer from a `whenBooted()` callback, which runs after
`boot()` and `booted()`, so the observer's listeners come after any the model
registers in either. A trait boot method of your own that needs an instance of
the model has to wait for a `whenBooted()` callback too.

The decision is made from inside the model's constructor, so it must not
throw. When the container cannot answer, as with a container that has been
flushed while a facade root still points at it, `shouldRegisterAuditObserver()`
declines to register the observer, logs a warning if logging still works, and
`new SomeModel` succeeds; that class then records no model events in that
worker.

## State that lives for the worker needs a test reset

PHPUnit runs many tests in one process, so a static set by one test is still
set in the next. `src/Testing/TestState.php` resets every static flag and
context key the package owns after each test. Anything you add that lives for
the worker needs a reset registered the same way; see
[Testing](testing.md#resetting-state-of-your-own).

## Resolvers run where the audit is written

With [queued auditing](queued-auditing.md), the job that writes an audit can
run after the request has finished, in another coroutine or another process.
`preloadResolverData()` in `src/Auditable.php` runs the resolvers on the
request first, and a resolver of your own has to use what it stored; see
[Resolvers](resolvers.md#queued-audits).

## Related

- [Disabling auditing](disabling-auditing.md)
- [Batches](batches.md)
- [Drivers](drivers.md)
- [Resolvers](resolvers.md)
- [Testing](testing.md)
- [Queued auditing](queued-auditing.md)
- [Configuration](configuration.md)
- [Contributing: coroutine safety](../CONTRIBUTING.md#coroutine-safety)
