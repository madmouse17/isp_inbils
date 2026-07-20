<?php

namespace Modules\NetworkAsset\Services;

use App\Services\Core\AuditService;
use App\Services\Core\CompanyService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\NetworkAsset\Models\NetworkAsset;
use Modules\NetworkAsset\Models\NetworkAssetInstallation;

class NetworkAssetService
{
    public static function install(NetworkAsset $asset, int $locationId, ?int $customerId = null, ?int $subscriptionId = null, ?int $spkId = null): NetworkAsset
    {
        abort_if($asset->status !== 'available', 422, 'Asset must be available to install.');

        $activeInstall = NetworkAssetInstallation::where('network_asset_id', $asset->id)
            ->whereNull('removed_at')->exists();
        abort_if($activeInstall, 422, 'Asset already has an active installation.');

        return DB::transaction(function () use ($asset, $locationId, $customerId, $subscriptionId, $spkId) {
            $asset->update([
                'status' => 'installed',
                'location_id' => $locationId,
                'customer_id' => $customerId,
                'subscription_id' => $subscriptionId,
                'installed_at' => now(),
            ]);

            NetworkAssetInstallation::create([
                'network_asset_id' => $asset->id,
                'location_id' => $locationId,
                'customer_id' => $customerId,
                'subscription_id' => $subscriptionId,
                'spk_id' => $spkId,
                'installed_by' => Auth::id(),
                'installed_at' => now(),
            ]);

            AuditService::log('network_asset', 'installed', [
                'asset_id' => $asset->id, 'location_id' => $locationId,
            ], $asset);

            return $asset->fresh();
        });
    }

    public static function remove(NetworkAsset $asset, string $reason): NetworkAsset
    {
        return DB::transaction(function () use ($asset, $reason) {
            $asset = NetworkAsset::withoutCompany()->lockForUpdate()->findOrFail($asset->id);
            abort_if($asset->status !== 'installed', 422, 'Asset must be installed to remove.');

            $asset->update([
                'status' => 'available',
                'location_id' => null,
                'customer_id' => null,
                'subscription_id' => null,
                'installed_at' => null,
            ]);

            NetworkAssetInstallation::where('network_asset_id', $asset->id)
                ->whereNull('removed_at')
                ->update([
                    'removed_at' => now(),
                    'removal_reason' => $reason,
                ]);

            AuditService::log('network_asset', 'removed', [
                'asset_id' => $asset->id, 'reason' => $reason,
            ], $asset);

            return $asset->fresh();
        });
    }

    public static function setMaintenance(NetworkAsset $asset, string $reason): NetworkAsset
    {
        abort_if(! in_array($asset->status, ['installed']), 422, 'Asset must be installed to set maintenance.');

        return DB::transaction(function () use ($asset, $reason) {
            $asset->update(['status' => 'maintenance']);

            AuditService::log('network_asset', 'maintenance', [
                'asset_id' => $asset->id, 'reason' => $reason,
            ], $asset);

            return $asset->fresh();
        });
    }

    public static function resume(NetworkAsset $asset): NetworkAsset
    {
        abort_if($asset->status !== 'maintenance', 422, 'Asset must be in maintenance to resume.');

        return DB::transaction(function () use ($asset) {
            $asset->update(['status' => 'installed']);

            AuditService::log('network_asset', 'resumed', ['asset_id' => $asset->id], $asset);

            return $asset->fresh();
        });
    }

    public static function setDamaged(NetworkAsset $asset, string $reason): NetworkAsset
    {
        abort_if(! in_array($asset->status, ['installed', 'maintenance']), 422, 'Asset must be installed or in maintenance to mark damaged.');

        return DB::transaction(function () use ($asset, $reason) {
            $asset->update(['status' => 'damaged']);

            AuditService::log('network_asset', 'damaged', [
                'asset_id' => $asset->id, 'reason' => $reason,
            ], $asset);

            return $asset->fresh();
        });
    }

    public static function repair(NetworkAsset $asset): NetworkAsset
    {
        abort_if($asset->status !== 'damaged', 422, 'Asset must be damaged to repair.');

        return DB::transaction(function () use ($asset) {
            $asset->update([
                'status' => 'available',
                'location_id' => null,
                'customer_id' => null,
                'subscription_id' => null,
                'installed_at' => null,
            ]);

            NetworkAssetInstallation::where('network_asset_id', $asset->id)
                ->whereNull('removed_at')
                ->update([
                    'removed_at' => now(),
                    'removal_reason' => 'repair',
                ]);

            AuditService::log('network_asset', 'repaired', ['asset_id' => $asset->id], $asset);

            return $asset->fresh();
        });
    }

    public static function retire(NetworkAsset $asset, string $reason): NetworkAsset
    {
        abort_if($asset->status === 'retired', 422, 'Asset already retired.');

        return DB::transaction(function () use ($asset, $reason) {
            $asset->update([
                'status' => 'retired',
                'retired_at' => now(),
            ]);

            NetworkAssetInstallation::where('network_asset_id', $asset->id)
                ->whereNull('removed_at')
                ->update([
                    'removed_at' => now(),
                    'removal_reason' => $reason,
                ]);

            AuditService::log('network_asset', 'retired', [
                'asset_id' => $asset->id, 'reason' => $reason,
            ], $asset);

            return $asset->fresh();
        });
    }

    public static function generateCode(): string
    {
        $year = now()->year;
        $prefix = "AST-{$year}-";

        $last = NetworkAsset::forCompany(CompanyService::currentId())
            ->where('code', 'like', $prefix.'%')
            ->orderByDesc('code')
            ->lockForUpdate()
            ->first();

        $next = $last ? ((int) substr($last->code, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }
}
