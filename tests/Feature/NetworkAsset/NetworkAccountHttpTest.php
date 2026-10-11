<?php

namespace Tests\Feature\NetworkAsset;

use App\Models\Core\Company;
use App\Models\Core\Location;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\NetworkAsset\Jobs\ApplyNetworkAccountJob;
use Modules\NetworkAsset\Models\NasDevice;
use Modules\NetworkAsset\Models\NetworkAccount;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\FakeRouterOs;
use Tests\TestCase;
use Tests\Traits\BuildsNetworkFixtures;
use Tests\Traits\BuildsSubscriptionBilling;
use Tests\Traits\CreatesCompanyUser;
use Tests\Traits\InteractsWithRouterOs;

class NetworkAccountHttpTest extends TestCase
{
    use BuildsNetworkFixtures;
    use BuildsSubscriptionBilling;
    use CreatesCompanyUser;
    use InteractsWithRouterOs;
    use RefreshDatabase;

    private User $admin;

    private Company $company;

    private NasDevice $nas;

    private FakeRouterOs $router;

    private ServiceSubscription $subscription;

    private Location $pop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->admin = $this->createCompanyUser();
        $this->company = $this->admin->company;
        $this->grantNetworkPermissionsToAdminRole();
        $this->fakeRouterOs();
        [$this->nas, $this->router] = $this->nasRouter($this->company);
        $this->pop = Location::factory()->create(['company_id' => $this->company->id, 'name' => 'POP Dago']);
        $this->nas->networkAsset->update(['location_id' => $this->pop->id]);
        $this->subscription = $this->subscriptionIn($this->company, 'active', null, $this->packageWithRouterProfile($this->company, 'home-20m'));
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
        return $overrides + ['nas_device_id' => $this->nas->id, 'service_type' => 'pppoe', 'username' => 'alice.home'];
    }

    private function account(?ServiceSubscription $subscription = null, array $attributes = []): NetworkAccount
    {
        return $this->networkAccountFor($subscription ?? $this->subscription, $this->nas, $attributes);
    }

    public function test_the_page_without_an_account_offers_the_form_data_and_suggests_the_router_at_the_pop(): void
    {
        [$elsewhere] = $this->nasRouter($this->company);
        $response = $this->show();

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('account', null)
            ->where('desiredState', 'enabled')
            ->where('installEnabled', true)
            ->where('can', ['manage' => true, 'reveal' => true])
            ->where('subscription.id', $this->subscription->id)
            ->where('subscription.code', $this->subscription->code)
            ->where('subscription.status', 'active')
            ->where('subscription.customer', ['id' => $this->subscription->customer_id, 'name' => $this->subscription->customer->name])
            ->where('subscription.speed_profile.radius_profile_name', 'home-20m')
            ->where('subscription.serving_pop', ['id' => $this->pop->id, 'name' => 'POP Dago'])
            ->has('nasOptions', 2));
        $options = collect($response->inertiaProps()['nasOptions'])->keyBy('id');
        $this->assertTrue($options[$this->nas->id]['suggested']);
        $this->assertFalse($options[$elsewhere->id]['suggested']);
        $this->assertSame(['id', 'label', 'suggested'], array_keys($options[$this->nas->id]));
        $this->assertSame(['name', 'radius_profile_name'], array_keys($response->inertiaProps()['subscription']['speed_profile']));
        $this->assertSame(['id', 'code', 'status', 'customer', 'speed_profile', 'serving_pop'], array_keys($response->inertiaProps()['subscription']));
    }

    public function test_the_desired_state_follows_the_subscription_status(): void
    {
        foreach (['pending' => 'enabled', 'active' => 'enabled', 'suspended' => 'isolated', 'terminated' => 'absent'] as $status => $state) {
            $subscription = $this->subscriptionIn($this->company, $status, null, $this->packageWithRouterProfile($this->company));

            $this->show($subscription)->assertInertia(fn (Assert $page) => $page->where('desiredState', $state));
        }
    }

    public function test_the_page_with_an_account_has_the_contract_shape_and_no_password(): void
    {
        $this->account(attributes: ['password' => 'fake-subscriber-plain-1']);

        $response = $this->show();

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('account.username', 'alice')
            ->where('account.sync_status', 'pending')
            ->where('account.online', false)
            ->where('account.nas_device_id', $this->nas->id));
        $this->assertSame(['id', 'nas_device_id', 'nas_label', 'service_type', 'username', 'sync_status', 'last_error', 'provisioned_profile', 'provisioned_at', 'online', 'last_online_at', 'current_address'], array_keys($response->inertiaProps()['account']));
        $this->assertStringContainsString($this->nas->networkAsset->code, $response->inertiaProps()['account']['nas_label']);
        $encoded = json_encode($response->inertiaProps());
        $this->assertStringNotContainsString('fake-subscriber-plain-1', $encoded);
        $this->assertStringNotContainsString('fake-router-api-password', $encoded);
        $this->assertSame(['subscription', 'account', 'desiredState', 'nasOptions', 'installEnabled', 'can'], array_values(array_intersect(array_keys($response->inertiaProps()), ['subscription', 'account', 'desiredState', 'nasOptions', 'installEnabled', 'can'])));
    }

    public function test_online_means_seen_at_the_routers_last_check(): void
    {
        $account = $this->account();
        $this->nas->forceFill(['last_seen_at' => now()])->save();
        $account->forceFill(['last_online_at' => now()->subSeconds(30), 'current_address' => '10.10.0.5'])->save();
        $this->show()->assertInertia(fn (Assert $page) => $page->where('account.online', true)->where('account.current_address', '10.10.0.5'));

        $account->forceFill(['last_online_at' => now()->subMinutes(5)])->save();
        $this->show()->assertInertia(fn (Assert $page) => $page->where('account.online', false));
    }

    public function test_store_with_a_blank_password_generates_one_flashes_it_once_and_the_router_gets_it(): void
    {
        $this->post(route('admin.subscriptions.network-account.store', $this->subscription), $this->storePayload())
            ->assertRedirect(route('admin.subscriptions.network-account.show', $this->subscription));

        $account = NetworkAccount::query()->firstOrFail();
        $first = $this->show();
        $password = $first->inertiaPage()['flash']['network_account']['password'];
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{12}$/', $password);
        $this->assertSame($password, $account->password);
        $this->assertStringNotContainsString($password, (string) DB::table('network_accounts')->value('password'));
        $this->assertStringNotContainsString($password, json_encode($first->inertiaProps()));
        $this->assertSame($password, $this->router->account('pppoe', 'alice.home')['password']);
        $this->assertSame('home-20m', $this->router->account('pppoe', 'alice.home')['profile']);

        $this->show()->assertInertia(fn (Assert $page) => $page->missingFlash('network_account'));
        $audit = Activity::query()->where('log_name', 'network_account')->where('description', 'created')->firstOrFail();
        $this->assertTrue($audit->properties['password_generated']);
        $this->assertStringNotContainsString($password, json_encode($audit->properties));
    }

    public function test_store_with_a_typed_password_flashes_nothing(): void
    {
        $this->post(route('admin.subscriptions.network-account.store', $this->subscription), $this->storePayload(['password' => 'fake-typed-pw1']))->assertRedirect();

        $this->show()->assertInertia(fn (Assert $page) => $page->missingFlash('network_account'));
        $this->assertSame('fake-typed-pw1', NetworkAccount::query()->firstOrFail()->password);
        $this->assertSame('fake-typed-pw1', $this->router->account('pppoe', 'alice.home')['password']);
    }

    public function test_store_queues_the_apply_job_after_commit(): void
    {
        Queue::fake();

        $this->post(route('admin.subscriptions.network-account.store', $this->subscription), $this->storePayload())->assertRedirect();

        Queue::assertPushed(ApplyNetworkAccountJob::class, 1);
        Queue::assertPushed(ApplyNetworkAccountJob::class, fn (ApplyNetworkAccountJob $job): bool => $job->accountId === NetworkAccount::query()->firstOrFail()->id && $job->companyId === $this->company->id);
    }

    public function test_store_validation(): void
    {
        $other = Company::factory()->create(['is_active' => true]);
        [$foreignNas] = $this->nasRouter($other);
        $this->account($this->subscriptionIn($this->company, 'active', null, $this->packageWithRouterProfile($this->company)), ['username' => 'taken']);
        $noProfile = $this->subscriptionIn($this->company, 'active', null, $this->packageWithRouterProfile($this->company, null));
        $terminated = $this->subscriptionIn($this->company, 'terminated', null, $this->packageWithRouterProfile($this->company));
        $withAccount = $this->subscriptionIn($this->company, 'active', null, $this->packageWithRouterProfile($this->company));
        $this->account($withAccount, ['username' => 'already-linked']);
        $url = fn (ServiceSubscription $subscription) => route('admin.subscriptions.network-account.store', $subscription);

        $cases = [
            'no router profile name' => [$noProfile, $this->storePayload(), 'subscription'],
            'terminated subscription' => [$terminated, $this->storePayload(), 'subscription'],
            'subscription already has an account' => [$withAccount, $this->storePayload(), 'subscription'],
            "another company's NAS router" => [$this->subscription, $this->storePayload(['nas_device_id' => $foreignNas->id]), 'nas_device_id'],
            'unknown NAS router' => [$this->subscription, $this->storePayload(['nas_device_id' => 999999]), 'nas_device_id'],
            'duplicate username in the company' => [$this->subscription, $this->storePayload(['username' => 'taken']), 'username'],
            'username too short' => [$this->subscription, $this->storePayload(['username' => 'ab']), 'username'],
            'username with a space' => [$this->subscription, $this->storePayload(['username' => 'bad name']), 'username'],
            'username too long' => [$this->subscription, $this->storePayload(['username' => str_repeat('a', 65)]), 'username'],
            'unknown service type' => [$this->subscription, $this->storePayload(['service_type' => 'l2tp']), 'service_type'],
            'password too short' => [$this->subscription, $this->storePayload(['password' => 'abc']), 'password'],
            'password too long' => [$this->subscription, $this->storePayload(['password' => str_repeat('p', 65)]), 'password'],
            'password with a space' => [$this->subscription, $this->storePayload(['password' => 'bad pass word']), 'password'],
        ];

        foreach ($cases as $name => [$subscription, $payload, $field]) {
            $response = $this->postJson($url($subscription), $payload);
            $this->assertSame(422, $response->getStatusCode(), $name);
            $response->assertJsonValidationErrors($field);
        }

        $this->assertSame(2, NetworkAccount::query()->count());
    }

    public function test_the_same_username_is_allowed_in_another_company(): void
    {
        $other = Company::factory()->create(['is_active' => true]);
        [$otherNas] = $this->nasRouter($other);
        $this->networkAccountFor($this->subscriptionIn($other, 'active', null, $this->packageWithRouterProfile($other)), $otherNas, ['username' => 'alice.home']);

        $this->postJson(route('admin.subscriptions.network-account.store', $this->subscription), $this->storePayload())->assertRedirect();

        $this->assertSame(2, NetworkAccount::withoutCompany()->where('username', 'alice.home')->count());
    }

    public function test_update_keeps_the_password_when_blank_and_queues_one_apply(): void
    {
        $account = $this->account(attributes: ['password' => 'fake-keep-me']);
        Queue::fake();

        $this->put(route('admin.subscriptions.network-account.update', $this->subscription), ['username' => 'alice.new', 'password' => '', 'service_type' => 'pppoe'])->assertRedirect();

        $account = $account->fresh();
        $this->assertSame('alice.new', $account->username);
        $this->assertSame('fake-keep-me', $account->password);
        Queue::assertPushed(ApplyNetworkAccountJob::class, 1);
        $audit = Activity::query()->where('log_name', 'network_account')->where('description', 'updated')->firstOrFail();
        $this->assertSame(['username'], $audit->properties['changed']);
        $this->assertSame(0, Activity::query()->where('description', 'password_changed')->count());
    }

    public function test_update_with_a_new_password_audits_password_changed_without_it_and_the_router_follows(): void
    {
        $this->account();
        $this->router->seedAccount('pppoe', 'alice', 'home-20m');

        $this->put(route('admin.subscriptions.network-account.update', $this->subscription), ['password' => 'fake-new-secret-9'])->assertRedirect();

        $this->assertSame('fake-new-secret-9', $this->router->account('pppoe', 'alice')['password']);
        $audit = Activity::query()->where('description', 'password_changed')->firstOrFail();
        $this->assertStringNotContainsString('fake-new-secret-9', json_encode($audit->properties));
        $this->assertSame(0, Activity::query()->where('log_name', 'network_account')->where('description', 'updated')->count());
    }

    public function test_update_validation_and_a_missing_account(): void
    {
        $this->account();
        $this->account($this->subscriptionIn($this->company, 'active', null, $this->packageWithRouterProfile($this->company)), ['username' => 'taken']);
        [$foreignNas] = $this->nasRouter(Company::factory()->create(['is_active' => true]));

        $this->putJson(route('admin.subscriptions.network-account.update', $this->subscription), ['username' => 'taken'])->assertUnprocessable()->assertJsonValidationErrors('username');
        $this->putJson(route('admin.subscriptions.network-account.update', $this->subscription), ['nas_device_id' => $foreignNas->id])->assertUnprocessable()->assertJsonValidationErrors('nas_device_id');
        $this->putJson(route('admin.subscriptions.network-account.update', $this->subscription), ['username' => 'alice'])->assertRedirect();
        $withoutAccount = $this->subscriptionIn($this->company, 'active', null, $this->packageWithRouterProfile($this->company));
        $this->put(route('admin.subscriptions.network-account.update', $withoutAccount), ['username' => 'nobody'])->assertNotFound();
    }

    public function test_reveal_flashes_the_password_once_and_audits_it_without_the_value(): void
    {
        $this->account(attributes: ['password' => 'fake-reveal-me-7']);

        $this->post(route('admin.subscriptions.network-account.reveal', $this->subscription))
            ->assertRedirect(route('admin.subscriptions.network-account.show', $this->subscription));

        $page = $this->show();
        $this->assertSame('fake-reveal-me-7', $page->inertiaPage()['flash']['network_account']['password']);
        $this->assertStringNotContainsString('fake-reveal-me-7', json_encode($page->inertiaProps()));
        $this->show()->assertInertia(fn (Assert $inertia) => $inertia->missingFlash('network_account'));
        $audit = Activity::query()->where('log_name', 'network_account')->where('description', 'password_revealed')->firstOrFail();
        $this->assertSame($this->admin->id, $audit->causer_id);
        $this->assertStringNotContainsString('fake-reveal-me-7', json_encode($audit->properties));
        $this->assertSame('alice', $audit->properties['username']);
    }

    public function test_reveal_is_throttled_to_ten_a_minute(): void
    {
        $this->account();

        foreach (range(1, 10) as $_) {
            $this->post(route('admin.subscriptions.network-account.reveal', $this->subscription))->assertRedirect();
        }

        $this->post(route('admin.subscriptions.network-account.reveal', $this->subscription))->assertStatus(429);
        $this->assertSame(10, Activity::query()->where('description', 'password_revealed')->count());
    }

    public function test_reveal_without_an_account_is_a_404_and_audits_nothing(): void
    {
        $this->post(route('admin.subscriptions.network-account.reveal', $this->subscription))->assertNotFound();

        $this->assertSame(0, Activity::query()->where('description', 'password_revealed')->count());
    }

    public function test_resync_queues_one_apply_job_and_is_throttled(): void
    {
        $account = $this->account();
        Queue::fake();

        $this->post(route('admin.subscriptions.network-account.resync', $this->subscription))->assertRedirect()->assertSessionHas('success');

        Queue::assertPushed(ApplyNetworkAccountJob::class, 1);
        Queue::assertPushed(ApplyNetworkAccountJob::class, fn (ApplyNetworkAccountJob $job): bool => $job->accountId === $account->id);

        foreach (range(1, 5) as $_) {
            $this->post(route('admin.subscriptions.network-account.resync', $this->subscription))->assertRedirect();
        }
        $this->post(route('admin.subscriptions.network-account.resync', $this->subscription))->assertStatus(429);
    }

    public function test_every_route_needs_its_permission(): void
    {
        $this->account();
        $nobody = $this->userWithPermissions($this->company);
        $viewer = $this->userWithPermissions($this->company, 'network_asset.account.view');
        $manager = $this->userWithPermissions($this->company, 'network_asset.account.view', 'network_asset.account.manage');
        $subscription = $this->subscription;
        $calls = [
            'show' => [fn () => $this->get(route('admin.subscriptions.network-account.show', $subscription)), 'network_asset.account.view'],
            'store' => [fn () => $this->postJson(route('admin.subscriptions.network-account.store', $subscription), $this->storePayload()), 'network_asset.account.manage'],
            'update' => [fn () => $this->putJson(route('admin.subscriptions.network-account.update', $subscription), ['username' => 'someone']), 'network_asset.account.manage'],
            'resync' => [fn () => $this->postJson(route('admin.subscriptions.network-account.resync', $subscription)), 'network_asset.account.manage'],
            'reveal' => [fn () => $this->postJson(route('admin.subscriptions.network-account.reveal', $subscription)), 'network_asset.account.reveal'],
        ];

        foreach ($calls as $name => [$call, $permission]) {
            $this->actingAs($nobody);
            $this->assertSame(403, $call()->getStatusCode(), "$name without any permission");
        }

        $this->actingAs($viewer);
        $this->assertSame(200, $calls['show'][0]()->getStatusCode());
        foreach (['store', 'update', 'resync', 'reveal'] as $name) {
            $this->assertSame(403, $calls[$name][0]()->getStatusCode(), "$name with view only");
        }

        $this->actingAs($manager);
        $this->assertSame(403, $calls['reveal'][0]()->getStatusCode(), 'reveal with manage but not reveal');
        $this->get(route('admin.subscriptions.network-account.show', $subscription))
            ->assertInertia(fn (Assert $page) => $page->where('can', ['manage' => true, 'reveal' => false]));
        $this->assertSame(0, Activity::query()->where('description', 'password_revealed')->count());
    }

    public function test_another_companys_subscription_is_a_404_on_every_route(): void
    {
        $other = Company::factory()->create(['is_active' => true]);
        [$otherNas] = $this->nasRouter($other);
        $foreign = $this->subscriptionIn($other, 'active', null, $this->packageWithRouterProfile($other));
        $this->networkAccountFor($foreign, $otherNas);

        $this->get(route('admin.subscriptions.network-account.show', $foreign))->assertNotFound();
        $this->post(route('admin.subscriptions.network-account.store', $foreign), $this->storePayload())->assertNotFound();
        $this->put(route('admin.subscriptions.network-account.update', $foreign), ['username' => 'hijack'])->assertNotFound();
        $this->post(route('admin.subscriptions.network-account.reveal', $foreign))->assertNotFound();
        $this->post(route('admin.subscriptions.network-account.resync', $foreign))->assertNotFound();
        $this->assertSame('alice', NetworkAccount::withoutCompany()->where('company_id', $other->id)->value('username'));
        $this->assertSame(0, Activity::query()->where('description', 'password_revealed')->count());
    }

    public function test_the_password_is_never_in_any_prop_or_audit_after_a_full_cycle(): void
    {
        $this->post(route('admin.subscriptions.network-account.store', $this->subscription), $this->storePayload())->assertRedirect();
        $password = NetworkAccount::query()->firstOrFail()->password;
        $this->post(route('admin.subscriptions.network-account.reveal', $this->subscription))->assertRedirect();
        $this->put(route('admin.subscriptions.network-account.update', $this->subscription), ['username' => 'alice.two', 'password' => 'fake-cycle-pass-3'])->assertRedirect();
        $this->post(route('admin.subscriptions.network-account.resync', $this->subscription))->assertRedirect();

        foreach ([$this->show(), $this->get(route('admin.nas-devices.show', $this->nas)), $this->get(route('admin.nas-devices.index'))] as $response) {
            $encoded = json_encode($response->inertiaProps());
            $this->assertStringNotContainsString($password, $encoded);
            $this->assertStringNotContainsString('fake-cycle-pass-3', $encoded);
        }
        foreach (Activity::query()->get() as $entry) {
            $this->assertStringNotContainsString($password, json_encode($entry->properties));
            $this->assertStringNotContainsString('fake-cycle-pass-3', json_encode($entry->properties));
        }
    }
}
