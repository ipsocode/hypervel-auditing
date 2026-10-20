<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Redactors;

use Hypervel\Support\Facades\Config;
use Ipsocode\Auditing\Contracts\AttributeRedactor;
use Ipsocode\Auditing\Exceptions\AuditingException;

class HashRedactor implements AttributeRedactor
{
    /**
     * An HMAC-SHA256 of the value, keyed with the application key.
     *
     * A key stored as `base64:...` is used as its decoded bytes, the way the
     * encrypter reads it.
     *
     * @throws AuditingException When the application key is empty
     */
    public static function redact(mixed $value): string
    {
        $key = (string) Config::get('app.key');

        if (str_starts_with($key, 'base64:')) {
            $key = (string) base64_decode(substr($key, 7), true);
        }

        if ($key === '') {
            throw new AuditingException('The HashRedactor needs a non-empty app.key.');
        }

        // Raw values may be ints, floats or bools; hash_hmac() takes a string.
        return hash_hmac('sha256', (string) $value, $key);
    }
}
