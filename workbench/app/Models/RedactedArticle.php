<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Ipsocode\Auditing\Redactors\RightRedactor;

/**
 * `content` is run through {@see RightRedactor} before it is written to the audit.
 */
class RedactedArticle extends Article
{
    protected array $attributeModifiers = [
        'content' => RightRedactor::class,
    ];
}
