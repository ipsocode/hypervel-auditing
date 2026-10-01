<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Encoders;

use Ipsocode\Auditing\Contracts\AttributeEncoder;

class Base64Encoder implements AttributeEncoder
{
    public static function encode($value)
    {
        // Null passes through; any other raw value is cast for base64_encode().
        if ($value === null) {
            return null;
        }

        return base64_encode((string) $value);
    }

    public static function decode($value)
    {
        if ($value === null) {
            return null;
        }

        return base64_decode((string) $value);
    }
}
