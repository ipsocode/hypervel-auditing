<?php

declare(strict_types=1);

namespace Workbench\App\Casts;

use Hypervel\Contracts\Database\Eloquent\CastsAttributes;
use Hypervel\Database\Eloquent\Model;

/**
 * A class cast that returns an object, so Eloquent stores the result in the
 * model's `classCastCache` and `save()` merges it back into the attributes.
 *
 * @implements CastsAttributes<Wrapper, mixed>
 */
class WrapperCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        return new Wrapper((string) $value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        return $value instanceof Wrapper ? $value->inner : $value;
    }
}
