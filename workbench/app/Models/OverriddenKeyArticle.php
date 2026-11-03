<?php

declare(strict_types=1);

namespace Workbench\App\Models;

/**
 * Keeps its withoutAuditing() flag under a key of its own, overriding
 * {@see \Ipsocode\Auditing\Auditable::auditingDisabledContextKey()}.
 */
class OverriddenKeyArticle extends Article
{
    protected static function auditingDisabledContextKey(): string
    {
        return '__auditing.test.overridden-key';
    }
}
