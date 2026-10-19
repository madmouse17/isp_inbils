<?php

namespace Tests\Feature\NetworkAsset;

use App\Jobs\TestCompanyIntegration;
use App\Models\Core\Company;
use App\Models\Core\CompanyIntegration;
use App\Models\User;
use App\Services\Core\CompanyService;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\NetworkAsset\Models\NetworkAccount;
use Modules\NetworkAsset\Services\Radius\RadiusDirectory;
use Modules\NetworkAsset\Services\Radius\RadiusException;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Traits\BuildsNetworkFixtures;
use Tests\Traits\BuildsSubscriptionBilling;
use Tests\Traits\CreatesCompanyUser;
use Tests\Traits\InteractsWithNasCoa;
use Tests\Traits\InteractsWithRadius;

/**
 * Every company has its own RADIUS database (Pengaturan → Integrasi): what it writes goes to its own connection, a
 * worker never serves one company's link to the next job, and a company without an enabled integration is told so
 * instead of reaching somebody else's database. Two SQLite files stand in for the FreeRADIUS databases of two ISPs;
 * the logins and the unreachable server are real answers of the MySQL server of the test environment.
 */
class RadiusPerCompanyTest extends TestCase
{
    use BuildsNetworkFixtures;
    use BuildsSubscriptionBilling;
    use CreatesCompanyUser;
    use InteractsWithNasCoa;
    use InteractsWithRadius;
    use RefreshDatabase;

    private const NOT_CONFIGURED = 'RADIUS belum dikonfigurasi untuk perusahaan ini (Pengaturan → Integrasi).';

    private const SECRET_A = 'fake-radius-secret-of-a';

    private User $adminA;

    /** Has a RADIUS database of its own. */
    private Company $a;

    /** Has another RADIUS database of its own. */
    private Company $b;

    /** Has not set RADIUS up: no integration row. */
    private Company $c;

    private string $fileA;

    private string $fileB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->fakeRadius();
        $this->adminA = $this->createCompanyUser();
        $this->a = $this->adminA->company;
        $this->grantNetworkPermissionsToAdminRole();
        $this->b = Company::factory()->create(['is_active' => true]);
        $this->c = Company::factory()->create(['is_active' => true]);

        $this->fileA = $this->newRadiusDatabase();
        $this->fileB = $this->newRadiusDatabase();
        $this->radiusIntegration($this->a, $this->fileA);
        $this->radiusIntegration($this->b, $this->fileB);
        CompanyIntegration::withoutCompany()->where('company_id', $this->c->id)->where('provider', 'radius')->delete();
        $this->disconnectsSent(); // binds the Disconnect fake: a Disconnect nobody scripted fails the test
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    private function inCompany(Company $company, Closure $work): mixed
    {
        return CompanyService::runAs((int) $company->id, $work);
    }

    private function rowOf(Company $company): CompanyIntegration
    {
        return CompanyIntegration::withoutCompany()->where('company_id', $company->id)->where('provider', 'radius')->firstOrFail();
    }

    /**
     * Rows of a fake RADIUS database, read without going through the application.
     *
     * @return list<array<string, mixed>>
     */
    private function rowsIn(string $file, string $table): array
    {
        return $this->radiusConnectionTo($file)->table($table)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();
    }

    /** @return list<string> */
    private function usernames(string $file, string $table = 'radcheck'): array
    {
        return array_column($this->rowsIn($file, $table), 'username');
    }

    /** @return list<string> the company links that are open right now */
    private function openRadiusConnections(): array
    {
        return array_values(array_filter(array_keys(DB::getConnections()), fn (string $name): bool => str_starts_with($name, 'radius_')));
    }

    private function accountOf(Company $company, string $local): NetworkAccount
    {
        $subscription = $this->subscriptionIn($company, 'active', null, $this->packageWithRadiusProfile($company, 'home-20m'));

        return $this->networkAccountFor($subscription, ['username' => $local.'@'.$this->realmOf($company)]);
    }

    /** What a queue worker does with the real database queue: take every job that is due, then stop. */
    private function runWorker(): void
    {
        Artisan::call('queue:work', ['--queue' => 'default', '--stop-when-empty' => true, '--sleep' => 0, '--memory' => 4096]);
    }

    /** The company's row pointing at the MySQL server of the test environment, with the given settings and secret. */
    private function pointAtMysql(Company $company, array $settings, string $secret): CompanyIntegration
    {
        $mysql = config('database.connections.mysql');
        config(['database.connections.radius' => ['driver' => 'mysql', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '']]);

        return CompanyIntegration::withoutCompany()->updateOrCreate(
            ['company_id' => $company->id, 'provider' => 'radius'],
            [
                'credentials' => ['password' => $secret],
                'settings' => ['host' => $mysql['host'], 'port' => $mysql['port'], 'database' => $mysql['database'], 'username' => $mysql['username'], ...$settings],
                'is_enabled' => true,
            ],
        );
    }

    // --- one connection per company --------------------------------------------------------------------------------

    public function test_the_connection_is_built_from_the_row_and_reused(): void
    {
        $row = $this->rowOf($this->a);

        $link = RadiusDirectory::connection($row);

        $this->assertSame("radius_{$this->a->id}", RadiusDirectory::connectionName($this->a->id));
        $this->assertSame("radius_{$this->a->id}", $link->getName());
        $this->assertSame($this->fileA, $link->getDatabaseName());
        $this->assertSame('fake-radius-db-secret', $link->getConfig('password'));
        $this->assertSame([PDO::ATTR_TIMEOUT => 5], $link->getConfig('options'), 'the driver gives up after five seconds');
        $this->assertSame($link, RadiusDirectory::connection($row), 'the open link is reused');
        $this->assertNotSame($link, RadiusDirectory::connection($this->rowOf($this->b)), 'another company, another link');
    }

    public function test_each_company_writes_to_its_own_radius_connection(): void
    {
        $radius = new RadiusDirectory();
        $hash = fn (string $secret): string => hash_hmac('sha256', $secret, (string) config('app.key'));

        $this->inCompany($this->a, fn () => $radius->putUser('alice@a', 'fake-secret-a1', 'a/home-20m', '10.70.0.9'));
        $this->inCompany($this->b, fn () => $radius->putUser('bob@b', 'fake-secret-b1', 'b/home-20m'));
        $this->inCompany($this->a, fn () => $radius->putUser('alina@a', 'fake-secret-a2', 'a/home-20m'));

        $this->assertSame(['alice@a', 'alina@a'], $this->usernames($this->fileA));
        $this->assertSame(['bob@b'], $this->usernames($this->fileB));
        $this->assertSame(['10.70.0.9'], array_column($this->rowsIn($this->fileA, 'radreply'), 'value'));
        $this->assertSame([], $this->rowsIn($this->fileB, 'radreply'));
        $this->assertSame([], $this->radiusRows('radcheck'), 'the shared database that no company points at stayed empty');
        $this->assertEqualsCanonicalizing(["radius_{$this->a->id}", "radius_{$this->b->id}"], $this->openRadiusConnections());

        // Reads are per company as well: B asks its own database and finds nothing of A.
        $this->assertTrue($this->inCompany($this->a, fn () => $radius->hasUser('alice@a', 'a/home-20m', $hash('fake-secret-a1'))));
        $this->assertFalse($this->inCompany($this->b, fn () => $radius->hasUser('alice@a', 'a/home-20m', $hash('fake-secret-a1'))));
        $this->assertSame('10.70.0.9', $this->inCompany($this->a, fn () => $radius->framedIpOf('alice@a')));
        $this->assertNull($this->inCompany($this->b, fn () => $radius->framedIpOf('alice@a')));
    }

    public function test_a_worker_loop_applies_the_accounts_of_two_companies_each_into_its_own_database(): void
    {
        $accountA = $this->accountOf($this->a, 'alice');
        $accountB = $this->accountOf($this->b, 'bob');
        config(['queue.default' => 'database']);
        $this->applyAccount($accountA);
        $this->applyAccount($accountB);
        $this->assertSame(2, DB::table('jobs')->count(), 'both runs wait on the real queue');

        $this->runWorker();

        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(['synced', 'synced'], [$accountA->fresh()->sync_status, $accountB->fresh()->sync_status]);
        $this->assertSame(['alice@'.$this->realmOf($this->a)], $this->usernames($this->fileA));
        $this->assertSame(['bob@'.$this->realmOf($this->b)], $this->usernames($this->fileB));
        $this->assertSame([$this->realmOf($this->a).'/home-20m'], array_column($this->rowsIn($this->fileA, 'radusergroup'), 'groupname'));
        $this->assertSame([$this->realmOf($this->b).'/home-20m'], array_column($this->rowsIn($this->fileB, 'radusergroup'), 'groupname'));
        $this->assertSame([], $this->disconnectsSent());
    }

    // --- the worker purge ------------------------------------------------------------------------------------------

    public function test_the_worker_purges_radius_connections_between_jobs(): void
    {
        $first = $this->accountOf($this->a, 'first');
        $second = $this->accountOf($this->a, 'second');
        $moved = $this->newRadiusDatabase();
        $settings = $this->rowOf($this->a)->settings;
        config(['queue.default' => 'database']);
        $this->applyAccount($first);
        $this->applyAccount($second);
        $open = null;
        $moveOnce = true;

        // The admin saves another database for company A in the web process while this worker is between two jobs. The
        // query builder fires no model event, so nothing here purges the link: only the worker's own loop can.
        Queue::after(function () use (&$open, &$moveOnce, $moved, $settings): void {
            if (! $moveOnce) {
                return;
            }

            $moveOnce = false;
            $open = $this->openRadiusConnections();
            DB::table('company_integrations')
                ->where('company_id', $this->a->id)
                ->where('provider', 'radius')
                ->update(['settings' => json_encode([...$settings, 'database' => $moved])]);
        });

        $this->runWorker();

        $this->assertSame(["radius_{$this->a->id}"], $open, 'the link of the first job outlived it');
        $this->assertSame(['first@'.$this->realmOf($this->a)], $this->usernames($this->fileA), 'the first job wrote to the database it was saved with');
        $this->assertSame(['second@'.$this->realmOf($this->a)], $this->usernames($moved), 'the second job reads the row again and opens a link to the new database');
        $this->assertSame(['synced', 'synced'], [$first->fresh()->sync_status, $second->fresh()->sync_status]);
        $this->assertSame([], $this->openRadiusConnections(), 'no link survives the loop');
    }

    public function test_the_purge_listener_lets_the_other_looping_listeners_run(): void
    {
        $ran = false;
        Queue::looping(function () use (&$ran): void {
            $ran = true;
        });

        // `until()` stops at the first listener that answers with a value: the purge must answer with nothing.
        $answer = $this->app['events']->until(new Looping('database', 'default'));

        $this->assertNull($answer);
        $this->assertTrue($ran);
    }

    public function test_saving_the_row_purges_the_open_connection(): void
    {
        $radius = new RadiusDirectory();
        $this->inCompany($this->a, fn () => $radius->putUser('alice@a', 'fake-secret-a1', 'a/home-20m'));
        $this->inCompany($this->b, fn () => $radius->putUser('bob@b', 'fake-secret-b1', 'b/home-20m'));
        $this->assertEqualsCanonicalizing(["radius_{$this->a->id}", "radius_{$this->b->id}"], $this->openRadiusConnections());

        // Another provider's row of company B is not a RADIUS change.
        CompanyIntegration::withoutCompany()->updateOrCreate(['company_id' => $this->b->id, 'provider' => 'mail'], ['settings' => ['host' => 'smtp.isp.test'], 'is_enabled' => false]);
        $this->assertEqualsCanonicalizing(["radius_{$this->a->id}", "radius_{$this->b->id}"], $this->openRadiusConnections());

        $moved = $this->newRadiusDatabase();
        $this->radiusIntegration($this->a, $moved);

        $this->assertSame(["radius_{$this->b->id}"], $this->openRadiusConnections(), 'saving the row of A closed the link of A only');

        $this->inCompany($this->a, fn () => $radius->putUser('alina@a', 'fake-secret-a2', 'a/home-20m'));

        $this->assertSame(['alina@a'], $this->usernames($moved), 'the next call opens a link to the saved database');
        $this->assertSame(['alice@a'], $this->usernames($this->fileA));

        $this->rowOf($this->b)->delete();

        $this->assertSame(["radius_{$this->a->id}"], $this->openRadiusConnections(), 'deleting the row closes its link too');
    }

    // --- a host the policy refuses ---------------------------------------------------------------------------------

    /** @return array<string, array{string}> */
    public static function refusedHosts(): array
    {
        return [
            'the cloud metadata address' => ['169.254.169.254'],
            'a link-local IPv6 address' => ['fe80::1'],
            'the metadata address in a libc spelling' => ['2852039166'],
            'the unspecified address' => ['0.0.0.0'],
        ];
    }

    #[DataProvider('refusedHosts')]
    public function test_a_host_the_policy_refuses_behaves_as_not_configured_and_is_never_dialled(string $host): void
    {
        Log::spy();
        $radius = new RadiusDirectory();
        $row = $this->rowOf($this->a);
        $row->update(['settings' => [...$row->settings, 'host' => $host]]);

        try {
            $this->inCompany($this->a, fn () => $radius->putUser('alice@a', 'fake-secret-a1', 'a/home-20m'));
            $this->fail('a refused host must not be dialled');
        } catch (RadiusException $e) {
            $this->assertSame(RadiusException::DISABLED, $e->kind);
            $this->assertSame(self::NOT_CONFIGURED, $e->getMessage());
        }

        $this->assertSame([], $this->openRadiusConnections(), 'no link was built');
        $this->assertSame([], $this->usernames($this->fileA), 'and nothing was written');
        $row = $this->rowOf($this->a);
        $this->assertSame("Host {$host} tidak diizinkan (alamat lokal atau metadata).", $row->last_error);
        $this->assertNull($row->verified_at);
        $this->assertSame('failed', CompanyIntegration::stateOf($row), 'the card shows it');
        Log::shouldHaveReceived('warning')->once()->with('integration.host_refused', ['company_id' => $this->a->id, 'provider' => 'radius']);
    }

    public function test_an_account_waits_with_the_not_configured_reason_while_its_host_is_refused_and_the_others_are_not_affected(): void
    {
        Log::spy();
        $row = $this->rowOf($this->a);
        $row->update(['settings' => [...$row->settings, 'host' => '169.254.169.254']]);
        $accountA = $this->accountOf($this->a, 'alice');
        $accountB = $this->accountOf($this->b, 'bob');

        $this->applyAccount($accountA);
        $this->applyAccount($accountB);

        $accountA = $accountA->fresh();
        $this->assertSame(['pending', self::NOT_CONFIGURED], [$accountA->sync_status, $accountA->last_error]);
        $this->assertSame([], $this->usernames($this->fileA));
        $this->assertSame('synced', $accountB->fresh()->sync_status, 'the host of another company is its own business');
        $this->assertSame([], $this->disconnectsSent());
    }

    public function test_the_row_is_dialled_again_once_the_host_is_allowed(): void
    {
        Log::spy();
        $radius = new RadiusDirectory();
        $row = $this->rowOf($this->a);
        $row->update(['settings' => [...$row->settings, 'host' => '169.254.169.254']]);

        try {
            $this->inCompany($this->a, fn () => $radius->putUser('alice@a', 'fake-secret-a1', 'a/home-20m'));
        } catch (RadiusException) {
        }

        $row = $this->rowOf($this->a);
        $row->update(['settings' => [...$row->settings, 'host' => '127.0.0.1']]);
        $this->inCompany($this->a, fn () => $radius->putUser('alice@a', 'fake-secret-a1', 'a/home-20m'));

        $this->assertSame(['alice@a'], $this->usernames($this->fileA));
    }

    // --- a company without RADIUS ----------------------------------------------------------------------------------

    public function test_a_company_without_radius_is_disabled_with_the_settings_hint(): void
    {
        $radius = new RadiusDirectory();
        $this->rowOf($this->b)->update(['is_enabled' => false]);

        foreach (['no row' => $this->c, 'a row that is switched off' => $this->b] as $case => $company) {
            try {
                $this->inCompany($company, fn () => $radius->putUser('nobody@x', 'fake-secret-x', 'x/group'));
                $this->fail("{$case}: a company without an enabled RADIUS must be refused");
            } catch (RadiusException $e) {
                $this->assertSame(RadiusException::DISABLED, $e->kind, $case);
                $this->assertSame(self::NOT_CONFIGURED, $e->getMessage(), $case);
            }
        }

        $this->assertSame([], $this->openRadiusConnections(), 'no link was opened for either');
        $this->assertSame([], $this->usernames($this->fileB), 'nothing reached the database of the switched-off company');
        $this->assertSame([], $this->usernames($this->fileA));

        $accountC = $this->accountOf($this->c, 'carol');
        $this->applyAccount($accountC);

        $accountC = $accountC->fresh();
        $this->assertSame(['pending', self::NOT_CONFIGURED], [$accountC->sync_status, $accountC->last_error]);
        $this->assertSame([], $this->disconnectsSent());
    }

    public function test_the_pages_of_a_company_without_radius_say_so_and_the_others_do_not(): void
    {
        $adminC = User::factory()->create(['company_id' => $this->c->id, 'email_verified_at' => now(), 'is_active' => true]);
        $adminC->assignRole('admin');
        $nasA = $this->nasClient($this->a);
        $nasC = $this->nasClient($this->c);
        $subscriptionA = $this->subscriptionIn($this->a, 'active', null, $this->packageWithRadiusProfile($this->a));
        $subscriptionC = $this->subscriptionIn($this->c, 'active', null, $this->packageWithRadiusProfile($this->c));

        $this->actingAs($this->adminA)->get(route('admin.nas-devices.show', $nasA))->assertOk()->assertInertia(fn (Assert $page) => $page->where('radiusEnabled', true));
        $this->actingAs($this->adminA)->get(route('admin.subscriptions.network-account.show', $subscriptionA))->assertOk()->assertInertia(fn (Assert $page) => $page->where('radiusEnabled', true));
        $this->actingAs($adminC)->get(route('admin.nas-devices.show', $nasC))->assertOk()->assertInertia(fn (Assert $page) => $page->where('radiusEnabled', false)->where('sessions', []));
        $this->actingAs($adminC)->get(route('admin.subscriptions.network-account.show', $subscriptionC))->assertOk()->assertInertia(fn (Assert $page) => $page->where('radiusEnabled', false)->where('session', null)->where('usage', null));
    }

    // --- "Uji koneksi" ---------------------------------------------------------------------------------------------

    public function test_the_check_passes_on_a_complete_schema_even_while_the_row_is_switched_off(): void
    {
        $radius = new RadiusDirectory();

        $this->assertNull($radius->check($this->rowOf($this->a)));

        $this->rowOf($this->a)->update(['is_enabled' => false]);

        $this->assertNull($radius->check($this->rowOf($this->a)), 'a key can be tested before it is switched on');
        $this->assertSame([], $this->openRadiusConnections(), 'the check closes its link');
    }

    public function test_the_check_reports_missing_tables(): void
    {
        $radius = new RadiusDirectory();
        $schema = $this->radiusConnectionTo($this->fileA)->getSchemaBuilder();
        $schema->drop('nas');
        $schema->drop('radacct');

        $this->assertSame('Tabel FreeRADIUS tidak lengkap: nas, radacct.', $radius->check($this->rowOf($this->a)));

        $schema->drop('radcheck');

        $this->assertSame('Tabel FreeRADIUS tidak lengkap: radcheck, nas, radacct.', $radius->check($this->rowOf($this->a)));
        $this->assertNull($radius->check($this->rowOf($this->b)), 'the database of B is complete');
        $this->assertSame([], $this->openRadiusConnections());
    }

    public function test_the_check_asks_for_what_is_missing_before_it_connects(): void
    {
        $blank = new CompanyIntegration(['company_id' => $this->c->id, 'provider' => 'radius']);

        $this->assertSame('Isi host, nama database, dan nama pengguna RADIUS dulu.', (new RadiusDirectory())->check($blank));
        $this->assertSame([], $this->openRadiusConnections());
    }

    public function test_the_check_reports_a_refused_login(): void
    {
        $row = $this->pointAtMysql($this->a, [], self::SECRET_A);

        $message = (new RadiusDirectory())->check($row);

        $this->assertSame('Login database RADIUS ditolak. Periksa nama pengguna dan kata sandi.', $message);
        $this->assertStringNotContainsString(self::SECRET_A, $message);
        $this->assertSame([], $this->openRadiusConnections());
    }

    public function test_the_check_reports_a_database_that_is_not_there(): void
    {
        $row = $this->pointAtMysql($this->a, ['database' => 'fake_radius_missing_db'], (string) config('database.connections.mysql.password'));

        $message = (new RadiusDirectory())->check($row);

        // MySQL answers 1049 to a user with rights on every database and 1044 to one with a grant per database.
        $this->assertContains($message, [
            'Database fake_radius_missing_db tidak ada di server RADIUS.',
            'Pengguna database RADIUS tidak punya akses ke database fake_radius_missing_db, atau database itu tidak ada.',
        ]);
    }

    public function test_the_check_reports_a_server_that_does_not_answer(): void
    {
        $row = $this->pointAtMysql($this->a, ['host' => '127.0.0.1', 'port' => 1], self::SECRET_A);

        $message = (new RadiusDirectory())->check($row);

        $this->assertSame('Database RADIUS tidak dapat dihubungi dalam 5 detik (127.0.0.1:1).', $message);
        $this->assertStringNotContainsString(self::SECRET_A, $message);
    }

    /** @return array<string, array{0: int, 1: string}> */
    public static function driverErrors(): array
    {
        return [
            'the login is refused' => [1045, 'Login database RADIUS ditolak. Periksa nama pengguna dan kata sandi.'],
            'the database does not exist' => [1049, 'Database fake_radius_db tidak ada di server RADIUS.'],
            'the user has no access to the database' => [1044, 'Pengguna database RADIUS tidak punya akses ke database fake_radius_db, atau database itu tidak ada.'],
            'the server does not answer' => [2002, 'Database RADIUS tidak dapat dihubungi dalam 5 detik (db.isp.test:3307).'],
        ];
    }

    #[DataProvider('driverErrors')]
    public function test_the_check_maps_driver_errors_to_indonesian_without_repeating_them(int $code, string $expected): void
    {
        $row = new CompanyIntegration(['provider' => 'radius', 'settings' => ['host' => 'db.isp.test', 'port' => 3307, 'database' => 'fake_radius_db', 'username' => 'fake_radius_user']]);
        $driverText = "SQLSTATE[HY000] [{$code}] Access denied for user 'fake_radius_user'@'db.isp.test' (using password: YES) fake-driver-secret";
        $withErrorInfo = new PDOException('generic');
        $withErrorInfo->errorInfo = ['HY000', $code, $driverText];

        // The number sits in the message of a failed connect, in errorInfo after a failed query, and the application wraps both.
        $causes = [new PDOException($driverText), $withErrorInfo, new QueryException('radius_1', 'select 1', [], new PDOException($driverText))];

        foreach ($causes as $cause) {
            $message = RadiusDirectory::failureMessage($cause, $row);

            $this->assertSame($expected, $message);
            $this->assertStringNotContainsString('fake-driver-secret', $message);
            $this->assertStringNotContainsString('fake_radius_user', $message);
        }

        $this->assertSame('Database RADIUS tidak dapat dihubungi dalam 5 detik (db.isp.test:3307).', RadiusDirectory::failureMessage(new \RuntimeException('anything else'), $row));
    }

    public function test_the_connection_test_job_records_the_outcome_on_the_row(): void
    {
        $row = $this->rowOf($this->a);

        TestCompanyIntegration::dispatchSync($row->id, $this->adminA->id);

        $row->refresh();
        $this->assertNotNull($row->verified_at);
        $this->assertNull($row->last_error);

        $this->radiusConnectionTo($this->fileA)->getSchemaBuilder()->drop('radacct');
        TestCompanyIntegration::dispatchSync($row->id, $this->adminA->id);

        $row->refresh();
        $this->assertNull($row->verified_at);
        $this->assertSame('Tabel FreeRADIUS tidak lengkap: radacct.', $row->last_error);
    }

    // --- network:sync ----------------------------------------------------------------------------------------------

    public function test_the_sync_visits_only_the_companies_with_an_enabled_radius(): void
    {
        $nasA = $this->nasClient($this->a);
        $nasB = $this->nasClient($this->b);
        $nasC = $this->nasClient($this->c);

        $this->artisan('network:sync')->expectsOutput('Network sync queued for 2 companies.')->assertSuccessful();

        $this->assertSame([$nasA->nasname], array_column($this->rowsIn($this->fileA, 'nas'), 'nasname'));
        $this->assertSame([$nasB->nasname], array_column($this->rowsIn($this->fileB, 'nas'), 'nasname'));
        $this->assertNotNull($nasA->fresh()->radius_synced_at);
        $this->assertNotNull($nasB->fresh()->radius_synced_at);
        $this->assertNull($nasC->fresh()->radius_synced_at, 'company C has no RADIUS: it is not asked for');
        $this->assertNull($nasC->fresh()->last_error);
        $this->assertSame([], $this->openRadiusConnections(), 'the sweep closes the link of each company it visited');

        $this->artisan('network:sync', ['--company' => $this->c->id])->expectsOutput('RADIUS is not configured for any company; nothing to sync.')->assertSuccessful();
    }

    public function test_the_sync_says_so_when_no_company_has_radius(): void
    {
        $nasA = $this->nasClient($this->a);
        $this->disableRadius();
        Queue::fake();

        $this->artisan('network:sync')->expectsOutput('RADIUS is not configured for any company; nothing to sync.')->assertSuccessful();

        Queue::assertNothingPushed();
        $this->assertNull($nasA->fresh()->radius_synced_at);
        $this->assertSame([], $this->rowsIn($this->fileA, 'nas'));
        $this->assertSame([], $this->openRadiusConnections());
    }
}
