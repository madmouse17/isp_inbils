<?php

namespace Tests\Feature\Ipam;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Ipam\Models\IpAddress;
use Modules\Ipam\Models\IpPrefix;
use Modules\Ipam\Models\Vlan;
use Modules\Ipam\Services\IpAssignmentService;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;
use Tests\Traits\BuildsIpamOdnFixtures;

class IpPrefixTest extends TestCase
{
    use BuildsIpamOdnFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->bootIpamOdnWorld();
    }

    /** @param array<string, mixed> $extra */
    private function register(string $cidr, string $usage = 'customer_static', array $extra = []): TestResponse
    {
        return $this->postJson(route('admin.ipam.prefixes.store'), ['cidr' => $cidr, 'usage' => $usage, ...$extra]);
    }

    private function prefix(string $cidr): IpPrefix
    {
        return IpPrefix::query()->where('cidr', $cidr)->firstOrFail();
    }

    public function test_registering_a_prefix_stores_the_normalised_cidr_and_its_range(): void
    {
        $pop = $this->makeLocation($this->company, 'POP-A', 'pop');
        $vlan = Vlan::factory()->create(['vid' => 120]);

        $this->register('10.20.0.5/24', 'customer_static', ['location_id' => $pop->id, 'vlan_id' => $vlan->id, 'description' => 'Customers POP A'])
            ->assertRedirect(route('admin.ipam.prefixes.index'));

        $prefix = $this->prefix('10.20.0.0/24');
        $this->assertSame(4, $prefix->family);
        $this->assertSame(24, $prefix->prefix_length);
        $this->assertSame('010.020.000.000', $prefix->range_from);
        $this->assertSame('010.020.000.255', $prefix->range_to);
        $this->assertSame('customer_static', $prefix->usage);
        $this->assertSame($pop->id, $prefix->location_id);
        $this->assertSame($vlan->id, $prefix->vlan_id);
        $this->assertNull($prefix->parent_id);
        $this->assertSame($this->company->id, $prefix->company_id);
    }

    public function test_an_ipv6_prefix_is_stored_in_its_normalised_form(): void
    {
        $this->register('2001:DB8:0::/48', 'container')->assertRedirect();

        $prefix = $this->prefix('2001:db8::/48');
        $this->assertSame(6, $prefix->family);
        $this->assertSame('2001:0db8:0000:0000:0000:0000:0000:0000', $prefix->range_from);
        $this->assertSame('2001:0db8:0000:ffff:ffff:ffff:ffff:ffff', $prefix->range_to);
        $this->assertDatabaseMissing('ip_prefixes', ['cidr' => '2001:DB8:0::/48']);
    }

    /** @return array<string, array{0: string}> */
    public static function invalidPrefixes(): array
    {
        return [
            'bare address' => ['10.0.0.1'],
            'length too long' => ['10.0.0.0/33'],
            'not an address' => ['not-an-ip'],
            'trailing text' => ['10.0.0.0/24 extra'],
            'ipv6 length too long' => ['2001:db8::/129'],
        ];
    }

    #[DataProvider('invalidPrefixes')]
    public function test_an_invalid_prefix_is_422_and_writes_nothing(string $cidr): void
    {
        $this->register($cidr)->assertStatus(422)->assertJsonValidationErrors('cidr');

        $this->assertDatabaseCount('ip_prefixes', 0);
    }

    public function test_a_new_prefix_nests_under_the_smallest_containing_prefix(): void
    {
        $this->register('10.20.0.0/16', 'container');
        $this->register('10.20.0.0/22', 'container');
        $this->register('10.20.1.0/24');
        $this->register('10.99.0.0/24');

        $this->assertNull($this->prefix('10.20.0.0/16')->parent_id);
        $this->assertSame($this->prefix('10.20.0.0/16')->id, $this->prefix('10.20.0.0/22')->parent_id);
        $this->assertSame($this->prefix('10.20.0.0/22')->id, $this->prefix('10.20.1.0/24')->parent_id);
        $this->assertNull($this->prefix('10.99.0.0/24')->parent_id);
    }

    public function test_a_new_supernet_adopts_the_prefixes_and_addresses_it_contains(): void
    {
        $this->register('10.20.0.0/16', 'container');
        $this->register('10.20.0.0/25');
        $this->register('10.20.1.0/24');
        $this->register('10.20.3.0/24');
        $container = $this->prefix('10.20.0.0/16');
        $reservation = IpAssignmentService::reserve($container, '10.20.1.200', 'switch');
        $outside = IpAssignmentService::reserve($container, '10.20.9.9');

        $this->assertSame($this->prefix('10.20.1.0/24')->id, $reservation->fresh()->ip_prefix_id);

        $this->register('10.20.0.0/23', 'container')->assertRedirect();

        $supernet = $this->prefix('10.20.0.0/23');
        $this->assertSame($container->id, $supernet->parent_id);
        $this->assertSame($supernet->id, $this->prefix('10.20.0.0/25')->parent_id);
        $this->assertSame($supernet->id, $this->prefix('10.20.1.0/24')->parent_id);
        $this->assertSame($container->id, $this->prefix('10.20.3.0/24')->parent_id, 'a prefix outside the new block keeps its parent');
        $this->assertSame($this->prefix('10.20.1.0/24')->id, $reservation->fresh()->ip_prefix_id, 'an address already in a nested prefix stays there');
        $this->assertSame($container->id, $outside->fresh()->ip_prefix_id);
    }

    public function test_an_address_registered_before_its_prefix_is_adopted_by_it(): void
    {
        $asset = $this->makeAsset($this->company, 'router', null, ['management_ip' => '10.50.0.5']);
        IpAssignmentService::syncAssetAddresses($asset);
        $this->assertNull(IpAddress::query()->where('address', '10.50.0.5')->value('ip_prefix_id'));

        $this->register('10.50.0.0/24', 'infrastructure')->assertRedirect();

        $this->assertSame($this->prefix('10.50.0.0/24')->id, IpAddress::query()->where('address', '10.50.0.5')->value('ip_prefix_id'));
    }

    public function test_a_duplicate_prefix_is_422_even_with_host_bits_set(): void
    {
        $this->register('10.20.0.0/24')->assertRedirect();

        $this->register('10.20.0.0/24')->assertStatus(422)->assertJsonValidationErrors('cidr');
        $this->register('10.20.0.77/24')->assertStatus(422)->assertJsonValidationErrors('cidr');

        $this->assertDatabaseCount('ip_prefixes', 1);
    }

    /**
     * CIDR blocks either nest or are disjoint, so no valid input can partially overlap another prefix.
     * The guard exists for rows that are not CIDR-aligned (corrupt or imported by hand).
     */
    public function test_a_prefix_that_partially_overlaps_a_non_aligned_row_is_422(): void
    {
        IpPrefix::factory()->cidr('10.30.0.128/25')->usage('container')->create([
            'range_from' => '010.030.000.128',
            'range_to' => '010.030.001.127',
        ]);

        $this->register('10.30.0.0/24')->assertStatus(422)->assertJsonValidationErrors('cidr');

        $this->assertDatabaseCount('ip_prefixes', 1);
    }

    public function test_the_same_prefix_can_be_registered_by_two_companies(): void
    {
        $this->register('10.20.0.0/24')->assertRedirect();
        $other = $this->otherCompany();
        $this->inCompany($other, fn () => IpPrefix::factory()->cidr('10.20.0.0/24')->create(['company_id' => $other->id]));

        $this->assertSame(2, IpPrefix::withoutCompany()->where('cidr', '10.20.0.0/24')->count());
    }

    public function test_update_changes_usage_pop_vlan_and_description_but_never_the_cidr(): void
    {
        $this->register('10.20.0.0/24')->assertRedirect();
        $prefix = $this->prefix('10.20.0.0/24');
        $pop = $this->makeLocation($this->company, 'POP-B', 'pop');
        $vlan = Vlan::factory()->create();

        $this->putJson(route('admin.ipam.prefixes.update', $prefix), [
            'cidr' => '172.16.0.0/12',
            'usage' => 'customer_dynamic',
            'location_id' => $pop->id,
            'vlan_id' => $vlan->id,
            'description' => 'Dynamic pool',
        ])->assertRedirect();

        $prefix->refresh();
        $this->assertSame('10.20.0.0/24', $prefix->cidr);
        $this->assertSame('customer_dynamic', $prefix->usage);
        $this->assertSame($pop->id, $prefix->location_id);
        $this->assertSame($vlan->id, $prefix->vlan_id);
        $this->assertSame('Dynamic pool', $prefix->description);
    }

    public function test_a_prefix_cannot_be_tied_to_another_companys_pop_or_vlan(): void
    {
        $other = $this->otherCompany();
        $foreignPop = $this->makeLocation($other, 'POP-X', 'pop');
        $foreignVlan = $this->inCompany($other, fn () => Vlan::factory()->create(['company_id' => $other->id]));

        $this->register('10.20.0.0/24', 'customer_static', ['location_id' => $foreignPop->id])
            ->assertStatus(422)->assertJsonValidationErrors('location_id');
        $this->register('10.20.0.0/24', 'customer_static', ['vlan_id' => $foreignVlan->id])
            ->assertStatus(422)->assertJsonValidationErrors('vlan_id');

        $this->assertDatabaseCount('ip_prefixes', 0);
    }

    public function test_a_leaf_prefix_can_be_deleted(): void
    {
        $this->register('10.20.0.0/24')->assertRedirect();
        $prefix = $this->prefix('10.20.0.0/24');

        $this->deleteJson(route('admin.ipam.prefixes.destroy', $prefix))->assertRedirect(route('admin.ipam.prefixes.index'));

        $this->assertDatabaseMissing('ip_prefixes', ['id' => $prefix->id]);
    }

    public function test_deleting_a_prefix_with_children_is_422(): void
    {
        $this->register('10.20.0.0/16', 'container');
        $this->register('10.20.0.0/24');
        $container = $this->prefix('10.20.0.0/16');

        $this->deleteJson(route('admin.ipam.prefixes.destroy', $container))->assertStatus(422)->assertJsonValidationErrors('prefix');

        $this->assertDatabaseHas('ip_prefixes', ['id' => $container->id]);
    }

    public function test_deleting_a_prefix_with_addresses_is_422(): void
    {
        $this->register('10.20.0.0/24', 'infrastructure');
        $prefix = $this->prefix('10.20.0.0/24');
        IpAssignmentService::reserve($prefix, '10.20.0.1', 'gateway');

        $this->deleteJson(route('admin.ipam.prefixes.destroy', $prefix))->assertStatus(422)->assertJsonValidationErrors('prefix');

        $this->assertDatabaseHas('ip_prefixes', ['id' => $prefix->id]);
    }

    public function test_every_prefix_route_is_404_for_another_companys_prefix(): void
    {
        $other = $this->otherCompany();
        $foreign = $this->inCompany($other, fn () => IpPrefix::factory()->cidr('192.0.2.0/24')->create(['company_id' => $other->id]));

        $this->get(route('admin.ipam.prefixes.show', $foreign))->assertNotFound();
        $this->putJson(route('admin.ipam.prefixes.update', $foreign), ['usage' => 'container'])->assertNotFound();
        $this->deleteJson(route('admin.ipam.prefixes.destroy', $foreign))->assertNotFound();
        $this->postJson(route('admin.ipam.reservations.store', $foreign), ['address' => '192.0.2.9'])->assertNotFound();

        $this->assertDatabaseHas('ip_prefixes', ['id' => $foreign->id, 'usage' => 'customer_static']);
    }

    public function test_prefix_changes_are_written_to_the_activity_log_with_the_actor(): void
    {
        $this->register('10.20.0.0/24')->assertRedirect();
        $prefix = $this->prefix('10.20.0.0/24');
        $this->putJson(route('admin.ipam.prefixes.update', $prefix), ['usage' => 'infrastructure'])->assertRedirect();

        $log = Activity::query()->where('log_name', 'ip_prefix')->where('subject_id', $prefix->id)->orderBy('id')->get();

        $this->assertSame(['created', 'updated'], $log->pluck('description')->all());
        $this->assertSame($this->admin->id, $log->first()->causer_id);
        $this->assertSame($this->company->id, (int) $log->first()->company_id);
    }

    public function test_the_index_lists_flat_rows_in_tree_order_with_depth_and_filters(): void
    {
        $pop = $this->makeLocation($this->company, 'POP-A', 'pop');
        $this->register('10.20.0.0/16', 'container');
        $this->register('10.20.0.0/22', 'customer_static', ['location_id' => $pop->id]);
        $this->register('10.20.4.0/24', 'customer_dynamic');
        $this->register('2001:db8::/48', 'container');

        $this->get(route('admin.ipam.prefixes.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Ipam/Prefixes/Index', false)
                ->has('prefixes', 4)
                ->where('prefixes.0.cidr', '10.20.0.0/16')
                ->where('prefixes.0.depth', 0)
                ->where('prefixes.1.cidr', '10.20.0.0/22')
                ->where('prefixes.1.depth', 1)
                ->where('prefixes.1.location.code', 'POP-A')
                ->where('prefixes.2.cidr', '10.20.4.0/24')
                ->where('prefixes.2.depth', 1)
                ->where('prefixes.3.cidr', '2001:db8::/48')
                ->where('prefixes.3.family', 6)
                ->where('can.manage', true)
                ->has('locations', 1)
                ->has('vlans', 0));

        $this->get(route('admin.ipam.prefixes.index', ['family' => 6]))
            ->assertInertia(fn (Assert $page) => $page->has('prefixes', 1)->where('filters.family', '6'));
        $this->get(route('admin.ipam.prefixes.index', ['usage' => 'customer_dynamic']))
            ->assertInertia(fn (Assert $page) => $page->has('prefixes', 1)->where('prefixes.0.cidr', '10.20.4.0/24'));
        $this->get(route('admin.ipam.prefixes.index', ['location_id' => $pop->id]))
            ->assertInertia(fn (Assert $page) => $page->has('prefixes', 1)->where('prefixes.0.cidr', '10.20.0.0/22'));
        $this->get(route('admin.ipam.prefixes.index', ['search' => '2001']))
            ->assertInertia(fn (Assert $page) => $page->has('prefixes', 1)->where('prefixes.0.cidr', '2001:db8::/48'));
    }

    public function test_an_array_search_is_ignored_instead_of_failing(): void
    {
        $this->register('10.20.0.0/24');

        $this->get(route('admin.ipam.prefixes.index', ['search' => ['10.20']]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('prefixes', 1));
    }

    public function test_the_index_hides_another_companys_prefixes(): void
    {
        $other = $this->otherCompany();
        $this->inCompany($other, fn () => IpPrefix::factory()->cidr('192.0.2.0/24')->create(['company_id' => $other->id]));
        $this->register('10.20.0.0/24');

        $this->get(route('admin.ipam.prefixes.index'))
            ->assertInertia(fn (Assert $page) => $page->has('prefixes', 1)->where('prefixes.0.cidr', '10.20.0.0/24'));
    }

    public function test_the_show_page_lists_children_and_paginated_addresses_with_their_holders(): void
    {
        $this->register('10.20.0.0/16', 'container');
        $this->register('10.20.0.0/24', 'infrastructure');
        $container = $this->prefix('10.20.0.0/16');
        $router = $this->makeAsset($this->company, 'router', null, ['management_ip' => '10.20.0.1']);
        IpAssignmentService::syncAssetAddresses($router);
        IpAssignmentService::reserve($container, '10.20.9.9', 'spare');

        $this->get(route('admin.ipam.prefixes.show', $container))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Ipam/Prefixes/Show', false)
                ->where('prefix.cidr', '10.20.0.0/16')
                ->where('prefix.parent', null)
                ->has('children', 1)
                ->where('children.0.cidr', '10.20.0.0/24')
                ->where('children.0.depth', 1)
                ->has('addresses.data', 2)
                ->where('addresses.data.0.address', '10.20.0.1')
                ->where('addresses.data.0.holder.type', 'asset')
                ->where('addresses.data.0.holder.id', $router->id)
                ->where('addresses.data.0.holder.href', route('admin.network-assets.show', $router, false))
                ->where('addresses.data.1.address', '10.20.9.9')
                ->where('addresses.data.1.holder', null)
                ->where('addresses.data.1.note', 'spare')
                ->where('addresses.meta.per_page', 25)
                ->where('can.manage', true)
                ->where('can.assign', true));

        $this->get(route('admin.ipam.prefixes.show', $this->prefix('10.20.0.0/24')))
            ->assertInertia(fn (Assert $page) => $page
                ->where('prefix.parent.cidr', '10.20.0.0/16')
                ->where('prefix.depth', 1));
    }

    public function test_the_show_page_carries_the_pop_and_vlan_pick_lists_of_the_edit_form(): void
    {
        $pop = $this->makeLocation($this->company, 'POP-A', 'pop');
        $this->makeLocation($this->company, 'SITE-A1', 'site', $pop);
        $this->makeLocation($this->otherCompany(), 'POP-X', 'pop');
        $vlan = Vlan::factory()->create(['vid' => 120, 'name' => 'Customers', 'location_id' => $pop->id]);
        Vlan::factory()->create(['vid' => 110, 'name' => 'Mgmt']);
        $this->register('10.20.0.0/24')->assertRedirect();

        $this->get(route('admin.ipam.prefixes.show', $this->prefix('10.20.0.0/24')))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('locations', 1)
                ->where('locations.0', ['id' => $pop->id, 'code' => 'POP-A', 'name' => 'POP-A'])
                ->has('vlans', 2)
                ->where('vlans.0', ['id' => Vlan::query()->where('vid', 110)->value('id'), 'vid' => 110, 'name' => 'Mgmt', 'location_code' => null])
                ->where('vlans.1', ['id' => $vlan->id, 'vid' => 120, 'name' => 'Customers', 'location_code' => 'POP-A']));
    }
}
