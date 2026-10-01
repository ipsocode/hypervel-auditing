# Batches

How to tie together the audits written by one logical operation, so they can
be fetched as a group.

## Opening a batch

A request that changes an order and its invoice writes two unrelated `audits`
rows. Run the work inside `Auditor::withinBatch()` and every
audit written inside it carries the same `batch_uuid`:

```php
use Ipsocode\Auditing\Facades\Auditor;
use Ipsocode\Auditing\Models\Audit;

$batch = Auditor::withinBatch(function () use ($order, $invoice) {
    $order->update(['status' => 'paid']);
    $invoice->update(['paid_at' => now()]);

    return Auditor::currentBatch();
});

Audit::where('batch_uuid', $batch)->get();
```

- `withinBatch()` returns whatever the callback returns.
  `Auditor::currentBatch()` returns the id of the batch open on the current
  coroutine, or `null`.
- Every kind of audit written inside the callback, on this coroutine, joins
  the batch: model events on any model,
  [relationship helpers](relationship-auditing.md) and
  [manual audits](manual-audits.md).
- An audit written outside a batch has a null `batch_uuid`. The column is
  nullable and indexed.
- The batch closes when the callback returns or throws.
- `getMetadata()` reports the id as `audit_batch_uuid`.

## Batch ids

A generated id is a version 7 UUID. Those are time-ordered, so new batches
append to the `batch_uuid` index instead of landing at random places in it.

To group audits under an id you already have, such as the id of an import or
a job, pass it as the second argument:

```php
Auditor::withinBatch(fn () => $import->run(), $import->uuid);
```

The id has to fit the column, which the bundled migration creates with
`uuid()`: a native UUID type on PostgreSQL and MariaDB 10.7+, which reject
anything that is not a UUID, and `char(36)` on MySQL. An id in UUID form fits
on every database.

## Nesting

A nested `withinBatch()` without an id joins the enclosing batch, so one
operation stays one batch even when a step it calls opens a batch of its own.
A nested call with an id opens that batch for the length of its callback; the
enclosing batch is open again once it returns.

```php
Auditor::withinBatch(function () use ($order, $refundBatch) {
    Auditor::withinBatch(fn () => $order->update(['status' => 'packed']));                 // the outer batch
    Auditor::withinBatch(fn () => $order->update(['status' => 'refunded']), $refundBatch); // $refundBatch
    $order->update(['status' => 'closed']);                                                // the outer batch again
});
```

## Queued audits

The open batch belongs to the coroutine that opened it, and the queue job that
writes an audit runs elsewhere, often after the batch has closed. The batch id
is captured with the rest of the audit when it is dispatched, so a
[queued audit](queued-auditing.md) lands in the batch that produced it, even
when a connection with `after_commit` holds the job until a transaction
commits after the batch has closed.

Nothing is left on the model afterwards: a later audit of the same instance
belongs to whichever batch is open when it is written.

## Child coroutines

A batch is stored in the coroutine context, and a child coroutine starts with
an empty context unless told to copy its parent's. Audits written in a child
that does not copy it belong to no batch:

| Started with | Joins the open batch |
|---|---|
| `parallel()`, `go()`, `co()` | Only with `copyContext: true` |
| `Coroutine::create()` | No; `Coroutine::fork()` is the copying form |
| `Concurrency::run()` | Yes, on the default `coroutine` driver |
| `Concurrency::defer()` | No; see below |

```php
use function Hypervel\Coroutine\parallel;

Auditor::withinBatch(function () use ($articles) {
    parallel(
        $articles->map(fn ($article) => fn () => $article->update(['status' => 'published']))->all(),
        copyContext: true,
    );
});
```

Copying the whole context also carries any `withoutAuditing()` scope that is
open around the call; see [Disabling auditing](disabling-auditing.md).

`Concurrency::defer()` starts its tasks after the request or command has
finished, when the batch has already closed. Read the id while the batch is
open and reopen it inside the task:

```php
use Hypervel\Support\Facades\Concurrency;

Auditor::withinBatch(function () use ($report) {
    $batch = Auditor::currentBatch();

    Concurrency::defer(fn () => Auditor::withinBatch(
        fn () => $report->update(['status' => 'sent']),
        $batch,
    ));
});
```

Code that tracks batch ids of its own should keep them in the coroutine
context too. A static property or a container singleton is shared by every
request the worker serves concurrently, and their audits would interleave into
one batch.

## Related

- [Queued auditing](queued-auditing.md)
- [Coroutines](coroutines.md)
- [Manual audits](manual-audits.md)
- [Relationship auditing](relationship-auditing.md)
- [Reading audits](reading-audits.md)
- [Schema](schema.md)
