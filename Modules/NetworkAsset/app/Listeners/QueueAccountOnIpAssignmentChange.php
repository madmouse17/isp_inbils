<?php

namespace Modules\NetworkAsset\Listeners;

use Modules\Ipam\Events\IpAssignmentChanged;
use Modules\NetworkAsset\Jobs\ApplyNetworkAccountJob;
use Modules\NetworkAsset\Models\NetworkAccount;

/**
 * The static address of a subscription is a RADIUS reply (Framed-IP-Address), so an assign or a release queues the
 * apply job of its network account. IPAM dispatches the event after commit, so the job never reads a rolled-back
 * assignment. No RADIUS call happens here.
 */
class QueueAccountOnIpAssignmentChange
{
    public function handle(IpAssignmentChanged $event): void
    {
        $accountId = NetworkAccount::withoutCompany()
            ->where('company_id', $event->companyId)
            ->where('subscription_id', $event->subscriptionId)
            ->value('id');

        if ($accountId !== null) {
            ApplyNetworkAccountJob::dispatch($event->companyId, (int) $accountId);
        }
    }
}
