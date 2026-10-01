<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Ipsocode\Auditing\Encoders\Base64Encoder;

/**
 * `content` is Base64-encoded in the audit and decoded when the audit is read.
 */
class EncodedArticle extends Article
{
    protected array $attributeModifiers = [
        'content' => Base64Encoder::class,
    ];
}
