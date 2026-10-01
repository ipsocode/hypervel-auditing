<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Hypervel\Database\Eloquent\Casts\AsArrayObject;

/**
 * `content` is stored as JSON behind an AsArrayObject cast, which the Audit
 * trait special-cases when formatting a historical value.
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
