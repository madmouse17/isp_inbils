<?php

namespace Tests\Feature\NetworkAsset;

use App\Models\Core\Company;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Modules\NetworkAsset\Models\NasDevice;
use Modules\NetworkAsset\Models\NetworkAccount;
use Modules\NetworkAsset\Services\NetworkAccountService;
use Modules\Service\Models\ServicePackage;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\TestHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use Tests\Traits\BuildsNetworkFixtures;
use Tests\Traits\BuildsSubscriptionBilling;
use Tests\Traits\CreatesCompanyUser;
use Tests\Traits\InteractsWithNasCoa;
use Tests\Traits\InteractsWithRadius;

/**
 * Matrix rows the other network suites leave open: the account service on its own against another company's
 * records, a mass isolir while RADIUS is down, a subscription moved to a package without a RADIUS profile name, and
 * the three secrets in every place a RADIUS answer or a failure can leave a trace.
 */
class NetworkAccessGapTest extends TestCase
{
    use BuildsNetworkFixtures;
    use BuildsSubscriptionBilling;
    use CreatesCompanyUser;
    use InteractsWithNasCoa;
    use InteractsWithRadius;
    use RefreshDatabase;

    private const SUBSCRIBERS = 12;

    private User $admin;

    private Company $company;

    private string $realm;

    private NasDevice $nas;

    private string $nasIp;

    /** @var array{0: string|false, 1: string|false}|null */
    private ?array $phpTraceSettings = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->admin = $this->createCompanyUser();
        $this->company = $this->admin->company;
        $this->realm = $this->realmOf($this->company);
        $this->grantNetworkPermissionsToAdminRole();
        $this->fakeRadius();
        $this->nas = $this->nasClient($this->company);
        $this->nasIp = (string) $this->nas->nasname;
        $this->coaAnswers($this->nasIp, 'ack');
        $this->actingAs($this->admin);
    }

    protected function tearDown(): void
    {
        if ($this->phpTraceSettings !== null) {
            ini_set('zend.exception_ignore_args', (string) $this->phpTraceSettings[0]);
            ini_set('zend.exception_string_param_max_len', (string) $this->phpTraceSettings[1]);
        }

        parent::tearDown();
    }

    private function package(Company $company, ?string $profile = 'home-20m'): ServicePackage
    {
        return $this->packageWithRadiusProfile($company, $profile);
    }

    /** A package change as the web action leaves it: the package and its version move together, and the observer sees it. */
    private function moveToPackage(ServiceSubscription $subscription, ServicePackage $package): void
    {
        $subscription->update(['service_package_id' => $package->id, 'service_package_version_id' => $package->versions()->value('id')]);
    }

    // --- tenant: the service on its own ---------------------------------------------------------------------------

    public function test_the_account_service_refuses_another_companys_records_without_the_http_layer(): void
    {
        Queue::fake();
        $other = Company::factory()->create(['is_active' => true]);
        $foreign = $this->subscriptionIn($other, 'active', null, $this->package($other));
        $foreignAccount = $this->networkAccountFor($foreign, ['username' => 'zed@'.$this->realmOf($other)]);
        $mine = $this->subscriptionIn($this->company, 'active', null, $this->package($this->company));
        $payload = ['service_type' => 'pppoe', 'username' => 'hijack', 'password' => 'fake-gap-secret-1'];

        // Another company's subscription is not found: 404, as on the route, not an error that tells it exists.
        $this->assertRefusedWith(404, fn () => NetworkAccountService::create($foreign, $payload));
        // Another company's account cannot be edited even when a caller hands it over.
        try {
            NetworkAccountService::update(NetworkAccount::withoutCompany()->findOrFail($foreignAccount->id), ['username' => 'hijack', 'password' => 'fake-gap-secret-2']);
            $this->fail('an account of another company must not be updated');
        } catch (ModelNotFoundException) {
        }
        // A username that already carries another company's realm is refused for an own subscription.
        $this->assertRefusedWith(422, fn () => NetworkAccountService::create($mine, ['username' => 'zed@'.$this->realmOf($other)] + $payload));

        $this->assertEquals(['zed@'.$this->realmOf($other) => $other->id], NetworkAccount::withoutCompany()->pluck('company_id', 'username')->all(), 'no account was created or moved');
        $this->assertSame(0, Activity::query()->where('log_name', 'network_account')->count(), 'a refused change leaves no audit entry');
        Queue::assertNothingPushed();
    }

    // --- mass isolir while RADIUS is down --------------------------------------------------------------------------

    public function test_a_mass_isolir_while_radius_is_down_leaves_every_account_pending_and_recovers_when_it_answers(): void
    {
        $this->company->update(['settings' => ['auto_suspend_enabled' => true, 'auto_suspend_overdue_days' => 7]]);
        $package = $this->package($this->company);

        foreach (range(1, self::SUBSCRIBERS) as $n) {
            $subscription = $this->subscriptionIn($this->company, 'active', null, $package);
            $this->invoiceFor($subscription, 10); // three days past the seven days of grace
            $this->applyAccount($this->networkAccountFor($subscription, ['username' => sprintf('user%02d@%s', $n, $this->realm)]));
        }
        $this->assertSame(['synced'], NetworkAccount::query()->pluck('sync_status')->unique()->values()->all());
        Schema::connection('radius')->drop('radusergroup');

        $this->assertSame(0, Artisan::call('billing:suspend-overdue', ['--company' => $this->company->id]));

        $this->assertSame(
            self::SUBSCRIBERS,
            ServiceSubscription::withoutCompany()->where('company_id', $this->company->id)->where('status', 'suspended')->where('suspension_source', 'overdue')->count(),
            'business first: every overdue subscription is suspended whatever RADIUS does',
        );
        $this->assertSame(['pending'], NetworkAccount::query()->pluck('sync_status')->unique()->values()->all());
        $this->assertSame(self::SUBSCRIBERS, NetworkAccount::query()->whereNotNull('last_error')->where('last_error', '!=', '')->count(), 'every account says why it waits');
        $this->assertSame(["{$this->realm}/home-20m"], NetworkAccount::query()->pluck('provisioned_group')->unique()->values()->all(), 'nothing was isolated yet');

        $this->fakeRadius();
        $this->travel(3)->minutes();

        $this->assertSame(0, Artisan::call('network:sync', ['--company' => $this->company->id]));

        $this->assertSame(['synced'], NetworkAccount::query()->pluck('sync_status')->unique()->values()->all(), 'the sync found RADIUS again and re-queued every pending account');
        $this->assertSame(["{$this->realm}/isolir"], NetworkAccount::query()->pluck('provisioned_group')->unique()->values()->all());
        $this->assertSame(self::SUBSCRIBERS, $this->radiusConnection()->table('radusergroup')->where('groupname', "{$this->realm}/isolir")->count());
    }

    // --- observer path of a package move (the web action that moves it is SubscriptionController::changePackage) ------

    public function test_a_package_without_a_radius_profile_name_fails_only_the_account_and_a_good_package_heals_it(): void
    {
        $subscription = $this->subscriptionIn($this->company, 'active', null, $this->package($this->company));
        $account = $this->networkAccountFor($subscription, ['username' => "carol@{$this->realm}"]);
        $this->applyAccount($account);
        $this->seedRadiusSession(['username' => "carol@{$this->realm}", 'nasipaddress' => $this->nasIp]);
        $nameless = $this->package($this->company, null);

        $this->moveToPackage($subscription, $nameless);

        $this->assertSame($nameless->id, $subscription->fresh()->service_package_id, 'business first: the package change itself succeeds');
        $account = $account->fresh();
        $this->assertSame('failed', $account->sync_status);
        $this->assertStringContainsString('belum punya nama profil RADIUS', (string) $account->last_error);
        $this->assertSame("{$this->realm}/home-20m", $this->radiusRows('radusergroup', ['username' => "carol@{$this->realm}"])[0]['groupname']);
        $this->assertSame([], $this->disconnectsSent(), 'the session of an account that cannot be applied stays');
        $this->assertSame('rejected', Activity::query()->where('description', 'provision_failed')->sole()->properties['error_kind']);

        $good = $this->package($this->company, 'home-50m');
        $this->moveToPackage($subscription, $good);

        $account = $account->fresh();
        $this->assertSame('synced', $account->sync_status);
        $this->assertNull($account->last_error);
        $this->assertSame("{$this->realm}/home-50m", $this->radiusRows('radusergroup', ['username' => "carol@{$this->realm}"])[0]['groupname']);
        $this->assertCount(1, $this->disconnectsSent());
    }

    // --- secrets: nowhere but RADIUS and the one-time flash ---------------------------------------------------------

    /** @return array<string, array{0: string}> */
    public static function radiusStates(): array
    {
        return [
            'RADIUS accepts everything' => ['up'],
            'the RADIUS database is down' => ['down'],
            'the switch is off' => ['off'],
        ];
    }

    #[DataProvider('radiusStates')]
    public function test_no_secret_is_left_in_any_store_page_export_or_log_whatever_radius_answers(string $state): void
    {
        $subscriberSecret = 'fake-gap-subscriber-pw-1';
        $changedSecret = 'fake-gap-subscriber-pw-2';
        $nasSecret = 'fake-gap-nas-radius-secret-9';
        // The production image has no php.ini: stack traces keep their arguments, which is what failed_jobs
        // and the exception log lines are made of.
        $this->phpTraceSettings = [ini_get('zend.exception_ignore_args'), ini_get('zend.exception_string_param_max_len')];
        ini_set('zend.exception_ignore_args', '0');
        ini_set('zend.exception_string_param_max_len', '15');
        config(['queue.default' => 'database']);
        $logs = new TestHandler();
        Log::channel(config('logging.default'))->getLogger()->pushHandler($logs);

        $this->nas->forceFill(['radius_secret' => $nasSecret])->save();
        match ($state) {
            'down' => Schema::connection('radius')->drop('radcheck'),
            'off' => $this->disableRadius(),
            default => null,
        };
        $subscription = $this->subscriptionIn($this->company, 'active', null, $this->package($this->company));
        $activitySince = (int) Activity::query()->max('id');

        $this->post(route('admin.subscriptions.network-account.store', $subscription), ['service_type' => 'pppoe', 'username' => 'alice.gap', 'password' => $subscriberSecret])->assertRedirect();
        $this->assertSame(1, DB::table('jobs')->count(), 'the apply job waits on the real queue');
        $jobsBeforeTheWorker = DB::table('jobs')->get();
        $this->runWorkerUntilIdle();

        $this->post(route('admin.subscriptions.network-account.reveal', $subscription))->assertRedirect();
        $this->put(route('admin.subscriptions.network-account.update', $subscription), ['password' => $changedSecret])->assertRedirect();
        $this->post(route('admin.subscriptions.network-account.resync', $subscription))->assertRedirect();
        ServiceSubscription::withoutCompany()->findOrFail($subscription->id)->update(['status' => 'suspended']);
        $this->runWorkerUntilIdle();

        $account = NetworkAccount::query()->where('subscription_id', $subscription->id)->sole();
        $this->assertContains($account->sync_status, ['up' => ['synced'], 'down' => ['pending', 'failed'], 'off' => ['pending']][$state], "the account ends `{$account->sync_status}` when `$state`");
        $pages = [
            $this->get(route('admin.subscriptions.network-account.show', $subscription)),
            $this->get(route('admin.subscriptions.show', $subscription)),
            $this->get(route('admin.nas-devices.index')),
            $this->get(route('admin.nas-devices.show', $this->nas)),
            $this->get(route('admin.nas-devices.edit', $this->nas)),
            $this->get(route('admin.nas-devices.create')),
            $this->get(route('admin.network-assets.show', $this->nas->networkAsset)),
            $this->get(route('admin.customers.show', $subscription->customer)),
        ];
        $export = $this->get(route('admin.network-assets.export', ['format' => 'csv']))->assertOk()->streamedContent();

        $everywhere = [
            'network_accounts' => $this->dump(DB::table('network_accounts')->get()),
            'nas_devices' => $this->dump(DB::table('nas_devices')->get()),
            'activity_log' => $this->dump(DB::table('activity_log')->where('id', '>', $activitySince)->get()),
            'notifications' => $this->dump(DB::table('notifications')->get()),
            'jobs before the worker' => $this->dump($jobsBeforeTheWorker),
            'jobs' => $this->dump(DB::table('jobs')->get()),
            'failed_jobs' => $this->dump(DB::table('failed_jobs')->get()),
            'log output' => $this->logText($logs),
            'network asset export' => $export,
            'pages' => $this->dump(array_map(fn ($page) => $page->assertOk()->inertiaProps(), $pages)),
        ];
        $this->assertNotSame('[]', $everywhere['activity_log'], 'the audit entries were written');
        foreach ($everywhere as $store => $content) {
            foreach ([$subscriberSecret, $changedSecret, $nasSecret] as $secret) {
                $at = strpos($content, $secret);

                $this->assertFalse($at !== false, "$store holds `$secret`: ...".($at === false ? '' : substr($content, max(0, $at - 90), 200)).'...');
            }
        }
        if ($state === 'up') {
            $this->assertSame($changedSecret, $this->radiusRows('radcheck')[0]['value'], 'the one place that must hold the secret in clear');
        }
    }

    // --- helpers ----------------------------------------------------------------------------------------------------

    private function assertRefusedWith(int $status, callable $call): void
    {
        try {
            $call();
            $this->fail("Expected the call to be refused with $status.");
        } catch (HttpException $e) {
            $this->assertSame($status, $e->getStatusCode());
        }
    }

    /** What a queue worker does with the real database queue: take every due job, retry the failed ones after their backoff. */
    private function runWorkerUntilIdle(): void
    {
        foreach (range(1, 7) as $_) {
            Artisan::call('queue:work', ['--queue' => 'default', '--stop-when-empty' => true, '--sleep' => 0, '--memory' => 4096]);

            if (DB::table('jobs')->doesntExist()) {
                return;
            }

            $this->travel(16)->minutes(); // past the longest backoff of the apply job (15 minutes)
        }
    }

    private function logText(TestHandler $handler): string
    {
        $formatter = new LineFormatter(null, null, true, true, true);

        return implode("\n", array_map(fn ($record): string => $formatter->format($record), $handler->getRecords()));
    }

    /** @param  iterable<mixed>  $rows */
    private function dump(iterable $rows): string
    {
        return (string) json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
