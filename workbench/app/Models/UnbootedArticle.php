<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Hypervel\Database\Eloquent\Model;
use Ipsocode\Auditing\Auditable;
use Ipsocode\Auditing\Contracts\Auditable as AuditableContract;

/**
 * An auditable model nothing else in the suite constructs. Eloquent boots a
 * class once per process, so the registration `bootAuditable()` defers runs on
 * the first construction only, and a test needs that first construction to
 * happen against a torn-down container. Do not use this model anywhere else.
 */
class UnbootedArticle extends Model implements AuditableContract
{
    use Auditable;

    protected ?string $table = 'articles';

    protected array $fillable = [
        'title',
        'content',
    ];
}
