<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Support;

use Ipsocode\Auditing\Contracts\Audit;
use Ipsocode\Auditing\Exceptions\AuditingException;

/**
 * The columns the retention paths order and filter an audit model by. The
 * framework types both as nullable, for a model with no primary key or no
 * timestamps; an audit model without them cannot be pruned.
 */
class AuditColumns
{
    /**
     * Return the audit model's primary key column.
     *
     * @throws AuditingException when the model has none
     */
    public static function key(Audit $audit): string
    {
        return $audit->getKeyName() ?? throw new AuditingException(sprintf(
            'The audit implementation %s must have a primary key.',
            $audit::class
        ));
    }

    /**
     * Return the audit model's created-at column.
     *
     * @throws AuditingException when the model has none
     */
    public static function createdAt(Audit $audit): string
    {
        return $audit->getCreatedAtColumn() ?? throw new AuditingException(sprintf(
            'The audit implementation %s must have a created-at column.',
            $audit::class
        ));
    }
}
