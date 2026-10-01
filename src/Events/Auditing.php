<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Events;

use Ipsocode\Auditing\Contracts\Auditable;
use Ipsocode\Auditing\Contracts\AuditDriver;

class Auditing
{
    public function __construct(
        public Auditable $model,
        public AuditDriver $driver
    ) {
    }
}
