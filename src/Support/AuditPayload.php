<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Support;

use Hypervel\Support\Facades\Config;
use Ipsocode\Auditing\Contracts\Auditable;

/**
 * Decisions about an audit's resolved payload that the Auditor, the details
 * driver and AuditorFake all make the same way.
 */
class AuditPayload
{
    /**
     * Whether the payload records nothing worth writing: no values on either
     * side, with empty audits neither allowed globally nor for this event.
     *
     * @param array<string, mixed> $data
     */
    public static function isEmpty(Auditable $model, array $data): bool
    {
        if (Config::get('auditing.empty_values')) {
            return false;
        }

        if (in_array($model->getAuditEvent(), Config::get('auditing.allowed_empty_values'))) {
            return false;
        }

        return empty($data['new_values']) && empty($data['old_values']);
    }

    /**
     * The fields named on either side of an audit, each once.
     *
     * @param array<string, mixed> $oldValues
     * @param array<string, mixed> $newValues
     * @return list<string>
     */
    public static function fields(array $oldValues, array $newValues): array
    {
        return array_values(array_unique(array_merge(array_keys($oldValues), array_keys($newValues))));
    }
}
