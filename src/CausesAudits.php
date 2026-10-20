<?php

declare(strict_types=1);

namespace Ipsocode\Auditing;

use Hypervel\Database\Eloquent\Relations\MorphMany;
use Hypervel\Support\Facades\Config;

/**
 * For the user model: the audits it caused, the inverse of an audit's `user` relation.
 */
trait CausesAudits
{
    /**
     * @return MorphMany<\Hypervel\Database\Eloquent\Model, $this>
     */
    public function causedAudits(): MorphMany
    {
        $morphPrefix = Config::get('auditing.user.morph_prefix', 'user');

        return $this->morphMany(
            Config::get('auditing.implementation', \Ipsocode\Auditing\Models\Audit::class),
            $morphPrefix,
            $morphPrefix . '_type',
            $morphPrefix . '_id'
        );
    }
}
