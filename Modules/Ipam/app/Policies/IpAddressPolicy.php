<?php

namespace Modules\Ipam\Policies;

use App\Models\User;
use Modules\Ipam\Models\IpAddress;

class IpAddressPolicy
{
    /** A subscription's address is a NOC assignment; anything else (a reservation) is register management. */
    public function delete(User $user, IpAddress $address): bool
    {
        return $address->subscription_id !== null
            ? $user->can('ipam.assign')
            : $user->can('ipam.manage');
    }
}
