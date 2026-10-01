<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Support;

/**
 * Every coroutine context key this package writes.
 *
 * Everything keyed here is coroutine-local state: a static or container
 * singleton would be shared by every concurrent request on the worker.
 *
 * The names follow the framework's own shape — `__auth.resolver`,
 * `__database.model.unguarded` — where the `__` prefix reserves the key from
 * application keys and the segment after it says which package owns it. Without
 * the prefix, an application that stores its own `auditing.batch` would find
 * this package reading it as an open batch id, and neither side would error.
 *
 * Their readers and TestState all name these keys, so they are defined once.
 *
 * @see \Ipsocode\Auditing\Testing\TestState for where they are flushed
 */
final class ContextKeys
{
    /**
     * The open audit batch on this coroutine.
     */
    public const string BATCH = '__auditing.batch';

    /**
     * The models currently inside a `restore()` on this coroutine.
     */
    public const string RESTORING = '__auditing.restoring';

    /**
     * Prefix for the per-class `withoutAuditing()` scope flags.
     */
    public const string DISABLED_PREFIX = '__auditing.disabled.';

    /**
     * The `withoutAuditing()` scope flag covering every auditable class.
     */
    public const string DISABLED_GLOBALLY = self::DISABLED_PREFIX . '*';

    /**
     * The scope flag for one auditable class.
     *
     * @param class-string $class
     */
    public static function disabledFor(string $class): string
    {
        return self::DISABLED_PREFIX . $class;
    }
}
