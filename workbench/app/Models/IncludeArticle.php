<?php

declare(strict_types=1);

namespace Workbench\App\Models;

/**
 * Only `title` is whitelisted for auditing via {@see $auditInclude}.
 */
class IncludeArticle extends Article
{
    protected array $auditInclude = [
        'title',
    ];
}
