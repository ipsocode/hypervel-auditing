<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Tests\Feature\Resolvers;

use Hypervel\Support\Facades\Auth;
use Ipsocode\Auditing\Resolvers\UserResolver;
use Ipsocode\Auditing\Tests\TestCase;
use Swoole\Coroutine\CanceledException;
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

    public function testAGuardThatThrowsIsSkippedForTheNextOne(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $this->app->get('config')->set('auditing.user.guards', [
            'not-a-configured-guard',
            $this->app->get('config')->get('auth.defaults.guard'),
        ]);

        $audit = $this->makeArticle()->audits()->where('event', 'created')->sole();

        $this->assertSame($user->getKey(), $audit->user_id);
    }

    public function testNoUserIsRecordedWhenEveryConfiguredGuardThrows(): void
    {
        $this->app->get('config')->set('auditing.user.guards', ['not-a-configured-guard']);

        $audit = $this->makeArticle()->audits()->where('event', 'created')->sole();

        $this->assertNull($audit->user_id);
    }

    public function testACancelledGuardCheckIsRethrownAndLaterGuardsAreNotTried(): void
    {
        $guard = $this->app->get('config')->get('auth.defaults.guard');

        $this->app->get('config')->set('auditing.user.guards', [$guard, 'not-a-configured-guard']);

        Auth::shouldReceive('guard')->once()->with($guard)->andThrow(new CanceledException);

        $this->expectException(CanceledException::class);

        UserResolver::resolve();
    }
}
