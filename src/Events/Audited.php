<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Events;

use Ipsocode\Auditing\Contracts\Audit;
use Ipsocode\Auditing\Contracts\Auditable;
use Ipsocode\Auditing\Contracts\AuditDriver;

class Audited
{
    public function __construct(
        public Auditable $model,
        public AuditDriver $driver,
        public ?Audit $audit = null
    ) {
    }
}
