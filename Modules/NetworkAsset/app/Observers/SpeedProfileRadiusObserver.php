<?php

namespace Modules\NetworkAsset\Observers;

use App\Services\Core\IntegrationConfig;
use Illuminate\Support\Facades\DB;
use Modules\NetworkAsset\Services\NetworkAccountProvisioner;
use Modules\NetworkAsset\Services\Radius\RadiusException;
use Modules\Service\Models\SpeedProfile;

/**
 * A changed speed profile rewrites that company's speed group in RADIUS, so new logins get the new rate. Online
 * sessions keep theirs until they redial or staff press Resync: no Disconnect is sent. A RADIUS outage never fails
 * the edit; the accounts converge on their next apply or sync.
 */
class SpeedProfileRadiusObserver
{
    public function updated(SpeedProfile $profile): void
    {
        if (! $profile->wasChanged(['radius_profile_name', 'download_max_mbps', 'upload_max_mbps']) || ! app(IntegrationConfig::class)->isEnabled('radius')) {
            return;
        }

        DB::afterCommit(function () use ($profile): void {
            try {
                app(NetworkAccountProvisioner::class)->writeSpeedGroup($profile);
            } catch (RadiusException) {
                // Retried by the next apply of an account of this profile; the edit itself stands.
            }
        });
    }
}
