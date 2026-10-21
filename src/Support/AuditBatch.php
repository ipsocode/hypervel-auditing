<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Support;

use Hypervel\Context\CoroutineContext;
use Hypervel\Support\Str;
use InvalidArgumentException;

/**
 * The batch the audits written by one logical operation belong to.
 *
 * A request that touches six models writes six unrelated `audits` rows; the
 * batch id is what ties them back together. It is stamped onto the nullable,
 * indexed `batch_uuid` column, so an audit written outside a batch keeps null.
 *
 * The open batch is coroutine-local; see ContextKeys.
 */
class AuditBatch
{
    /**
     * The batch open on this coroutine, if any.
     */
    public static function current(): ?string
    {
        $batch = CoroutineContext::get(ContextKeys::BATCH);

        return is_string($batch) && $batch !== '' ? $batch : null;
    }

    /**
     * Run a callback with a batch open, stamping every audit it writes, and
     * return its result.
     *
     * @param null|string $batchUuid an explicit UUID (e.g. the id of an import) instead of a generated one
     *
     * @throws InvalidArgumentException when the explicit id is not a UUID, which the `batch_uuid` column rejects
     */
    public static function within(callable $callback, ?string $batchUuid = null): mixed
    {
        if ($batchUuid !== null && ! Str::isUuid($batchUuid)) {
            throw new InvalidArgumentException("The batch id [{$batchUuid}] is not a UUID.");
        }

        $previous = static::current();

        // A nested call joins the enclosing batch unless it names its own id, so
        // one operation stays one batch. Generated ids are UUID v7, time-ordered,
        // so new batches append to the end of the `batch_uuid` index.
        $batch = $batchUuid ?? $previous ?? (string) Str::uuid7();

        CoroutineContext::set(ContextKeys::BATCH, $batch);

        try {
            return $callback();
        } finally {
            // Restore rather than clear: an inner batch closing must not close
            // the outer one it was nested in.
            if ($previous === null) {
                CoroutineContext::forget(ContextKeys::BATCH);
            } else {
                CoroutineContext::set(ContextKeys::BATCH, $previous);
            }
        }
    }
}
