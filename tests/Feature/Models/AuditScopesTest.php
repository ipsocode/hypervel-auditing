<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Tests\Feature\Models;

use Hypervel\Support\Facades\Config;
use Hypervel\Support\Facades\Schema;
use Ipsocode\Auditing\Facades\Auditor;
use Ipsocode\Auditing\Models\Audit;
use Ipsocode\Auditing\Tests\TestCase;
use Workbench\App\Models\Article;
use Workbench\App\Models\AuditLog;
use Workbench\App\Models\User;

class AuditScopesTest extends TestCase
{
    public function testForFieldMatchesAuditsThatRecordedTheField(): void
    {
        $article = Article::factory()->create(['title' => 'V1', 'content' => 'Body']);
        $article->update(['title' => 'V2']);
        $article->update(['content' => 'New body']);

        $audits = $article->audits()->forField('title')->where('event', 'updated')->get();

        $this->assertCount(1, $audits);
        $this->assertSame(['title' => 'V2'], $audits->first()->new_values);
    }

    public function testForFieldAcceptsAnArrayOfFields(): void
    {
        $article = Article::factory()->create(['title' => 'V1', 'content' => 'Body']);
        $article->update(['title' => 'V2']);
        $article->update(['content' => 'New body']);

        $this->assertCount(2, $article->audits()->forField(['title', 'content'])->where('event', 'updated')->get());
        $this->assertCount(0, $article->audits()->forField('missing')->get());
    }

    public function testForEventMatchesOneOrManyEvents(): void
    {
        $article = Article::factory()->create();
        $article->update(['title' => 'V2']);

        $this->assertSame(['updated'], $article->audits()->forEvent('updated')->pluck('event')->all());
        $this->assertCount(2, $article->audits()->forEvent(['created', 'updated'])->get());
    }

    public function testByUserMatchesTheCauser(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $article = Article::factory()->create();

        Auditor::actingAs($alice, fn () => $article->update(['title' => 'By Alice']));
        Auditor::actingAs($bob, fn () => $article->update(['title' => 'By Bob']));

        $audits = $article->audits()->byUser($alice)->get();

        $this->assertCount(1, $audits);
        $this->assertSame(['title' => 'By Alice'], $audits->first()->new_values);
    }

    public function testByUserWithNullMatchesAuditsWithoutACauser(): void
    {
        $user = User::factory()->create();
        $article = Article::factory()->create();

        Auditor::actingAs($user, fn () => $article->update(['title' => 'Caused']));

        $audits = $article->audits()->byUser(null)->get();

        $this->assertSame(['created'], $audits->pluck('event')->all());
    }

    public function testByUserReadsTheColumnsNamedByTheMorphPrefix(): void
    {
        Config::set('auditing.user.morph_prefix', 'actor');

        Schema::table('audits', function ($table) {
            $table->renameColumn('user_type', 'actor_type');
            $table->renameColumn('user_id', 'actor_id');
        });

        $user = User::factory()->create();
        $article = Article::factory()->create();

        Auditor::actingAs($user, fn () => $article->update(['title' => 'Caused']));

        $this->assertSame(['updated'], $article->audits()->byUser($user)->pluck('event')->all());
        $this->assertSame(['created'], $article->audits()->byUser(null)->pluck('event')->all());
    }

    public function testInBatchMatchesAuditsOfTheBatch(): void
    {
        $article = Article::factory()->create();

        $batch = Auditor::withinBatch(function () use ($article) {
            $article->update(['title' => 'Batched']);

            return Auditor::currentBatch();
        });

        $audits = $article->audits()->inBatch($batch)->get();

        $this->assertSame(['updated'], $audits->pluck('event')->all());
        $this->assertCount(0, $article->audits()->inBatch('no-such-batch')->get());
    }

    public function testScopesChainFromTheRelation(): void
    {
        $article = Article::factory()->create(['title' => 'V1', 'content' => 'Body']);
        $article->update(['title' => 'V2']);
        $article->update(['content' => 'New body']);

        $audits = $article->audits()->forEvent('updated')->forField('title')->get();

        $this->assertCount(1, $audits);
        $this->assertInstanceOf(Audit::class, $audits->first());
    }

    public function testScopesWorkOnACustomImplementation(): void
    {
        Config::set('auditing.implementation', AuditLog::class);

        $article = Article::factory()->create(['title' => 'V1']);
        $article->update(['title' => 'V2']);

        $audits = $article->audits()->forEvent('updated')->forField('title')->get();

        $this->assertCount(1, $audits);
        $this->assertInstanceOf(AuditLog::class, $audits->first());
        $this->assertCount(1, AuditLog::query()->forField('title')->forEvent('updated')->byUser(null)->get());
    }
}
