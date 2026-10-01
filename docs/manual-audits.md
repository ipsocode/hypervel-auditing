# Manual audits

How to record an event that has no column behind it, such as a login, an
export or a permission grant, against a model.

## Writing one

```php
use Ipsocode\Auditing\Facades\Auditor;

$audit = Auditor::on($article)
    ->as('exported')
    ->with(['format' => 'pdf', 'pages' => 12])
    ->log();
```

`Auditor::on()` takes any auditable model and returns an
`Ipsocode\Auditing\PendingAudit`. Outside the facade, call `on()` on an
injected `Ipsocode\Auditing\Contracts\Auditor`.

| Method | Effect |
|---|---|
| `as(string $event)` | Names the event |
| `with(array $properties)` | Adds properties to the new side; later calls merge, a repeated key takes the last value |
| `from(array $properties)` | Adds properties to the old side, merging the same way |
| `by(?Authenticatable $user)` | Records `$user` as the causer; `null` leaves the choice to the user resolver |
| `log(?string $event = null)` | Writes the audit and returns it, or `null` when nothing was written |

An event name passed to `log()` takes precedence over `as()`. Without a name
from either, `log()` throws `AuditingException`. Any name is accepted:
`auditing.events` and `$auditEvents` do not apply to manual audits.

Record both sides for a change of state:

```php
Auditor::on($order)
    ->as('approved')
    ->from(['state' => 'pending'])
    ->with(['state' => 'approved'])
    ->log();
```

## What is stored

The audit gets one `audits` row and one `audit_details` row per property key.
Values are stored as text, so they read back as strings:

```php
$audit->fresh()->new_values;   // ['format' => 'pdf', 'pages' => '12']
```

Arrays are stored as JSON. A side you did not fill reads back as `null` for
each key. Do not name a manual audit after a model event such as `created`
or `deleted`: the audit model reads one or both sides of those events back
as empty; see [Schema](schema.md).

The rest of the row is filled as for any audit: the model as the auditable,
the causer, the columns from the configured resolvers (by default the IP
address, user agent and URL of the current request), and the open batch.
The model's `generateTags()` and `transformAudit()` hooks run as usual.

No attribute rules apply to these properties. The model's attribute
modifiers, `$auditInclude` and `$auditExclude` cover its own attributes
only, so redact or encode a sensitive value yourself before passing it to
`with()` or `from()`.

## The causer

Without `by()`, the causer comes from `auditing.user.resolver`, which by
default returns the user authenticated on the current request. That is the
wrong answer for an action taken on someone else's behalf, and no answer at
all in a queue job or a console command. Pass the user explicitly in those cases:

```php
Auditor::on($account)->as('impersonated')->by($subject)->log();
```

## When nothing is written

`log()` returns `null` when:

- auditing is disabled for the model, by a `withoutAuditing()` scope or a
  static flag;
- an `Events\Auditing` listener returns `false`;
- both sides are empty, `auditing.empty_values` is `false`, and the event is
  not listed in `auditing.allowed_empty_values`;
- the driver writes nothing.

`auditing.enabled` and `auditing.console` are not read on this path; see
[Disabling auditing](disabling-auditing.md).

## How it runs

`log()` calls `Auditor::execute()` directly, so the audit is written inline,
before `log()` returns, whatever `auditing.queue.enable` says. On the way:

- `Events\Auditing` fires and can veto the write, then `Events\Audited`
  follows it. `Events\AuditCustom` is not dispatched; that event is how the
  relationship helpers reach the `Auditor`.
- The audit joins the batch open on the current coroutine, whatever the
  model's `auditBatchUuid` property holds.
- The driver's threshold prune runs afterwards, and manual audits count
  towards the model's threshold like any other audit.

To write the audit, `log()` sets the model's custom-audit state
(`auditEvent`, `auditCustomOld`, `auditCustomNew`, `isCustomEvent`,
`preloadedResolverData` and `auditBatchUuid`) and puts every value back
afterwards, including when a listener throws. A model that is in the middle
of its own audit is left undisturbed.

## Related

- [Relationship auditing](relationship-auditing.md)
- [Events](events.md)
- [Batches](batches.md)
- [Resolvers](resolvers.md)
- [Reading audits](reading-audits.md)
- [Disabling auditing](disabling-auditing.md)
- [Schema](schema.md)
