<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Hypervel\Database\Eloquent\Factories\HasFactory;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\BelongsToMany;
use Hypervel\Database\Eloquent\SoftDeletes;
use Ipsocode\Auditing\Auditable;
use Ipsocode\Auditing\Contracts\Auditable as AuditableContract;

class Article extends Model implements AuditableContract
{
    use Auditable;
    use HasFactory;
    use SoftDeletes;

    protected ?string $table = 'articles';

    protected array $casts = [
        'reviewed' => 'bool',
        'published_at' => 'datetime',
    ];

    protected array $fillable = [
        'title',
        'content',
        'published_at',
        'reviewed',
    ];

    /**
     * The relation the pivot auditing helpers are tested on.
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'article_category');
    }
}
