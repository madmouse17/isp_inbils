<?php

namespace Tests\Feature\NetworkAsset;

use App\Models\Core\Company;
use App\Models\Core\Location;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Ipam\Services\IpAssignmentService;
use Modules\Ipam\Services\IpPrefixService;
use Modules\NetworkAsset\Jobs\ApplyNetworkAccountJob;
use Modules\NetworkAsset\Models\NasDevice;
use Modules\NetworkAsset\Models\NetworkAccount;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;
use Tests\Traits\BuildsNetworkFixtures;
use Tests\Traits\BuildsSubscriptionBilling;
use Tests\Traits\CreatesCompanyUser;
use Tests\Traits\InteractsWithNasCoa;
use Tests\Traits\InteractsWithRadius;

class NetworkAccountHttpTest extends TestCase
{
    use BuildsNetworkFixtures;
    use BuildsSubscriptionBilling;
    use CreatesCompanyUser;
    use InteractsWithNasCoa;
    use InteractsWithRadius;
    use RefreshDatabase;

    private User $admin;

    private Company $company;

    private string $realm;

    private NasDevice $nas;

    private string $nasIp;

    private ServiceSubscription $subscription;

    private Location $pop;

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
        $this->pop = Location::factory()->create(['company_id' => $this->company->id, 'name' => 'POP Dago']);
        $this->subscription = $this->subscriptionIn($this->company, 'active', null, $this->packageWithRadiusProfile($this->company, 'home-20m'));
        $this->subscription->forceFill(['serving_pop_id' => $this->pop->id])->save();
        $this->actingAs($this->admin);
    }

    private function show(?ServiceSubscription $subscription = null): TestResponse
    {
        return $this->get(route('admin.subscriptions.network-account.show', $subscription ?? $this->subscription));
    }

    /** @param array<string, mixed> $overrides */
    private function storePayload(array $overrides = []): array
    {
        return $overrides + ['service_type' => 'pppoe', 'username' => 'alice.home'];
    }

    /** @param array<string, mixed> $attributes */
    private function account(?ServiceSubscription $subscription = null, array $attributes = []): NetworkAccount
    {
        return $this->networkAccountFor($subscription ?? $this->subscription, $attributes);
    }

    private function packaged(string $status = 'active', ?string $profile = 'home-20m'): ServiceSubscription
    {
        return $this->subscriptionIn($this->company, $status, null, $this->packageWithRadiusProfile($this->company, $profile));
    }

    /** @return list<string> */
    private function accountKeys(): array
    {
        return ['id', 'service_type', 'username', 'sync_status', 'last_error', 'provisioned_group', 'provisioned_at', 'disconnect_pending', 'pending_disconnect_at', 'last_disconnect_at'];
    }

    public function test_the_page_without_an_account_has_the_contract_shape(): void
    {
        $response = $this->show();

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('account', null)
            ->where('session', null)
            ->where('usage', null)
            ->where('staticAddress', null)
            ->where('realm', $this->realm)
            ->where('desiredState', 'enabled')
            ->where('isolatedBecause', [])
            ->where('radiusEnabled', true)
            ->where('can', ['manage' => true, 'reveal' => true])
            ->where('subscription.id', $this->subscription->public_id)
            ->where('subscription.code', $this->subscription->code)
            ->where('subscription.status', 'active')
            ->where('subscription.blocked_at', null)
            ->where('subscription.customer', ['id' => $this->subscription->customer->public_id, 'name' => $this->subscription->customer->name, 'code' => $this->subscription->customer->code])
            ->where('subscription.speed_profile.radius_profile_name', 'home-20m')
            ->where('subscription.serving_pop', ['id' => $this->pop->public_id, 'name' => 'POP Dago'])
            ->missing('nasOptions')
            ->missing('installEnabled'));
        $this->assertSame(['id', 'code', 'status', 'blocked_at', 'customer', 'speed_profile', 'serving_pop'], array_keys($response->inertiaProps()['subscription']));
    }

    public function test_show_names_expected_static_address_and_prefix(): void
    {
        $pool = IpPrefixService::create(['cidr' => '10.99.0.0/26', 'usage' => 'customer_static']);
        $v6 = IpPrefixService::create(['cidr' => '2001:db8:62::/48', 'usage' => 'customer_static']);
        IpAssignmentService::assignToSubscription($this->subscription, $v6, '2001:db8:62::1');
        IpAssignmentService::assignToSubscription($this->subscription, $pool, '10.99.0.1');

        $this->show()->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('staticAddress', ['address' => '10.99.0.1', 'prefix' => '10.99.0.0/26']));
    }

    public function test_show_without_assignment_has_no_static_address(): void
    {
        $this->show()->assertOk()->assertInertia(fn (Assert $page) => $page->where('staticAddress', null));

        // Another subscription's address and an IPv6-only assignment are not the one RADIUS hands out.
        $pool = IpPrefixService::create(['cidr' => '10.99.1.0/26', 'usage' => 'customer_static']);
        IpAssignmentService::assignToSubscription($this->packaged(), $pool, '10.99.1.1');
        $v6 = IpPrefixService::create(['cidr' => '2001:db8:63::/48', 'usage' => 'customer_static']);
        IpAssignmentService::assignToSubscription($this->subscription, $v6, '2001:db8:63::1');

        $this->show()->assertInertia(fn (Assert $page) => $page->where('staticAddress', null));
    }

    public function test_show_names_the_pool_only_to_a_holder_of_ipam_view(): void
    {
        $pool = IpPrefixService::create(['cidr' => '10.99.2.0/26', 'usage' => 'customer_static']);
        IpAssignmentService::assignToSubscription($this->subscription, $pool, '10.99.2.1');
        $viewer = $this->userWithPermissions($this->company, 'network_asset.account.view');
        $withIpam = $this->userWithPermissions($this->company, 'network_asset.account.view', 'ipam.view');

        $this->actingAs($viewer);
        $this->show()->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('staticAddress', ['address' => '10.99.2.1', 'prefix' => null]));

        $this->actingAs($withIpam);
        $this->show()->assertInertia(fn (Assert $page) => $page
            ->where('staticAddress', ['address' => '10.99.2.1', 'prefix' => '10.99.2.0/26']));
    }

    public function test_the_desired_state_and_the_reasons_follow_the_subscription(): void
    {
        $blocked = $this->packaged();
        $blocked->forceFill(['blocked_at' => now()])->save();
        $both = $this->packaged('suspended');
        $both->forceFill(['blocked_at' => now()])->save();
        $cases = [
            [$this->packaged('pending'), 'enabled', []],
            [$this->packaged('active'), 'enabled', []],
            [$this->packaged('suspended'), 'isolated', ['suspended']],
            [$blocked, 'isolated', ['blocked']],
            [$both, 'isolated', ['suspended', 'blocked']],
            [$this->packaged('terminated'), 'absent', []],
        ];

        foreach ($cases as [$subscription, $state, $because]) {
            $this->show($subscription)->assertInertia(fn (Assert $page) => $page->where('desiredState', $state)->where('isolatedBecause', $because));
        }
        $this->show($blocked)->assertInertia(fn (Assert $page) => $page->where('subscription.blocked_at', fn ($at) => $at !== null));
    }

    public function test_the_page_with_an_account_has_the_contract_shape_and_no_secret(): void
    {
        $this->account(attributes: ['password' => 'fake-subscriber-plain-1']);

        $response = $this->show();

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('account.username', "alice@{$this->realm}")
            ->where('account.sync_status', 'pending')
            ->where('account.provisioned_group', null)
            ->where('account.disconnect_pending', false)
            ->where('account.pending_disconnect_at', null));
        $this->assertSame($this->accountKeys(), array_keys($response->inertiaProps()['account']));
        $encoded = json_encode($response->inertiaProps());
        $this->assertStringNotContainsString('fake-subscriber-plain-1', $encoded);
        $this->assertStringNotContainsString('fake-radius-shared-secret', $encoded);
    }

    public function test_the_page_says_since_when_a_disconnect_is_owed(): void
    {
        $since = now()->subMinutes(20);
        $this->account()->forceFill(['pending_disconnect_at' => $since])->save();

        $this->show()->assertInertia(fn (Assert $page) => $page
            ->where('account.disconnect_pending', true)
            ->where('account.pending_disconnect_at', $since->toIso8601String()));
    }

    public function test_the_session_and_this_months_usage_are_read_live_from_radius(): void
    {
        $this->account();
        $username = "alice@{$this->realm}";
        $this->seedRadiusSession(['username' => $username, 'nasipaddress' => $this->nasIp, 'framedipaddress' => '10.10.0.5', 'acctinputoctets' => 1000, 'acctoutputoctets' => 4000]);
        $this->seedRadiusSession(['username' => $username, 'nasipaddress' => $this->nasIp, 'acctstarttime' => now()->subMonths(2)->format('Y-m-d H:i:s'), 'acctstoptime' => now()->subMonths(2)->addHour()->format('Y-m-d H:i:s'), 'acctinputoctets' => 7, 'acctoutputoctets' => 9]);
        $this->seedRadiusSession(['username' => "someone-else@{$this->realm}", 'acctinputoctets' => 5000, 'acctoutputoctets' => 5000]);

        $response = $this->show();

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('session.framed_ip', '10.10.0.5')
            ->where('session.nas_ip', $this->nasIp)
            ->where('session.nas_label', $this->nas->networkAsset->code.' - '.$this->nas->networkAsset->name)
            ->where('usage', ['month' => now()->format('Y-m'), 'input_octets' => 1000, 'output_octets' => 4000]));
        $this->assertSame(['session_id', 'nas_ip', 'nas_label', 'framed_ip', 'started_at', 'last_seen_at', 'stale'], array_keys($response->inertiaProps()['session']));
    }

    public function test_the_session_has_no_label_when_no_nas_of_the_company_owns_the_address(): void
    {
        $this->account();
        $this->seedRadiusSession(['username' => "alice@{$this->realm}", 'nasipaddress' => '203.0.113.99']);

        $this->show()->assertInertia(fn (Assert $page) => $page->where('session.nas_ip', '203.0.113.99')->where('session.nas_label', null));
    }

    public function test_an_offline_subscriber_has_no_session_but_does_have_usage(): void
    {
        $this->account();
        $this->seedRadiusSession(['username' => "alice@{$this->realm}", 'acctstoptime' => now()->format('Y-m-d H:i:s'), 'acctinputoctets' => 10, 'acctoutputoctets' => 20]);

        $this->show()->assertInertia(fn (Assert $page) => $page->where('session', null)->where('usage', ['month' => now()->format('Y-m'), 'input_octets' => 10, 'output_octets' => 20]));
    }

    public function test_session_and_usage_are_null_while_the_switch_is_off_or_radius_is_down(): void
    {
        $this->account();
        $this->seedRadiusSession(['username' => "alice@{$this->realm}"]);

        Schema::connection('radius')->drop('radacct');
        $this->show()->assertOk()->assertInertia(fn (Assert $page) => $page->where('session', null)->where('usage', null)->where('radiusEnabled', true)->where('account.username', "alice@{$this->realm}"));

        $this->disableRadius();
        $this->show()->assertOk()->assertInertia(fn (Assert $page) => $page->where('session', null)->where('usage', null)->where('radiusEnabled', false));
    }

    public function test_store_appends_the_realm_generates_a_secret_flashes_it_once_and_writes_radius(): void
    {
        $this->post(route('admin.subscriptions.network-account.store', $this->subscription), $this->storePayload())
            ->assertRedirect(route('admin.subscriptions.network-account.show', $this->subscription));

        $account = NetworkAccount::query()->firstOrFail();
        $this->assertSame("alice.home@{$this->realm}", $account->username);
        $first = $this->show();
        $secret = $first->inertiaPage()['flash']['network_account']['secret'];
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{12}$/', $secret);
        $this->assertSame($secret, $account->password);
        $this->assertStringNotContainsString($secret, (string) DB::table('network_accounts')->value('password'));
        $this->assertStringNotContainsString($secret, json_encode($first->inertiaProps()));
        $this->assertSame($secret, $this->radiusRows('radcheck', ['username' => "alice.home@{$this->realm}"])[0]['value']);
        $this->assertSame("{$this->realm}/home-20m", $this->radiusRows('radusergroup', ['username' => "alice.home@{$this->realm}"])[0]['groupname']);
        $this->assertSame('synced', $account->fresh()->sync_status);

        $this->show()->assertInertia(fn (Assert $page) => $page->missingFlash('network_account'));
        $audit = Activity::query()->where('log_name', 'network_account')->where('description', 'created')->firstOrFail();
        $this->assertTrue($audit->properties['secret_generated']);
        $this->assertStringNotContainsString($secret, json_encode($audit->properties));
    }

    public function test_store_with_a_typed_secret_flashes_nothing(): void
    {
        $this->post(route('admin.subscriptions.network-account.store', $this->subscription), $this->storePayload(['password' => 'fake-typed-pw1']))->assertRedirect();

        $this->show()->assertInertia(fn (Assert $page) => $page->missingFlash('network_account'));
        $this->assertSame('fake-typed-pw1', NetworkAccount::query()->firstOrFail()->password);
        $this->assertSame('fake-typed-pw1', $this->radiusRows('radcheck')[0]['value']);
    }

    public function test_store_queues_the_apply_job_after_commit(): void
    {
        Queue::fake();

        $this->post(route('admin.subscriptions.network-account.store', $this->subscription), $this->storePayload())->assertRedirect();

        Queue::assertPushed(ApplyNetworkAccountJob::class, 1);
        Queue::assertPushed(ApplyNetworkAccountJob::class, fn (ApplyNetworkAccountJob $job): bool => $job->accountId === NetworkAccount::query()->firstOrFail()->id && $job->companyId === $this->company->id);
        $this->assertSame([], $this->radiusRows('radcheck'));
    }

    public function test_store_with_the_switch_off_saves_the_account_pending_and_writes_nothing(): void
    {
        $this->disableRadius();

        $this->post(route('admin.subscriptions.network-account.store', $this->subscription), $this->storePayload())->assertRedirect();

        $this->assertSame('pending', NetworkAccount::query()->firstOrFail()->sync_status);
        $this->assertSame([], $this->disconnectsSent());
    }

    public function test_store_validation(): void
    {
        $this->account($this->packaged(), ['username' => "taken@{$this->realm}"]);
        $noProfile = $this->packaged('active', null);
        $terminated = $this->packaged('terminated');
        $withAccount = $this->packaged();
        $this->account($withAccount, ['username' => "already-linked@{$this->realm}"]);
        $url = fn (ServiceSubscription $subscription) => route('admin.subscriptions.network-account.store', $subscription);

        $cases = [
            'no speed profile name' => [$noProfile, $this->storePayload(), 'subscription'],
            'terminated subscription' => [$terminated, $this->storePayload(), 'subscription'],
            'subscription already has an account' => [$withAccount, $this->storePayload(), 'subscription'],
            'duplicate username' => [$this->subscription, $this->storePayload(['username' => 'taken']), 'username'],
            'username too short' => [$this->subscription, $this->storePayload(['username' => 'ab']), 'username'],
            'username with a space' => [$this->subscription, $this->storePayload(['username' => 'bad name']), 'username'],
            'username with upper case' => [$this->subscription, $this->storePayload(['username' => 'Alice']), 'username'],
            'username with a realm' => [$this->subscription, $this->storePayload(['username' => 'alice@other']), 'username'],
            'username too long' => [$this->subscription, $this->storePayload(['username' => str_repeat('a', 41)]), 'username'],
            'unknown service type' => [$this->subscription, $this->storePayload(['service_type' => 'l2tp']), 'service_type'],
            'secret too short' => [$this->subscription, $this->storePayload(['password' => 'abc']), 'password'],
            'secret too long' => [$this->subscription, $this->storePayload(['password' => str_repeat('p', 65)]), 'password'],
            'secret with a space' => [$this->subscription, $this->storePayload(['password' => 'bad pass word']), 'password'],
        ];

        foreach ($cases as $name => [$subscription, $payload, $field]) {
            $response = $this->postJson($url($subscription), $payload);
            $this->assertSame(422, $response->getStatusCode(), $name);
            $response->assertJsonValidationErrors($field);
        }

        $this->assertSame(2, NetworkAccount::query()->count());
    }

    public function test_store_refuses_a_company_whose_code_cannot_be_a_realm(): void
    {
        $this->company->update(['code' => 'ACME_BAD CODE']);

        $this->postJson(route('admin.subscriptions.network-account.store', $this->subscription), $this->storePayload())->assertUnprocessable()->assertJsonValidationErrors('realm');

        $this->assertSame(0, NetworkAccount::query()->count());
        $this->show()->assertInertia(fn (Assert $page) => $page->where('realm', null));
    }

    public function test_a_username_taken_in_another_company_gets_the_same_neutral_message(): void
    {
        $this->account($this->packaged(), ['username' => "alice.home@{$this->realm}"]);
        $other = Company::factory()->create(['is_active' => true]);
        $this->networkAccountFor($this->subscriptionIn($other, 'active', null, $this->packageWithRadiusProfile($other)), ['username' => 'bob@'.$this->realmOf($other)]);

        $own = $this->postJson(route('admin.subscriptions.network-account.store', $this->subscription), $this->storePayload());
        $own->assertJsonValidationErrors('username');
        $this->assertSame('Nama pengguna ini tidak tersedia.', $own->json('errors.username.0'));
    }

    public function test_the_same_local_part_in_two_companies_never_collides_because_the_realms_differ(): void
    {
        $other = Company::factory()->create(['is_active' => true]);
        $this->networkAccountFor($this->subscriptionIn($other, 'active', null, $this->packageWithRadiusProfile($other)), ['username' => 'alice.home@'.$this->realmOf($other)]);

        $this->post(route('admin.subscriptions.network-account.store', $this->subscription), $this->storePayload())->assertRedirect();

        $this->assertSame(2, NetworkAccount::withoutCompany()->where('username', 'like', 'alice.home@%')->count());
    }

    public function test_update_keeps_the_secret_when_blank_realm_stays_appended_and_one_apply_is_queued(): void
    {
        $account = $this->account(attributes: ['password' => 'fake-keep-me']);
        Queue::fake();

        $this->put(route('admin.subscriptions.network-account.update', $this->subscription), ['username' => 'alice.new', 'password' => '', 'service_type' => 'pppoe'])->assertRedirect();

        $account = $account->fresh();
        $this->assertSame("alice.new@{$this->realm}", $account->username);
        $this->assertSame('fake-keep-me', $account->password);
        Queue::assertPushed(ApplyNetworkAccountJob::class, 1);
        $audit = Activity::query()->where('log_name', 'network_account')->where('description', 'updated')->firstOrFail();
        $this->assertSame(['username'], $audit->properties['changed']);
        $this->assertSame(0, Activity::query()->where('description', 'secret_changed')->count());
    }

    public function test_update_with_the_same_local_part_changes_nothing_and_queues_nothing(): void
    {
        $this->account();
        Queue::fake();

        $this->put(route('admin.subscriptions.network-account.update', $this->subscription), ['username' => 'alice', 'service_type' => 'pppoe'])->assertRedirect();

        Queue::assertNothingPushed();
        $this->assertSame(0, Activity::query()->where('log_name', 'network_account')->count());
    }

    public function test_update_with_a_new_secret_audits_secret_changed_without_it_and_keeps_the_session(): void
    {
        $account = $this->account();
        $this->applyAccount($account);
        $this->seedRadiusSession(['username' => "alice@{$this->realm}"]);

        $this->put(route('admin.subscriptions.network-account.update', $this->subscription), ['password' => 'fake-new-secret-9'])->assertRedirect();

        $this->assertSame('fake-new-secret-9', $this->radiusRows('radcheck', ['username' => "alice@{$this->realm}"])[0]['value']);
        $this->assertSame([], $this->disconnectsSent());
        $audit = Activity::query()->where('description', 'secret_changed')->firstOrFail();
        $this->assertStringNotContainsString('fake-new-secret-9', json_encode($audit->properties));
        $this->assertSame(0, Activity::query()->where('log_name', 'network_account')->where('description', 'updated')->count());
    }

    public function test_update_validation_and_a_missing_account(): void
    {
        $this->account();
        $this->account($this->packaged(), ['username' => "taken@{$this->realm}"]);

        $this->putJson(route('admin.subscriptions.network-account.update', $this->subscription), ['username' => 'taken'])->assertUnprocessable()->assertJsonValidationErrors('username');
        $this->putJson(route('admin.subscriptions.network-account.update', $this->subscription), ['username' => 'Bad Name'])->assertUnprocessable()->assertJsonValidationErrors('username');
        $this->putJson(route('admin.subscriptions.network-account.update', $this->subscription), ['username' => 'alice'])->assertRedirect();
        $this->put(route('admin.subscriptions.network-account.update', $this->packaged()), ['username' => 'nobody'])->assertNotFound();
    }

    public function test_reveal_flashes_the_secret_once_and_audits_it_without_the_value(): void
    {
        $this->account(attributes: ['password' => 'fake-reveal-me-7']);

        $this->post(route('admin.subscriptions.network-account.reveal', $this->subscription))
            ->assertRedirect(route('admin.subscriptions.network-account.show', $this->subscription));

        $page = $this->show();
        $this->assertSame('fake-reveal-me-7', $page->inertiaPage()['flash']['network_account']['secret']);
        $this->assertStringNotContainsString('fake-reveal-me-7', json_encode($page->inertiaProps()));
        $this->show()->assertInertia(fn (Assert $inertia) => $inertia->missingFlash('network_account'));
        $audit = Activity::query()->where('log_name', 'network_account')->where('description', 'secret_revealed')->firstOrFail();
        $this->assertSame($this->admin->id, $audit->causer_id);
        $this->assertStringNotContainsString('fake-reveal-me-7', json_encode($audit->properties));
        $this->assertSame("alice@{$this->realm}", $audit->properties['username']);
    }

    public function test_reveal_is_throttled_to_ten_a_minute(): void
    {
        $this->account();

        foreach (range(1, 10) as $_) {
            $this->post(route('admin.subscriptions.network-account.reveal', $this->subscription))->assertRedirect();
        }

        $this->post(route('admin.subscriptions.network-account.reveal', $this->subscription))->assertStatus(429);
        $this->assertSame(10, Activity::query()->where('description', 'secret_revealed')->count());
    }

    public function test_reveal_without_an_account_is_a_404_and_audits_nothing(): void
    {
        $this->post(route('admin.subscriptions.network-account.reveal', $this->subscription))->assertNotFound();

        $this->assertSame(0, Activity::query()->where('description', 'secret_revealed')->count());
    }

    public function test_resync_owes_a_disconnect_queues_one_apply_job_and_is_throttled(): void
    {
        $account = $this->account();
        Queue::fake();

        $this->post(route('admin.subscriptions.network-account.resync', $this->subscription))->assertRedirect()->assertSessionHas('success', 'Sinkron ulang dimulai. Sesi pelanggan diputus setelah router menjawab.');

        $this->assertNotNull($account->fresh()->pending_disconnect_at);
        Queue::assertPushed(ApplyNetworkAccountJob::class, 1);
        Queue::assertPushed(ApplyNetworkAccountJob::class, fn (ApplyNetworkAccountJob $job): bool => $job->accountId === $account->id);

        foreach (range(1, 5) as $_) {
            $this->post(route('admin.subscriptions.network-account.resync', $this->subscription))->assertRedirect();
        }
        $this->post(route('admin.subscriptions.network-account.resync', $this->subscription))->assertStatus(429);
    }

    public function test_resync_rewrites_the_user_and_drops_the_open_session(): void
    {
        $account = $this->account();
        $this->applyAccount($account);
        $this->seedRadiusSession(['username' => "alice@{$this->realm}", 'nasipaddress' => $this->nasIp]);

        $this->post(route('admin.subscriptions.network-account.resync', $this->subscription))->assertRedirect();

        $this->assertCount(1, $this->disconnectsSent());
        $this->assertNull($account->fresh()->pending_disconnect_at);
        $this->assertSame('synced', $account->fresh()->sync_status);
    }

    public function test_every_route_needs_its_permission(): void
    {
        $this->account();
        $nobody = $this->userWithPermissions($this->company);
        $viewer = $this->userWithPermissions($this->company, 'network_asset.account.view');
        $manager = $this->userWithPermissions($this->company, 'network_asset.account.view', 'network_asset.account.manage');
        $subscription = $this->subscription;
        $calls = [
            'show' => fn () => $this->get(route('admin.subscriptions.network-account.show', $subscription)),
            'store' => fn () => $this->postJson(route('admin.subscriptions.network-account.store', $subscription), $this->storePayload()),
            'update' => fn () => $this->putJson(route('admin.subscriptions.network-account.update', $subscription), ['username' => 'someone']),
            'resync' => fn () => $this->postJson(route('admin.subscriptions.network-account.resync', $subscription)),
            'reveal' => fn () => $this->postJson(route('admin.subscriptions.network-account.reveal', $subscription)),
        ];

        foreach ($calls as $name => $call) {
            $this->actingAs($nobody);
            $this->assertSame(403, $call()->getStatusCode(), "$name without any permission");
        }

        $this->actingAs($viewer);
        $this->assertSame(200, $calls['show']()->getStatusCode());
        foreach (['store', 'update', 'resync', 'reveal'] as $name) {
            $this->assertSame(403, $calls[$name]()->getStatusCode(), "$name with view only");
        }

        $this->actingAs($manager);
        $this->assertSame(403, $calls['reveal']()->getStatusCode(), 'reveal with manage but not reveal');
        $this->get(route('admin.subscriptions.network-account.show', $subscription))
            ->assertInertia(fn (Assert $page) => $page->where('can', ['manage' => true, 'reveal' => false]));
        $this->assertSame(0, Activity::query()->where('description', 'secret_revealed')->count());
    }

    public function test_another_companys_subscription_is_a_404_on_every_route(): void
    {
        $other = Company::factory()->create(['is_active' => true]);
        $foreign = $this->subscriptionIn($other, 'active', null, $this->packageWithRadiusProfile($other));
        $this->networkAccountFor($foreign);

        $this->get(route('admin.subscriptions.network-account.show', $foreign))->assertNotFound();
        $this->post(route('admin.subscriptions.network-account.store', $foreign), $this->storePayload())->assertNotFound();
        $this->put(route('admin.subscriptions.network-account.update', $foreign), ['username' => 'hijack'])->assertNotFound();
        $this->post(route('admin.subscriptions.network-account.reveal', $foreign))->assertNotFound();
        $this->post(route('admin.subscriptions.network-account.resync', $foreign))->assertNotFound();
        $this->assertSame('alice@'.$this->realmOf($other), NetworkAccount::withoutCompany()->where('company_id', $other->id)->value('username'));
        $this->assertSame(0, Activity::query()->where('description', 'secret_revealed')->count());
    }

    public function test_the_secret_is_never_in_any_prop_or_audit_after_a_full_cycle(): void
    {
        $this->post(route('admin.subscriptions.network-account.store', $this->subscription), $this->storePayload())->assertRedirect();
        $secret = NetworkAccount::query()->firstOrFail()->password;
        $this->post(route('admin.subscriptions.network-account.reveal', $this->subscription))->assertRedirect();
        $this->put(route('admin.subscriptions.network-account.update', $this->subscription), ['username' => 'alice.two', 'password' => 'fake-cycle-pass-3'])->assertRedirect();
        $this->post(route('admin.subscriptions.network-account.resync', $this->subscription))->assertRedirect();

        foreach ([$this->show(), $this->get(route('admin.nas-devices.show', $this->nas)), $this->get(route('admin.nas-devices.index'))] as $response) {
            $encoded = json_encode($response->inertiaProps());
            $this->assertStringNotContainsString($secret, $encoded);
            $this->assertStringNotContainsString('fake-cycle-pass-3', $encoded);
            $this->assertStringNotContainsString('fake-radius-shared-secret', $encoded);
        }
        foreach (Activity::query()->get() as $entry) {
            $this->assertStringNotContainsString($secret, json_encode($entry->properties));
            $this->assertStringNotContainsString('fake-cycle-pass-3', json_encode($entry->properties));
            $this->assertStringNotContainsString('fake-radius-shared-secret', json_encode($entry->properties));
        }
    }
}
