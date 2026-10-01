<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Ipsocode\Auditing\Encoders\Base64Encoder;
use Ipsocode\Auditing\Redactors\LeftRedactor;

/**
 * A redactor on the nullable `published_at` column and an encoder on `content`,
 * so the modifier pipeline sees a null value next to a non-null one.
 */
class NullableModifierArticle extends Article
{
    protected ?string $table = 'articles';

    protected array $attributeModifiers = [
        'published_at' => LeftRedactor::class,
        'content' => Base64Encoder::class,
    ];
}
