<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Hypervel\Database\Eloquent\Casts\Attribute;

/**
 * Carries both flavours of read accessor, because the Audit trait formats
 * historical values through whichever one the auditable declares:
 * the classic `getFooAttribute()` and the `Attribute`-returning kind.
 */
class MutatorArticle extends Article
{
    protected ?string $table = 'articles';

    /**
     * Classic get mutator — hasGetMutator()/mutateAttribute().
     */
    public function getTitleAttribute(mixed $value): string
    {
        return 'mutated:' . $value;
    }

    /**
     * Attribute-object mutator — hasAttributeMutator()/mutateAttributeMarkedAttribute().
     */
    protected function content(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value): string => 'attr:' . $value,
        );
    }
}
