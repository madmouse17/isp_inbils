<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Core\ServiceSubscription;
use App\Services\Core\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Ipam\Events\IpAssignmentChanged;
use Modules\Ipam\Models\IpAddress;
use Modules\Ipam\Services\IpAssignmentService;
use Modules\Ipam\Services\IpPrefixService;
use Modules\NetworkAsset\Models\OdnPort;
use Modules\NetworkAsset\Queries\OdnOccupancyQuery;
use Modules\NetworkAsset\Services\OdnService;
use Modules\Service\Database\Factories\ServicePackageFactory;
use Modules\Service\Models\ServicePackage;
use Modules\SPK\Database\Factories\WorkOrderFactory;
use Modules\SPK\Models\WorkOrder;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Tests\Traits\BuildsIpamOdnFixtures;
use Tests\Traits\BuildsOdnTopology;

/**
 * IPAM and ODN where they meet the rest of the app: onboarding reserves a drop, terminate gives back the static
 * address and the drop port, and the subscription, customer-create and SPK pages carry the new props.
 */
class IpamOdnLifecycleTest extends TestCase
{
    use BuildsIpamOdnFixtures;
    use BuildsOdnTopology;
    use RefreshDatabase;

    private ServicePackage $package;

    /** @var array<string, mixed> */
    private array $net;

    /** @var list<OdnPort> outputs of a 1:8 splitter in the ODP under the serving POP */
    private array $outputs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->bootIpamOdnWorld(withSeededRoles: true);
        $this->seedRegion();
        $this->package = ServicePackageFactory::new()->create(['company_id' => $this->company->id, 'price_mrc' => 250000, 'price_otc' => 150000, 'contract_min_months' => 12]);
        $this->net = $this->topology($this->company);
        [, $this->outputs] = $this->splitter($this->net['odp'], 8);
    }

    /** @param array<string, mixed> $subscription extra subscription keys */
    private function onboard(array $subscription = [], ?int $popId = null): TestResponse
    {
        return $this->postJson(route('admin.customers.store'), $this->payload($subscription, $popId));
    }

    /** @return array<string, int> row counts that must not move when an onboarding is refused */
    private function onboardingFootprint(): array
    {
        return [
            'customers' => DB::table('customers')->count(),
            'users' => DB::table('users')->where('email', 'lifecycle@example.test')->count(),
            'subscriptions' => DB::table('service_subscriptions')->count(),
            'work_orders' => DB::table('work_orders')->count(),
            'addresses' => DB::table('customer_addresses')->count(),
            'drops' => OdnPort::query()->whereNotNull('subscription_id')->count(),
        ];
    }

    public function test_onboarding_with_a_free_port_under_the_serving_pop_reserves_the_drop(): void
    {
        $this->onboard(['odn_port_id' => $this->outputs[2]->id])->assertRedirect();

        $subscription = ServiceSubscription::query()->firstOrFail();
        $this->assertSame($this->net['pop']->id, $subscription->serving_pop_id);
        $this->assertSame($subscription->id, $this->outputs[2]->fresh()->subscription_id);
        $this->assertSame(1, WorkOrder::query()->where('subscription_id', $subscription->id)->where('type', 'installation')->count());
        $this->assertSame(1, DB::table('customers')->where('code', 'CUS-LIFECYCLE')->count());
    }

    public function test_onboarding_without_a_port_behaves_as_before(): void
    {
        $this->onboard()->assertRedirect();

        $this->assertSame(1, ServiceSubscription::query()->count());
        $this->assertSame(1, WorkOrder::query()->count());
        $this->assertSame(0, OdnPort::query()->whereNotNull('subscription_id')->count());
    }

    public function test_an_empty_port_field_is_the_same_as_no_port(): void
    {
        $this->onboard(['odn_port_id' => null])->assertRedirect();
        $this->assertSame(0, OdnPort::query()->whereNotNull('subscription_id')->count());
    }

    public function test_an_occupied_port_refuses_the_whole_onboarding(): void
    {
        OdnService::assignDrop($this->outputs[0], $this->makeSubscription($this->company));
        $before = $this->onboardingFootprint();

        $this->onboard(['odn_port_id' => $this->outputs[0]->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('subscription.odn_port_id');

        $this->assertSame($before, $this->onboardingFootprint(), 'no customer, user, subscription, address or SPK is left behind');
    }

    public function test_a_port_that_feeds_a_splitter_or_is_not_an_odp_output_refuses_the_onboarding(): void
    {
        $this->splitter($this->net['odc'], 4, $this->outputs[1]);
        $pon = $this->ponPorts($this->net['olt'], 1);
        $before = $this->onboardingFootprint();

        foreach ([$this->outputs[1], $pon[0]] as $port) {
            $this->onboard(['odn_port_id' => $port->id])->assertStatus(422)->assertJsonValidationErrors('subscription.odn_port_id');
        }

        $this->assertSame($before, $this->onboardingFootprint());
    }

    public function test_another_companys_port_is_refused_and_nothing_is_created(): void
    {
        $other = $this->otherCompany();
        $foreign = $this->topology($other, 'X');
        $foreignOutputs = $this->inCompany($other, fn () => $this->splitter($foreign['odp'], 4)[1]);
        $before = $this->onboardingFootprint();

        $this->onboard(['odn_port_id' => $foreignOutputs[0]->id])->assertStatus(422)->assertJsonValidationErrors('subscription.odn_port_id');

        $this->assertSame($before, $this->onboardingFootprint());
        $this->assertNull(OdnPort::withoutCompany()->find($foreignOutputs[0]->id)->subscription_id);
    }

    public function test_a_port_outside_the_serving_pop_refuses_the_onboarding(): void
    {
        $other = $this->topology($this->company, 'B');
        [, $otherOutputs] = $this->splitter($other['odp'], 4);
        $before = $this->onboardingFootprint();

        $this->onboard(['odn_port_id' => $otherOutputs[0]->id])->assertStatus(422)->assertJsonValidationErrors('subscription.odn_port_id');

        $this->assertSame($before, $this->onboardingFootprint());
    }

    public function test_a_location_code_faking_the_pop_path_does_not_pass_as_under_the_pop(): void
    {
        // A legacy or imported row bypasses the HTTP code rule: its path "POP-A > SPOOF" looks like a child of POP-A.
        $spoof = $this->makeLocation($this->company, $this->net['pop']->code.' > SPOOF', 'site');
        $odp = $this->odpAt($this->company, $spoof, 'ODP-SPOOF');
        [, $spoofOutputs] = $this->splitter($odp, 4);
        $before = $this->onboardingFootprint();

        $this->onboard(['odn_port_id' => $spoofOutputs[0]->id])->assertStatus(422)->assertJsonValidationErrors('subscription.odn_port_id');
        $this->assertSame($before, $this->onboardingFootprint());

        $offered = array_column(OdnOccupancyQuery::freePortOptions($this->net['pop']->id), 'odp_code');
        $this->assertNotContains('ODP-SPOOF', $offered, 'the drop picker must not offer it either');
        $this->assertContains($this->net['odp']->code, $offered);

        $listed = collect($this->get(route('admin.odn.index', ['pop_id' => $this->net['pop']->id]))->viewData('page')['props']['odps']['data'])->pluck('code');
        $this->assertNotContains('ODP-SPOOF', $listed->all());
    }

    public function test_a_user_without_the_drop_permission_cannot_pick_a_port(): void
    {
        $role = Role::firstOrCreate(['name' => 'onboarder', 'guard_name' => 'web']);
        $role->syncPermissions(Permission::query()->whereIn('name', ['customer.create', 'customer.address.manage', 'customer.subscription.manage', 'spk.create', 'customer.view'])->get());
        $this->actingAs($this->makeUser($this->company, 'onboarder'));

        $this->onboard(['odn_port_id' => $this->outputs[0]->id])->assertRedirect();

        $this->assertNull($this->outputs[0]->fresh()->subscription_id, 'a forged port is ignored, like a forged acquirer');
        $this->assertSame(1, ServiceSubscription::query()->count());
    }

    public function test_an_unknown_subscription_key_is_still_refused(): void
    {
        $this->onboard(['status' => 'active'])->assertStatus(422)->assertJsonValidationErrors('subscription');
    }

    public function test_the_customer_create_page_loads_drop_options_only_on_a_partial_reload(): void
    {
        $page = route('admin.customers.create', ['serving_pop_id' => $this->net['pop']->id]);

        $this->get($page)->assertOk()->assertInertia(fn (Assert $inertia) => $inertia
            ->component('Admin/Customers/Create', false)
            ->missing('dropPortOptions')
            ->where('can.assignDrop', true));

        $this->get($page, $this->partialReload('Admin/Customers/Create', 'dropPortOptions'))
            ->assertOk()
            ->assertJsonCount(8, 'props.dropPortOptions')
            ->assertJsonPath('props.dropPortOptions.0.odp_code', 'ODP-A11')
            ->assertJsonPath('props.dropPortOptions.0.free_count', 8)
            ->assertJsonPath('props.dropPortOptions.0.location_path', 'POP-A > RACK-A1 > SITE-A11')
            ->assertJsonPath('props.dropPortOptions.0.label', 'ODP-A11 · 1:8 · port 1');
        $this->get(route('admin.customers.create'), $this->partialReload('Admin/Customers/Create', 'dropPortOptions'))
            ->assertJsonPath('props.dropPortOptions', []);
    }

    public function test_a_user_without_the_drop_permission_gets_no_drop_options(): void
    {
        $role = Role::firstOrCreate(['name' => 'onboarder', 'guard_name' => 'web']);
        $role->syncPermissions(Permission::query()->whereIn('name', ['customer.create', 'customer.address.manage', 'customer.subscription.manage', 'spk.create', 'customer.view'])->get());
        $this->actingAs($this->makeUser($this->company, 'onboarder'));

        $this->get(route('admin.customers.create', ['serving_pop_id' => $this->net['pop']->id]), $this->partialReload('Admin/Customers/Create', 'dropPortOptions'))
            ->assertOk()
            ->assertJsonPath('props.dropPortOptions', null);
    }

    public function test_terminate_releases_the_static_address_and_the_drop_port_and_audits_both(): void
    {
        $pool = IpPrefixService::create(['cidr' => '10.110.0.0/24', 'usage' => 'customer_static']);
        $subscription = $this->makeSubscription($this->company, 'active', $this->net['pop']);
        $other = $this->makeSubscription($this->company, 'active', $this->net['pop']);
        IpAssignmentService::assignToSubscription($subscription, $pool);
        IpAssignmentService::assignToSubscription($other, $pool);
        OdnService::assignDrop($this->outputs[0], $subscription);
        OdnService::assignDrop($this->outputs[1], $other);
        Event::fake([IpAssignmentChanged::class]);

        SubscriptionService::terminate($subscription, 'moved away');

        $this->assertSame('terminated', $subscription->fresh()->status);
        $this->assertNull(IpAssignmentService::staticAddressFor($subscription));
        $this->assertSame(0, IpAddress::query()->where('subscription_id', $subscription->id)->count());
        $this->assertNull($this->outputs[0]->fresh()->subscription_id);
        $this->assertSame('10.110.0.2', IpAssignmentService::staticAddressFor($other), 'other subscriptions keep theirs');
        $this->assertSame($other->id, $this->outputs[1]->fresh()->subscription_id);
        Event::assertDispatchedTimes(IpAssignmentChanged::class, 1);
        Event::assertDispatched(IpAssignmentChanged::class, fn (IpAssignmentChanged $event): bool => $event->subscriptionId === $subscription->id);

        $released = Activity::query()->where('log_name', 'ipam')->where('description', 'address_released')->firstOrFail();
        $dropReleased = Activity::query()->where('log_name', 'odn')->where('description', 'drop_released')->firstOrFail();
        $this->assertSame('subscription terminated', $released->properties['reason']);
        $this->assertSame($subscription->id, $released->properties['subscription_id']);
        $this->assertSame($this->outputs[0]->id, $dropReleased->properties['port_id']);
        $this->assertSame($subscription->id, $dropReleased->properties['subscription_id']);
    }

    public function test_the_released_address_and_port_can_be_given_to_someone_else_at_once(): void
    {
        $pool = IpPrefixService::create(['cidr' => '10.111.0.0/30', 'usage' => 'customer_static']);
        $leaver = $this->makeSubscription($this->company, 'active');
        $newcomer = $this->makeSubscription($this->company, 'pending');
        IpAssignmentService::assignToSubscription($leaver, $pool, '10.111.0.1');
        OdnService::assignDrop($this->outputs[0], $leaver);

        SubscriptionService::terminate($leaver, 'left');
        IpAssignmentService::assignToSubscription($newcomer, $pool, '10.111.0.1');
        OdnService::assignDrop($this->outputs[0], $newcomer);

        $this->assertSame('10.111.0.1', IpAssignmentService::staticAddressFor($newcomer));
        $this->assertSame($newcomer->id, $this->outputs[0]->fresh()->subscription_id);
    }

    public function test_terminate_through_the_route_releases_too(): void
    {
        $pool = IpPrefixService::create(['cidr' => '10.112.0.0/24', 'usage' => 'customer_static']);
        $subscription = $this->makeSubscription($this->company, 'active');
        IpAssignmentService::assignToSubscription($subscription, $pool);
        OdnService::assignDrop($this->outputs[0], $subscription);

        $this->postJson(route('admin.subscriptions.terminate', $subscription), ['reason' => 'contract ended'])->assertRedirect();

        $this->assertNull(IpAssignmentService::staticAddressFor($subscription));
        $this->assertNull($this->outputs[0]->fresh()->subscription_id);
    }

    public function test_suspend_and_reactivate_leave_the_address_and_the_port_alone(): void
    {
        $pool = IpPrefixService::create(['cidr' => '10.113.0.0/24', 'usage' => 'customer_static']);
        $subscription = $this->makeSubscription($this->company, 'active');
        IpAssignmentService::assignToSubscription($subscription, $pool);
        OdnService::assignDrop($this->outputs[0], $subscription);
        Event::fake([IpAssignmentChanged::class]);

        SubscriptionService::suspend($subscription, 'unpaid');

        $this->assertSame('10.113.0.1', IpAssignmentService::staticAddressFor($subscription));
        $this->assertSame($subscription->id, $this->outputs[0]->fresh()->subscription_id);

        SubscriptionService::reactivate($subscription);

        $this->assertSame('10.113.0.1', IpAssignmentService::staticAddressFor($subscription));
        $this->assertSame($subscription->id, $this->outputs[0]->fresh()->subscription_id);
        Event::assertNotDispatched(IpAssignmentChanged::class);
    }

    public function test_terminating_a_subscription_without_either_changes_nothing_else(): void
    {
        $subscription = $this->makeSubscription($this->company, 'active');
        Event::fake([IpAssignmentChanged::class]);

        SubscriptionService::terminate($subscription, 'none held');

        $this->assertSame('terminated', $subscription->fresh()->status);
        Event::assertNotDispatched(IpAssignmentChanged::class);
        $this->assertSame(0, Activity::query()->where('description', 'drop_released')->count());
    }

    public function test_the_subscription_page_lists_addresses_pools_and_the_drop_port(): void
    {
        $popPool = IpPrefixService::create(['cidr' => '10.120.0.0/24', 'usage' => 'customer_static', 'location_id' => $this->net['pop']->id]);
        IpPrefixService::create(['cidr' => '10.121.0.0/24', 'usage' => 'customer_static']);
        IpPrefixService::create(['cidr' => '10.122.0.0/24', 'usage' => 'customer_static', 'location_id' => $this->topology($this->company, 'B')['pop']->id]);
        IpPrefixService::create(['cidr' => '10.123.0.0/24', 'usage' => 'customer_dynamic']);
        $subscription = $this->makeSubscription($this->company, 'active', $this->net['pop']);
        IpAssignmentService::assignToSubscription($subscription, $popPool, '10.120.0.9');
        OdnService::assignDrop($this->outputs[3], $subscription);

        $this->get(route('admin.subscriptions.show', $subscription))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Subscriptions/Show', false)
                ->has('subscription')
                ->has('ipAddresses', 1)
                ->where('ipAddresses.0.address', '10.120.0.9')
                ->where('ipAddresses.0.family', 4)
                ->where('ipAddresses.0.prefix_cidr', '10.120.0.0/24')
                ->where('ipAddresses.0.status', 'assigned')
                ->has('staticPools', 2)
                ->where('staticPools.0.cidr', '10.120.0.0/24')
                ->where('staticPools.0.free', '253')
                ->where('staticPools.1.cidr', '10.121.0.0/24')
                ->where('staticPools.1.free', '254')
                ->where('dropPort', [
                    'port_id' => $this->outputs[3]->id,
                    'number' => 4,
                    'odp_asset_id' => $this->net['odp']->id,
                    'odp_code' => 'ODP-A11',
                    'odp_name' => $this->net['odp']->name,
                    'location_path' => 'POP-A > RACK-A1 > SITE-A11',
                ])
                ->missing('dropPortOptions')
                ->where('can.assignIp', true)
                ->where('can.assignDrop', true));
    }

    public function test_the_subscription_page_without_addresses_or_drop(): void
    {
        $subscription = $this->makeSubscription($this->company, 'active');

        $this->get(route('admin.subscriptions.show', $subscription))
            ->assertInertia(fn (Assert $page) => $page->where('ipAddresses', [])->where('dropPort', null));
    }

    public function test_the_subscription_page_loads_drop_options_only_on_a_partial_reload(): void
    {
        $subscription = $this->makeSubscription($this->company, 'active', $this->net['pop']);
        OdnService::assignDrop($this->outputs[0], $subscription);

        $this->get(route('admin.subscriptions.show', $subscription), $this->partialReload('Admin/Subscriptions/Show', 'dropPortOptions'))
            ->assertOk()
            ->assertJsonCount(7, 'props.dropPortOptions');
    }

    public function test_pools_are_withheld_from_a_user_who_cannot_assign_addresses(): void
    {
        IpPrefixService::create(['cidr' => '10.124.0.0/24', 'usage' => 'customer_static']);
        $subscription = $this->makeSubscription($this->company, 'active', $this->net['pop']);

        $role = Role::firstOrCreate(['name' => 'subscription-viewer', 'guard_name' => 'web']);
        $role->syncPermissions(Permission::query()->whereIn('name', ['customer.subscription.view', 'ipam.view', 'odn.view'])->get());
        $this->actingAs($this->makeUser($this->company, 'subscription-viewer'));
        $this->get(route('admin.subscriptions.show', $subscription))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->missing('staticPools')
                ->has('ipAddresses')
                ->where('can.assignIp', false)
                ->where('can.assignDrop', false));

        $this->actingAs($this->makeUser($this->company, 'staff'));
        $this->get(route('admin.subscriptions.show', $subscription))
            ->assertInertia(fn (Assert $page) => $page->missing('staticPools')->where('can.assignIp', false)->where('can.assignDrop', true));
    }

    public function test_addresses_and_the_drop_are_hidden_from_a_user_who_cannot_view_the_registers(): void
    {
        $pool = IpPrefixService::create(['cidr' => '10.125.0.0/24', 'usage' => 'customer_static']);
        $subscription = $this->makeSubscription($this->company, 'active');
        IpAssignmentService::assignToSubscription($subscription, $pool);
        OdnService::assignDrop($this->outputs[0], $subscription);
        $role = Role::firstOrCreate(['name' => 'viewer', 'guard_name' => 'web']);
        $role->syncPermissions(Permission::query()->whereIn('name', ['customer.subscription.view'])->get());
        $this->actingAs($this->makeUser($this->company, 'viewer'));

        $this->get(route('admin.subscriptions.show', $subscription))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('ipAddresses', [])->where('dropPort', null)->missing('staticPools'));
    }

    public function test_the_installation_spk_page_shows_the_reserved_drop_and_others_show_none(): void
    {
        $subscription = $this->makeSubscription($this->company, 'pending', $this->net['pop']);
        OdnService::assignDrop($this->outputs[1], $subscription);
        $installation = $this->workOrderFor($subscription, 'installation');
        $maintenance = $this->workOrderFor($subscription, 'maintenance');
        $noDrop = $this->workOrderFor($this->makeSubscription($this->company, 'pending'), 'installation');

        $this->get(route('admin.spk.show', $installation))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/SPK/Show', false)
                ->where('dropPort.port_id', $this->outputs[1]->id)
                ->where('dropPort.number', 2)
                ->where('dropPort.odp_code', 'ODP-A11')
                ->where('dropPort.location_path', 'POP-A > RACK-A1 > SITE-A11'));
        $this->get(route('admin.spk.show', $maintenance))->assertInertia(fn (Assert $page) => $page->where('dropPort', null));
        $this->get(route('admin.spk.show', $noDrop))->assertInertia(fn (Assert $page) => $page->where('dropPort', null));
    }

    public function test_the_spk_page_hides_the_drop_from_a_user_who_cannot_view_the_odn(): void
    {
        $subscription = $this->makeSubscription($this->company, 'pending', $this->net['pop']);
        OdnService::assignDrop($this->outputs[1], $subscription);
        $installation = $this->workOrderFor($subscription, 'installation');
        $role = Role::firstOrCreate(['name' => 'spk-viewer', 'guard_name' => 'web']);
        $role->syncPermissions(Permission::query()->whereIn('name', ['spk.view'])->get());
        $this->actingAs($this->makeUser($this->company, 'spk-viewer'));

        $this->get(route('admin.spk.show', $installation))->assertOk()->assertInertia(fn (Assert $page) => $page->where('dropPort', null));
    }

    private function workOrderFor(ServiceSubscription $subscription, string $type): WorkOrder
    {
        return WorkOrderFactory::new()->create([
            'company_id' => $this->company->id,
            'type' => $type,
            'status' => 'generated',
            'customer_id' => $subscription->customer_id,
            'subscription_id' => $subscription->id,
            'location_id' => $subscription->serving_pop_id,
            'created_by' => $this->admin->id,
        ]);
    }

    /** @return array<string, string> headers of an Inertia partial reload that asks for one prop */
    private function partialReload(string $component, string $prop): array
    {
        return [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
            'X-Inertia-Partial-Component' => $component,
            'X-Inertia-Partial-Data' => $prop,
        ];
    }

    /**
     * @param  array<string, mixed>  $subscription
     * @return array<string, mixed>
     */
    private function payload(array $subscription = [], ?int $popId = null): array
    {
        return [
            'code' => 'CUS-LIFECYCLE',
            'name' => 'Lifecycle Customer',
            'type' => 'Individual',
            'email' => 'lifecycle@example.test',
            'phone' => '0800000002',
            'is_active' => true,
            'addresses' => [[
                'label' => 'Installation', 'address' => 'Installation Street 2', 'city' => 'Jakarta', 'postal_code' => '12346',
                'province_code' => '31', 'city_code' => '3171', 'district_code' => '3171010', 'village_code' => '3171010001',
                'lat' => -6.201, 'lng' => 106.817, 'is_primary' => true, 'is_installation_point' => true, 'notes' => null,
            ]],
            'contacts' => [[
                'name' => 'Primary Contact', 'position' => 'Owner', 'phone' => '0812-3456-7890',
                'email' => 'lifecycle.contact@example.test', 'is_primary' => true, 'notes' => null,
            ]],
            'subscription' => [
                'service_package_id' => $this->package->id,
                'serving_pop_id' => $popId ?? $this->net['pop']->id,
                'billing_day' => 5,
                'mrc_amount' => null,
                'otc_installation_fee' => null,
                'contract_months' => null,
                'notes' => null,
                ...$subscription,
            ],
        ];
    }

    private function seedRegion(): void
    {
        DB::table('indonesia_provinces')->insert(['code' => '31', 'name' => 'DKI JAKARTA']);
        DB::table('indonesia_cities')->insert(['code' => '3171', 'province_code' => '31', 'name' => 'KOTA JAKARTA SELATAN']);
        DB::table('indonesia_districts')->insert(['code' => '3171010', 'city_code' => '3171', 'name' => 'KEBAYORAN BARU']);
        DB::table('indonesia_villages')->insert(['code' => '3171010001', 'district_code' => '3171010', 'name' => 'GANDARIA UTARA']);
    }
}
