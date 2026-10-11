<?php

namespace Tests\Feature\Ipam;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Ipam\Models\IpPrefix;
use Modules\Ipam\Models\Vlan;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Traits\BuildsIpamOdnFixtures;

class VlanTest extends TestCase
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
    private function register(int $vid, array $extra = []): TestResponse
    {
        return $this->postJson(route('admin.ipam.vlans.store'), ['vid' => $vid, 'name' => "VLAN {$vid}", ...$extra]);
    }

    public function test_a_company_wide_vlan_is_registered(): void
    {
        $this->register(100, ['description' => 'Management'])->assertRedirect(route('admin.ipam.vlans.index'));

        $vlan = Vlan::query()->where('vid', 100)->firstOrFail();
        $this->assertNull($vlan->location_id);
        $this->assertSame('VLAN 100', $vlan->name);
        $this->assertSame('Management', $vlan->description);
        $this->assertSame($this->company->id, $vlan->company_id);
    }

    public function test_a_duplicate_vid_in_the_same_pop_is_422(): void
    {
        $pop = $this->makeLocation($this->company, 'POP-A', 'pop');
        $this->register(200, ['location_id' => $pop->id])->assertRedirect();

        $this->register(200, ['location_id' => $pop->id])->assertStatus(422)->assertJsonValidationErrors('vid');

        $this->assertSame(1, Vlan::query()->where('vid', 200)->count());
    }

    public function test_the_same_vid_in_another_pop_is_fine(): void
    {
        $a = $this->makeLocation($this->company, 'POP-A', 'pop');
        $b = $this->makeLocation($this->company, 'POP-B', 'pop');

        $this->register(200, ['location_id' => $a->id])->assertRedirect();
        $this->register(200, ['location_id' => $b->id])->assertRedirect();

        $this->assertSame(2, Vlan::query()->where('vid', 200)->count());
    }

    public function test_a_duplicate_company_wide_vid_is_422_although_the_index_treats_null_as_distinct(): void
    {
        $this->register(300)->assertRedirect();

        $this->register(300)->assertStatus(422)->assertJsonValidationErrors('vid');

        $this->assertSame(1, Vlan::query()->where('vid', 300)->count());
    }

    public function test_a_company_wide_vid_and_a_pop_vid_do_not_collide(): void
    {
        $pop = $this->makeLocation($this->company, 'POP-A', 'pop');

        $this->register(400)->assertRedirect();
        $this->register(400, ['location_id' => $pop->id])->assertRedirect();

        $this->assertSame(2, Vlan::query()->where('vid', 400)->count());
    }

    public function test_two_companies_can_both_register_a_vid(): void
    {
        $this->register(500)->assertRedirect();
        $other = $this->otherCompany();
        $this->inCompany($other, fn () => Vlan::factory()->create(['company_id' => $other->id, 'vid' => 500]));

        $this->assertSame(2, Vlan::withoutCompany()->where('vid', 500)->count());
    }

    /** @return array<string, array{0: int|string}> */
    public static function invalidVids(): array
    {
        return ['zero' => [0], 'above 4094' => [4095], 'negative' => [-5], 'text' => ['abc']];
    }

    #[DataProvider('invalidVids')]
    public function test_a_vid_outside_1_to_4094_is_422(int|string $vid): void
    {
        $this->postJson(route('admin.ipam.vlans.store'), ['vid' => $vid, 'name' => 'x'])->assertStatus(422)->assertJsonValidationErrors('vid');

        $this->assertDatabaseCount('vlans', 0);
    }

    public function test_the_edge_vids_1_and_4094_are_accepted(): void
    {
        $this->register(1)->assertRedirect();
        $this->register(4094)->assertRedirect();

        $this->assertSame([1, 4094], Vlan::query()->orderBy('vid')->pluck('vid')->all());
    }

    public function test_a_vlan_cannot_be_tied_to_another_companys_pop(): void
    {
        $foreignPop = $this->makeLocation($this->otherCompany(), 'POP-X', 'pop');

        $this->register(600, ['location_id' => $foreignPop->id])->assertStatus(422)->assertJsonValidationErrors('location_id');
    }

    public function test_update_changes_the_vlan_and_rechecks_uniqueness_ignoring_itself(): void
    {
        $this->register(700)->assertRedirect();
        $this->register(701)->assertRedirect();
        $vlan = Vlan::query()->where('vid', 700)->firstOrFail();

        $this->putJson(route('admin.ipam.vlans.update', $vlan), ['vid' => 700, 'name' => 'Renamed', 'description' => 'x'])->assertRedirect();
        $this->assertSame('Renamed', $vlan->fresh()->name);

        $this->putJson(route('admin.ipam.vlans.update', $vlan), ['vid' => 701, 'name' => 'Clash'])->assertStatus(422)->assertJsonValidationErrors('vid');
        $this->assertSame(700, $vlan->fresh()->vid);
    }

    public function test_a_vlan_used_by_a_prefix_cannot_be_deleted(): void
    {
        $vlan = Vlan::factory()->create(['vid' => 800]);
        IpPrefix::factory()->cidr('10.20.0.0/24')->create(['vlan_id' => $vlan->id]);

        $this->deleteJson(route('admin.ipam.vlans.destroy', $vlan))->assertStatus(422)->assertJsonValidationErrors('vlan');

        $this->assertDatabaseHas('vlans', ['id' => $vlan->id]);
    }

    public function test_an_unused_vlan_is_deleted(): void
    {
        $vlan = Vlan::factory()->create(['vid' => 801]);

        $this->deleteJson(route('admin.ipam.vlans.destroy', $vlan))->assertRedirect(route('admin.ipam.vlans.index'));

        $this->assertDatabaseMissing('vlans', ['id' => $vlan->id]);
    }

    public function test_every_vlan_route_is_404_for_another_companys_vlan(): void
    {
        $other = $this->otherCompany();
        $foreign = $this->inCompany($other, fn () => Vlan::factory()->create(['company_id' => $other->id, 'vid' => 900]));

        $this->putJson(route('admin.ipam.vlans.update', $foreign), ['vid' => 901, 'name' => 'x'])->assertNotFound();
        $this->deleteJson(route('admin.ipam.vlans.destroy', $foreign))->assertNotFound();

        $this->assertDatabaseHas('vlans', ['id' => $foreign->id, 'vid' => 900]);
    }

    public function test_the_index_is_paginated_with_pop_code_and_prefix_count(): void
    {
        $pop = $this->makeLocation($this->company, 'POP-A', 'pop');
        $other = $this->otherCompany();
        $this->inCompany($other, fn () => Vlan::factory()->create(['company_id' => $other->id, 'vid' => 4000]));
        $this->register(10, ['location_id' => $pop->id, 'description' => 'Voice']);
        $this->register(20);
        $used = Vlan::query()->where('vid', 20)->firstOrFail();
        IpPrefix::factory()->cidr('10.20.0.0/24')->create(['vlan_id' => $used->id]);
        IpPrefix::factory()->cidr('10.21.0.0/24')->create(['vlan_id' => $used->id]);

        $this->get(route('admin.ipam.vlans.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Ipam/Vlans/Index', false)
                ->has('vlans.data', 2)
                ->where('vlans.data.0.vid', 10)
                ->where('vlans.data.0.location.code', 'POP-A')
                ->where('vlans.data.0.description', 'Voice')
                ->where('vlans.data.0.prefixes_count', 0)
                ->where('vlans.data.1.vid', 20)
                ->where('vlans.data.1.location', null)
                ->where('vlans.data.1.prefixes_count', 2)
                ->where('vlans.meta.per_page', 25)
                ->has('locations', 1)
                ->where('can.manage', true));
    }
}
