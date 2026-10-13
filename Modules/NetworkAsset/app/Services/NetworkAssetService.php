<?php

namespace Modules\NetworkAsset\Services;

use App\Models\Core\Customer;
use App\Models\Core\Location;
use App\Models\Core\ServiceSubscription;
use App\Services\Core\AuditService;
use App\Services\Core\CompanyService;
use App\Services\Core\NumberSequenceService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\NetworkAsset\Models\NetworkAsset;
use Modules\NetworkAsset\Models\NetworkAssetInstallation;

class NetworkAssetService
{
    public static function install(NetworkAsset $asset, int $locationId, ?int $customerId = null, ?int $subscriptionId = null, ?int $spkId = null): NetworkAsset
    {
        abort_unless(CompanyService::currentId() !== null, 403, 'Company context is required to install a network asset.');

        return DB::transaction(function () use ($asset, $locationId, $customerId, $subscriptionId, $spkId) {
            $asset = self::lockAsset($asset);
            abort_if($asset->getAttribute('status') !== 'available', 422, 'Asset must be available to install.');

            $location = self::lockCompanyModel(Location::query(), $locationId, 'location');
            abort_unless((int) $location->company_id === (int) $asset->company_id, 422, 'Location must belong to the same company as the asset.');

            abort_unless($subscriptionId === null || $customerId !== null, 422, 'Customer is required when installing with a subscription.');

            $customer = null;
            if ($customerId !== null) {
                $customer = self::lockCompanyModel(Customer::query(), $customerId, 'customer');
                abort_unless((int) $customer->company_id === (int) $asset->company_id, 422, 'Customer must belong to the same company as the asset.');
            }

            $subscription = null;
            if ($subscriptionId !== null) {
                $subscription = self::lockCompanyModel(ServiceSubscription::query(), $subscriptionId, 'subscription');
                abort_unless((int) $subscription->company_id === (int) $asset->company_id, 422, 'Subscription must belong to the same company as the asset.');
            }

            $workOrder = null;
            if ($spkId !== null) {
                $workOrder = DB::table('work_orders')
                    ->whereNull('deleted_at')
                    ->where('id', $spkId)
                    ->lockForUpdate()
                    ->first();
                abort_unless($workOrder !== null, 404, 'Work order not found.');
                abort_unless((int) $workOrder->company_id === (int) $asset->company_id, 404, 'Work order not found.');
            }

            if ($customer !== null && $subscription !== null && (int) $subscription->customer_id !== (int) $customer->id) {
                abort(422, 'Subscription must belong to the selected customer.');
            }

            if ($workOrder !== null) {
                abort_unless($customer === null || (int) $workOrder->customer_id === (int) $customer->id, 422, 'Customer does not match the selected SPK.');
                abort_unless($subscription === null || (int) $workOrder->subscription_id === (int) $subscription->id, 422, 'Subscription does not match the selected SPK.');
                abort_unless((int) $workOrder->location_id === (int) $location->id, 422, 'Location does not match the selected SPK.');
            }

            $activeInstall = NetworkAssetInstallation::query()
                ->where('network_asset_id', $asset->getAttribute('id'))
                ->whereNull('removed_at')
                ->lockForUpdate()
                ->exists();
            abort_if($activeInstall, 422, 'Asset already has an active installation.');

            $asset->update([
                'status' => 'installed',
                'location_id' => $locationId,
                'customer_id' => $customerId,
                'subscription_id' => $subscriptionId,
                'installed_at' => now(),
            ]);

            NetworkAssetInstallation::create([
                'network_asset_id' => $asset->getAttribute('id'),
                'asset_code_snapshot' => $asset->code,
                'asset_serial_snapshot' => $asset->serial_number,
                'location_id' => $locationId,
                'customer_id' => $customerId,
                'subscription_id' => $subscriptionId,
                'spk_id' => $spkId,
                'installed_by' => Auth::id(),
                'installed_at' => now(),
            ]);

            AuditService::log('network_asset', 'installed', [
                'asset_id' => $asset->getAttribute('id'),
                'location_id' => $locationId,
            ], $asset);

            return $asset->fresh();
        });
    }

    public static function remove(NetworkAsset|NetworkAssetInstallation $subject, string $reason): NetworkAsset
    {
        return DB::transaction(function () use ($subject, $reason) {
            [$asset, $installation] = self::lockedRemovalTargets($subject);

            abort_if($asset->getAttribute('status') !== 'installed', 422, 'Asset must be installed to remove.');

            $asset->update([
                'status' => 'available',
                'location_id' => null,
                'customer_id' => null,
                'subscription_id' => null,
                'installed_at' => null,
            ]);

            $installation->update([
                'removed_at' => now(),
                'removal_reason' => $reason,
            ]);

            AuditService::log('network_asset', 'removed', [
                'asset_id' => $asset->getAttribute('id'),
                'reason' => $reason,
            ], $asset);

            return $asset->fresh();
        });
    }

    public static function releaseOnt(NetworkAsset $asset, string $reason): NetworkAsset
    {
        return self::remove($asset, $reason);
    }

    public static function setMaintenance(NetworkAsset $asset, string $reason): NetworkAsset
    {
        return DB::transaction(function () use ($asset, $reason) {
            $asset = self::lockAsset($asset);
            abort_if($asset->getAttribute('status') !== 'installed', 422, 'Asset must be installed to set maintenance.');

            $asset->update(['status' => 'maintenance']);

            AuditService::log('network_asset', 'maintenance', [
                'asset_id' => $asset->getAttribute('id'),
                'reason' => $reason,
            ], $asset);

            return $asset->fresh();
        });
    }

    public static function resume(NetworkAsset $asset): NetworkAsset
    {
        return DB::transaction(function () use ($asset) {
            $asset = self::lockAsset($asset);
            abort_if($asset->getAttribute('status') !== 'maintenance', 422, 'Asset must be in maintenance to resume.');

            $asset->update(['status' => 'installed']);

            AuditService::log('network_asset', 'resumed', ['asset_id' => $asset->getAttribute('id')], $asset);

            return $asset->fresh();
        });
    }

    public static function setDamaged(NetworkAsset $asset, string $reason): NetworkAsset
    {
        return DB::transaction(function () use ($asset, $reason) {
            $asset = self::lockAsset($asset);
            abort_if(! in_array($asset->getAttribute('status'), ['installed', 'maintenance'], true), 422, 'Asset must be installed or in maintenance to mark damaged.');

            $asset->update(['status' => 'damaged']);

            AuditService::log('network_asset', 'damaged', [
                'asset_id' => $asset->getAttribute('id'),
                'reason' => $reason,
            ], $asset);

            return $asset->fresh();
        });
    }

    public static function repair(NetworkAsset $asset): NetworkAsset
    {
        return DB::transaction(function () use ($asset) {
            $asset = self::lockAsset($asset);
            abort_if($asset->getAttribute('status') !== 'damaged', 422, 'Asset must be damaged to repair.');

            $asset->update([
                'status' => 'available',
                'location_id' => null,
                'customer_id' => null,
                'subscription_id' => null,
                'installed_at' => null,
            ]);

            self::closeActiveInstallation($asset->getAttribute('id'), 'repair');

            AuditService::log('network_asset', 'repaired', ['asset_id' => $asset->getAttribute('id')], $asset);

            return $asset->fresh();
        });
    }

    public static function retire(NetworkAsset $asset, string $reason): NetworkAsset
    {
        return DB::transaction(function () use ($asset, $reason) {
            $asset = self::lockAsset($asset);
            abort_if($asset->getAttribute('status') === 'retired', 422, 'Asset already retired.');

            $asset->update([
                'status' => 'retired',
                'retired_at' => now(),
            ]);

            self::closeActiveInstallation($asset->getAttribute('id'), $reason);

            AuditService::log('network_asset', 'retired', [
                'asset_id' => $asset->getAttribute('id'),
                'reason' => $reason,
            ], $asset);

            return $asset->fresh();
        });
    }

    public static function generateCode(): string
    {
        return DB::transaction(fn () => NumberSequenceService::generate('network_asset'));
    }

    private static function lockAsset(NetworkAsset $asset): NetworkAsset
    {
        $companyId = CompanyService::currentId();

        /** @var NetworkAsset $locked */
        $locked = NetworkAsset::withoutCompany()->lockForUpdate()->findOrFail($asset->id);

        if ($companyId !== null) {
            abort_unless((int) $locked->company_id === (int) $companyId, 404, 'Network asset not found.');
        }

        return $locked;
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return TModel
     */
    private static function lockCompanyModel(Builder $query, int $id, string $label): Model
    {
        $companyId = CompanyService::currentId();

        $model = $query->whereKey($id)->lockForUpdate()->first();

        abort_unless($model !== null, 404, ucfirst($label).' not found.');

        if ($companyId !== null) {
            abort_unless((int) $model->getAttribute('company_id') === (int) $companyId, 404, ucfirst($label).' not found.');
        }

        return $model;
    }

    /**
     * @return array{0: NetworkAsset, 1: NetworkAssetInstallation}
     */
    private static function lockedRemovalTargets(NetworkAsset|NetworkAssetInstallation $subject): array
    {
        if ($subject instanceof NetworkAssetInstallation) {
            $installation = NetworkAssetInstallation::query()->lockForUpdate()->findOrFail($subject->id);
            abort_if($installation->removed_at !== null, 422, 'Asset installation already removed.');

            $asset = self::lockAsset(NetworkAsset::query()->findOrFail($installation->network_asset_id));

            return [$asset, $installation];
        }

        $asset = self::lockAsset($subject);
        $installation = NetworkAssetInstallation::query()
            ->where('network_asset_id', $asset->id)
            ->whereNull('removed_at')
            ->lockForUpdate()
            ->first();

        if (! $installation) {
            abort(422, 'Asset must have an active installation to remove.');
        }

        return [$asset, $installation];
    }

    private static function closeActiveInstallation(int $assetId, string $reason): void
    {
        NetworkAssetInstallation::query()
            ->where('network_asset_id', $assetId)
            ->whereNull('removed_at')
            ->update([
                'removed_at' => now(),
                'removal_reason' => $reason,
            ]);
    }
}
