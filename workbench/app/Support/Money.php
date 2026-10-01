<?php

declare(strict_types=1);

namespace Workbench\App\Support;

/**
 * A plain value object with __toString(), which
 * {@see \Ipsocode\Auditing\Auditable::resolveAuditExclusions()} keeps in the
 * audit on purpose.
 */
class Money
{
    public function __construct(private readonly string $amount, private readonly string $currency)
    {
    }

    public function __toString(): string
    {
        return $this->amount . ' ' . $this->currency;
    }
}
