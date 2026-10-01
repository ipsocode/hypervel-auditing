<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Testing;

use Hypervel\Context\CoroutineContext;
use Hypervel\Testing\PHPUnit\AfterEachTestCleanup;
use Ipsocode\Auditing\Auditable;
use Ipsocode\Auditing\Models\Audit;
use Ipsocode\Auditing\Support\ContextKeys;

/**
 * Package-level test-state registrar.
 *
 * Declared via `extra.hypervel.test-state` in composer.json and discovered
 * during PHPUnit extension bootstrap, so this package's worker-lifetime state is
 * reset in consuming applications too, including in workers that only run unit
 * tests and never boot a Hypervel application.
 */
class TestState
{
    public static function register(): void
    {
        AfterEachTestCleanup::flushUsing('ipsocode/hypervel-auditing', fn () => static::flushState());
    }

    /**
     * Re-enable auditing and drop this package's coroutine state. The static
     * flags live for the worker's lifetime, so a test that sets one would
     * otherwise suppress auditing for every later test in that worker.
     */
    public static function flushState(): void
    {
        Audit::$auditingGloballyDisabled = false;

        $keys = [ContextKeys::DISABLED_GLOBALLY, ContextKeys::RESTORING, ContextKeys::BATCH];

        foreach (static::auditableClasses() as $class) {
            $class::$auditingDisabled = false;
            $keys[] = ContextKeys::disabledFor($class);
        }

        // A Feature test's coroutine context ends with it, but a write made
        // outside a coroutine (a #[UnitTest] method, a test that opts out of
        // coroutines) lands in worker-level storage that RunTestsInCoroutine
        // copies into every later test, so clear it and the current coroutine's.
        CoroutineContext::clearFromNonCoroutine($keys);

        foreach ($keys as $key) {
            CoroutineContext::forget($key);
        }
    }

    /**
     * Every loaded class using Auditable. The trait gives each class its own
     * `$auditingDisabled`, and the flag is publicly assignable, so scanning the
     * declared classes catches every copy however it was set.
     *
     * @return list<class-string>
     */
    protected static function auditableClasses(): array
    {
        $classes = [];

        foreach (get_declared_classes() as $class) {
            // Cheap filter first — very few classes have this property at all.
            if (! property_exists($class, 'auditingDisabled')) {
                continue;
            }

            if (in_array(Auditable::class, class_uses_recursive($class), true)) {
                $classes[] = $class;
            }
        }

        return $classes;
    }
}
