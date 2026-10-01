<?php

declare(strict_types=1);

namespace Workbench\Database\Seeders;

use Hypervel\Database\Console\Seeds\WithoutModelEvents;
use Hypervel\Database\Seeder;
use Workbench\App\Models\Article;
use Workbench\App\Models\Category;
use Workbench\App\Models\User;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Data for exploring the package by hand (`testbench db:seed`, then
     * `testbench tinker`). WithoutModelEvents keeps the observer from seeing
     * the seeded rows, so they write no audits.
     */
    public function run(): void
    {
        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $categories = Category::factory()->count(3)->create();

        Article::factory()
            ->count(3)
            ->create()
            ->merge(Article::factory()->count(2)->published()->create())
            ->each(fn (Article $article) => $article->categories()->attach(
                $categories->random(2)->pluck('id')->all()
            ));
    }
}
