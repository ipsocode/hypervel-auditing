<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Tests\Feature\Resolvers;

use Ipsocode\Auditing\Tests\TestCase;
use Workbench\App\Models\Article;
use Workbench\App\Models\User;

class UserResolverTest extends TestCase
{
    private function makeArticle(): Article
    {
        return Article::factory()->create();
    }

    public function testAuthenticatedUserIsRecordedOnTheAudit(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $audit = $this->makeArticle()->audits()->where('event', 'created')->sole();

        $this->assertSame($user->getKey(), $audit->user_id);
        $this->assertSame($user->getMorphClass(), $audit->user_type);
    }

    public function testGuestLeavesUserColumnsNull(): void
    {
        $audit = $this->makeArticle()->audits()->where('event', 'created')->sole();

        $this->assertNull($audit->user_id);
        $this->assertNull($audit->user_type);
    }
}
