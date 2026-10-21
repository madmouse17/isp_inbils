<?php

namespace Tests\Feature\NetworkAsset;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Core\Location;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\NetworkAsset\Database\Factories\NetworkAssetFactory;
use Modules\NetworkAsset\Models\NetworkAsset;
use Modules\NetworkAsset\Services\NetworkAssetService;
use Modules\SPK\Database\Factories\WorkOrderFactory;
use Tests\TestCase;

/**
 * Lifecycle route coverage: store + maintenance/resume/damage/repair/retire/remove.
 * Guards against the fatals fixed in CLEAN-P0 (generateCode, lifecycle methods, remove() signature).
 */
class NetworkAssetLifecycleRouteTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        app()['cache']->forget('spatie.permission.cache');

        $permissions = [
            'network_asset.create',
            'network_asset.install',
            'network_asset.maintenance',
            'network_asset.repair',
            'network_asset.retire',
            'network_asset.remove',
        ];

        $this->company = Company::factory()->create(['is_active' => true]);
        $this->admin = User::factory()->create([
            'company_id' => $this->company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $this->grantPermissions($this->admin, [...$permissions, 'app.web']);
        $this->actingAs($this->admin);

        $this->location = Location::factory()->create(['company_id' => $this->company->id]);
    }

    private function makeAsset(string $status = 'available'): NetworkAsset
    {
        return NetworkAssetFactory::new()->create([
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'serial_number' => 'SN-'.uniqid(),
            'status' => $status,
        ]);
    }

    public function test_store_generates_code_and_creates_asset(): void
    {
        $response = $this->post(route('admin.network-assets.store'), [
            'name' => 'Router 1',
            'asset_type' => 'router',
        ]);

        $response->assertRedirect(route('admin.network-assets.index'));
        $asset = NetworkAsset::query()->where('name', 'Router 1')->firstOrFail();
        $this->assertNotEmpty($asset->code);
        $this->assertSame('available', $asset->status);
    }

    public function test_maintenance_then_resume_route_happy_path(): void
    {
        $asset = $this->makeAsset('installed');

        $this->post(route('admin.network-assets.maintenance', $asset), ['reason' => 'scheduled check'])
            ->assertRedirect();
        $this->assertSame('maintenance', $asset->fresh()->status);

        $this->post(route('admin.network-assets.resume', $asset))
            ->assertRedirect();
        $this->assertSame('installed', $asset->fresh()->status);
    }

    public function test_maintenance_route_rejects_invalid_transition(): void
    {
        $asset = $this->makeAsset('available');

        $this->post(route('admin.network-assets.maintenance', $asset), ['reason' => 'invalid'])
            ->assertStatus(422);
        $this->assertSame('available', $asset->fresh()->status);
    }

    public function test_damage_then_repair_route_happy_path(): void
    {
        $asset = $this->makeAsset('installed');

        $this->post(route('admin.network-assets.damage', $asset), ['reason' => 'broken'])
            ->assertRedirect();
        $this->assertSame('damaged', $asset->fresh()->status);

        $this->post(route('admin.network-assets.repair', $asset))
            ->assertRedirect();
        $this->assertSame('available', $asset->fresh()->status);
    }

    public function test_repair_route_rejects_invalid_transition(): void
    {
        $asset = $this->makeAsset('available');

        $this->post(route('admin.network-assets.repair', $asset))
            ->assertStatus(422);
    }

    public function test_retire_route_happy_path(): void
    {
        $asset = $this->makeAsset('installed');

        $this->post(route('admin.network-assets.retire', $asset), ['reason' => 'end of life'])
            ->assertRedirect();
        $this->assertSame('retired', $asset->fresh()->status);
        $this->assertNotNull($asset->fresh()->retired_at);
    }

    public function test_retire_route_rejects_already_retired(): void
    {
        $asset = $this->makeAsset('retired');

        $this->post(route('admin.network-assets.retire', $asset), ['reason' => 'again'])
            ->assertStatus(422);
    }

    public function test_remove_route_releases_installed_asset(): void
    {
        $asset = $this->makeAsset('available');
        NetworkAssetService::install($asset, $this->location->id);

        $this->post(route('admin.network-assets.remove', $asset->fresh()), ['reason' => 'decommission'])
            ->assertRedirect();
        $this->assertSame('available', $asset->fresh()->status);
    }

    public function test_remove_route_rejects_non_installed_asset(): void
    {
        $asset = $this->makeAsset('available');

        $this->post(route('admin.network-assets.remove', $asset), ['reason' => 'not installed'])
            ->assertStatus(422);
    }

    public function test_install_route_rejects_soft_deleted_location_without_writing(): void
    {
        $asset = $this->makeAsset('available');
        $this->location->delete();

        $this->post(route('admin.network-assets.install', $asset), [
            'location_id' => $this->location->public_id,
        ])->assertSessionHasErrors('location_id');

        $this->assertSame('available', $asset->fresh()->status);
        $this->assertDatabaseMissing('network_asset_installations', ['network_asset_id' => $asset->id]);
    }

    public function test_install_route_rejects_soft_deleted_customer_without_writing(): void
    {
        $asset = $this->makeAsset('available');
        $customer = Customer::factory()->create(['company_id' => $this->company->id]);
        $customer->delete();

        $this->post(route('admin.network-assets.install', $asset), [
            'location_id' => $this->location->public_id,
            'customer_id' => $customer->public_id,
        ])->assertSessionHasErrors('customer_id');

        $this->assertSame('available', $asset->fresh()->status);
        $this->assertDatabaseMissing('network_asset_installations', ['network_asset_id' => $asset->id]);
    }

    public function test_install_route_rejects_soft_deleted_subscription_without_writing(): void
    {
        $asset = $this->makeAsset('available');
        $customer = Customer::factory()->create(['company_id' => $this->company->id]);
        $subscription = ServiceSubscription::factory()->create([
            'company_id' => $this->company->id,
            'customer_id' => $customer->id,
        ]);
        $subscription->delete();

        $this->post(route('admin.network-assets.install', $asset), [
            'location_id' => $this->location->public_id,
            'customer_id' => $customer->public_id,
            'subscription_id' => $subscription->public_id,
        ])->assertSessionHasErrors('subscription_id');

        $this->assertSame('available', $asset->fresh()->status);
        $this->assertDatabaseMissing('network_asset_installations', ['network_asset_id' => $asset->id]);
    }

    public function test_install_route_rejects_soft_deleted_work_order_without_writing(): void
    {
        $asset = $this->makeAsset('available');
        $customer = Customer::factory()->create(['company_id' => $this->company->id]);
        $workOrder = WorkOrderFactory::new()->create([
            'company_id' => $this->company->id,
            'customer_id' => $customer->id,
            'location_id' => $this->location->id,
            'created_by' => $this->admin->id,
        ]);
        $workOrder->delete();

        $this->post(route('admin.network-assets.install', $asset), [
            'location_id' => $this->location->public_id,
            'customer_id' => $customer->public_id,
            'spk_id' => $workOrder->public_id,
        ])->assertSessionHasErrors('spk_id');

        $this->assertSame('available', $asset->fresh()->status);
        $this->assertDatabaseMissing('network_asset_installations', ['network_asset_id' => $asset->id]);
    }
}
