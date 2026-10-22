<?php

namespace Tests\Feature\NetworkAsset;

use App\Models\Core\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Ipam\Services\IpAssignmentService;
use Modules\NetworkAsset\Database\Factories\NetworkAssetFactory;
use Modules\NetworkAsset\Models\NasDevice;
use Modules\NetworkAsset\Models\NetworkAsset;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;
use Tests\Traits\BuildsNetworkFixtures;
use Tests\Traits\CreatesCompanyUser;
use Tests\Traits\InteractsWithRadius;

/** F2-U08: the NAS form makes the router asset in the same step, so a router never has to be entered twice. */
class NasCreateRouterTest extends TestCase
{
    use BuildsNetworkFixtures;
    use CreatesCompanyUser;
    use InteractsWithRadius;
    use RefreshDatabase;

    private User $admin;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->admin = $this->createCompanyUser();
        $this->company = $this->admin->company;
        $this->grantNetworkPermissionsToAdminRole();
        $this->fakeRadius();
        $this->actingAs($this->admin);
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'new_asset' => ['name' => 'Router Dago Baru', 'management_ip' => '192.0.2.60'],
            'coa_port' => 1700,
            'radius_secret' => 'fake-radius-secret-1',
        ];
    }

    private function nothingWasCreated(): void
    {
        $this->assertSame(0, NetworkAsset::query()->count());
        $this->assertSame(0, NasDevice::query()->count());
        $this->assertSame([], $this->radiusRows('nas'));
    }

    public function test_the_router_asset_and_the_nas_are_made_together_and_written_to_radius(): void
    {
        $this->post(route('admin.nas-devices.store'), $this->payload())
            ->assertRedirect(route('admin.nas-devices.show', NasDevice::query()->firstOrFail()))
            ->assertSessionHas('success', 'Router NAS didaftarkan.');

        $asset = NetworkAsset::query()->sole();
        $nas = NasDevice::query()->sole();
        $this->assertSame(['router', 'available', 'Router Dago Baru', '192.0.2.60'], [$asset->asset_type, $asset->status, $asset->name, $asset->management_ip]);
        $this->assertNotSame('', $asset->code);
        $this->assertSame([$asset->id, '192.0.2.60', 1700], [$nas->network_asset_id, $nas->nasname, $nas->coa_port], 'the NAS address defaults to the management IP');
        $this->assertTrue(DB::table('ip_addresses')->where('network_asset_id', $asset->id)->exists(), 'the address is claimed in IPAM like the asset form does');
        $this->assertNotNull($nas->radius_synced_at);
        $this->assertCount(1, $this->radiusRows('nas', ['nasname' => '192.0.2.60']));
        $this->assertSame(1, Activity::query()->where('log_name', 'nas_device')->where('description', 'created')->count());
    }

    public function test_an_address_typed_for_the_nas_is_kept(): void
    {
        $this->post(route('admin.nas-devices.store'), $this->payload(['nasname' => '198.51.100.61']))->assertRedirect();

        $this->assertSame('198.51.100.61', NasDevice::query()->sole()->nasname);
        $this->assertSame('192.0.2.60', NetworkAsset::query()->sole()->management_ip);
    }

    public function test_an_existing_asset_and_a_new_one_at_once_are_refused(): void
    {
        $existing = NetworkAssetFactory::new()->create(['company_id' => $this->company->id, 'asset_type' => 'router', 'management_ip' => '192.0.2.62', 'ip_address' => null]);

        $this->postJson(route('admin.nas-devices.store'), $this->payload(['network_asset_id' => $existing->public_id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('network_asset_id');

        $this->assertSame(0, NasDevice::query()->count());
        $this->assertSame(1, NetworkAsset::query()->count());
    }

    public function test_the_new_router_needs_a_name_and_an_ipv4_management_address(): void
    {
        $cases = [
            'no name' => [['management_ip' => '192.0.2.63'], 'new_asset.name'],
            'no address' => [['name' => 'Router'], 'new_asset.management_ip'],
            'IPv6' => [['name' => 'Router', 'management_ip' => '2001:db8::1'], 'new_asset.management_ip'],
            'not an address' => [['name' => 'Router', 'management_ip' => 'router.example.net'], 'new_asset.management_ip'],
            'name too long' => [['name' => str_repeat('r', 256), 'management_ip' => '192.0.2.64'], 'new_asset.name'],
        ];

        foreach ($cases as $name => [$newAsset, $field]) {
            $response = $this->postJson(route('admin.nas-devices.store'), $this->payload(['new_asset' => $newAsset]));
            $this->assertSame(422, $response->getStatusCode(), $name);
            $response->assertJsonValidationErrors($field);
        }

        $this->nothingWasCreated();
    }

    public function test_an_empty_or_padded_new_router_block_is_a_422_not_a_500(): void
    {
        foreach (['empty' => [], 'extra key' => ['name' => 'Router', 'management_ip' => '192.0.2.66', 'asset_type' => 'switch']] as $name => $newAsset) {
            $response = $this->postJson(route('admin.nas-devices.store'), $this->payload(['new_asset' => $newAsset, 'nasname' => '198.51.100.66']));
            $this->assertSame(422, $response->getStatusCode(), $name);
            $response->assertJsonValidationErrors('new_asset');
        }

        $this->nothingWasCreated();
    }

    public function test_an_address_ipam_gives_to_something_else_is_refused_and_nothing_is_kept(): void
    {
        $holder = NetworkAssetFactory::new()->create(['company_id' => $this->company->id, 'asset_type' => 'switch', 'management_ip' => '192.0.2.65', 'ip_address' => null]);
        IpAssignmentService::syncAssetAddresses($holder);

        $response = $this->postJson(route('admin.nas-devices.store'), $this->payload(['new_asset' => ['name' => 'Router', 'management_ip' => '192.0.2.65']]));

        $response->assertUnprocessable()->assertJsonValidationErrors('new_asset.management_ip');
        $this->assertSame(1, NetworkAsset::query()->count(), 'the router asset made on the way was rolled back with the NAS');
        $this->assertSame(0, NasDevice::query()->count());
        $this->assertSame([], $this->radiusRows('nas'));
    }

    public function test_an_address_another_nas_holds_is_refused_without_creating_an_asset(): void
    {
        $taken = $this->nasClient($this->company, '192.0.2.66');

        $this->postJson(route('admin.nas-devices.store'), $this->payload(['new_asset' => ['name' => 'Router', 'management_ip' => $taken->nasname]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('nasname');

        $this->assertSame(1, NetworkAsset::query()->count(), 'only the asset of the NAS that already existed');
    }

    public function test_making_the_asset_takes_the_permission_to_create_assets(): void
    {
        $managerOfRouters = $this->userWithPermissions($this->company, 'network_asset.nas.manage');
        $this->actingAs($managerOfRouters);

        $this->postJson(route('admin.nas-devices.store'), $this->payload())->assertForbidden();
        $this->nothingWasCreated();

        $this->actingAs($this->userWithPermissions($this->company, 'network_asset.nas.manage', 'network_asset.create'));
        $this->post(route('admin.nas-devices.store'), $this->payload())->assertRedirect();

        $this->assertSame(1, NasDevice::query()->count());
    }

    public function test_picking_an_existing_asset_still_needs_no_asset_permission(): void
    {
        $asset = NetworkAssetFactory::new()->create(['company_id' => $this->company->id, 'asset_type' => 'router', 'management_ip' => '192.0.2.67']);
        $this->actingAs($this->userWithPermissions($this->company, 'network_asset.nas.manage'));

        $this->post(route('admin.nas-devices.store'), ['network_asset_id' => $asset->public_id, 'radius_secret' => 'fake-radius-secret-3'])->assertRedirect();

        $this->assertSame($asset->id, NasDevice::query()->sole()->network_asset_id);
    }
}
