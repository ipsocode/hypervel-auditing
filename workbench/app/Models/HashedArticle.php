<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Ipsocode\Auditing\Redactors\HashRedactor;

/**
 * `content` is run through {@see HashRedactor} before it is written to the audit.
 */
class HashedArticle extends Article
{
    protected array $attributeModifiers = [
        'content' => HashRedactor::class,
    ];
}
