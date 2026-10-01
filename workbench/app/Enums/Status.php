<?php

declare(strict_types=1);

namespace Workbench\App\Enums;

/**
 * Pure (non-backed) enum — has no `value`, and json_encode() cannot represent
 * it at all, so it has to reduce to its `name`.
 */
enum Status
{
    case Draft;
    case Published;
}
