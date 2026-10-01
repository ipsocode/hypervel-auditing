<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Hypervel\Database\Eloquent\Relations\Pivot;
use Ipsocode\Auditing\Auditable;
use Ipsocode\Auditing\Contracts\Auditable as AuditableContract;

/**
 * An Auditable pivot: the pivot helpers detach and sync through
 * `$pivotClass::withoutAuditing()`, so its own row writes add no audits beside
 * the single relation audit the helper records.
 */
class AuditableCategoryPivot extends Pivot implements AuditableContract
{
    use Auditable;

    protected ?string $table = 'article_category';

    public bool $incrementing = false;
}
