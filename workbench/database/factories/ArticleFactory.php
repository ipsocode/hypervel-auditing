<?php

declare(strict_types=1);

namespace Workbench\Database\Factories;

use Hypervel\Database\Eloquent\Factories\Factory;
use Workbench\App\Models\Article;

/**
 * @extends Factory<Article>
 */
class ArticleFactory extends Factory
{
    /** @var class-string<Article> */
    protected ?string $model = Article::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // `published_at` is deliberately absent rather than explicitly null:
        // an attribute set to null is still an audited attribute, so including
        // it here would add a null detail row to every created article's audit.
        return [
            'title' => fake()->sentence(3),
            'content' => fake()->paragraph(),
            'reviewed' => false,
        ];
    }

    /**
     * An article that has been reviewed and published.
     */
    public function published(): static
    {
        return $this->state(fn (array $attributes): array => [
            'reviewed' => true,
            'published_at' => fake()->dateTimeBetween('-1 year')->format('Y-m-d H:i:s'),
        ]);
    }
}
