<?php

declare(strict_types=1);

namespace Ipsocode\Auditing;

use Hypervel\Database\Eloquent\Relations\MorphMany;
use Hypervel\Support\Facades\Config;
use Ipsocode\Auditing\Models\Audit as AuditModel;

/**
 * For the user model: the audits it caused, the inverse of an audit's `user` relation.
 */
// @phpstan-ignore trait.unused (applications use it on their own user model, outside src/)
trait CausesAudits
{
    /**
     * @return MorphMany<AuditModel, $this>
     */
    public function causedAudits(): MorphMany
    {
        $morphPrefix = Config::get('auditing.user.morph_prefix');

        return $this->morphMany(
            Config::get('auditing.implementation'),
            $morphPrefix,
            $morphPrefix . '_type',
            $morphPrefix . '_id'
        );
    }
}
