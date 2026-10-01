<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use stdClass;

/**
 * Maps `content` to a class that is neither an AttributeRedactor nor an
 * AttributeEncoder, so modifying it raises an AuditingException.
 */
class BadModifierArticle extends Article
{
    protected array $attributeModifiers = [
        'content' => stdClass::class,
    ];
}
