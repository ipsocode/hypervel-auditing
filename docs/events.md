# Events

The events the package dispatches while recording an audit, and what a
listener can do with each.

All five live in `Ipsocode\Auditing\Events`. Register listeners as for any
other event, typically in a service provider's `boot()`.

## Which paths fire which events

| Event | Model events, inline | Model events, queued | Relationship helpers | Manual audits |
|---|---|---|---|---|
| `DispatchingAudit` | No | On the request | No | No |
| `DispatchAudit` | No | On the request | No | No |
| `AuditCustom` | No | No | Yes | No |
| `Auditing` | Yes | In the job | Yes | Yes |
| `Audited` | Yes | In the job | Yes | Yes |

In order, per path:

- Model event, inline: `Auditing`, then `Audited`.
- Model event, queued: `DispatchingAudit` and `DispatchAudit` on the request,
  then `Auditing` and `Audited` in the job that writes the audit. On the `sync`
  connection the job runs straight away, on the request coroutine.
- Relationship helper: `AuditCustom`, then `Auditing` and `Audited`.
- Manual audit: `Auditing`, then `Audited`.

`Auditing` and `DispatchingAudit` are dispatched with `until()`: the first
listener to return something other than `null` ends the dispatch, and later
listeners do not run. Return `false` to veto. A listener that only observes
should return nothing, since returning `true` would also stop the listeners
after it, without vetoing anything.

The other three are dispatched normally and the package does not read what
their listeners return. The dispatcher still stops at a listener that returns
`false`, though, and skips the listeners after it, which can include the
package's own `RecordCustomAudit` or `ProcessDispatchAudit`.

An exception thrown by a listener propagates to the code that triggered the
audit, or out of the job on the queued path.

## `Auditing`

Fires just before an audit is written, once the model has passed the usual
checks (an audited event, auditing not disabled) and its driver has been
resolved. It fires before the audit data is built and before the empty-value
check, so an audit can still be dropped as empty after it.

| Property | Type | |
|---|---|---|
| `$model` | `Contracts\Auditable` | The model being audited. `getAuditEvent()` names the event; `isCustomEvent` is `true` for relationship and manual audits |
| `$driver` | `Contracts\AuditDriver` | The driver about to write it |

Veto: return `false`, and nothing is written. On the queued path it fires in
the job, so returning `false` there drops an audit that has already crossed
the queue.

```php
use Hypervel\Support\Facades\Event;
use Ipsocode\Auditing\Events\Auditing;

Event::listen(Auditing::class, function (Auditing $event) {
    if ($event->model instanceof Article && $event->model->status === 'draft') {
        return false;
    }
});
```

## `Audited`

Fires after the driver has written the audit and the threshold prune has run.
It does not fire when nothing was written.

| Property | Type | |
|---|---|---|
| `$model` | `Contracts\Auditable` | The audited model |
| `$driver` | `Contracts\AuditDriver` | The driver that wrote it |
| `$audit` | `null\|Contracts\Audit` | The audit written; always set when the package fires the event |

Veto: no, the audit already exists. This is the event to react to a written
audit with: the bundled driver inserts detail rows in bulk, so they fire no
Eloquent model events of their own.

```php
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Facades\Log;
use Ipsocode\Auditing\Events\Audited;

Event::listen(Audited::class, function (Audited $event): void {
    Log::info('Audit written', [
        'event' => $event->audit->event,
        'auditable' => $event->model->getMorphClass() . ':' . $event->model->getKey(),
    ]);
});
```

## `AuditCustom`

Dispatched by the [relationship helpers](relationship-auditing.md) after their
write, to hand the audit to the `Auditor`. The package's own listener,
`Listeners\RecordCustomAudit`, checks `auditing.enabled` and calls
`Auditor::execute()`.

| Property | Type | |
|---|---|---|
| `$model` | `Contracts\Auditable` | The parent model, carrying the custom-audit state: `auditEvent` (`attach`, `detach` or `sync`), `isCustomEvent`, and `auditCustomOld` / `auditCustomNew` keyed by relationship name |

That state is reset as soon as the dispatch returns, including when a
listener throws, so read it while handling the event.

Veto: not supported. To stop a relationship audit, veto `Auditing` or wrap
the helper call in `withoutAuditing()`.

Manual audits do not dispatch this event; record your own events with
[`Auditor::on()`](manual-audits.md) rather than by dispatching it.

## `DispatchingAudit`

Fires on the request when [queued auditing](queued-auditing.md) is on, before
a model event's audit is handed to the queue. By then the disabled checks have
passed and the resolvers have run.

| Property | Type | |
|---|---|---|
| `$model` | `Contracts\Auditable` | The model, with its event set and its resolver results stored in `preloadedResolverData` |

Veto: return `false`, and the audit is neither dispatched nor written inline.

## `DispatchAudit`

Dispatched on the request right after `DispatchingAudit` passes. It carries
the audit across the queue: the model state it needs is captured when the
event is constructed, and that captured state, not the live model, is what
gets serialized.

| Property | Type | |
|---|---|---|
| `$model` | `Contracts\Auditable` | On the request, the model you saved. After the event is restored in the job, a new instance rebuilt from the captured state |

Veto: no. The package handles it with `Listeners\ProcessDispatchAudit`, a
queued listener on `auditing.queue.connection` and `auditing.queue.queue`,
which checks `auditing.enabled` and calls `Auditor::execute()`. A listener of
your own that is not queued runs on the request, with the live model.

## Registration

`AuditingServiceProvider` registers the two package listeners,
`RecordCustomAudit` for `AuditCustom` and `ProcessDispatchAudit` for
`DispatchAudit`, whatever `auditing.enabled` says at boot. Both read the
setting each time they run, so it can be changed at runtime.

## Related

- [Queued auditing](queued-auditing.md)
- [Relationship auditing](relationship-auditing.md)
- [Manual audits](manual-audits.md)
- [Disabling auditing](disabling-auditing.md)
- [Drivers](drivers.md)
- [Retention](retention.md)
