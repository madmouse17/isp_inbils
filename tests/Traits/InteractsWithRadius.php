<?php

namespace Tests\Traits;

use App\Models\Core\Company;
use App\Models\Core\CompanyIntegration;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * An SQLite file standing in for the FreeRADIUS database: the stock table and column names that RadiusDirectory
 * uses, created per test. Each company has its own RADIUS integration (a `company_integrations` row), so every company
 * gets an enabled row that points at the file, and the application opens its own `radius_{companyId}` connection to
 * it. A file, not `:memory:`, because those connections are purged (a saved row, a worker loop) and a purged
 * in-memory database is gone. Nothing here reaches a real server.
 *
 *   $this->fakeRadius();
 *   $this->seedRadiusSession(['username' => 'budi@acme', 'nasipaddress' => '192.0.2.10']);
 *   $this->radiusRows('radcheck', ['username' => 'budi@acme']);
 *   $this->disableRadius();
 *
 * `radiusConnection()` is the shared database, and its query log also counts what the company connections run, so a
 * test that asserts "RADIUS was not asked" sees every statement whichever connection ran it.
 */
trait InteractsWithRadius
{
    /** Whether a company created from now on gets an enabled row, see fakeRadius(). */
    protected bool $radiusForNewCompanies = false;

    protected bool $radiusListening = false;

    private bool $radiusMirroring = false;

    /** @var list<string> the SQLite files of this test, deleted when it ends */
    private array $radiusFiles = [];

    /**
     * Give every company, the ones that exist and the ones a test creates later, an enabled RADIUS integration that
     * points at one fresh shared database, so the order in which a test builds its world does not matter. Calling it
     * again swaps in a new, empty database (RADIUS "back up" after a test broke it).
     */
    protected function fakeRadius(): void
    {
        $database = $this->newRadiusDatabase();

        config(['database.connections.radius' => ['driver' => 'sqlite', 'database' => $database, 'prefix' => '', 'foreign_key_constraints' => false]]);
        $this->purgeRadiusConnections();
        $this->radiusForNewCompanies = true;
        $this->mirrorCompanyConnectionsIntoRadiusLog();

        if (! $this->radiusListening) {
            // A model listener cannot be removed on its own (flushEventListeners would also drop the app's own hooks), so
            // disableRadius() switches this one off.
            Company::created(function (Company $company): void {
                if ($this->radiusForNewCompanies) {
                    $this->radiusIntegration($company);
                }
            });
            $this->radiusListening = true;
        }

        foreach (Company::query()->get() as $company) {
            $this->radiusIntegration($company);
        }
    }

    /** Every company's row is switched off, its connection details kept, and a company created later gets none. */
    protected function disableRadius(): void
    {
        $this->radiusForNewCompanies = false;

        foreach (CompanyIntegration::withoutCompany()->where('provider', 'radius')->get() as $row) {
            $row->update(['is_enabled' => false]);
        }
    }

    /**
     * The company's own RADIUS integration: pointing at `$database` (the shared fake one unless a test gives another).
     * The SQLite driver ignores host, port and user, so those are only there to look like a real row.
     */
    protected function radiusIntegration(Company $company, ?string $database = null, bool $enabled = true): CompanyIntegration
    {
        return CompanyIntegration::withoutCompany()->updateOrCreate(
            ['company_id' => $company->id, 'provider' => 'radius'],
            [
                'credentials' => ['password' => 'fake-radius-db-secret'],
                'settings' => ['host' => '127.0.0.1', 'port' => 3306, 'database' => $database ?? (string) config('database.connections.radius.database'), 'username' => 'fake_radius_user'],
                'is_enabled' => $enabled,
            ],
        );
    }

    /**
     * Switch every existing company's integration on and point it at a MySQL server that refuses the connection: RADIUS
     * is configured and down. The names are the ones that must never show up in a page, a response or a message
     * ('127.0.0.1', 'fake_radius_user', 'fake_radius_db', the secret below).
     */
    protected function breakRadius(): void
    {
        config(['database.connections.radius' => ['driver' => 'mysql', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '']]);
        DB::purge('radius');

        foreach (CompanyIntegration::withoutCompany()->where('provider', 'radius')->get() as $row) {
            $row->update([
                'credentials' => ['password' => 'fake-db-password'],
                'settings' => ['host' => '127.0.0.1', 'port' => '1', 'database' => 'fake_radius_db', 'username' => 'fake_radius_user'],
                'is_enabled' => true,
            ]);
        }
    }

    /**
     * A new SQLite file with the stock FreeRADIUS tables, deleted when the test ends. `fakeRadius()` shares one between
     * every company; a test of tenant separation gives each company its own.
     */
    protected function newRadiusDatabase(): string
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'inbils-radius-');

        if ($this->radiusFiles === []) {
            $this->beforeApplicationDestroyed(function (): void {
                foreach ($this->radiusFiles as $path) {
                    foreach (['', '-journal', '-wal', '-shm'] as $suffix) {
                        @unlink($path.$suffix);
                    }
                }
            });
        }

        $this->radiusFiles[] = $file;
        $schema = $this->radiusConnectionTo($file)->getSchemaBuilder();

        foreach (['radcheck', 'radreply'] as $table) {
            $schema->create($table, function (Blueprint $t): void {
                $t->increments('id');
                $t->string('username', 64)->default('')->index();
                $t->string('attribute', 64)->default('');
                $t->string('op', 2)->default('==');
                $t->string('value', 253)->default('');
            });
        }
        $schema->create('radgroupreply', function (Blueprint $t): void {
            $t->increments('id');
            $t->string('groupname', 64)->default('')->index();
            $t->string('attribute', 64)->default('');
            $t->string('op', 2)->default('=');
            $t->string('value', 253)->default('');
        });
        $schema->create('radusergroup', function (Blueprint $t): void {
            $t->increments('id');
            $t->string('username', 64)->default('')->index();
            $t->string('groupname', 64)->default('');
            $t->integer('priority')->default(1);
        });
        $schema->create('nas', function (Blueprint $t): void {
            $t->increments('id');
            $t->string('nasname', 128)->index();
            $t->string('shortname', 32)->nullable();
            $t->string('type', 30)->default('other');
            $t->integer('ports')->nullable();
            $t->string('secret', 60)->default('secret');
            $t->string('server', 64)->nullable();
            $t->string('community', 50)->nullable();
            $t->string('description', 200)->default('RADIUS Client');
        });
        $schema->create('radacct', function (Blueprint $t): void {
            $t->bigIncrements('radacctid');
            $t->string('acctsessionid', 64)->default('');
            $t->string('acctuniqueid', 32)->unique();
            $t->string('username', 64)->default('')->index();
            $t->string('nasipaddress', 15)->default('')->index();
            $t->dateTime('acctstarttime')->nullable()->index();
            $t->dateTime('acctupdatetime')->nullable();
            $t->dateTime('acctstoptime')->nullable()->index();
            $t->bigInteger('acctinputoctets')->nullable();
            $t->bigInteger('acctoutputoctets')->nullable();
            $t->string('framedipaddress', 15)->default('');
        });

        return $file;
    }

    /**
     * A direct connection to a fake RADIUS database, to read or seed it without going through the application. Its
     * name never starts with `radius_`, so the worker's purge and the company connections leave it alone.
     */
    protected function radiusConnectionTo(string $database): Connection
    {
        $name = 'fakeradius_'.md5($database);

        if (! array_key_exists($name, DB::getConnections())) {
            DB::build(['driver' => 'sqlite', 'database' => $database, 'prefix' => '', 'foreign_key_constraints' => false, 'name' => $name]);
        }

        return DB::connection($name);
    }

    /** The shared database: the `radius` template connection of config/database.php, as fakeRadius() set it. */
    protected function radiusConnection(): Connection
    {
        return DB::connection('radius');
    }

    /**
     * An open session that started and updated a minute ago; override any radacct column.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function seedRadiusSession(array $attributes = []): void
    {
        static $n = 0;
        $n++;

        $this->radiusConnection()->table('radacct')->insert($attributes + [
            'acctsessionid' => "sess-$n",
            'acctuniqueid' => "uniq-$n",
            'username' => 'budi@acme',
            'nasipaddress' => '192.0.2.10',
            'acctstarttime' => now()->subMinutes(2)->format('Y-m-d H:i:s'),
            'acctupdatetime' => now()->subMinute()->format('Y-m-d H:i:s'),
            'acctstoptime' => null,
            'acctinputoctets' => 0,
            'acctoutputoctets' => 0,
            'framedipaddress' => '198.51.100.7',
        ]);
    }

    /**
     * @param  array<string, mixed>  $where
     * @return list<array<string, mixed>>
     */
    protected function radiusRows(string $table, array $where = []): array
    {
        return $this->radiusConnection()->table($table)->where($where)->orderBy($table === 'radacct' ? 'radacctid' : 'id')->get()
            ->map(fn (object $row): array => (array) $row)->all();
    }

    /** Close every company connection the application opened, as a saved row or a worker loop does. */
    protected function purgeRadiusConnections(): void
    {
        foreach (array_keys(DB::getConnections()) as $name) {
            if ($name === 'radius' || str_starts_with($name, 'radius_')) {
                DB::purge($name);
            }
        }
    }

    /**
     * The application reaches RADIUS through `radius_{companyId}`, never through `radius`. Every statement of those
     * connections is logged on `radiusConnection()` too, so the tests that count or forbid RADIUS statements through its
     * query log keep seeing all of them.
     */
    private function mirrorCompanyConnectionsIntoRadiusLog(): void
    {
        if ($this->radiusMirroring) {
            return;
        }

        DB::listen(function (QueryExecuted $query): void {
            if (str_starts_with($query->connectionName, 'radius_')) {
                $this->radiusConnection()->logQuery($query->sql, $query->bindings, $query->time);
            }
        });
        $this->radiusMirroring = true;
    }
}
