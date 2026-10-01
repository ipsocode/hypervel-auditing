<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Support;

use Hypervel\Support\Facades\Config;

/**
 * The two audit table names, from `auditing.tables`.
 */
class AuditTables
{
    public const DEFAULT_AUDITS_TABLE = 'audits';

    public const DEFAULT_AUDITING_DETAILS_TABLE = 'audit_details';

    public static function audits(): string
    {
        return (string) Config::get('auditing.tables.audits', self::DEFAULT_AUDITS_TABLE);
    }

    public static function auditDetails(): string
    {
        return (string) Config::get('auditing.tables.audit_details', self::DEFAULT_AUDITING_DETAILS_TABLE);
    }
}
