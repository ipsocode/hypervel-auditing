<?php

declare(strict_types=1);

namespace Workbench\App\Models;

/**
 * Stores dates in a non-ISO format, so historical values fall through to the
 * model's own `$dateFormat` when the Audit trait normalises them to UTC.
 */
class CustomDateFormatArticle extends Article
{
    protected ?string $table = 'articles';

    protected ?string $dateFormat = 'd/m/Y H:i:s';
}
