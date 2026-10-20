<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Encoders;

use Hypervel\Contracts\Encryption\DecryptException;
use Hypervel\Support\Facades\Crypt;
use Ipsocode\Auditing\Contracts\AttributeEncoder;

class EncryptedEncoder implements AttributeEncoder
{
    public static function encode(mixed $value): ?string
    {
        // Null passes through; any other raw value is cast for encryptString().
        if ($value === null) {
            return null;
        }

        return Crypt::encryptString((string) $value);
    }

    public static function decode(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        try {
            return Crypt::decryptString((string) $value);
        } catch (DecryptException) {
            // Written under another key, or never encrypted: show it as stored.
            return $value;
        }
    }
}
