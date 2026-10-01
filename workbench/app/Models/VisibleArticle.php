<?php

declare(strict_types=1);

namespace Workbench\App\Models;

/**
 * Declares `$visible`, so strict mode excludes every attribute outside it —
 * the non-visible half of the strict-mode rule that `$hidden` alone misses.
 */
class VisibleArticle extends Article
{
    protected ?string $table = 'articles';

    protected array $visible = [
        'title',
    ];
}
