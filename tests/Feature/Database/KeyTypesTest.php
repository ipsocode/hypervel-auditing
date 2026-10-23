<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Tests\Feature\Database;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Support\Facades\Config;
use Hypervel\Support\Facades\DB;
use InvalidArgumentException;
use Ipsocode\Auditing\Facades\Auditor;
use Ipsocode\Auditing\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Workbench\App\Models\UuidArticle;
use Workbench\App\Models\UuidUser;

class KeyTypesTest extends TestCase
{
    protected function defineEnvironment(Application $app): void
    {
        parent::defineEnvironment($app);

        // Both key columns are UUIDs for the whole class, as an application
        // keyed by HasUuids would set them before its first migrate.
        $app->get('config')->set('auditing.key_types', ['auditable' => 'uuid', 'user' => 'uuid']);

        // Never connected to: pretend() renders the DDL without executing it.
        $app->get('config')->set('database.connections.mysql_pretend', [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => 3306,
            'database' => 'nowhere',
            'username' => 'nobody',
            'password' => '',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function mysqlDdl(): array
    {
        $definition = require dirname(__DIR__, 3) . '/database/migrations/2026_06_06_000001_create_audits_table.php';

        Config::set('auditing.connection', 'mysql_pretend');

        return array_map(
            static fn (array $entry): string => $entry['query'],
            DB::connection('mysql_pretend')->pretend(static fn () => $definition->up())
        );
    }

    #[DataProvider('keyTypes')]
    public function testEachKeyTypeSetsTheColumnType(string $type, string $sqlType): void
    {
        Config::set('auditing.key_types', ['auditable' => $type, 'user' => $type]);

        $ddl = implode("\n", $this->mysqlDdl());

        $this->assertStringContainsString("`auditable_id` {$sqlType} not null", $ddl);
        $this->assertStringContainsString("`user_id` {$sqlType} null", $ddl);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function keyTypes(): array
    {
        return [
            'bigint' => ['bigint', 'bigint unsigned'],
            'uuid' => ['uuid', 'char(36)'],
            'ulid' => ['ulid', 'char(26)'],
            'string' => ['string', 'varchar(255)'],
        ];
    }

    public function testTheKeyTypesAreIndependent(): void
    {
        Config::set('auditing.key_types', ['auditable' => 'ulid', 'user' => 'bigint']);

        $ddl = implode("\n", $this->mysqlDdl());

        $this->assertStringContainsString('`auditable_id` char(26) not null', $ddl);
        $this->assertStringContainsString('`user_id` bigint unsigned null', $ddl);
    }

    public function testAnUnknownKeyTypeFailsTheMigrationNamingTheKey(): void
    {
        Config::set('auditing.key_types', ['auditable' => 'bigint', 'user' => 'integer']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('auditing.key_types.user must be one of bigint, uuid, ulid or string, [integer] given.');

        $this->mysqlDdl();
    }

    public function testANonScalarKeyTypeIsNamedByItsType(): void
    {
        Config::set('auditing.key_types', ['auditable' => ['uuid'], 'user' => 'bigint']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('auditing.key_types.auditable must be one of bigint, uuid, ulid or string, [array] given.');

        $this->mysqlDdl();
    }

    public function testAMissingKeyTypeFallsBackToBigint(): void
    {
        Config::set('auditing.key_types', []);

        $ddl = implode("\n", $this->mysqlDdl());

        $this->assertStringContainsString('`auditable_id` bigint unsigned not null', $ddl);
    }

    public function testAUuidKeyedModelChangedByAUuidKeyedUserIsAudited(): void
    {
        $user = UuidUser::create(['name' => 'Alice']);
        $other = UuidUser::create(['name' => 'Bob']);
        $article = UuidArticle::create(['title' => 'V1']);

        Auditor::actingAs($user, fn () => $article->update(['title' => 'V2']));
        Auditor::actingAs($other, fn () => $article->update(['title' => 'V3']));

        $audit = $article->audits()->byUser($user)->sole();

        $this->assertSame($article->getKey(), $audit->auditable_id);
        $this->assertSame($user->getKey(), $audit->user_id);
        $this->assertTrue($audit->user->is($user));
        $this->assertSame(['title' => 'V2'], $audit->new_values);
        $this->assertSame([$audit->getKey()], $user->causedAudits()->pluck('id')->all());
    }

    public function testTransitionToWorksForAUuidKeyedModel(): void
    {
        $article = UuidArticle::create(['title' => 'V1']);
        $article->update(['title' => 'V2']);

        $audit = $article->audits()->where('event', 'updated')->sole();

        $article->title = 'scratch';
        $article->transitionTo($audit);

        $this->assertSame('V2', $article->title);

        $article->transitionTo($audit, true);

        $this->assertSame('V1', $article->title);
    }
}
