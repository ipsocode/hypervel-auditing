# Resolvers

Resolvers fill the columns that describe where an audit came from (the URL,
the IP address, the user agent and the causer); this page covers the bundled
ones, how to add or replace one, and the rule every resolver must follow when
audits are queued.

## The bundled resolvers

`auditing.resolvers` maps an `audits` column to the class that fills it:

| Key | Class | Value |
|---|---|---|
| `ip_address` | `Resolvers\IpAddressResolver` | `Request::ip()` |
| `user_agent` | `Resolvers\UserAgentResolver` | The `User-Agent` header, or `''` |
| `url` | `Resolvers\UrlResolver` | `Request::fullUrl()` for a request. In a console process, the command line, such as `artisan import:articles --fresh`, or `'console'` when there is none |

The command line comes from `$_SERVER['argv']`, because the request Hypervel
builds from the Swoole request carries no `argv`.

With the bundled driver, each resolver runs once for an audit written inline,
while the audit payload is built. Its result goes into the `audits` column
named by its key, and `getMetadata()` reports it as `audit_<key>`:
`audit_ip_address`, `audit_user_agent`, `audit_url` (see
[Reading audits](reading-audits.md)).

## The causer

The causer is resolved separately, by the `Contracts\UserResolver` in
`auditing.user.resolver`. Its static `resolve()` returns the user, or `null`
for none:

```php
namespace Ipsocode\Auditing\Contracts;

interface UserResolver
{
    /**
     * @return null|\Hypervel\Contracts\Auth\Authenticatable
     */
    public static function resolve();
}
```

The bundled `Resolvers\UserResolver` tries the guards in `auditing.user.guards`
in order (`web`, then `sanctum`, by default) and returns the user of the first
one that has an authenticated user. A guard that throws an exception, such as
one the application does not define, is skipped.

The user's `getAuthIdentifier()` and `getMorphClass()` are stored in `user_id`
and `user_type`, so a custom user resolver should return an Eloquent model.
The column names follow `auditing.user.morph_prefix`. `$audit->user` loads the
causer back.

A manual audit can name its causer instead: `Auditor::on($model)->by($user)`
records `$user` and skips the user resolver for that audit (see
[Manual audits](manual-audits.md)).

## Adding a resolver

A resolver implements `Contracts\Resolver`, a single static method that
receives the model being audited:

```php
namespace App\Auditing;

use Hypervel\Support\Facades\Request;
use Ipsocode\Auditing\Contracts\Auditable;
use Ipsocode\Auditing\Contracts\Resolver;

class RequestIdResolver implements Resolver
{
    public static function resolve(Auditable $auditable): ?string
    {
        return $auditable->preloadedResolverData['request_id'] ?? Request::header('X-Request-Id');
    }
}
```

The first lookup is what keeps it correct for queued audits; see
[Queued audits](#queued-audits).

Register it under the name of the column it fills. `resolvers` merges with the
package's defaults key by key, so the three bundled resolvers stay:

```php
// config/auditing.php
'resolvers' => [
    'request_id' => App\Auditing\RequestIdResolver::class,
],
```

Then add the column. The bundled driver writes every payload key to the
`audits` row, so until the column exists, every audit fails on insert:

```php
use Hypervel\Database\Migrations\Migration;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\Schema;
use Ipsocode\Auditing\Support\AuditTables;

return new class extends Migration {
    public function up(): void
    {
        Schema::connection(config('auditing.connection'))->table(AuditTables::audits(), function (Blueprint $table) {
            $table->string('request_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection(config('auditing.connection'))->table(AuditTables::audits(), function (Blueprint $table) {
            $table->dropColumn('request_id');
        });
    }
};
```

`resolve()` is static, and a class's static properties are shared by every
request the worker serves at the same time. Keep a resolver free of state of
its own; see [Coroutines](coroutines.md).

## Replacing or switching off a resolver

Point a bundled key at another class to change how it is filled:

```php
'resolvers' => [
    'ip_address' => App\Auditing\ForwardedIpResolver::class,
],
```

Set a key to `null` and the resolver is skipped: its column is left null. The
key still appears in `getMetadata()`, with a null value.

A configured class that does not implement the matching contract throws an
`AuditingException`, "Invalid Resolver implementation for: <key>" or "Invalid
UserResolver implementation", as soon as an audit needs it: from the save,
relationship helper or `log()` call that triggered the audit, or for a queued
audit, on the request before it is dispatched.

## Queued audits

Resolvers run where the audit payload is built. For an inline audit that is
the code that triggered it, usually inside the request. With
[queued auditing](queued-auditing.md) it is the job that writes the audit,
which can run after the request has finished, in another coroutine or in a
`queue:work` process. A resolver that reads the request there reads the wrong
one, or none. On `queue:work`, which is a console process, a URL read in the
job would be the worker's own command line.

So before a model event's audit is dispatched, the observer calls
`preloadResolverData()` on the request. It runs every resolver and the user
resolver, and stores the results on the model in `$preloadedResolverData`:
one entry per configured resolver, plus `user` when there is a causer. That
array crosses the queue with the audit. In the job:

- The user resolver is not called when a `user` entry is present; the stored
  user is the causer.
- The resolvers are called again, and each bundled one returns the stored
  value for its key when there is one.

A resolver of your own must do the same: read its key from
`$auditable->preloadedResolverData` first, and resolve only when that is
missing, as `RequestIdResolver` above does. One that skips the lookup records
whatever the job sees, usually nothing useful.

[Relationship audits](relationship-auditing.md) and
[manual audits](manual-audits.md) are always written inline, so their
resolvers run in the code that triggers them.

## Related

- [Queued auditing](queued-auditing.md)
- [Reading audits](reading-audits.md)
- [Manual audits](manual-audits.md)
- [Schema](schema.md)
- [Configuration](configuration.md)
- [Coroutines](coroutines.md)
