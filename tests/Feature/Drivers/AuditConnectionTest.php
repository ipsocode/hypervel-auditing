<?php

declare(strict_types=1);

namespace Ipsocode\Auditing\Tests\Feature\Drivers;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Support\Facades\Config;
use Hypervel\Support\Facades\DB;
use Ipsocode\Auditing\Models\Audit;
use Ipsocode\Auditing\Models\AuditDetail;
use Ipsocode\Auditing\Support\AuditTables;
use Ipsocode\Auditing\Tests\TestCase;
use Throwable;
use Workbench\App\Models\Article;

/**
 * `auditing.connection` puts the two audit tables on a database of their own. The
 * models honour it; so must the transaction that writes them, or a failure part
 * way through leaves a metadata row committed with only some of its details.
 */
class AuditConnectionTest extends TestCase
{
    protected function defineEnvironment(Application $app): void
    {
        parent::defineEnvironment($app);

        $app->get('config')->set('database.connections.audit_db', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // Set before the migrations are registered: the bundled migrations
        // resolve their schema builder from this key, so they build the two
        // audit tables on `audit_db`.
        $app->get('config')->set('auditing.connection', 'audit_db');
    }

    public function testTheAuditModelsResolveTheConfiguredConnection(): void
    {
        $this->assertSame('audit_db', (new Audit)->getConnectionName());
        $this->assertSame('audit_db', (new AuditDetail)->getConnectionName());
    }

    public function testAuditsAreWrittenToTheConfiguredConnection(): void
    {
        $article = Article::factory()->create(['title' => 'V1']);
        $article->update(['title' => 'V2']);

        $audit = $article->audits()->where('event', 'updated')->sole();

        $this->assertSame(['title' => 'V1'], $audit->old_values);
        $this->assertSame(2, DB::connection('audit_db')->table(AuditTables::audits())->count());
    }

    public function testTheDriverRunsItsTransactionOnTheAuditConnection(): void
    {
        Config::set('auditing.driver', LevelRecordingDriver::class);
        LevelRecordingDriver::$levels = [];

        $defaultConnection = Config::get('database.default');

        // RefreshDatabase holds a transaction open on the default connection,
        // so its level is the baseline: the driver must not open another there.
        $baseline = DB::connection($defaultConnection)->transactionLevel();

        Article::factory()->create();

        $this->assertNotEmpty(LevelRecordingDriver::$levels, 'the driver never reached a detail write');

        foreach (LevelRecordingDriver::$levels as $level) {
            $this->assertGreaterThan(0, $level['audit'], 'the audit connection must be inside a transaction');
            $this->assertSame($baseline, $level['default'], 'no transaction should have been opened on the default connection');
        }
    }

    public function testAFailedDetailWriteRollsBackTheWholeAudit(): void
    {
        // `field` is NOT NULL, so a detail row with a null field aborts the
        // insert. The metadata row written moments earlier must go with it.
        $before = DB::connection('audit_db')->table(AuditTables::audits())->count();

        try {
            DB::connection('audit_db')->transaction(function () {
                Audit::create([
                    'event' => 'created',
                    'auditable_type' => Article::class,
                    'auditable_id' => 1,
                ])->details()->create(['field' => null, 'old_value' => null, 'new_value' => 'x']);
            });
        } catch (Throwable) {
        }

        $this->assertSame($before, DB::connection('audit_db')->table(AuditTables::audits())->count());
    }
}

/**
 * Records both connections' transaction levels while the driver builds its
 * detail rows, inside the transaction it opened.
 */
class LevelRecordingDriver extends \Ipsocode\Auditing\Drivers\AuditDetails
{
    /** @var array<int, array{default: int, audit: int}> */
    public static array $levels = [];

    protected function normalizeValue(mixed $value): ?string
    {
        static::$levels[] = [
            'default' => DB::connection(Config::get('database.default'))->transactionLevel(),
            'audit' => DB::connection('audit_db')->transactionLevel(),
        ];

        return parent::normalizeValue($value);
    }
}
