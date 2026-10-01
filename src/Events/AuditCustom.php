<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Events;

use Ipsocode\Auditing\Contracts\Auditable;

class AuditCustom
{
    public function __construct(
        public Auditable $model
    ) {
    }
}
