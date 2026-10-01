<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Contracts;

/**
 * Optional for a driver. Auditor::execute() resolves toAudit() once, to decide
 * whether the audit is empty; a driver implementing this is handed that payload,
 * so the configured resolvers, and side effects such as the user lookup, do not
 * run a second time.
 */
interface AcceptsResolvedAudit
{
    /**
     * @param array<string,mixed> $data the payload from Auditable::toAudit()
     */
    public function auditWithData(Auditable $model, array $data): ?Audit;
}
