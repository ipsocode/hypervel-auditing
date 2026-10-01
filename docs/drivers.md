# Drivers

A driver is what persists an audit; this page covers how the `Auditor` picks
and calls one, what the bundled `audit_details` driver does, and how to write
and register a driver of your own.

## Choosing a driver

`auditing.driver` names the driver for every model and defaults to
`'audit_details'`. A model's `$auditDriver` property overrides it for that
model:

```php
use App\Auditing\MirroredDriver;
use Hypervel\Database\Eloquent\Model;
use Ipsocode\Auditing\Auditable;
use Ipsocode\Auditing\Contracts\Auditable as AuditableContract;

class Article extends Model implements AuditableContract
{
    use Auditable;

    protected string $auditDriver = MirroredDriver::class;
}
```

The value is a name or a class. `Auditor::auditDriver($model)` turns it into a
driver by trying, in order:

1. A creator registered under that name with `extend()` (see
   [Registering a named driver](#registering-a-named-driver)).
2. A driver built into the `Auditor`. `audit_details` is the only one.
3. A class of that name, built through the container, so its constructor
   dependencies are injected.

A value that matches none of them throws `InvalidArgumentException`, and an
object that does not implement `Contracts\AuditDriver` throws
`AuditingException` ("The driver must implement the AuditDriver contract").
Both surface when an audit is about to be written, not when the application
boots.

## How the `Auditor` calls a driver

Every audit goes through `Auditor::execute($model)`: model events, written
inline or by the queue job, [relationship audits](relationship-auditing.md)
and [manual audits](manual-audits.md). It runs these steps:

1. Returns `null` if the model is not ready: its event is not audited, or
   auditing is disabled for it.
2. Resolves the model's driver.
3. Fires `Events\Auditing`. A listener returning `false` ends it here.
4. Builds the audit payload with `$model->toAudit()`, which runs the
   [resolvers](resolvers.md).
5. Drops the audit if both of its sides are empty, unless
   `auditing.empty_values` is true or the event is listed in
   `auditing.allowed_empty_values`.
6. Hands the audit to the driver: `auditWithData($model, $payload)` when the
   driver implements `Contracts\AcceptsResolvedAudit`, `audit($model)`
   otherwise.
7. If the driver returned `null`, returns `null`: no prune, no `Audited` event.
8. Calls the driver's `prune($model)` and ignores what it returns.
9. Fires `Events\Audited` with the model, the driver and the audit, and returns
   the audit.

A driver therefore never sees an audit that is disabled, vetoed, or empty when
empty audits are not wanted. [Events](events.md) covers the two events.

## The bundled `audit_details` driver

`Drivers\AuditDetails` stores each audit as one `audits` row plus one
`audit_details` row per field, in a single transaction on the audit
connection. [Schema](schema.md) describes the two tables and how values are
stored as text.

It writes through the class in `auditing.implementation`, which must have a
`details()` relation; see [Audit model](audit-model.md). Its `prune()`
enforces the per-model threshold; see [Retention](retention.md). It implements
`AcceptsResolvedAudit`, so the payload is built once per audit, and its
`audit()` method builds the payload itself for callers that use the plain
contract.

## Writing a driver

A driver implements `Contracts\AuditDriver`:

```php
namespace Ipsocode\Auditing\Contracts;

interface AuditDriver
{
    public function audit(Auditable $model): ?Audit;

    public function prune(Auditable $model): bool;
}
```

- `audit()` writes the audit and returns it as a `Contracts\Audit`. Returning
  `null` tells the `Auditor` that nothing was written: it then skips the prune
  and the `Audited` event, and a manual audit's `log()` returns `null`. A
  driver that stores audits somewhere other than the audit tables still needs
  to return an audit for those to run.
- `prune()` runs after every audit the driver writes. Return `true` if it
  deleted anything. `$model->getAuditThreshold()` is the per-model limit, `0`
  for none; a driver that keeps everything returns `false`.

The payload from `$model->toAudit()` is an array with these keys, after the
model's `transformAudit()` has had the last word:

| Key | Value |
|---|---|
| `old_values`, `new_values` | The two sides, keyed by attribute, or by relation or property name for relationship and manual audits. Model events have their [attribute modifiers](attribute-modifiers.md) applied |
| `event` | The event name |
| `auditable_type`, `auditable_id` | The audited model's morph class and key |
| `user_type`, `user_id` | The causer's morph class and auth identifier, or null. The prefix is `auditing.user.morph_prefix` |
| `tags` | `generateTags()` joined with commas, or null |
| `batch_uuid` | The [batch](batches.md) the audit belongs to, or null |
| One key per resolver | `ip_address`, `user_agent` and `url` by default |

This driver writes every audit through the bundled one and also sends a
summary of it to the log:

```php
namespace App\Auditing;

use Hypervel\Support\Facades\Log;
use Ipsocode\Auditing\Contracts\AcceptsResolvedAudit;
use Ipsocode\Auditing\Contracts\Audit;
use Ipsocode\Auditing\Contracts\Auditable;
use Ipsocode\Auditing\Contracts\AuditDriver;
use Ipsocode\Auditing\Drivers\AuditDetails;

class MirroredDriver implements AuditDriver, AcceptsResolvedAudit
{
    public function __construct(
        private readonly AuditDetails $database,
    ) {
    }

    public function audit(Auditable $model): ?Audit
    {
        return $this->auditWithData($model, $model->toAudit());
    }

    public function auditWithData(Auditable $model, array $data): ?Audit
    {
        $audit = $this->database->auditWithData($model, $data);

        if ($audit !== null) {
            Log::info('Audit written', [
                'event' => $data['event'],
                'auditable' => $data['auditable_type'] . ':' . $data['auditable_id'],
                'fields' => array_keys(($data['old_values'] ?? []) + ($data['new_values'] ?? [])),
            ]);
        }

        return $audit;
    }

    public function prune(Auditable $model): bool
    {
        return $this->database->prune($model);
    }
}
```

Set `auditing.driver` or a model's `$auditDriver` to the class name, and the
container builds it with its `AuditDetails` dependency. There is no generator
command for drivers.

### Accepting the resolved payload

The `Auditor` builds the payload once, in step 4, to check for an empty audit.
A driver that also implements `Contracts\AcceptsResolvedAudit` is handed that
same array:

```php
public function auditWithData(Auditable $model, array $data): ?Audit;
```

A driver without it is called through `audit($model)`, and if it calls
`toAudit()` there, every resolver and the user resolver run a second time for
the same audit. Keep `audit()` working as well, as the example does, since
other code can call a driver through the plain contract.

## Registering a named driver

To refer to a driver by a short name, register a creator on the `Auditor` in a
service provider's `boot()`:

```php
use App\Auditing\MirroredDriver;

public function boot(): void
{
    $this->app->make('auditor')->extend('mirrored', function ($app) {
        return $app->make(MirroredDriver::class);
    });
}
```

The creator receives the container. Then use the name:

```php
// config/auditing.php
'driver' => 'mirrored',
```

A creator takes precedence over a built-in driver of the same name, so
`extend('audit_details', ...)` replaces the bundled driver.

The `auditor` alias and `Contracts\Auditor` both return the shared instance
that writes audits; building the `Ipsocode\Auditing\Auditor` class directly
gives you a separate one whose creators the package never uses. `extend()`
comes from Hypervel's `Manager` and is not part of `Contracts\Auditor`, which
is why the example resolves the alias.

## One instance per worker

The `Auditor` is a container singleton, and it keeps the first instance it
creates of each driver, by name or class, for the rest of the worker's life.
Every audit on that worker, from every concurrent request, goes through that
one instance. So:

- Keep no per-request state on a driver, such as the current user, the request
  or a running count. Store what has to be per request in
  `Hypervel\Context\CoroutineContext`, or read it from the model or the
  payload.
- The constructor runs once per worker. Inject services there, not anything
  that belongs to a request.
- Register creators at boot. A creator registered after its name has been
  resolved is not used on that worker, because the instance is already kept.

The bundled driver holds no state, which is what makes sharing it safe.
[Coroutines](coroutines.md) covers the same rule for the rest of the package.

## Related

- [Schema](schema.md)
- [Audit model](audit-model.md)
- [Retention](retention.md)
- [Events](events.md)
- [Resolvers](resolvers.md)
- [Configuration](configuration.md)
- [Coroutines](coroutines.md)
