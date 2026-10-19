<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Contracts;

interface AttributeEncoder extends AttributeModifier
{
    /**
     * @param  mixed  $value
     * @return mixed
     */
    public static function encode($value);

    /**
     * @param  mixed  $value
     * @return mixed
     */
    public static function decode($value);
}
