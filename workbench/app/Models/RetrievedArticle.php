<?php

declare(strict_types=1);

namespace Workbench\App\Models;

/**
 * Audits `retrieved` on top of the default events, through its own
 * `$auditEvents`, so its class registers the `retrieved` listener at boot.
 */
class RetrievedArticle extends Article
{
    protected array $auditEvents = ['created', 'updated', 'deleted', 'restored', 'retrieved'];
}
