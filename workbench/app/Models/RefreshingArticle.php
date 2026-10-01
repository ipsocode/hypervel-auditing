<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Hypervel\Database\Eloquent\Attributes\Refreshes;

/**
 * `reviewed` is read back from the database after each write, so its default
 * and any change a trigger makes reach the model before the audit is built.
 */
#[Refreshes('reviewed')]
class RefreshingArticle extends Article
{
    protected ?string $table = 'articles';
}
