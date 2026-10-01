<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Ipsocode\Auditing\Encoders\Base64Encoder;

/**
 * An encoder mapped onto the one nullable column, so an audited value can be
 * genuinely null on a side that would otherwise be handed to decode().
 */
class NullableEncodedArticle extends Article
{
    protected ?string $table = 'articles';

    protected array $attributeModifiers = [
        'published_at' => Base64Encoder::class,
    ];
}
