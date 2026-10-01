<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Tests\Feature\Auditor;

use Closure;
use Error;
use Hypervel\Support\Facades\Config;
use Hypervel\Testing\PHPUnit\AfterEachTestCleanup;
use Ipsocode\Auditing\Contracts\Auditor as AuditorContract;
use Ipsocode\Auditing\Drivers\AuditDetails;
use Ipsocode\Auditing\Events\DispatchAudit;
use Ipsocode\Auditing\Exceptions\AuditingException;
use Ipsocode\Auditing\Testing\TestState;
use Ipsocode\Auditing\Tests\TestCase;
use ReflectionProperty;
use Workbench\App\Drivers\NullDriver;
use Workbench\App\Models\Article;
use Workbench\App\Models\AuditWithoutDetails;
use Workbench\App\Models\Category;

/**
 * The seams around the audit pipeline that the happy path never reaches: the
 * Manager's unnamed-driver fallback, the guards that let a write be skipped or
 * refused, and the package's own test-state registrar.
 */
class AuditorInternalsTest extends TestCase
{
    /**
     * The key TestState registers its cleanup under.
     */
    private const CLEANUP_KEY = 'ipsocode/hypervel-auditing';

    private function auditor(): AuditorContract
    {
        return $this->app->get(AuditorContract::class);
    }

    public function testAskingTheManagerForAnUnnamedDriverYieldsTheBundledOne(): void
    {
        $this->assertInstanceOf(AuditDetails::class, $this->auditor()->driver());
    }

    public function testTheUnnamedDriverFollowsTheAuditDriverConfig(): void
    {
        Config::set('auditing.driver', NullDriver::class);

        $this->assertInstanceOf(NullDriver::class, $this->auditor()->driver());
    }

    public function testExecuteRecordsNothingForAModelWithNoAuditEvent(): void
    {
        $article = Article::factory()->create();

        // setAuditEvent() nulls the event when it is not one the model audits,
        // which is the state a model sits in outside an observed write.
        $article->setAuditEvent('not-an-audited-event');

        $this->auditor()->execute($article);

        $this->assertNull($article->getAuditEvent());
        $this->assertSame(1, $article->audits()->count(), 'only the creation audit may exist');
    }

    public function testToAuditIsResolvedOnlyOnceEvenWhenEmptyValuesIsDisabled(): void
    {
        Config::set('auditing.empty_values', false);

        $article = new class extends Article {
            protected ?string $table = 'articles';

            public int $toAuditCalls = 0;

            public function toAudit(): array
            {
                ++$this->toAuditCalls;

                return parent::toAudit();
            }
        };

        $article->title = 'V1';
        $article->content = 'Body';
        $article->reviewed = false;
        $article->save();

        // Auditor::execute() resolves it for the empty-values check and hands
        // that payload to the driver, which must not resolve it again.
        $this->assertSame(1, $article->toAuditCalls);
        $this->assertSame(1, $article->audits()->count());
    }

    public function testAnAuditImplementationWithoutADetailsRelationIsRefused(): void
    {
        Config::set('auditing.implementation', AuditWithoutDetails::class);

        try {
            Article::factory()->create();
            $this->fail('Expected the driver to refuse an implementation with no details() relation.');
        } catch (AuditingException $exception) {
            $this->assertSame(
                sprintf(
                    'The audit implementation %s must expose a details() relation to use the audit_details driver',
                    AuditWithoutDetails::class
                ),
                $exception->getMessage()
            );
        }

        // The guard fires inside the driver's transaction, so the metadata row
        // written just before it must roll back rather than linger detail-less.
        $this->assertSame(0, AuditWithoutDetails::query()->count());
    }

    public function testUnserializingAnEventWhoseClassIsNotAuditableThrows(): void
    {
        $event = new DispatchAudit(Article::factory()->create());

        $this->expectException(AuditingException::class);
        $this->expectExceptionMessage(
            'Cannot restore a queued DispatchAudit event for ' . Category::class . ': it is not Auditable.'
        );

        $event->__unserialize([
            'class' => Category::class,
            'model_data' => ['auditEvent' => 'created'],
        ]);
    }

    /**
     * A property named in `auditEventSerializedProperties` that doesn't exist
     * on the model is a typo — genuinely absent, so skipping it is right.
     */
    public function testSerializingToleratesANonexistentSerializedProperty(): void
    {
        $article = new class(['title' => 'V1', 'content' => 'Body']) extends Article {
            protected ?string $table = 'articles';

            public array $auditEventSerializedProperties = ['thisPropertyDoesNotExist'];
        };
        $article->save();

        $restored = unserialize(serialize(new DispatchAudit($article)));

        $this->assertInstanceOf(Article::class, $restored->model);
    }

    /**
     * A property named in `auditEventSerializedProperties` that exists but was
     * never initialized throws `Error`, not `ReflectionException`, when read
     * through reflection. It is a real fault, not a typo, and must propagate:
     * swallowing it would silently produce a subtly wrong audit.
     */
    public function testSerializingPropagatesAnErrorFromAnUninitializedTypedProperty(): void
    {
        $article = new class(['title' => 'V1', 'content' => 'Body']) extends Article {
            protected ?string $table = 'articles';

            public array $auditEventSerializedProperties = ['uninitializedTyped'];

            protected string $uninitializedTyped;
        };
        $article->save();

        $this->expectException(Error::class);

        serialize(new DispatchAudit($article));
    }

    public function testGetSerializedDateMatchesTheAuditModelsOwnDateSerialization(): void
    {
        $audit = Article::factory()->create()->audits()->sole();

        $this->assertSame(
            $audit->toArray()['created_at'],
            $audit->getSerializedDate($audit->created_at)
        );
    }

    public function testRegisterInstallsTheFlushCallbackUnderThePackageKey(): void
    {
        AfterEachTestCleanup::forget(self::CLEANUP_KEY);

        try {
            $this->assertArrayNotHasKey(self::CLEANUP_KEY, $this->registeredCleanups());

            TestState::register();

            $callbacks = $this->registeredCleanups();
            $this->assertArrayHasKey(self::CLEANUP_KEY, $callbacks);

            // Invoked directly rather than through runCallbacks(), which would
            // also fire every unrelated registrar in this worker.
            Article::$auditingDisabled = true;
            ($callbacks[self::CLEANUP_KEY])();

            $this->assertFalse(Article::$auditingDisabled);
        } finally {
            // The registry is worker-lifetime state: leaving it forgotten would
            // strip this package's cleanup from every later test in the process.
            TestState::register();
        }
    }

    /**
     * @return array<string,Closure>
     */
    private function registeredCleanups(): array
    {
        $property = new ReflectionProperty(AfterEachTestCleanup::class, 'callbacks');

        $property->setAccessible(true);

        return $property->getValue();
    }
}
