<?php

namespace Modules\NetworkAsset\Observers;

use App\Services\Core\AuditService;
use Modules\NetworkAsset\Models\NasDevice;
use Modules\NetworkAsset\Models\NetworkAsset;

/**
 * A NAS router's API credentials are sent to whatever address its asset carries. A new address is therefore a new,
 * unconfirmed destination: the router's write flag goes off until an administrator saves the NAS router again.
 * Who may change the address at all is decided in UpdateNetworkAssetRequest; this covers every path.
 */
class NetworkAssetEndpointObserver
{
    public function updated(NetworkAsset $asset): void
    {
        if (! $asset->wasChanged('management_ip')) {
            return;
        }

        $nas = NasDevice::withoutCompany()
            ->where('company_id', $asset->company_id)
            ->where('network_asset_id', $asset->id)
            ->first();

        if ($nas === null) {
            return;
        }

        $wasWritable = $nas->provisioning_enabled;
        $nas->forceFill(['provisioning_enabled' => false])->save();

        AuditService::log('nas_device', 'endpoint_changed', [
            'network_asset_id' => $asset->id,
            'from' => $asset->getOriginal('management_ip'),
            'to' => $asset->getAttribute('management_ip'),
            'writes_switched_off' => $wasWritable,
        ], $nas);
    }
}
