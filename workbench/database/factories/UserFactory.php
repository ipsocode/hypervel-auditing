<?php

declare(strict_types=1);

namespace Workbench\Database\Factories;

use Hypervel\Database\Eloquent\Factories\Factory;
use Hypervel\Support\Facades\Hash;
use Hypervel\Support\Str;
use Workbench\App\Models\User;

class UserFactory extends Factory
{
    /** @var class-string<User> */
    protected ?string $model = User::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes): array => [
            'email_verified_at' => null,
        ]);
    }
}
