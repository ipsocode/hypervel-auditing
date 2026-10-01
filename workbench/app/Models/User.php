<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Hypervel\Database\Eloquent\Factories\HasFactory;
use Hypervel\Foundation\Auth\User as Authenticatable;
use Hypervel\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory;
    use Notifiable;

    /** @var array<int, string> */
    protected array $fillable = [
        'name',
        'email',
        'password',
    ];

    /** @var array<int, string> */
    protected array $hidden = [
        'password',
        'remember_token',
    ];

    /** @var array<string, string> */
    protected array $casts = [
        'email_verified_at' => 'datetime',
    ];
}
