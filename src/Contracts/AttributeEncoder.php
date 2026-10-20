<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Contracts;

interface AttributeEncoder extends AttributeModifier
{
    public static function encode(mixed $value): ?string;

    public static function decode(mixed $value): mixed;
}
