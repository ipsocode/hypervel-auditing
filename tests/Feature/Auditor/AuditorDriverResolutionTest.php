<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Tests\Feature\Auditor;

use Hypervel\Support\Facades\Config;
use Hypervel\Support\Facades\Event;
use InvalidArgumentException;
use Ipsocode\Auditing\Contracts\Auditor as AuditorContract;
use Ipsocode\Auditing\Drivers\AuditDetails;
use Ipsocode\Auditing\Events\Audited;
use Ipsocode\Auditing\Exceptions\AuditingException;
use Ipsocode\Auditing\Tests\TestCase;
use Workbench\App\Drivers\NotADriver;
use Workbench\App\Drivers\NullDriver;
use Workbench\App\Models\Article;

/**
 * `auditing.driver` accepts either a registered manager key or a class name, which
 * is how an application swaps in its own persistence.
 */
class AuditorDriverResolutionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        NullDriver::$auditCalls = 0;
        NullDriver::$pruneCalls = 0;
    }

    private function auditor(): AuditorContract
    {
        return $this->app->get(AuditorContract::class);
    }

    private function article(): Article
    {
        return Article::factory()->create();
    }

    public function testTheDefaultDriverKeyResolvesToTheBundledDriver(): void
    {
        $this->assertInstanceOf(AuditDetails::class, $this->auditor()->auditDriver(new Article));
    }

    public function testACustomDriverClassNameIsResolvedThroughTheContainer(): void
    {
        Config::set('auditing.driver', NullDriver::class);

        $this->assertInstanceOf(NullDriver::class, $this->auditor()->auditDriver(new Article));
    }

    public function testAClassThatIsNotADriverIsRejected(): void
    {
        Config::set('auditing.driver', NotADriver::class);

        $this->expectException(AuditingException::class);
        $this->expectExceptionMessage('The driver must implement the AuditDriver contract');

        $this->auditor()->auditDriver(new Article);
    }

    public function testAnUnknownDriverKeyIsRejected(): void
    {
        Config::set('auditing.driver', 'nope');

        $this->expectException(InvalidArgumentException::class);

        $this->auditor()->auditDriver(new Article);
    }

    public function testADriverReturningNullSkipsPruningAndTheAuditedEvent(): void
    {
        Config::set('auditing.driver', NullDriver::class);

        $audited = 0;
        Event::listen(Audited::class, function () use (&$audited) {
            ++$audited;
        });

        $article = $this->article();

        $this->assertSame(1, NullDriver::$auditCalls);
        $this->assertSame(0, NullDriver::$pruneCalls, 'prune() must not run for a skipped audit');
        $this->assertSame(0, $audited, 'no Audited event for a skipped audit');
        $this->assertSame(0, $article->audits()->count());
    }

    public function testTheAuditedEventCarriesTheDriverAndThePersistedAudit(): void
    {
        $captured = null;
        Event::listen(Audited::class, function (Audited $event) use (&$captured) {
            $captured = $event;
        });

        $article = $this->article();

        $this->assertInstanceOf(AuditDetails::class, $captured->driver);
        $this->assertSame($article->audits()->sole()->getKey(), $captured->audit->getKey());
    }

    public function testAModelCanChooseItsOwnDriver(): void
    {
        $article = new class extends Article {
            protected ?string $table = 'articles';

            protected ?string $auditDriver = NullDriver::class;
        };

        $this->assertInstanceOf(NullDriver::class, $this->auditor()->auditDriver($article));
    }
}
