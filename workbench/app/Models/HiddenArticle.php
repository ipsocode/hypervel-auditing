<?php

declare(strict_types=1);

namespace Workbench\App\Models;

/**
 * `content` is hidden so strict mode (`auditing.strict`) excludes it from audits.
 */
class HiddenArticle extends Article
{
    protected array $hidden = [
        'content',
    ];
}
