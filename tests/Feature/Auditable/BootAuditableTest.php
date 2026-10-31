<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Tests\Feature\Auditable;

use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Support\Facades\Config;
use Hypervel\Support\Facades\Facade;
use Ipsocode\Auditing\AuditableObserver;
use Ipsocode\Auditing\Models\Audit;
use Ipsocode\Auditing\Tests\TestCase;
use Mockery as m;
use RuntimeException;
use Workbench\App\Models\Article;
use Workbench\App\Models\RetrievedArticle;
use Workbench\App\Models\UnbootedArticle;

/**
 * `bootAuditable()` runs inside the model constructor, so anything it throws
 * breaks `new SomeModel`. A parallel test worker can leave a torn-down
 * container behind a non-null facade root; declining to register the observer
 * then is correct, throwing is not.
 */
class BootAuditableTest extends TestCase
{
    public function testObserverIsRegisteredWhenTheContainerIsHealthy(): void
    {
        $article = Article::factory()->create();

        $this->assertSame(1, $article->audits()->count());
    }

    public function testOneSharedObserverIsBoundToEachEventOnce(): void
    {
        new RetrievedArticle;

        $observers = [];

        foreach (AuditableObserver::EVENTS as $event) {
            // Bound callables, not `AuditableObserver@event` strings: a string
            // is resolved through reflection and the container on every fire.
            $bound = $this->observerListeners(RetrievedArticle::class, $event);

            $this->assertCount(1, $bound, "one audit observer listener for [{$event}]");
            $this->assertSame($event, $bound[0][1]);

            $observers[] = $bound[0][0];
        }

        $this->assertCount(1, array_unique(array_map(spl_object_id(...), $observers)));
    }

    public function testAClassThatDoesNotAuditRetrievedIsNotListenedToOnHydration(): void
    {
        Article::factory()->count(3)->create();

        $events = $this->app->get('events');

        $this->assertSame([], $this->observerListeners(Article::class, 'retrieved'));
        $this->assertFalse($events->hasListeners('eloquent.retrieved: ' . Article::class));

        foreach (array_diff(AuditableObserver::EVENTS, ['retrieved']) as $event) {
            $this->assertCount(1, $this->observerListeners(Article::class, $event), "[{$event}] still observed");
        }

        // The observer is what reads the event list on a retrieved fire; with no
        // listener, hydrating rows reads it not once.
        $original = $this->app->get('config');
        $config = m::spy($original);
        Config::swap($config);

        try {
            $this->assertCount(3, Article::query()->get());
        } finally {
            Config::swap($original);
        }

        $config->shouldNotHaveReceived('get', ['auditing.events']);
        $this->assertSame(0, Audit::query()->where('event', 'retrieved')->count());
    }

    public function testAClassThatAuditsRetrievedRecordsEachHydratedRow(): void
    {
        $articles = collect(range(1, 3))->map(fn (int $i): RetrievedArticle => RetrievedArticle::create([
            'title' => "Article {$i}",
            'content' => 'Body',
        ]));

        $this->assertCount(3, RetrievedArticle::query()->get());

        foreach ($articles as $article) {
            $this->assertSame(1, $article->audits()->where('event', 'retrieved')->count());
        }
    }

    public function testWhetherRetrievedIsObservedIsDecidedWhenTheClassBoots(): void
    {
        $article = Article::factory()->create();

        Config::set('auditing.events', ['created', 'updated', 'deleted', 'restored', 'retrieved']);

        Article::query()->findOrFail($article->getKey());

        $this->assertSame(0, $article->audits()->where('event', 'retrieved')->count());
    }

    public function testTheConsoleSwitchIsCheckedOnEachEventNotAtBoot(): void
    {
        Config::set('auditing.console', false);

        $article = Article::factory()->create();

        $this->assertSame(0, $article->audits()->count());

        Config::set('auditing.console', true);

        $article->update(['title' => 'Changed']);

        $this->assertSame(1, $article->audits()->where('event', 'updated')->count());

        Article::disableAuditing();

        try {
            $article->update(['title' => 'Again']);
        } finally {
            Article::enableAuditing();
        }

        $this->assertSame(1, $article->audits()->count());
    }

    public function testAnEventTheModelDoesNotAuditReadsNoConfig(): void
    {
        $article = new class extends Article {
            protected array $auditEvents = ['created'];
        };

        $original = $this->app->get('config');
        $config = m::spy($original);
        Config::swap($config);

        try {
            (new AuditableObserver)->retrieved($article);
        } finally {
            Config::swap($original);
        }

        $config->shouldNotHaveReceived('get');
        $this->addToAssertionCount(1);
    }

    public function testConstructingAnAuditableModelSurvivesATornDownContainer(): void
    {
        $application = Facade::getFacadeApplication();

        // A flushed container as the trait sees it: a non-null facade root that
        // resolves nothing. A guard against a *null* root alone would let the
        // lookups run and throw out of the constructor.
        $torn = m::mock(ContainerContract::class);
        $torn->shouldReceive('make', 'get')
            ->andThrow(new RuntimeException('Target class [app] does not exist.'));

        try {
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication($torn);

            $model = new UnbootedArticle;
        } finally {
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication($application);
        }

        $this->assertInstanceOf(UnbootedArticle::class, $model);
    }

    /**
     * @param class-string $model
     * @return list<array{AuditableObserver, string}>
     */
    private function observerListeners(string $model, string $event): array
    {
        $listeners = $this->app->get('events')->getRawListeners();

        return array_values(array_filter(
            $listeners['eloquent.' . $event . ': ' . $model] ?? [],
            static fn (mixed $listener): bool => is_array($listener) && $listener[0] instanceof AuditableObserver,
        ));
    }
}
