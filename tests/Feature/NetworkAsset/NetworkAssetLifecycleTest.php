<?php

namespace Tests\Feature\NetworkAsset;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Core\CustomerAddress;
use App\Models\Core\Location;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Modules\Inventory\Models\Product;
use Modules\Ipam\Models\IpAddress;
use Modules\Ipam\Models\IpPrefix;
use Modules\Ipam\Models\Vlan;
use Modules\Ipam\Services\IpAssignmentService;
use Modules\NetworkAsset\Database\Factories\NetworkAssetFactory;
use Modules\NetworkAsset\Models\NasDevice;
use Modules\NetworkAsset\Models\NetworkAsset;
use Modules\NetworkAsset\Models\NetworkAssetInstallation;
use Modules\NetworkAsset\Models\OdnPort;
use Modules\NetworkAsset\Models\OdnSplitter;
use Modules\Service\Models\ServicePackage;
use Modules\SPK\Database\Factories\WorkOrderFactory;
use Modules\SPK\Models\WorkOrderItem;
use Modules\Ticketing\Models\Ticket;
use Modules\Ticketing\Models\TicketCategory;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Network assets under the master lifecycle (spec .scratch/master-lifecycle): an asset that anything ever pointed at
 * is never deleted (it is retired instead), an asset nothing points at is deleted for good and gives its addresses
 * back, the status filter reaches the deleted rows and Pulihkan brings one back. The relations of the network and IPAM
 * models keep resolving a parent that was deleted since.
 */
class NetworkAssetLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    private Location $location;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        app()['cache']->forget('spatie.permission.cache');

        $this->company = Company::factory()->create(['is_active' => true]);
        $this->admin = $this->user('admin');
        $this->location = Location::factory()->create(['company_id' => $this->company->id]);
        $this->actingAs($this->admin);
    }

    /** @return array<string, array{string, string}> the label the refusal names, and the fixture that gives an asset one such row */
    public static function referencingRows(): array
    {
        return [
            'installation history' => ['riwayat pemasangan', 'installationHistory'],
            'work order item' => ['item SPK', 'workOrderItem'],
            'ticket' => ['tiket', 'ticket'],
            'ticket in the trash' => ['tiket', 'trashedTicket'],
            'ODN splitter' => ['splitter ODN', 'splitter'],
            'ODN port' => ['port ODN', 'port'],
            'NAS device' => ['perangkat NAS', 'nasDevice'],
            'subscription served by the ONT' => ['langganan', 'ontSubscription'],
        ];
    }

    /** @return array<string, array{string}> */
    public static function statusesThatBlockADelete(): array
    {
        return ['installed' => ['installed'], 'in maintenance' => ['maintenance']];
    }

    /** @return array<string, array{class-string, string}> every relation W2-NETWORK-BE lets resolve a deleted parent */
    public static function historyRelations(): array
    {
        return [
            'asset product' => [NetworkAsset::class, 'product'],
            'asset location' => [NetworkAsset::class, 'location'],
            'asset customer' => [NetworkAsset::class, 'customer'],
            'asset subscription' => [NetworkAsset::class, 'subscription'],
            'installation location' => [NetworkAssetInstallation::class, 'location'],
            'installation customer' => [NetworkAssetInstallation::class, 'customer'],
            'installation subscription' => [NetworkAssetInstallation::class, 'subscription'],
            'installation work order' => [NetworkAssetInstallation::class, 'workOrder'],
            'NAS device asset' => [NasDevice::class, 'networkAsset'],
            'ODN port asset' => [OdnPort::class, 'asset'],
            'ODN port subscription' => [OdnPort::class, 'subscription'],
            'ODN splitter asset' => [OdnSplitter::class, 'asset'],
            'IP address subscription' => [IpAddress::class, 'subscription'],
            'IP address asset' => [IpAddress::class, 'networkAsset'],
            'IP prefix location' => [IpPrefix::class, 'location'],
            'VLAN location' => [Vlan::class, 'location'],
        ];
    }

    public function test_an_asset_with_installation_history_is_refused_and_keeps_its_addresses(): void
    {
        $asset = $this->asset(['ip_address' => '10.80.0.1']);
        IpAssignmentService::syncAssetAddresses($asset);
        $this->installationHistory($asset);

        $this->deleteJson(route('admin.network-assets.destroy', $asset))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lifecycle' => 'Aset jaringan masih dipakai 1 riwayat pemasangan, jadi tidak bisa dihapus.']);

        $this->assertDatabaseHas('network_assets', ['id' => $asset->id, 'deleted_at' => null]);
        $this->assertSame(['10.80.0.1'], $this->heldBy($asset));
    }

    public function test_an_unreferenced_asset_releases_its_addresses_and_is_hard_deleted(): void
    {
        $asset = $this->asset(['ip_address' => '10.80.8.1', 'management_ip' => '10.80.8.2']);
        IpAssignmentService::syncAssetAddresses($asset);
        $this->assertCount(2, $this->heldBy($asset));

        $this->delete(route('admin.network-assets.destroy', $asset))->assertSessionHas('success', 'Aset jaringan dihapus permanen.');

        $this->assertDatabaseMissing('network_assets', ['id' => $asset->id]);
        $this->assertDatabaseCount('ip_addresses', 0);
        $audit = Activity::query()->where('log_name', 'network_asset')->where('description', 'force_deleted')->sole();
        $this->assertSame($asset->public_id, $audit->properties['id']);

        $second = $this->asset(['ip_address' => '10.80.8.1']);
        IpAssignmentService::syncAssetAddresses($second);
        $this->assertSame(['10.80.8.1'], $this->heldBy($second), 'the address is free for another asset');
    }

    #[DataProvider('statusesThatBlockADelete')]
    public function test_an_installed_or_maintained_asset_is_refused_with_the_status_message(string $status): void
    {
        $asset = $this->asset(['ip_address' => '10.80.9.1', 'status' => $status]);
        IpAssignmentService::syncAssetAddresses($asset);

        $this->deleteJson(route('admin.network-assets.destroy', $asset))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lifecycle' => 'Aset yang terpasang atau dalam perawatan tidak dapat dihapus. Lepas dulu.']);

        $this->assertDatabaseHas('network_assets', ['id' => $asset->id, 'deleted_at' => null]);
        $this->assertSame(['10.80.9.1'], $this->heldBy($asset));
    }

    #[DataProvider('referencingRows')]
    public function test_every_declared_reference_keeps_the_asset(string $label, string $fixture): void
    {
        $asset = $this->asset();
        $this->{$fixture}($asset);

        $this->deleteJson(route('admin.network-assets.destroy', $asset))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lifecycle' => "Aset jaringan masih dipakai 1 {$label}, jadi tidak bisa dihapus."]);

        $this->assertDatabaseHas('network_assets', ['id' => $asset->id, 'deleted_at' => null]);
    }

    public function test_the_status_filter_lists_deleted_assets_and_restore_brings_one_back(): void
    {
        $this->asset(['name' => 'Router aktif']);
        $this->asset(['name' => 'Router terpasang', 'status' => 'installed']);
        $gone = $this->asset(['name' => 'Router hilang']);
        $gone->delete();

        $this->assertSame(['Router aktif', 'Router terpasang'], $this->listed());
        $this->assertSame(['Router aktif', 'Router terpasang'], $this->listed('bogus'), 'an unknown value is the default view');
        $this->assertSame(['Router aktif'], $this->listed('available'));
        $this->assertSame(['Router terpasang'], $this->listed('installed'));
        $this->assertSame(['Router hilang'], $this->listed('dihapus'));
        $this->assertSame(['Router aktif', 'Router hilang', 'Router terpasang'], $this->listed('semua'));
        $this->assertSame(['available', 'installed', 'maintenance', 'damaged', 'retired'], NetworkAsset::STATUSES);

        $this->get(route('admin.network-assets.show', $gone->public_id))->assertNotFound();

        $this->from('/back')->post(route('admin.network-assets.restore', $gone->public_id))
            ->assertRedirect('/back')->assertSessionHas('success', 'Aset jaringan dipulihkan.');

        $this->assertNull($gone->fresh()->deleted_at);
        $this->assertSame(['Router aktif', 'Router hilang', 'Router terpasang'], $this->listed());
        $this->assertSame([], $this->listed('dihapus'));
        $restored = Activity::query()->where('log_name', 'network_asset')->where('description', 'restored')->get();
        $this->assertTrue($restored->contains(fn (Activity $activity): bool => $activity->properties->get('id') === $gone->public_id), 'Pulihkan is audited');
    }

    public function test_the_index_carries_the_row_state_and_what_the_user_may_do(): void
    {
        $this->asset(['name' => 'Router aktif']);
        $this->asset(['name' => 'Router hilang'])->delete();

        $props = $this->props($this->get(route('admin.network-assets.index', ['status' => 'semua'])));
        $rows = collect($props['assets']['data'])->keyBy('name');

        $this->assertSame('semua', $props['filters']['status']);
        $this->assertNull($rows['Router aktif']['deleted_at']);
        $this->assertNotNull($rows['Router hilang']['deleted_at']);
        $this->assertNull($rows['Router aktif']['references'], 'the index sends no counts, so it offers no delete');
        $this->assertTrue($props['can']['update']);
        $this->assertTrue($props['can']['delete']);

        $limited = $this->props($this->actingAs($this->user('asset-viewer', ['network_asset.view']))->get(route('admin.network-assets.index')));
        $this->assertFalse($limited['can']['update']);
        $this->assertFalse($limited['can']['delete']);
    }

    public function test_restore_needs_the_delete_permission_and_an_asset_is_retired_never_deactivated(): void
    {
        $gone = $this->asset();
        $gone->delete();
        $updater = $this->user('asset-updater', ['network_asset.view', 'network_asset.update']);
        $deleter = $this->user('asset-deleter', ['network_asset.view', 'network_asset.delete']);
        $restore = route('admin.network-assets.restore', $gone->public_id);

        $this->actingAs($updater)->postJson($restore)->assertForbidden();
        $this->assertSoftDeleted($gone);

        $this->actingAs($deleter)->postJson($restore)->assertRedirect();
        $this->assertNotSoftDeleted($gone);

        $this->assertTrue(Route::has('admin.network-assets.restore'));
        $this->assertFalse(Route::has('admin.network-assets.deactivate'));
        $this->assertFalse(Route::has('admin.network-assets.activate'));
    }

    public function test_another_company_asset_is_not_reachable_through_restore_or_destroy(): void
    {
        $other = Company::factory()->create(['is_active' => true]);
        $foreign = NetworkAssetFactory::new()->create(['company_id' => $other->id, 'status' => 'available']);
        $foreignGone = NetworkAssetFactory::new()->create(['company_id' => $other->id, 'status' => 'available']);
        $foreignGone->delete();

        $this->postJson(route('admin.network-assets.restore', $foreignGone->public_id))->assertNotFound();
        $this->deleteJson(route('admin.network-assets.destroy', $foreign->public_id))->assertNotFound();

        $this->assertSoftDeleted($foreignGone);
        $this->assertNotSoftDeleted($foreign);
    }

    public function test_an_asset_still_resolves_the_customer_subscription_location_and_product_that_were_deleted(): void
    {
        $subscription = $this->subscription();
        $product = Product::factory()->create(['company_id' => $this->company->id]);
        $asset = $this->asset([
            'status' => 'installed',
            'customer_id' => $subscription->customer_id,
            'subscription_id' => $subscription->id,
            'location_id' => $this->location->id,
            'product_id' => $product->id,
        ]);

        $subscription->delete();
        $subscription->customer->delete();
        $this->location->delete();
        $product->delete();
        $shown = NetworkAsset::query()->findOrFail($asset->id);

        $this->assertSame($subscription->customer_id, $shown->customer?->id);
        $this->assertSame($subscription->id, $shown->subscription?->id);
        $this->assertSame($this->location->id, $shown->location?->id);
        $this->assertSame($product->id, $shown->product?->id);
    }

    /** @param class-string $model */
    #[DataProvider('historyRelations')]
    public function test_a_history_relation_keeps_resolving_a_deleted_parent(string $model, string $relation): void
    {
        $removed = (new $model())->{$relation}()->getQuery()->removedScopes();

        $this->assertContains(SoftDeletingScope::class, $removed, "{$model}::{$relation}() hides a deleted parent");
    }

    /** @param  list<string>  $permissions  none: the template or role of that name is used as it is; `app.web` opens the admin pages for a role of exactly these */
    private function user(string $role, array $permissions = []): User
    {
        $user = User::factory()->create(['company_id' => $this->company->id, 'email_verified_at' => now(), 'is_active' => true]);

        return $this->grantRole($user, $permissions === [] ? $role : $this->companyRole($this->company, $role, [...$permissions, 'app.web']));
    }

    /** @param  array<string, mixed>  $attributes */
    private function asset(array $attributes = []): NetworkAsset
    {
        return NetworkAssetFactory::new()->create([
            'company_id' => $this->company->id,
            'asset_type' => 'router',
            'status' => 'available',
            'ip_address' => null,
            'management_ip' => null,
            ...$attributes,
        ]);
    }

    /** @return list<string> */
    private function heldBy(NetworkAsset $asset): array
    {
        return IpAddress::query()->where('network_asset_id', $asset->id)->orderBy('address_cmp')->pluck('address')->all();
    }

    /** @return list<string> the names the index lists for a status, sorted */
    private function listed(?string $status = null): array
    {
        $rows = $this->props($this->get(route('admin.network-assets.index', $status === null ? [] : ['status' => $status])))['assets']['data'];

        return collect($rows)->pluck('name')->sort()->values()->all();
    }

    /** @return array<string, mixed> */
    private function props(TestResponse $response): array
    {
        return $response->assertOk()->viewData('page')['props'];
    }

    private function installationHistory(NetworkAsset $asset): void
    {
        NetworkAssetInstallation::query()->forceCreate([
            'company_id' => $this->company->id,
            'network_asset_id' => $asset->id,
            'location_id' => $this->location->id,
            'installed_by' => $this->admin->id,
            'installed_at' => now()->subWeek(),
            'removed_at' => now()->subDay(),
            'removal_reason' => 'Pelanggan pindah',
        ]);
    }

    private function workOrderItem(NetworkAsset $asset): void
    {
        $workOrder = WorkOrderFactory::new()->create(['company_id' => $this->company->id, 'created_by' => $this->admin->id]);

        WorkOrderItem::query()->forceCreate([
            'company_id' => $this->company->id,
            'work_order_id' => $workOrder->id,
            'product_id' => Product::factory()->create(['company_id' => $this->company->id])->id,
            'network_asset_id' => $asset->id,
        ]);
    }

    private function ticket(NetworkAsset $asset): Ticket
    {
        $category = TicketCategory::withoutCompany()->where('company_id', $this->company->id)->first()
            ?? TicketCategory::query()->forceCreate([
                'company_id' => $this->company->id, 'name' => 'Gangguan', 'code' => 'gangguan', 'default_sla_hours' => 8,
                'default_response_hours' => 4, 'default_priority' => 'medium', 'is_active' => true,
            ]);

        return Ticket::query()->forceCreate([
            'company_id' => $this->company->id,
            'code' => 'TCK-'.++$this->sequence,
            'title' => 'Asset lifecycle fixture '.$this->sequence,
            'source' => 'internal',
            'category_id' => $category->id,
            'network_asset_id' => $asset->id,
            'status' => 'open',
            'priority' => 'medium',
            'created_by' => $this->admin->id,
        ]);
    }

    private function trashedTicket(NetworkAsset $asset): void
    {
        $this->ticket($asset)->delete();
    }

    private function splitter(NetworkAsset $asset): void
    {
        OdnSplitter::factory()->create(['company_id' => $this->company->id, 'network_asset_id' => $asset->id]);
    }

    private function port(NetworkAsset $asset): void
    {
        OdnPort::factory()->create(['company_id' => $this->company->id, 'network_asset_id' => $asset->id]);
    }

    private function nasDevice(NetworkAsset $asset): void
    {
        NasDevice::factory()->create(['company_id' => $this->company->id, 'network_asset_id' => $asset->id, 'nasname' => '192.0.2.99']);
    }

    private function ontSubscription(NetworkAsset $asset): void
    {
        $this->subscription(['ont_asset_id' => $asset->id]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function subscription(array $attributes = []): ServiceSubscription
    {
        $customer = Customer::factory()->create(['company_id' => $this->company->id]);

        return ServiceSubscription::factory()->create([
            'company_id' => $this->company->id,
            'customer_id' => $customer->id,
            'service_package_id' => ServicePackage::factory()->create(['company_id' => $this->company->id])->id,
            'installation_address_id' => CustomerAddress::factory()->create(['company_id' => $this->company->id, 'customer_id' => $customer->id])->id,
            ...$attributes,
        ]);
    }
}
