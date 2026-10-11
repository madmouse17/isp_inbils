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
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Modules\NetworkAsset\Models\NasDevice;
use Modules\NetworkAsset\Models\NetworkAccount;
use Modules\NetworkAsset\Notifications\NasDeviceUnreachableNotification;
use Modules\NetworkAsset\Services\NetworkAccountService;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\TestHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\FakeRouterOs;
use Tests\TestCase;
use Tests\Traits\BuildsNetworkFixtures;
use Tests\Traits\BuildsSubscriptionBilling;
use Tests\Traits\CreatesCompanyUser;
use Tests\Traits\InteractsWithRouterOs;

/**
 * Matrix rows the suites of tickets 02-04 leave open: the account service on its own against another
 * company's records, a mass isolir while the router is down, a package without a router profile name
 * changed through the HTTP route, and the two passwords in every place a router answer or a failure can
 * leave a trace.
 */
class NetworkAccessGapTest extends TestCase
{
    use BuildsNetworkFixtures;
    use BuildsSubscriptionBilling;
    use CreatesCompanyUser;
    use InteractsWithRouterOs;
    use RefreshDatabase;

    private const SUBSCRIBERS = 50;

    private User $admin;

    private Company $company;

    private NasDevice $nas;

    private FakeRouterOs $router;

    /** @var array{0: string|false, 1: string|false}|null */
    private ?array $phpTraceSettings = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->admin = $this->createCompanyUser();
        $this->company = $this->admin->company;
        $this->grantNetworkPermissionsToAdminRole();
        $this->fakeRouterOs();
        [$this->nas, $this->router] = $this->nasRouter($this->company);
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

    // --- tenant: the service on its own ---------------------------------------------------------------------------

    public function test_the_account_service_refuses_another_companys_records_without_the_http_layer(): void
    {
        Queue::fake();
        $other = Company::factory()->create(['is_active' => true]);
        [$otherNas] = $this->nasRouter($other);
        $foreign = $this->subscriptionIn($other, 'active', null, $this->packageWithRouterProfile($other));
        $foreignAccount = $this->networkAccountFor($foreign, $otherNas, ['username' => 'zed']);
        $mine = $this->subscriptionIn($this->company, 'active', null, $this->packageWithRouterProfile($this->company));
        $ownAccount = $this->networkAccountFor($this->subscriptionIn($this->company, 'active', null, $this->packageWithRouterProfile($this->company)), $this->nas, ['username' => 'bob']);
        $payload = fn (NasDevice $nas, string $username): array => ['nas_device_id' => $nas->id, 'service_type' => 'pppoe', 'username' => $username, 'password' => 'fake-gap-secret-1'];

        // Another company's subscription is not found: 404, as on the route, not an error that tells it exists.
        $this->assertRefusedWith(404, fn () => NetworkAccountService::create($foreign, $payload($this->nas, 'hijack-one')));
        // Another company's router cannot be chosen for an own subscription.
        $this->assertRefusedWith(422, fn () => NetworkAccountService::create($mine, $payload($otherNas, 'hijack-two')));
        $this->assertRefusedWith(422, fn () => NetworkAccountService::update($ownAccount, ['nas_device_id' => $otherNas->id]));
        // Another company's account cannot be edited even when a caller hands it over.
        try {
            NetworkAccountService::update(NetworkAccount::withoutCompany()->findOrFail($foreignAccount->id), ['username' => 'hijack-three', 'password' => 'fake-gap-secret-2']);
            $this->fail('an account of another company must not be updated');
        } catch (ModelNotFoundException) {
        }

        $this->assertEquals(['zed' => $other->id, 'bob' => $this->company->id], NetworkAccount::withoutCompany()->pluck('company_id', 'username')->all(), 'no account was created or moved');
        $this->assertSame($this->nas->id, $ownAccount->fresh()->nas_device_id);
        $this->assertSame(0, Activity::query()->where('log_name', 'network_account')->count(), 'a refused change leaves no audit entry');
        Queue::assertNothingPushed();
    }

    // --- mass isolir while the router is down ----------------------------------------------------------------------

    public function test_a_mass_isolir_while_the_router_is_down_calls_it_once_leaves_every_account_pending_and_recovers_when_it_answers(): void
    {
        Notification::fake();
        $manager = $this->userWithPermissions($this->company, 'network_asset.nas.manage');
        $this->company->update(['settings' => ['auto_suspend_enabled' => true, 'auto_suspend_overdue_days' => 7]]);
        $package = $this->packageWithRouterProfile($this->company, 'home-20m');

        foreach (range(1, self::SUBSCRIBERS) as $n) {
            $subscription = $this->subscriptionIn($this->company, 'active', null, $package);
            $this->invoiceFor($subscription, 10); // three days past the seven days of grace
            $account = $this->networkAccountFor($subscription, $this->nas, ['username' => sprintf('user%02d', $n)]);
            $this->applyAccount($account);
            $this->router->seedSession('pppoe', $account->username, '10.10.0.'.$n);
        }
        $this->assertCount(self::SUBSCRIBERS, $this->router->accounts());
        $this->assertSame(['synced'], NetworkAccount::query()->pluck('sync_status')->unique()->values()->all());
        $this->router->forgetRequests();
        $this->router->down('timeout'); // the worst kind: every call would cost the full ten seconds

        $this->assertSame(0, Artisan::call('billing:suspend-overdue', ['--company' => $this->company->id]));

        $this->assertSame(
            self::SUBSCRIBERS,
            ServiceSubscription::withoutCompany()->where('company_id', $this->company->id)->where('status', 'suspended')->where('suspension_source', 'overdue')->count(),
            'business first: every overdue subscription is suspended whatever the router does',
        );
        $this->assertCount(1, $this->router->requests(), 'only the first job asked the dead router; the other 49 saw the outage and skipped');
        $this->assertSame(['pending'], NetworkAccount::query()->pluck('sync_status')->unique()->values()->all());
        $this->assertSame(self::SUBSCRIBERS, NetworkAccount::query()->whereNotNull('last_error')->where('last_error', '!=', '')->count(), 'every account says why it waits');
        $this->assertSame(['home-20m'], array_values(array_unique(array_column($this->router->accounts(), 'profile'))), 'nothing was isolated on the router yet');
        $this->assertNotNull($this->nas->fresh()->unreachable_since);
        $this->assertSame(1, Activity::query()->where('log_name', 'nas_device')->where('description', 'unreachable')->count());
        Notification::assertSentToTimes($manager, NasDeviceUnreachableNotification::class, 1);

        $this->router->up();
        $this->travel(3)->minutes();

        $this->assertSame(0, Artisan::call('network:sync', ['--company' => $this->company->id]));

        $this->assertSame(['synced'], NetworkAccount::query()->pluck('sync_status')->unique()->values()->all(), 'the sync found the router again and re-queued every pending account');
        $this->assertSame(['isolir'], NetworkAccount::query()->pluck('provisioned_profile')->unique()->values()->all());
        $this->assertSame(['isolir'], array_values(array_unique(array_column($this->router->accounts(), 'profile'))));
        $this->assertSame([], $this->router->sessions(), 'the profile changed, so every running session was dropped');
        $this->assertCount(2 * self::SUBSCRIBERS, $this->router->writes(), 'one profile change and one dropped session per account');
        $this->assertNull($this->nas->fresh()->unreachable_since);
        $this->assertSame(1, Activity::query()->where('log_name', 'nas_device')->where('description', 'recovered')->count());
        Notification::assertSentToTimes($manager, NasDeviceUnreachableNotification::class, 1);
    }

    // --- observer path of SubscriptionController::update ----------------------------------------------------------

    public function test_a_package_without_a_router_profile_name_chosen_on_the_update_route_fails_only_the_account_and_a_good_package_heals_it(): void
    {
        $subscription = $this->subscriptionIn($this->company, 'active', null, $this->packageWithRouterProfile($this->company, 'home-20m'));
        $account = $this->networkAccountFor($subscription, $this->nas, ['username' => 'carol']);
        $this->applyAccount($account);
        $this->router->seedSession('pppoe', 'carol', '10.10.0.9');
        $this->router->forgetRequests();
        $nameless = $this->packageWithRouterProfile($this->company, null);

        $this->put(route('admin.subscriptions.update', $subscription), ['service_package_id' => $nameless->id])->assertRedirect();

        $this->assertSame($nameless->id, $subscription->fresh()->service_package_id, 'business first: the package change itself succeeds');
        $account = $account->fresh();
        $this->assertSame('failed', $account->sync_status);
        $this->assertStringContainsString('no router profile name', (string) $account->last_error);
        $this->assertSame([], $this->router->requests(), 'the router is not asked about a profile that cannot be named');
        $this->assertSame('home-20m', $this->router->account('pppoe', 'carol')['profile']);
        $this->assertCount(1, $this->router->sessions('pppoe', 'carol'));
        $this->assertSame('rejected', Activity::query()->where('description', 'provision_failed')->sole()->properties['error_kind']);

        $good = $this->packageWithRouterProfile($this->company, 'home-50m');
        $this->put(route('admin.subscriptions.update', $subscription), ['service_package_id' => $good->id])->assertRedirect();

        $account = $account->fresh();
        $this->assertSame('synced', $account->sync_status);
        $this->assertNull($account->last_error);
        $this->assertSame('home-50m', $this->router->account('pppoe', 'carol')['profile']);
        $this->assertSame([], $this->router->sessions('pppoe', 'carol'));
    }

    // --- secrets: nowhere but the router and the one-time flash ----------------------------------------------------

    /** @return array<string, array{0: string}> */
    public static function routerAnswers(): array
    {
        return [
            'the router accepts everything' => ['up'],
            'the call times out' => ['timeout'],
            'the certificate is refused' => ['tls'],
            'the API credentials are refused' => ['auth'],
            'the router rejects the request' => ['rejected'],
            'the router answers 500 with a page that echoes a secret' => ['server'],
        ];
    }

    #[DataProvider('routerAnswers')]
    public function test_neither_password_is_left_in_any_store_page_export_or_log_whatever_the_router_answers(string $answer): void
    {
        $subscriberPassword = 'fake-gap-subscriber-pw-1';
        $changedPassword = 'fake-gap-subscriber-pw-2';
        $apiPassword = 'fake-gap-router-api-pw-9';
        // The production image has no php.ini: stack traces keep their arguments, which is what failed_jobs
        // and the exception log lines are made of.
        $this->phpTraceSettings = [ini_get('zend.exception_ignore_args'), ini_get('zend.exception_string_param_max_len')];
        ini_set('zend.exception_ignore_args', '0');
        ini_set('zend.exception_string_param_max_len', '15');
        config(['queue.default' => 'database']);
        $logs = new TestHandler();
        Log::channel(config('logging.default'))->getLogger()->pushHandler($logs);

        $this->nas->forceFill(['api_password' => $apiPassword])->save();
        if ($answer !== 'up') {
            $this->router->down($answer);
        }
        $subscription = $this->subscriptionIn($this->company, 'active', null, $this->packageWithRouterProfile($this->company, 'home-20m'));
        $activitySince = (int) Activity::query()->max('id');

        $this->post(route('admin.subscriptions.network-account.store', $subscription), ['nas_device_id' => $this->nas->id, 'service_type' => 'pppoe', 'username' => 'alice.gap', 'password' => $subscriberPassword])->assertRedirect();
        $this->assertSame(1, DB::table('jobs')->count(), 'the apply job waits on the real queue');
        $jobsBeforeTheWorker = DB::table('jobs')->get();
        $this->runWorkerUntilIdle();

        $this->post(route('admin.subscriptions.network-account.reveal', $subscription))->assertRedirect();
        $this->put(route('admin.subscriptions.network-account.update', $subscription), ['password' => $changedPassword])->assertRedirect();
        $this->post(route('admin.subscriptions.network-account.resync', $subscription))->assertRedirect();
        ServiceSubscription::withoutCompany()->findOrFail($subscription->id)->update(['status' => 'suspended']);
        $this->runWorkerUntilIdle();

        $account = NetworkAccount::query()->where('subscription_id', $subscription->id)->sole();
        // A dead router leaves the account pending while its outage is fresh (jobs skip the call for two minutes);
        // every other kind of failure ends in failed once the retries are used up.
        $this->assertContains($account->sync_status, ['up' => ['synced'], 'timeout' => ['pending', 'failed']][$answer] ?? ['failed'], "the account ends `{$account->sync_status}` after `$answer`");
        $this->assertNotEmpty($this->router->requests(), 'the router was really asked');
        $answer === 'up'
            ? $this->assertSame(0, DB::table('failed_jobs')->count())
            : $this->assertGreaterThan(0, DB::table('failed_jobs')->count(), 'the failure path of the real queue ran');
        $pages = [
            $this->get(route('admin.subscriptions.network-account.show', $subscription)),
            $this->get(route('admin.subscriptions.show', $subscription)),
            $this->get(route('admin.nas-devices.index')),
            $this->get(route('admin.nas-devices.show', $this->nas)),
            $this->get(route('admin.nas-devices.edit', $this->nas)),
            $this->get(route('admin.nas-devices.create')),
            $this->get(route('admin.network-assets.show', $this->nas->networkAsset)),
            $this->get(route('admin.customers.show', $subscription->customer_id)),
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
            foreach ([$subscriberPassword, $changedPassword, $apiPassword, 'fake-leaked-secret-123'] as $secret) {
                $at = strpos($content, $secret);

                $this->assertFalse($at !== false, "$store holds `$secret`: ...".($at === false ? '' : substr($content, max(0, $at - 90), 200)).'...');
            }
        }

        // On the wire the subscriber password travels only in a create or update body, and the router
        // password only as the Basic credential: never in an address, never in a read.
        foreach ($this->router->requests() as $request) {
            $this->assertStringNotContainsString($subscriberPassword, json_encode($request['query']));
            $this->assertStringNotContainsString($changedPassword, json_encode($request['query']));
            $this->assertStringNotContainsString($apiPassword, json_encode($request));
            if (! in_array($request['method'], ['PUT', 'PATCH'], true)) {
                $this->assertNull($request['body'], 'reads and deletes carry no body');
            }
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

            $this->travel(16)->minutes(); // past the longest backoff of the apply job (15 minutes) and the two-minute skip window
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
