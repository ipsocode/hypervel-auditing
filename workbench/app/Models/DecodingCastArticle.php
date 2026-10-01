<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Hypervel\Database\Eloquent\Casts\AsArrayObject;
use Hypervel\Database\Eloquent\Casts\AsCollection;

/**
 * Both casts decode the model's attributes rather than the value they are
 * handed, so a stored value has to be cast on a model that holds it.
 */
class DecodingCastArticle extends Article
{
    protected ?string $table = 'articles';

    protected function casts(): array
    {
        return [
            'title' => AsCollection::class,
            'content' => AsArrayObject::nullable(),
        ];
    }
}
