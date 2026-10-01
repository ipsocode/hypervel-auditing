<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Hypervel\Database\Eloquent\Casts\AsArrayObject;

/**
 * `content` is stored as JSON behind an AsArrayObject cast.
 */
class ArrayObjectArticle extends Article
{
    protected ?string $table = 'articles';

    protected array $casts = [
        'content' => AsArrayObject::class,
        'reviewed' => 'bool',
        'published_at' => 'datetime',
    ];
}
