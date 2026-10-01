<?php

declare(strict_types=1);

namespace Workbench\App\Enums;

/**
 * Backed enum — reduces to its `value`.
 */
enum Priority: string
{
    case Low = 'low';
    case High = 'high';
}
