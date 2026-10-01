<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Tests\Feature\Workbench;

use Ipsocode\Auditing\Tests\TestCase;
use Workbench\App\Models\Article;
use Workbench\App\Models\Category;
use Workbench\App\Models\User;
use Workbench\Database\Seeders\DatabaseSeeder;

/**
 * The seeder is the one place that runs the Workbench factories together, so a
 * broken factory fails here by name, not as a confusing failure in whichever
 * test uses it first.
 */
class WorkbenchSeederTest extends TestCase
{
    protected function defineDatabaseSeeders(): void
    {
        $this->seed(DatabaseSeeder::class);
    }

    public function testTheWorkbenchSeederPopulatesEveryFixtureModel(): void
    {
        $this->assertSame(1, User::query()->count());
        $this->assertSame(3, Category::query()->count());
        $this->assertSame(5, Article::query()->count());

        $this->assertSame(2, Article::query()->first()->categories()->count());
    }

    public function testSeedingDoesNotWriteAudits(): void
    {
        // The seeder runs WithoutModelEvents, so the observer never sees the rows.
        $this->assertSame(0, Article::query()->first()->audits()->count());
    }
}
