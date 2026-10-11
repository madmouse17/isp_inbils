<?php

namespace Modules\Ipam\Policies;

use App\Models\User;
use Modules\Ipam\Models\Vlan;

class VlanPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ipam.view');
    }

    public function create(User $user): bool
    {
        return $user->can('ipam.manage');
    }

    public function update(User $user, Vlan $vlan): bool
    {
        return $user->can('ipam.manage');
    }

    public function delete(User $user, Vlan $vlan): bool
    {
        return $user->can('ipam.manage');
    }
}
