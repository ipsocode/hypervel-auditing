<?php

declare(strict_types=1);

namespace Ipsocode\Auditing;

use Hypervel\Database\Eloquent\Relations\MorphMany;
use Hypervel\Support\Facades\Config;
use Ipsocode\Auditing\Models\Audit as AuditModel;
use Ipsocode\Auditing\Support\UserMorph;

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
        /** @var class-string<AuditModel> $implementation */
        $implementation = Config::get('auditing.implementation');

        return $this->morphMany(
            $implementation,
            UserMorph::prefix(),
            UserMorph::typeColumn(),
            UserMorph::idColumn()
        );
    }
}
