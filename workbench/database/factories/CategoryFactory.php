<?php

declare(strict_types=1);

namespace Workbench\Database\Factories;

use Hypervel\Database\Eloquent\Factories\Factory;
use Workbench\App\Models\Category;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    /** @var class-string<Category> */
    protected ?string $model = Category::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
        ];
    }
}
