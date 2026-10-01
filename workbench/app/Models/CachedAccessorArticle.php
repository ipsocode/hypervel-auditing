<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Hypervel\Database\Eloquent\Casts\Attribute;

/**
 * `content` has a cached accessor with a mutator: Eloquent keeps the accessor's
 * result in `attributeCastCache` and runs it back through the mutator on save.
 */
class CachedAccessorArticle extends Article
{
    protected ?string $table = 'articles';

    protected function content(): Attribute
    {
        return Attribute::make(
            get: fn (string $value): string => strtoupper($value),
            set: fn (string $value): string => strtolower($value),
        )->shouldCache();
    }
}
