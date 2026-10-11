<?php

namespace Tests\Feature\Ipam;

use App\Models\Core\ServiceSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Modules\Ipam\Models\IpAddress;
use Modules\Ipam\Services\IpAssignmentService;
use Modules\Ipam\Services\IpPrefixService;
use Modules\NetworkAsset\Models\NetworkAsset;
use Tests\TestCase;
use Tests\Traits\BuildsIpamOdnFixtures;

/** Q6a: the asset's ip_address and management_ip stay as typed; IPAM is the conflict authority. */
class IpAssetWriteThroughTest extends TestCase
{
    use BuildsIpamOdnFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->bootIpamOdnWorld(withSeededRoles: true);
    }

    /** @param array<string, mixed> $extra */
    private function createAsset(array $extra = [], string $name = 'Core router'): TestResponse
    {
        return $this->postJson(route('admin.network-assets.store'), ['name' => $name, 'asset_type' => 'router', ...$extra]);
    }

    /** @param array<string, mixed> $extra */
    private function updateAsset(NetworkAsset $asset, array $extra = []): TestResponse
    {
        return $this->putJson(route('admin.network-assets.update', $asset), ['name' => $asset->name, 'asset_type' => 'router', ...$extra]);
    }

    private function heldBy(NetworkAsset $asset): array
    {
        return IpAddress::query()->where('network_asset_id', $asset->id)->orderBy('family')->orderBy('address_cmp')->pluck('address')->all();
    }

    private function heldBySubscription(ServiceSubscription $subscription, string $cidr, string $address): void
    {
        $pool = IpPrefixService::create(['cidr' => $cidr, 'usage' => 'customer_static']);
        IpAssignmentService::assignToSubscription($subscription, $pool, $address);
    }

    public function test_creating_an_asset_registers_both_addresses_and_keeps_the_typed_columns(): void
    {
        $this->createAsset(['ip_address' => '10.80.0.1', 'management_ip' => '2001:DB8::5'])->assertRedirect(route('admin.network-assets.index'));

        $asset = NetworkAsset::query()->where('name', 'Core router')->firstOrFail();
        $this->assertSame('10.80.0.1', $asset->ip_address);
        $this->assertSame('2001:DB8::5', $asset->management_ip, 'the asset columns stay a display copy');
        $this->assertSame(['10.80.0.1', '2001:db8::5'], $this->heldBy($asset));
        $row = IpAddress::query()->where('address', '10.80.0.1')->firstOrFail();
        $this->assertSame('assigned', $row->status);
        $this->assertNull($row->subscription_id);
        $this->assertNull($row->ip_prefix_id);
    }

    public function test_an_asset_without_addresses_registers_nothing(): void
    {
        $this->createAsset()->assertRedirect();

        $this->assertDatabaseCount('ip_addresses', 0);
    }

    public function test_the_same_value_in_both_columns_is_one_row(): void
    {
        $this->createAsset(['ip_address' => '10.80.0.9', 'management_ip' => '10.80.0.9'])->assertRedirect();

        $this->assertSame(['10.80.0.9'], $this->heldBy(NetworkAsset::query()->firstOrFail()));
    }

    public function test_an_address_inside_a_registered_prefix_is_linked_to_it(): void
    {
        $prefix = IpPrefixService::create(['cidr' => '10.80.1.0/24', 'usage' => 'infrastructure']);

        $this->createAsset(['management_ip' => '10.80.1.2'])->assertRedirect();

        $this->assertSame($prefix->id, IpAddress::query()->where('address', '10.80.1.2')->value('ip_prefix_id'));
    }

    public function test_creating_with_an_address_held_by_a_subscription_is_422_and_writes_nothing(): void
    {
        $subscription = $this->makeSubscription($this->company);
        $this->heldBySubscription($subscription, '10.80.2.0/24', '10.80.2.50');

        $response = $this->createAsset(['ip_address' => '10.80.2.50'])->assertStatus(422)->assertJsonValidationErrors('ip_address');

        $this->assertStringContainsString($subscription->code, $response->json('errors.ip_address.0'));
        $this->assertDatabaseCount('network_assets', 0);
        $this->assertDatabaseCount('ip_addresses', 1);

        $this->createAsset(['management_ip' => '10.80.2.50'])->assertStatus(422)->assertJsonValidationErrors('management_ip');
        $this->assertDatabaseCount('network_assets', 0);
    }

    public function test_a_second_asset_cannot_claim_an_address_another_asset_holds(): void
    {
        $this->createAsset(['ip_address' => '10.80.3.1'], 'First')->assertRedirect();

        $this->createAsset(['management_ip' => '10.80.3.1'], 'Second')->assertStatus(422)->assertJsonValidationErrors('management_ip');

        $this->assertDatabaseMissing('network_assets', ['name' => 'Second']);
    }

    public function test_an_asset_cannot_claim_a_reserved_address(): void
    {
        $prefix = IpPrefixService::create(['cidr' => '10.80.4.0/24', 'usage' => 'infrastructure']);
        IpAssignmentService::reserve($prefix, '10.80.4.1', 'gateway');

        $response = $this->createAsset(['ip_address' => '10.80.4.1'])->assertStatus(422)->assertJsonValidationErrors('ip_address');

        $this->assertStringContainsString('reserved (gateway)', $response->json('errors.ip_address.0'));
        $this->assertDatabaseCount('network_assets', 0);
    }

    public function test_updating_an_address_moves_the_asset_row_and_clearing_it_frees_it(): void
    {
        $asset = $this->makeAsset($this->company, 'router', null, ['ip_address' => '10.80.5.1']);
        IpAssignmentService::syncAssetAddresses($asset);

        $this->updateAsset($asset, ['ip_address' => '10.80.5.2'])->assertRedirect();
        $this->assertSame(['10.80.5.2'], $this->heldBy($asset));
        $this->assertSame('10.80.5.2', $asset->fresh()->ip_address);

        $this->updateAsset($asset, ['ip_address' => null])->assertRedirect();
        $this->assertSame([], $this->heldBy($asset));
        $this->assertNull($asset->fresh()->ip_address);
    }

    public function test_an_unchanged_address_is_not_rewritten(): void
    {
        $asset = $this->makeAsset($this->company, 'router', null, ['ip_address' => '10.80.6.1']);
        IpAssignmentService::syncAssetAddresses($asset);
        $rowId = IpAddress::query()->where('address', '10.80.6.1')->value('id');

        $this->updateAsset($asset, ['ip_address' => '10.80.6.1', 'vendor' => 'MikroTik'])->assertRedirect();

        $this->assertSame($rowId, IpAddress::query()->where('address', '10.80.6.1')->value('id'));
        $this->assertSame('MikroTik', $asset->fresh()->vendor);
    }

    public function test_updating_to_a_conflicting_address_is_422_and_changes_nothing(): void
    {
        $subscription = $this->makeSubscription($this->company);
        $this->heldBySubscription($subscription, '10.80.7.0/24', '10.80.7.9');
        $asset = $this->makeAsset($this->company, 'router', null, ['ip_address' => '10.80.7.1', 'vendor' => 'Old']);
        IpAssignmentService::syncAssetAddresses($asset);

        $this->updateAsset($asset, ['ip_address' => '10.80.7.9', 'vendor' => 'New'])
            ->assertStatus(422)->assertJsonValidationErrors('ip_address');

        $fresh = $asset->fresh();
        $this->assertSame('10.80.7.1', $fresh->ip_address);
        $this->assertSame('Old', $fresh->vendor, 'the whole save is rolled back');
        $this->assertSame(['10.80.7.1'], $this->heldBy($asset));
    }

    public function test_deleting_an_asset_releases_its_addresses(): void
    {
        $asset = $this->makeAsset($this->company, 'router', null, ['ip_address' => '10.80.8.1', 'management_ip' => '10.80.8.2']);
        IpAssignmentService::syncAssetAddresses($asset);
        $this->assertCount(2, $this->heldBy($asset));

        $this->deleteJson(route('admin.network-assets.destroy', $asset))->assertRedirect();

        $this->assertSoftDeleted('network_assets', ['id' => $asset->id]);
        $this->assertDatabaseCount('ip_addresses', 0);
        $second = $this->makeAsset($this->company, 'switch', null, ['ip_address' => '10.80.8.1']);
        IpAssignmentService::syncAssetAddresses($second);
        $this->assertSame(['10.80.8.1'], $this->heldBy($second));
    }

    public function test_an_installed_asset_still_cannot_be_deleted_and_keeps_its_addresses(): void
    {
        $asset = $this->makeAsset($this->company, 'router', null, ['ip_address' => '10.80.9.1', 'status' => 'installed']);
        IpAssignmentService::syncAssetAddresses($asset);

        $this->deleteJson(route('admin.network-assets.destroy', $asset))->assertStatus(422);

        $this->assertSame(['10.80.9.1'], $this->heldBy($asset));
    }

    public function test_the_asset_request_still_refuses_a_value_that_is_not_an_ip(): void
    {
        $this->createAsset(['ip_address' => '10.80.10'])->assertStatus(422)->assertJsonValidationErrors('ip_address');
    }
}
