<?php

declare(strict_types=1);

namespace Workbench\App\Models;

/**
 * Remaps the audit events two ways at once: `created` is routed to a named
 * getter (the string-key branch of resolveAttributeGetter()) and the `*ted`
 * wildcard covers `updated` and `deleted`; it does not match `restored`.
 */
class CustomEventArticle extends Article
{
    protected ?string $table = 'articles';

    protected array $auditEvents = [
        'created' => 'getCreatedHeadlineAttributes',
        '*ted',
    ];

    /**
     * @return array{0: array<string,mixed>, 1: array<string,mixed>}
     */
    public function getCreatedHeadlineAttributes(): array
    {
        return [[], ['title' => $this->attributes['title']]];
    }
}
