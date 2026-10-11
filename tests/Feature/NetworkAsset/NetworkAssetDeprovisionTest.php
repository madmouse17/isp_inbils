<?php

namespace Tests\Feature\NetworkAsset;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Core\Location;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use App\Services\Core\CompanyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\NetworkAsset\Database\Factories\NetworkAssetFactory;
use Modules\NetworkAsset\Models\NetworkAsset;
use Modules\NetworkAsset\Models\NetworkAssetInstallation;
use Modules\NetworkAsset\Services\NetworkAssetService;
use Modules\SPK\Database\Factories\WorkOrderFactory;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Coverage for install/remove/releaseOnt (production surface).
 * Replaces orphan deprovision/provisionFromSpk claims.
 */
class NetworkAssetDeprovisionTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    private Location $location;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->user = User::factory()->create(['company_id' => $this->company->id]);
        $this->actingAs($this->user);

        $this->location = Location::factory()->create(['company_id' => $this->company->id]);
        $this->customer = Customer::factory()->create(['company_id' => $this->company->id]);
    }

    private function makeAsset(string $status = 'available', ?string $serial = null): NetworkAsset
    {
        return NetworkAssetFactory::new()->create([
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'serial_number' => $serial ?? ('SN-'.uniqid()),
            'status' => $status,
        ]);
    }

    public function test_install_then_remove_releases_asset_and_sets_removed_at(): void
    {
        $asset = $this->makeAsset('available', 'SN-RELEASE-1');

        NetworkAssetService::install(
            $asset,
            $this->location->id,
            $this->customer->id,
            null,
            null,
        );

        $installation = NetworkAssetInstallation::query()->where('network_asset_id', $asset->id)->whereNull('removed_at')->firstOrFail();

        $this->assertSame('installed', $asset->fresh()->status);
        $this->assertNull($installation->removed_at);

        $closed = NetworkAssetService::remove($asset, 'decommission');

        $this->assertSame('available', $closed->status);
        $this->assertNotNull($installation->fresh()->removed_at);
        $this->assertSame('available', $asset->fresh()->status);
        $this->assertNull($asset->fresh()->customer_id);
        $this->assertNull($asset->fresh()->subscription_id);
        $this->assertNull($asset->fresh()->location_id);
    }

    public function test_release_ont_closes_open_installations_and_returns_available(): void
    {
        $asset = $this->makeAsset('available', 'SN-ONT-1');

        NetworkAssetService::install(
            $asset,
            $this->location->id,
            $this->customer->id,
            null,
            null,
        );

        $released = NetworkAssetService::releaseOnt($asset->fresh(), 'termination');

        $this->assertSame('available', $released->status);
        $this->assertNull($released->customer_id);
        $this->assertNull($released->subscription_id);
        $this->assertNull($released->location_id);
        $this->assertSame(
            0,
            NetworkAssetInstallation::query()
                ->where('network_asset_id', $asset->id)
                ->whereNull('removed_at')
                ->count()
        );
    }

    public function test_remove_rejects_foreign_company_installation(): void
    {
        $asset = $this->makeAsset('available');
        $installation = NetworkAssetService::install(
            $asset,
            $this->location->id,
            $this->customer->id,
            null,
            null,
        );

        $foreign = Company::factory()->create();
        $this->actingAs(User::factory()->create(['company_id' => $foreign->id]));
        CompanyService::resetCache();

        try {
            NetworkAssetService::remove($installation, 'cross-tenant');
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }

        $this->assertNull($installation->fresh()->removed_at);
        $this->assertSame('installed', $asset->fresh()->status);
    }

    public function test_remove_rejects_already_closed_installation(): void
    {
        $asset = $this->makeAsset('available');
        $installation = NetworkAssetService::install(
            $asset,
            $this->location->id,
            $this->customer->id,
            null,
            null,
        );
        NetworkAssetService::remove($installation, 'first');

        try {
            NetworkAssetService::remove($installation->fresh(), 'second');
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    public function test_remove_without_a_user_closes_the_active_installation_inside_an_explicit_company_scope(): void
    {
        $asset = $this->makeAsset('available');
        NetworkAssetService::install(
            $asset,
            $this->location->id,
            $this->customer->id,
            null,
            null,
        );
        $installation = NetworkAssetInstallation::query()->where('network_asset_id', $asset->id)->whereNull('removed_at')->firstOrFail();

        auth()->logout();
        CompanyService::resetCache();

        $closed = CompanyService::runAs((int) $installation->company_id, fn () => NetworkAssetService::remove($installation, 'no-user'));

        $this->assertSame('available', $closed->status);
        $this->assertNotNull(NetworkAssetInstallation::withoutCompany()->find($installation->id)->removed_at);
    }

    public function test_install_requires_available_or_reserved_selected_asset_and_does_not_fallback(): void
    {
        $selected = $this->makeAsset('damaged', 'SN-SELECTED');
        $this->makeAsset('available', 'SN-OTHER');

        try {
            NetworkAssetService::install(
                $selected,
                $this->location->id,
                $this->customer->id,
                null,
                null,
            );
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $this->assertSame(0, NetworkAssetInstallation::query()->count());
        $this->assertSame('damaged', $selected->fresh()->status);
        $this->assertSame('available', NetworkAsset::query()->where('serial_number', 'SN-OTHER')->value('status'));
    }

    public function test_install_locks_and_rejects_active_installation_race(): void
    {
        $asset = $this->makeAsset('available', 'SN-ACTIVE');

        NetworkAssetService::install(
            $asset,
            $this->location->id,
            $this->customer->id,
            null,
            null,
        );

        try {
            NetworkAssetService::install(
                $asset->fresh(),
                $this->location->id,
                $this->customer->id,
                null,
                null,
            );
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $this->assertSame(1, NetworkAssetInstallation::query()->whereNull('removed_at')->count());
    }

    public function test_install_rejects_soft_deleted_location_without_writing(): void
    {
        $asset = $this->makeAsset('available', 'SN-SOFT-LOCATION');
        $this->location->delete();

        try {
            NetworkAssetService::install($asset, $this->location->id, $this->customer->id);
            $this->fail('Expected HttpException');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }

        $this->assertSame('available', $asset->fresh()->status);
        $this->assertDatabaseMissing('network_asset_installations', ['network_asset_id' => $asset->id]);
    }

    public function test_install_rejects_soft_deleted_customer_and_subscription_without_writing(): void
    {
        $asset = $this->makeAsset('available', 'SN-SOFT-CUSTOMER');
        $this->customer->delete();

        try {
            NetworkAssetService::install($asset, $this->location->id, $this->customer->id);
            $this->fail('Expected HttpException');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }

        $this->assertSame('available', $asset->fresh()->status);
        $this->assertDatabaseMissing('network_asset_installations', ['network_asset_id' => $asset->id]);
    }

    public function test_install_rejects_soft_deleted_subscription_without_writing(): void
    {
        $asset = $this->makeAsset('available', 'SN-SOFT-SUBSCRIPTION');
        $subscription = ServiceSubscription::factory()->create(['customer_id' => $this->customer->id]);
        $subscription->delete();

        try {
            NetworkAssetService::install($asset, $this->location->id, $this->customer->id, $subscription->id);
            $this->fail('Expected HttpException');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }

        $this->assertSame('available', $asset->fresh()->status);
        $this->assertDatabaseMissing('network_asset_installations', ['network_asset_id' => $asset->id]);
    }

    public function test_install_rejects_soft_deleted_work_order_without_writing(): void
    {
        $asset = $this->makeAsset('available', 'SN-SOFT-SPK');
        $workOrder = WorkOrderFactory::new()->create([
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'location_id' => $this->location->id,
            'created_by' => $this->user->id,
        ]);
        $workOrder->delete();

        try {
            NetworkAssetService::install($asset, $this->location->id, $this->customer->id, null, $workOrder->id);
            $this->fail('Expected HttpException');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }

        $this->assertSame('available', $asset->fresh()->status);
        $this->assertDatabaseMissing('network_asset_installations', ['network_asset_id' => $asset->id]);
    }

    public function test_release_ont_rejects_foreign_company_installation(): void
    {
        $asset = $this->makeAsset('available', 'SN-REL');
        NetworkAssetService::install(
            $asset,
            $this->location->id,
            $this->customer->id,
            null,
            null,
        );

        $foreign = Company::factory()->create();
        $this->actingAs(User::factory()->create(['company_id' => $foreign->id]));
        CompanyService::resetCache();

        try {
            NetworkAssetService::releaseOnt($asset->fresh(), 'foreign-stale');
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }

        $this->assertSame('installed', $asset->fresh()->status);
    }
}
