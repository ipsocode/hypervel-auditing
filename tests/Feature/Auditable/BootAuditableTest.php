<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Tests\Feature\Auditable;

use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Support\Facades\Facade;
use Ipsocode\Auditing\AuditableObserver;
use Ipsocode\Auditing\Tests\TestCase;
use Mockery;
use RuntimeException;
use Workbench\App\Models\Article;
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
        new Article;

        $listeners = $this->app->get('events')->getRawListeners();
        $observers = [];

        foreach (AuditableObserver::EVENTS as $event) {
            // Bound callables, not `AuditableObserver@event` strings: a string
            // is resolved through reflection and the container on every fire.
            $bound = array_values(array_filter(
                $listeners['eloquent.' . $event . ': ' . Article::class] ?? [],
                static fn (mixed $listener): bool => is_array($listener) && $listener[0] instanceof AuditableObserver,
            ));

            $this->assertCount(1, $bound, "one audit observer listener for [{$event}]");
            $this->assertSame($event, $bound[0][1]);

            $observers[] = $bound[0][0];
        }

        $this->assertCount(1, array_unique(array_map(spl_object_id(...), $observers)));
    }

    public function testConstructingAnAuditableModelSurvivesATornDownContainer(): void
    {
        $application = Facade::getFacadeApplication();

        // A flushed container as the trait sees it: a non-null facade root that
        // resolves nothing. A guard against a *null* root alone would let the
        // lookups run and throw out of the constructor.
        $torn = Mockery::mock(ContainerContract::class);
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
}
