<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Tests\Feature\Contracts;

use Ipsocode\Auditing\Contracts\Audit;
use Ipsocode\Auditing\Contracts\Auditable;
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
     * @return array<string, array{class-string}>
     */
    public static function contracts(): array
    {
        return [
            'Auditable' => [Auditable::class],
            'Audit' => [Audit::class],
        ];
    }

    public function testAuditSyncRejectsANonClosureCallback(): void
    {
        $this->expectException(TypeError::class);

        Article::factory()->create()->auditSync('categories', [], true, ['*'], 'not a closure');
    }
}
