<?php

declare(strict_types=1);

namespace Workbench\App\Models;

/**
 * Emits a fixed set of audit tags for {@see \Ipsocode\Auditing\Audit::getTags()}.
 */
class TaggedArticle extends Article
{
    public function generateTags(): array
    {
        return ['php', 'audit'];
    }
}
