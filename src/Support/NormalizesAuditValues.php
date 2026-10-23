<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Support;

use BackedEnum;
use Stringable;
use UnitEnum;

/**
 * How an audited value is reduced to the string a detail row stores, shared by
 * the `audit_details` driver and the test fake so a recorded audit reads back
 * like a stored one.
 */
trait NormalizesAuditValues
{
    /**
     * Reduce an attribute value to a string for one column: booleans give '1'
     * and '0', other scalars are cast (a float that the cast would change keeps
     * its full precision), enums give their value or name, stringable objects their string form, and
     * anything else is JSON-encoded.
     */
    protected function normalizeValue(mixed $value): ?string
    {
        if ($value === null || is_string($value)) {
            return $value;
        }

        // (string) false is '', which reads as an emptied attribute and differs from
        // the '0' a database that returns an int stores.
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        // A float is cast at `precision` digits, which can erase a real change
        // (1.0 → 1.0000000000000002 reads back as '1' both times); keep the cast
        // whenever it round-trips and use the shortest exact form when it doesn't.
        if (is_float($value)) {
            $string = (string) $value;

            return (float) $string === $value ? $string : var_export($value, true);
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        // resolveAuditExclusions() keeps enums and stringable objects; json_encode()
        // would flatten such an object to "{}" and cannot encode a pure enum.
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        if ($value instanceof UnitEnum) {
            return $value->name;
        }

        if ($value instanceof Stringable || (is_object($value) && method_exists($value, '__toString'))) {
            return (string) $value;
        }

        // Arrays (kept with `auditing.allowed_array_values`) are stored as JSON; a
        // value json_encode() cannot represent returns false and is stored as null.
        $encoded = json_encode($value);

        return $encoded === false ? null : $encoded;
    }
}
