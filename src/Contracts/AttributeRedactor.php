<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Contracts;

interface AttributeRedactor extends AttributeModifier
{
    public static function redact($value): string;
}
