<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Support;

use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Auth\Authenticatable;

/**
 * The causer named for the audits written inside one `Auditor::actingAs()` call.
 *
 * The scope is stored as `['user' => $user]` rather than the user, so a scope
 * opened with null, which records no causer, can be told apart from no scope,
 * which leaves the causer to the user resolver.
 *
 * The open scope is coroutine-local; see ContextKeys.
 */
class AuditCauser
{
    /**
     * Whether a scope is open on this coroutine.
     */
    public static function isScoped(): bool
    {
        return static::scope() !== null;
    }

    /**
     * The user of the scope open on this coroutine, if any.
     */
    public static function current(): ?Authenticatable
    {
        return static::scope()['user'] ?? null;
    }

    /**
     * Run a callback with a causer named, and return its result.
     */
    public static function acting(?Authenticatable $user, callable $callback): mixed
    {
        $previous = static::scope();

        CoroutineContext::set(ContextKeys::CAUSER, ['user' => $user]);

        try {
            return $callback();
        } finally {
            // Restore rather than clear: an inner scope closing must not close
            // the outer one it was nested in.
            if ($previous === null) {
                CoroutineContext::forget(ContextKeys::CAUSER);
            } else {
                CoroutineContext::set(ContextKeys::CAUSER, $previous);
            }
        }
    }

    /**
     * @return null|array{user: null|Authenticatable}
     */
    protected static function scope(): ?array
    {
        $scope = CoroutineContext::get(ContextKeys::CAUSER);

        return is_array($scope) && array_key_exists('user', $scope) ? $scope : null;
    }
}
