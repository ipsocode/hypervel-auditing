<?php

declare(strict_types=1);

namespace Ipsocode\Auditing;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\MorphMany;
use Hypervel\Support\Facades\Config;
use Ipsocode\Auditing\Models\Audit as AuditModel;

/**
 * For the user model: the audits it caused, the inverse of an audit's `user` relation.
 */
trait CausesAudits
{
    /**
     * @return MorphMany<Model, $this>
     */
    public function causedAudits(): MorphMany
    {
        $morphPrefix = Config::get('auditing.user.morph_prefix', 'user');

        return $this->morphMany(
            Config::get('auditing.implementation', AuditModel::class),
            $morphPrefix,
            $morphPrefix . '_type',
            $morphPrefix . '_id'
        );
    }
}
