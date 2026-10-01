<?php

declare(strict_types=1);

namespace Workbench\App\Models;

/**
 * Caps its own audit history with `$auditThreshold`, overriding `auditing.threshold`.
 */
class ThresholdArticle extends Article
{
    protected ?string $table = 'articles';

    protected int $auditThreshold = 2;
}
