<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Tests\Feature\Contracts;

use Ipsocode\Auditing\Contracts\AcceptsResolvedAudit;
use Ipsocode\Auditing\Contracts\AttributeEncoder;
use Ipsocode\Auditing\Contracts\AttributeRedactor;
use Ipsocode\Auditing\Contracts\Audit;
use Ipsocode\Auditing\Contracts\Auditable;
use Ipsocode\Auditing\Contracts\AuditDriver;
use Ipsocode\Auditing\Contracts\Auditor;
use Ipsocode\Auditing\Contracts\Resolver;
use Ipsocode\Auditing\Contracts\UserResolver;
use Ipsocode\Auditing\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use TypeError;
use Workbench\App\Models\Article;

/**
 * An untyped interface method can only gain a return type before the first
 * stable release: afterwards it would break every implementation.
 */
class ContractsAreTypedTest extends TestCase
{
    /**
     * @param class-string $contract
     */
    #[DataProvider('contracts')]
    public function testEveryContractMethodDeclaresAReturnType(string $contract): void
    {
        foreach ((new ReflectionClass($contract))->getMethods() as $method) {
            $this->assertTrue($method->hasReturnType(), sprintf('%s::%s() has no return type', $contract, $method->getName()));
        }
    }

    /**
     * Every interface in src/Contracts but the AttributeModifier marker, which declares no method.
     *
     * @return array<string, array{class-string}>
     */
    public static function contracts(): array
    {
        return [
            'Auditable' => [Auditable::class],
            'Audit' => [Audit::class],
            'AcceptsResolvedAudit' => [AcceptsResolvedAudit::class],
            'AttributeEncoder' => [AttributeEncoder::class],
            'AttributeRedactor' => [AttributeRedactor::class],
            'AuditDriver' => [AuditDriver::class],
            'Auditor' => [Auditor::class],
            'Resolver' => [Resolver::class],
            'UserResolver' => [UserResolver::class],
        ];
    }

    public function testAuditSyncRejectsANonClosureCallback(): void
    {
        $this->expectException(TypeError::class);

        Article::factory()->create()->auditSync('categories', [], true, ['*'], 'not a closure');
    }
}
