<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Contracts;

interface AttributeEncoder extends AttributeModifier
{
    public static function encode($value);

    public static function decode($value);
}
