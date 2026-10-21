<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Redactors;

use Ipsocode\Auditing\Contracts\AttributeRedactor;

class LeftRedactor implements AttributeRedactor
{
    public static function redact(mixed $value): string
    {
        // Raw values may be ints, floats or bools; mb_strlen() takes a string.
        $value = (string) $value;

        $total = mb_strlen($value, 'UTF-8');
        $tenth = (int) ceil($total / 10);

        // Make sure single character strings get redacted
        $length = ($total > $tenth) ? ($total - $tenth) : 1;

        return mb_str_pad(mb_substr($value, $length, null, 'UTF-8'), $total, '#', STR_PAD_LEFT, 'UTF-8');
    }
}
