<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Support;

use Hypervel\Support\Facades\Config;

/**
 * The two audit table names, from `auditing.tables`.
 */
class AuditTables
{
    public static function audits(): string
    {
        return (string) Config::get('auditing.tables.audits');
    }

    public static function auditDetails(): string
    {
        return (string) Config::get('auditing.tables.audit_details');
    }
}
