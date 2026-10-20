<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Tests\Feature\Auditable;

use Ipsocode\Auditing\Auditable;
use Ipsocode\Auditing\Concerns\AuditsRelationships;
use Ipsocode\Auditing\Tests\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Workbench\App\Models\Article;

/**
 * The pivot helpers live in AuditsRelationships, which Auditable uses: an
 * application model that uses only Auditable keeps every helper as is.
 */
class RelationshipAuditTraitTest extends TestCase
{
    public function testAnAuditableModelStillHasEveryRelationshipHelperAsAPublicMethod(): void
    {
        $this->assertContains(AuditsRelationships::class, class_uses(Auditable::class));
        $traitFile = (new ReflectionClass(AuditsRelationships::class))->getFileName();

        foreach (['auditAttach', 'auditDetach', 'auditSync', 'auditSyncWithoutDetaching', 'auditSyncWithPivotValues'] as $helper) {
            $method = new ReflectionMethod(Article::class, $helper);

            $this->assertTrue($method->isPublic(), "{$helper} must stay public");
            $this->assertSame($traitFile, $method->getFileName());
        }
    }
}
