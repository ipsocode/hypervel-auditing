<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Tests\Feature\Models;

use Hypervel\Support\Facades\Config;
use Hypervel\Support\Facades\Schema;
use Ipsocode\Auditing\Facades\Auditor;
use Ipsocode\Auditing\Tests\TestCase;
use Workbench\App\Models\Article;
use Workbench\App\Models\AuditableUser;
use Workbench\App\Models\AuditLog;
use Workbench\App\Models\User;

class CausesAuditsTest extends TestCase
{
    public function testCausedAuditsReturnsOnlyTheAuditsTheUserCaused(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $article = Article::factory()->create();

        Auditor::actingAs($alice, fn () => $article->update(['title' => 'By Alice']));
        Auditor::actingAs($bob, fn () => $article->update(['title' => 'By Bob']));

        $audits = $alice->causedAudits()->get();

        $this->assertCount(1, $audits);
        $this->assertSame(['title' => 'By Alice'], $audits->first()->new_values);
    }

    public function testCausedAuditsReadsTheColumnsNamedByTheMorphPrefix(): void
    {
        Config::set('auditing.user.morph_prefix', 'actor');

        Schema::table('audits', function ($table) {
            $table->renameColumn('user_type', 'actor_type');
            $table->renameColumn('user_id', 'actor_id');
        });

        $user = User::factory()->create();
        $article = Article::factory()->create();

        Auditor::actingAs($user, fn () => $article->update(['title' => 'Caused']));

        $this->assertSame(['updated'], $user->causedAudits()->pluck('event')->all());
    }

    public function testAuditsAndCausedAuditsAreDifferentSetsOnAnAuditableUser(): void
    {
        $user = AuditableUser::factory()->create();
        $article = Article::factory()->create();

        Auditor::actingAs($user, function () use ($article, $user) {
            $article->update(['title' => 'By the user']);
            $user->update(['name' => 'Renamed']);
        });

        $this->assertSame(['created', 'updated'], $user->audits()->orderBy('id')->pluck('event')->all());
        $this->assertSame(['updated', 'updated'], $user->causedAudits()->orderBy('id')->pluck('event')->all());
        $this->assertNotSame(
            $user->audits()->pluck('id')->all(),
            $user->causedAudits()->pluck('id')->all()
        );
    }

    public function testCausedAuditsReturnsTheConfiguredImplementation(): void
    {
        Config::set('auditing.implementation', AuditLog::class);

        $user = User::factory()->create();
        $article = Article::factory()->create();

        Auditor::actingAs($user, fn () => $article->update(['title' => 'Caused']));

        $this->assertInstanceOf(AuditLog::class, $user->causedAudits()->first());
    }
}
