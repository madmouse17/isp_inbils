<?php

namespace Modules\Ipam\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A subscription's static address was assigned or released (including release on terminate).
 * Dispatched after the surrounding transaction commits; provisioning reads the result with
 * IpAssignmentService::staticAddressFor().
 */
class IpAssignmentChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $companyId,
        public readonly int $subscriptionId,
    ) {}
}
