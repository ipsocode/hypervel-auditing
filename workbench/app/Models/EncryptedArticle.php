<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Ipsocode\Auditing\Encoders\EncryptedEncoder;

/**
 * `content` is encrypted in the audit and decrypted when the audit is read.
 */
class EncryptedArticle extends Article
{
    protected array $attributeModifiers = [
        'content' => EncryptedEncoder::class,
    ];
}
