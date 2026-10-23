# Testing

What a test suite needs for audits to be recorded, what the package resets
after every test, and how to turn auditing off or assert on it in a test.

## Recording audits in tests

PHPUnit runs as a console process, and in a console process model events are
audited only when `auditing.console` is `true`. It defaults to `false`, and the
bundled config reads no environment variable for it, so a test suite has to
turn it on or no model audits are recorded.

The observer checks the setting on every audited event, so it can be set at
any point before the event, for example in the base test case:

```php
namespace Tests;

use Hypervel\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['auditing.console' => true]);
    }
}
```

The application is rebuilt for each test, so the setting applies to every
test. A model saved earlier than this, while `parent::setUp()` seeds the
database for instance, is not audited.

To have the value in place before anything runs, have your published
`config/auditing.php` read it from an environment variable of your choosing,
and set that variable in `phpunit.xml`:

```php
// config/auditing.php
'console' => env('AUDITING_CONSOLE', false),
```

```xml
<php>
    <env name="AUDITING_CONSOLE" value="true"/>
</php>
```

A package tested with Testbench can set the key in `defineEnvironment()`
instead, which runs before the service providers boot; this package's own
suite does that in `tests/TestCase.php`.

[Configuration](configuration.md#switches-read-at-boot) explains where the two
switches are read.

## What is reset after every test

Some of the package's state outlives a single test: static flags last for the
whole PHPUnit process, and context written outside a coroutine is copied into
every later test. Left alone, one test that turns auditing off would turn it
off for every test after it in that process.

`Ipsocode\Auditing\Testing\TestState` resets it after every test:

- `Models\Audit::$auditingGloballyDisabled`, set back to `false`.
- `$auditingDisabled`, set back to `false` on every loaded class that uses the
  `Auditable` trait. The classes are found by scanning the declared classes,
  so a flag assigned directly is reset as well as one set by
  `disableAuditing()`.
- The coroutine context entries the package writes: the global
  `withoutAuditing()` scope and the scope of every loaded auditable class, the
  observer's in-flight restore markers, and the open batch. They are cleared
  from the current coroutine and from the worker-level storage that code
  running outside a coroutine, such as a test's `setUp()`, writes to.

It does not touch configuration or the database, and it knows nothing about
state of your own; see
[Resetting state of your own](#resetting-state-of-your-own).
`TestState::flushState()` is public, so a test can also call it directly.

### How it is registered

`TestState` is declared in the package's `composer.json`:

```json
"extra": {
    "hypervel": {
        "test-state": [
            "Ipsocode\\Auditing\\Testing\\TestState"
        ]
    }
}
```

The framework's PHPUnit extension discovers that entry in every installed
package and runs the registered cleanup after each test, so an application
that requires this package gets the reset with no setup of its own. The
extension has to be registered in `phpunit.xml`; nothing else runs the
cleanup:

```xml
<extensions>
    <bootstrap class="Hypervel\Testing\PHPUnit\AfterEachTestExtension"/>
</extensions>
```

The extension runs the cleanup after every test, `#[UnitTest]` methods
included, although those never boot an application: registration goes through
the extension, not through the package's service provider.

An application that lists this package under `extra.hypervel.dont-discover`
skips its registrar along with its service provider. List
`Ipsocode\Auditing\Testing\TestState` under `extra.hypervel.test-state` in the
application's own `composer.json` to keep the reset.

## Turning auditing off in a test

`withoutAuditing()` keeps setup out of the audit trail and leaves the code
under test audited:

```php
$article = Article::withoutAuditing(fn () => Article::factory()->create());

$article->update(['title' => 'Revised title']);

$this->assertSame(1, $article->audits()->count());
```

A test that does not care about audits at all can switch a model off for the
whole test. `TestState` turns it back on after each test, so call it in
`setUp()`, not once per class:

```php
protected function setUp(): void
{
    parent::setUp();

    Article::disableAuditing();
}
```

[Disabling auditing](disabling-auditing.md) covers both, and why
`disableAuditing()` belongs in tests and boot code only.

## Asserting on audits

`Auditor::fake()` replaces the auditor with one that records the audits the
real auditor would write, and asserts on them. Nothing reaches the audit
tables, and no driver runs:

```php
use Ipsocode\Auditing\Contracts\Audit;
use Ipsocode\Auditing\Facades\Auditor;

$fake = Auditor::fake();

$article->update(['title' => 'Revised']);

$fake->assertAudited($article, 'updated');
$fake->assertAudited(Article::class, 'updated', fn (Audit $audit) => $audit->getModified()['title']['new'] === 'Revised');
$fake->assertNotAudited($article, 'deleted');
$fake->assertAuditedCount(1);

$fake->recorded(fn (Audit $audit) => $audit->event === 'updated');   // a Collection
```

- `assertAudited($model, ?string $event = null, ?callable $callback = null)`
  takes a model instance, matched with `is()`, or a class name, matching any
  instance of it. A null event matches any event, and the callback receives
  each audit that matches so far.
- `assertNotAudited()` takes the same arguments. `assertNothingAudited()` takes
  none, and `assertAuditedCount()` takes the number of audits recorded.
- A failed assertion lists what was recorded, by model, key and event.

Model events, the relationship helpers and manual audits made with
`Auditor::on()` are all recorded; `log()` returns the recorded audit. The fake
applies the gates the real auditor applies before it reaches a driver: an
event that is not audited, `withoutAuditing()` and the empty-value guard record
nothing. A recorded audit is an unsaved instance of `auditing.implementation`,
and `getModified()`, `getMetadata()`, the causer and the batch id read back as
they would from a stored one. `actingAs()`, `withinBatch()`, `currentBatch()`
and `auditDriver()` still go to the real auditor.

The fake is also bound to `Ipsocode\Auditing\Contracts\Auditor`, so a class
that injects the contract gets it. Calling `Auditor::fake()` again starts a
fresh recording. It lasts for the test: the framework rebuilds the application,
and with it the facade, before the next one.

The fake fires neither `Auditing` nor `Audited`. A listener that vetoes an
audit is not consulted, so `assertNotAudited()` does not reflect it, and no
listener is handed an audit that was never saved. With queued auditing on the
`sync` connection the job records through the fake like any other audit.

Model events still need `auditing.console` on, as in
[Recording audits in tests](#recording-audits-in-tests): the observer checks it
on every event, and the fake does not change that.

To check what is stored, read the audits back through the model, which runs the
real driver and so needs the audit tables:

```php
$article = Article::factory()->create(['title' => 'Draft title']);

$article->update(['title' => 'Revised title']);

$this->assertSame(
    ['title' => ['new' => 'Revised title', 'old' => 'Draft title']],
    $article->audits()->latest('id')->first()->getModified(),
);
```

To assert on the package's events instead, fake only those events.
`Event::fake()` with no arguments fakes the model events as well, so the audit
observer never runs and nothing is audited:

```php
use Hypervel\Support\Facades\Event;
use Ipsocode\Auditing\Events\Audited;

Event::fake([Audited::class]);

$article->update(['title' => 'Revised title']);

Event::assertDispatched(Audited::class, fn (Audited $event) => $event->model->is($article));
```

## Resetting state of your own

A resolver, attribute modifier or driver of your own that keeps a static
property needs the same reset, or one test's values leak into the next. Write
a registrar with a static `register()` method that hands a callback to
`AfterEachTestCleanup::flushUsing()`:

```php
namespace App\Testing;

use App\Auditing\AuditTagRegistry;
use Hypervel\Testing\PHPUnit\AfterEachTestCleanup;

class AuditingTestState
{
    public static function register(): void
    {
        AfterEachTestCleanup::flushUsing('app/auditing', static function (): void {
            AuditTagRegistry::flushState();
        });
    }
}
```

and list it under `extra.hypervel.test-state`, in the application's
`composer.json` or in your package's. A static of that kind is shared by every
request on a production worker too; [Coroutines](coroutines.md) covers where
such state belongs instead.

## Related

- [Disabling auditing](disabling-auditing.md)
- [Configuration](configuration.md)
- [Coroutines](coroutines.md)
- [Events](events.md)
- [Reading audits](reading-audits.md)
