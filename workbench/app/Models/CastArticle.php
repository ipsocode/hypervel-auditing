<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Workbench\App\Casts\WrapperCast;

/**
 * `content` uses an object-returning class cast, the shape Eloquent memoizes in
 * `classCastCache` and merges back into the attributes on save.
 */
class CastArticle extends Article
{
    protected ?string $table = 'articles';

    protected array $casts = [
        'content' => WrapperCast::class,
        'reviewed' => 'bool',
        'published_at' => 'datetime',
    ];
}
