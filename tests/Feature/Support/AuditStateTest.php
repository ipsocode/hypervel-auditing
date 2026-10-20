<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Tests\Feature\Support;

use Hypervel\Support\Facades\Config;
use Ipsocode\Auditing\Auditable;
use Ipsocode\Auditing\Events\DispatchAudit;
use Ipsocode\Auditing\Facades\Auditor;
use Ipsocode\Auditing\Support\AuditState;
use Ipsocode\Auditing\Tests\TestCase;
use ReflectionClass;
use ReflectionProperty;
use Workbench\App\Models\Article;

/**
 * The audit state is listed once, in AuditState, and read by the queued payload
 * and the manual audit. These tests fail when the trait and the list drift, or
 * when either reader stops covering a listed property.
 */
class AuditStateTest extends TestCase
{
    /**
     * @return array<string,mixed> a distinct, non-default value for every listed property
     */
    private function distinctValues(): array
    {
        $values = [
            'auditEvent' => 'restored-event',
            'auditCustomOld' => ['old' => 1],
            'auditCustomNew' => ['new' => 2],
            'isCustomEvent' => true,
            'preloadedResolverData' => ['marker' => 'kept'],
            'auditBatchUuid' => 'a-pinned-batch-uuid',
        ];

        // Fails when a name is added to the list without a value here.
        $this->assertEqualsCanonicalizing(AuditState::PROPERTIES, array_keys($values));

        return $values;
    }

    private function article(): Article
    {
        return Article::factory()->create(['title' => 'V1']);
    }

    public function testEveryListedNameIsAPropertyDeclaredOnAnAuditableModel(): void
    {
        $model = new ReflectionClass(Article::class);

        foreach (AuditState::PROPERTIES as $name) {
            $this->assertTrue($model->hasProperty($name), "Article declares no \${$name}.");
        }
    }

    public function testEveryPublicPropertyTheTraitDeclaresIsListed(): void
    {
        $declared = [];

        foreach ((new ReflectionClass(Auditable::class))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if (! $property->isStatic()) {
                $declared[] = $property->getName();
            }
        }

        $this->assertEqualsCanonicalizing($declared, AuditState::PROPERTIES);
    }

    public function testEveryListedPropertyReachesTheQueueWorker(): void
    {
        Config::set('auditing.queue.enable', false);

        $article = $this->article();

        foreach ($this->distinctValues() as $name => $value) {
            $article->{$name} = $value;
        }

        $restored = unserialize(serialize(new DispatchAudit($article)));

        foreach ($this->distinctValues() as $name => $value) {
            $this->assertSame($value, $restored->model->{$name}, "\${$name} was lost on the way to the queue.");
        }
    }

    public function testAManualAuditRestoresEveryListedProperty(): void
    {
        $article = $this->article();

        foreach ($this->distinctValues() as $name => $value) {
            $article->{$name} = $value;
        }

        Auditor::on($article)->as('exported')->log();

        foreach ($this->distinctValues() as $name => $value) {
            $this->assertSame($value, $article->{$name}, "\${$name} was left changed by log().");
        }
    }
}
