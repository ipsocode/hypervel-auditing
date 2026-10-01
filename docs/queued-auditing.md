# Queued auditing

How to move the audit write for model events off the request and onto a queue
connection.

## Turning it on

```php
// config/auditing.php
'queue' => [
    'enable' => true,
    'connection' => 'redis',
    'queue' => 'audits',
    'delay' => 0,
],
```

`queue` merges with the package defaults key by key, so a config that sets
only `enable` keeps the other three.

With `enable` false, the default, the observer calls `Auditor::execute()`
itself: the audit is written on the request coroutine, inside the caller's
transaction when that transaction is on the audit connection.

## What happens on the request

With `enable` true, each model event that would be audited goes through these
steps on the request coroutine:

1. The usual checks run: the event has to be one the model audits, and no
   `withoutAuditing()` scope or static flag may disable it. An audit that
   fails them is never dispatched.
2. The configured resolvers and the user resolver run, and their results are
   stored on the model (`preloadResolverData()`), because the job that writes
   the audit has no request to read.
3. `Events\DispatchingAudit` fires. A listener that returns `false` stops the
   audit; nothing is written.
4. The model's loaded relations are unloaded, so they are not serialized.
   This is the instance you saved, not a copy: load again any relation you
   still need.
5. `Events\DispatchAudit` is dispatched. It captures everything the job needs
   as it is constructed.

`Listeners\ProcessDispatchAudit`, a queued listener, handles `DispatchAudit` on
`queue.connection` and `queue.queue`. The job rebuilds the model and, if
`auditing.enabled` is still true, passes it to `Auditor::execute()`, which
fires `Events\Auditing` and `Events\Audited` there.

## Where the write happens

`enable` alone does not move the write: `connection` defaults to `sync`, which
runs the job immediately, on the same coroutine. The connection decides:

| `connection` | Where the audit is written | Survives a worker exit |
|---|---|---|
| `sync` | Immediately, on the request coroutine | Not applicable |
| `deferred` | On the request coroutine as it ends, after the response is sent | No |
| `background` | In a new coroutine on the same worker | No |
| `redis`, `database`, `sqs`, … | By a `queue:work` process | Yes |

`deferred` and `background` need no queue backend and no worker process. They
hold jobs in memory, so an audit that is not yet written when the worker exits
is lost. `deferred` needs a coroutine to attach the job to: a command that
sets `$coroutine = false` should use `sync` or `background`.

`delay` is in seconds; a numeric string, as `env()` returns, works too. A
positive delay sends the job through the connection's `later()`, which on
`deferred` and `background` is an in-memory timer that writes the audit that
many seconds afterwards. With `0` the job is pushed as usual.

## Transactions

Hypervel's shipped `config/queue.php` turns `after_commit` on for every
connection except `sync` and `database`. On those connections, an audit
dispatched inside a database transaction is written only after the
transaction commits, and dropped if it rolls back.

The payload is captured at dispatch. Saving the same model again before the
commit, or closing the [batch](batches.md) the audit was dispatched in, does
not change what is written.

## What crosses the queue

The job does not receive your model instance. It builds a new instance of the
model's class and restores a fixed list of properties onto it: `attributes`,
`original`, `excludedAttributes`, `auditEvent`, `auditExclude`,
`auditCustomOld`, `auditCustomNew`, `isCustomEvent`, `preloadedResolverData`
and `auditBatchUuid`, plus the name of the model's connection. Properties
declared on the class, such as `$auditInclude` or `$attributeModifiers`, have
their declared values on the new instance. Anything else set at runtime on
the instance you saved is lost unless you name it:

```php
class Article extends Model implements AuditableContract
{
    use Auditable;

    public array $auditEventSerializedProperties = ['changeReason'];

    // Set by the caller before saving and read by transformAudit(), so the
    // job that writes the audit needs it too.
    public ?string $changeReason = null;
}
```

`$auditEventSerializedProperties` itself must be public: it is read from
outside the model, and a protected or private declaration is not seen, so
nothing extra would be carried. The properties it names may be public,
protected or private, but a property private to a parent class is not found
and is skipped.

- A name that is not a property of the model is skipped.
- A typed property that is named but was never initialized throws an `Error`
  when the audit is dispatched, from the save that triggered it.
- A job whose recorded class is not auditable throws `AuditingException` when
  it is restored on the worker.

## Resolvers on the queue

The job that writes the audit has no request behind it, which is why step 2
runs the resolvers on the request and stores their results with the model.
The causer travels the same way, so a queued audit records the user who was
authenticated on the request. Every bundled resolver returns its stored
value in the job; a resolver of your own has to do the same, as
[Resolvers](resolvers.md) shows, or it records whatever the job sees.

## What is never queued

[Relationship audits](relationship-auditing.md) and
[manual audits](manual-audits.md) are always written inline, whatever
`queue.enable` says. The relationship page explains why.

## Related

- [Events](events.md)
- [Batches](batches.md)
- [Resolvers](resolvers.md)
- [Disabling auditing](disabling-auditing.md)
- [Configuration](configuration.md)
- [Coroutines](coroutines.md)
