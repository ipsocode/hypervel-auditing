<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Contracts;

use Hypervel\Database\Eloquent\Relations\MorphMany;

/**
 * @phpstan-require-extends \Hypervel\Database\Eloquent\Model
 */
interface Auditable
{
    /**
     * @return MorphMany<\Ipsocode\Auditing\Models\Audit, \Hypervel\Database\Eloquent\Model>
     */
    public function audits(): MorphMany;

    public function setAuditEvent(string $event): Auditable;

    /**
     * @return null|string
     */
    public function getAuditEvent();

    /**
     * @return array<string>
     */
    public function getAuditEvents(): array;

    public function readyForAuditing(): bool;

    /**
     * @return array<string,mixed>
     *
     * @throws \Ipsocode\Auditing\Exceptions\AuditingException
     */
    public function toAudit(): array;

    /**
     * @return array<string>
     */
    public function getAuditInclude(): array;

    /**
     * @return array<string>
     */
    public function getAuditExclude(): array;

    public function getAuditStrict(): bool;

    public function getAuditTimestamps(): bool;

    /**
     * @return null|string
     */
    public function getAuditDriver();

    public function getAuditThreshold(): int;

    /**
     * @return array<string,string> attribute name => AttributeModifier class
     */
    public function getAttributeModifiers(): array;

    /**
     * Called by toAudit() on its finished payload; override to reshape what the
     * driver writes.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function transformAudit(array $data): array;

    /**
     * @return array<string>
     */
    public function generateTags(): array;

    /**
     * Fill the model with the audit's new values, or its old ones when $old is
     * true. Nothing is saved.
     *
     * @throws \Ipsocode\Auditing\Exceptions\AuditableTransitionException
     */
    public function transitionTo(Audit $audit, bool $old = false): Auditable;
}
