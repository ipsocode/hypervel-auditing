<?php

declare(strict_types=1);

namespace Workbench\App\Casts;

/**
 * Value object returned by {@see WrapperCast}. Object-returning casts are the
 * ones Eloquent memoizes in `classCastCache`.
 */
class Wrapper
{
    public function __construct(public readonly string $inner)
    {
    }

    public function __toString(): string
    {
        return $this->inner;
    }
}
