<?php

declare(strict_types=1);

namespace Ipsocode\Auditing;

use Hypervel\Database\Eloquent\Relations\MorphMany;
use Hypervel\Support\Facades\Config;
use Ipsocode\Auditing\Models\Audit as AuditModel;

/**
 * For the user model: the audits it caused, the inverse of an audit's `user` relation.
 */
trait CausesAudits
{
    /**
     * @return MorphMany<AuditModel, $this>
     */
    public function causedAudits(): MorphMany
    {
        $morphPrefix = Config::get('auditing.user.morph_prefix');

        /** @var class-string<AuditModel> $implementation */
        $implementation = Config::get('auditing.implementation');

        return $this->morphMany(
            $implementation,
            $morphPrefix,
            $morphPrefix . '_type',
            $morphPrefix . '_id'
        );
    }
}
