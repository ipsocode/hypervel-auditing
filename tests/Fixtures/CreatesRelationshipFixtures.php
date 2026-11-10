<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Tests\Fixtures;

use Workbench\App\Models\Article;
use Workbench\App\Models\Category;

/**
 * An article and three categories, created inside the test's coroutine so the
 * database work runs where the test does.
 */
trait CreatesRelationshipFixtures
{
    private Article $article;

    /** @var array<string, Category> */
    private array $categories = [];

    protected function setUpInCoroutine(): void
    {
        $this->article = Article::factory()->create();

        foreach (['php', 'testing', 'audit'] as $name) {
            $this->categories[$name] = Category::factory()->create(['name' => $name]);
        }
    }
}
