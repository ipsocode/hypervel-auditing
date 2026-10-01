<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Events;

use Ipsocode\Auditing\Contracts\Auditable;

class DispatchingAudit
{
    public function __construct(
        public Auditable $model
    ) {
    }
}
