<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Redactors;

use Ipsocode\Auditing\Contracts\AttributeRedactor;

class LeftRedactor implements AttributeRedactor
{
    public static function redact($value): string
    {
        // Raw values may be ints, floats or bools; strlen() takes a string.
        $value = (string) $value;

        $total = strlen($value);
        $tenth = (int) ceil($total / 10);

        // Make sure single character strings get redacted
        $length = ($total > $tenth) ? ($total - $tenth) : 1;

        return str_pad(substr($value, $length), $total, '#', STR_PAD_LEFT);
    }
}
