<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Hypervel\Database\Eloquent\Relations\BelongsToMany;

/**
 * Relates through {@see AuditableCategoryPivot} so the pivot helpers take their
 * Auditable-pivot path.
 */
class PivotAuditedArticle extends Article
{
    protected ?string $table = 'articles';

    public function categories(): BelongsToMany
    {
        // The pivot keys are named for the parent Article; the subclass name
        // would otherwise derive `pivot_audited_article_id`.
        return $this->belongsToMany(Category::class, 'article_category', 'article_id', 'category_id')
            ->using(AuditableCategoryPivot::class);
    }
}
