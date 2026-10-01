<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Hypervel\Database\Eloquent\Factories\HasFactory;
use Hypervel\Database\Eloquent\Model;

class Category extends Model
{
    use HasFactory;

    protected ?string $table = 'categories';

    protected array $fillable = [
        'name',
    ];
}
