<?php

namespace Tests\Feature\Ipam;

use App\Models\Core\Company;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Modules\Ipam\Models\IpAddress;
use Modules\Ipam\Models\IpPrefix;
use Modules\Ipam\Models\Vlan;
use Modules\Ipam\Services\IpAssignmentService;
use Tests\TestCase;
use Tests\Traits\BuildsIpamOdnFixtures;

/**
 * Ticket 07: the tenant boundary and the permission floor of every /admin/ipam route, driven by the route
 * table so a route added later fails the completeness test until it is covered here too.
 *
 * Mutation checks (2026-10-13, each reverted byte-for-byte; the other suites stayed green):
 *  - drop the (company_id, address_cmp) unique from the ip_addresses migration: 3 failed of 12 in
 *    IpamConcurrencyTest + IpamConstraintsTest (the automatic-vs-asset-claim race ended with 2 winners);
 *  - drop the FOR UPDATE on the target port in OdnService::assignDrop: 1 failed of 2 in OdnConcurrencyTest
 *    (two drops took the last free port);
 *  - drop the serving-POP path check (assertUnderServingPop): 4 failed of 44 in OdnDropTest + IpamOdnLifecycleTest;
 *  - drop the IP and drop-port release from SubscriptionService::terminate: 3 failed of 23 in IpamOdnLifecycleTest.
 */
class IpamTenantAndPermissionMatrixTest extends TestCase
{
    use BuildsIpamOdnFixtures;
    use RefreshDatabase;

    /** No record in the URL, so they cannot be 404; the body test and the floor test cover them. */
    private const COLLECTION_ROUTES = [
        'admin.ipam.prefixes.index',
        'admin.ipam.prefixes.store',
        'admin.ipam.vlans.index',
        'admin.ipam.vlans.store',
    ];

    private IpPrefix $pool;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->bootIpamOdnWorld();

        $this->pool = IpPrefix::factory()->cidr('10.80.0.0/24')->create(['company_id' => $this->company->id]);
    }

    /** @return array{prefix: IpPrefix, vlan: Vlan, address: IpAddress, subscription: ServiceSubscription} */
    private function records(Company $company, string $network): array
    {
        return $this->inCompany($company, function () use ($company, $network): array {
            $prefix = IpPrefix::factory()->cidr("{$network}.0/24")->create(['company_id' => $company->id]);

            return [
                'prefix' => $prefix,
                'vlan' => Vlan::factory()->create(['company_id' => $company->id, 'vid' => 900]),
                'address' => IpAssignmentService::reserve($prefix, "{$network}.9"),
                'subscription' => $this->makeSubscription($company),
            ];
        });
    }

    /**
     * Every IPAM route, each sending a body that would be valid for the current company.
     *
     * @param  array{prefix: IpPrefix, vlan: Vlan, address: IpAddress, subscription: ServiceSubscription}  $r
     * @return array<string, Closure(): TestResponse>
     */
    private function requests(array $r): array
    {
        return [
            'admin.ipam.prefixes.index' => fn () => $this->get(route('admin.ipam.prefixes.index')),
            'admin.ipam.prefixes.store' => fn () => $this->postJson(route('admin.ipam.prefixes.store'), ['cidr' => '10.250.0.0/24', 'usage' => 'container']),
            'admin.ipam.prefixes.show' => fn () => $this->get(route('admin.ipam.prefixes.show', $r['prefix'])),
            'admin.ipam.prefixes.update' => fn () => $this->putJson(route('admin.ipam.prefixes.update', $r['prefix']), ['usage' => 'infrastructure']),
            'admin.ipam.prefixes.destroy' => fn () => $this->deleteJson(route('admin.ipam.prefixes.destroy', $r['prefix'])),
            'admin.ipam.reservations.store' => fn () => $this->postJson(route('admin.ipam.reservations.store', $r['prefix']), ['address' => '10.80.0.77']),
            'admin.ipam.subscription-addresses.store' => fn () => $this->postJson(route('admin.ipam.subscription-addresses.store', $r['subscription']), ['ip_prefix_id' => $this->pool->id]),
            'admin.ipam.addresses.destroy' => fn () => $this->deleteJson(route('admin.ipam.addresses.destroy', $r['address'])),
            'admin.ipam.vlans.index' => fn () => $this->get(route('admin.ipam.vlans.index')),
            'admin.ipam.vlans.store' => fn () => $this->postJson(route('admin.ipam.vlans.store'), ['vid' => 78, 'name' => 'New']),
            'admin.ipam.vlans.update' => fn () => $this->putJson(route('admin.ipam.vlans.update', $r['vlan']), ['vid' => 901, 'name' => 'Renamed']),
            'admin.ipam.vlans.destroy' => fn () => $this->deleteJson(route('admin.ipam.vlans.destroy', $r['vlan'])),
        ];
    }

    public function test_every_ipam_route_has_an_entry_in_this_matrix(): void
    {
        $registered = collect(Route::getRoutes()->getRoutes())
            ->map(fn ($route) => $route->getName())
            ->filter(fn ($name) => is_string($name) && str_starts_with($name, 'admin.ipam.'))
            ->values()
            ->all();

        $this->assertEqualsCanonicalizing(
            $registered,
            array_keys($this->requests($this->records($this->company, '10.81.0'))),
            'A route was added or removed: give it a tenant and a permission check here.',
        );
    }

    public function test_every_record_bound_ipam_route_is_404_for_another_companys_record(): void
    {
        $foreign = $this->records($this->otherCompany(), '192.0.2');

        foreach ($this->requests($foreign) as $name => $send) {
            if (in_array($name, self::COLLECTION_ROUTES, true)) {
                continue;
            }

            $this->assertSame(404, $send()->getStatusCode(), $name);
        }

        $this->assertDatabaseHas('ip_prefixes', ['id' => $foreign['prefix']->id, 'usage' => 'customer_static']);
        $this->assertDatabaseHas('vlans', ['id' => $foreign['vlan']->id, 'vid' => 900, 'name' => $foreign['vlan']->name]);
        $this->assertDatabaseHas('ip_addresses', ['id' => $foreign['address']->id, 'status' => 'reserved']);
        $this->assertDatabaseCount('ip_addresses', 1);
    }

    public function test_a_user_without_ipam_permissions_gets_403_on_every_route_and_changes_nothing(): void
    {
        $mine = $this->records($this->company, '10.82.0');
        $before = [IpPrefix::query()->count(), Vlan::query()->count(), IpAddress::query()->count()];

        $roleless = User::factory()->create(['company_id' => $this->company->id, 'email_verified_at' => now(), 'is_active' => true]);

        foreach ([$this->makeUser($this->company, 'customer'), $roleless] as $user) {
            $this->actingAs($user);

            foreach ($this->requests($mine) as $name => $send) {
                $this->assertSame(403, $send()->getStatusCode(), "{$name} for user {$user->id}");
            }
        }

        $this->assertSame($before, [IpPrefix::query()->count(), Vlan::query()->count(), IpAddress::query()->count()]);
        $this->assertDatabaseHas('vlans', ['id' => $mine['vlan']->id, 'vid' => 900]);
    }

    public function test_another_companys_id_in_the_body_is_refused_and_nothing_is_written(): void
    {
        $other = $this->otherCompany();
        $foreign = $this->records($other, '192.0.2');
        $foreignPop = $this->makeLocation($other, 'POP-X', 'pop');
        $mine = $this->records($this->company, '10.83.0');
        $counts = fn (): array => [IpPrefix::query()->count(), Vlan::query()->count(), IpAddress::query()->count()];
        $before = $counts();

        $cases = [
            'vlan_id on a new prefix' => [fn () => $this->postJson(route('admin.ipam.prefixes.store'), ['cidr' => '10.84.0.0/24', 'usage' => 'container', 'vlan_id' => $foreign['vlan']->id]), 'vlan_id'],
            'location_id on a new prefix' => [fn () => $this->postJson(route('admin.ipam.prefixes.store'), ['cidr' => '10.84.0.0/24', 'usage' => 'container', 'location_id' => $foreignPop->id]), 'location_id'],
            'vlan_id on a prefix update' => [fn () => $this->putJson(route('admin.ipam.prefixes.update', $mine['prefix']), ['usage' => 'infrastructure', 'vlan_id' => $foreign['vlan']->id]), 'vlan_id'],
            'location_id on a prefix update' => [fn () => $this->putJson(route('admin.ipam.prefixes.update', $mine['prefix']), ['usage' => 'infrastructure', 'location_id' => $foreignPop->id]), 'location_id'],
            'location_id on a new VLAN' => [fn () => $this->postJson(route('admin.ipam.vlans.store'), ['vid' => 78, 'name' => 'New', 'location_id' => $foreignPop->id]), 'location_id'],
            'location_id on a VLAN update' => [fn () => $this->putJson(route('admin.ipam.vlans.update', $mine['vlan']), ['vid' => 901, 'name' => 'Renamed', 'location_id' => $foreignPop->id]), 'location_id'],
            'ip_prefix_id on a static address' => [fn () => $this->postJson(route('admin.ipam.subscription-addresses.store', $mine['subscription']), ['ip_prefix_id' => $foreign['prefix']->id]), 'ip_prefix_id'],
        ];

        foreach ($cases as $label => [$send, $field]) {
            $response = $send();

            $this->assertSame(422, $response->getStatusCode(), $label);
            $response->assertJsonValidationErrors($field);
        }

        $this->assertSame($before, $counts());
        $this->assertDatabaseHas('ip_prefixes', ['id' => $mine['prefix']->id, 'usage' => 'customer_static', 'vlan_id' => null, 'location_id' => null]);
        $this->assertDatabaseHas('vlans', ['id' => $mine['vlan']->id, 'vid' => 900, 'location_id' => null]);
    }
}
