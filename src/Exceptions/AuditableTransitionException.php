<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Exceptions;

use Throwable;

class AuditableTransitionException extends AuditingException
{
    /** @var array<string> audited attributes the model does not have */
    protected $incompatibilities = [];

    /**
     * @param array<string> $incompatibilities
     */
    public function __construct(string $message = '', array $incompatibilities = [], int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);

        $this->incompatibilities = $incompatibilities;
    }

    /**
     * @return array<string>
     */
    public function getIncompatibilities(): array
    {
        return $this->incompatibilities;
    }
}
