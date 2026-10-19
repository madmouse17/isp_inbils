<?php

namespace Modules\NetworkAsset\Observers;

use App\Models\Core\ServiceSubscription;
use Modules\NetworkAsset\Jobs\ApplyNetworkAccountJob;
use Modules\NetworkAsset\Models\NetworkAccount;

/**
 * The network follows the subscription, never the other way round: Core knows nothing of RADIUS. A status, package
 * (the version it was sold under decides the speed profile) or block change, also one made outside SubscriptionService,
 * and a delete queue the apply job for the subscription's network account, after the surrounding transaction commits.
 * No RADIUS call happens here.
 *
 * The change is read while the event fires: after commit the model may already have been saved again.
 */
class ServiceSubscriptionNetworkObserver
{
    public function updated(ServiceSubscription $subscription): void
    {
        if ($subscription->wasChanged(['status', 'service_package_id', 'service_package_version_id', 'blocked_at'])) {
            $this->queueApply($subscription);
        }
    }

    public function deleted(ServiceSubscription $subscription): void
    {
        $this->queueApply($subscription);
    }

    private function queueApply(ServiceSubscription $subscription): void
    {
        $accountId = NetworkAccount::withoutCompany()
            ->where('company_id', $subscription->company_id)
            ->where('subscription_id', $subscription->id)
            ->value('id');

        if ($accountId !== null) {
            ApplyNetworkAccountJob::dispatchAfterCommit((int) $subscription->company_id, (int) $accountId);
        }
    }
}
