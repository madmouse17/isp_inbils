<?php

namespace Modules\Ipam\Policies;

use App\Models\User;
use Modules\Ipam\Models\IpPrefix;

class IpPrefixPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ipam.view');
    }

    public function view(User $user, IpPrefix $prefix): bool
    {
        return $user->can('ipam.view');
    }

    public function create(User $user): bool
    {
        return $user->can('ipam.manage');
    }

    public function update(User $user, IpPrefix $prefix): bool
    {
        return $user->can('ipam.manage');
    }

    public function delete(User $user, IpPrefix $prefix): bool
    {
        return $user->can('ipam.manage');
    }

    public function reserve(User $user, IpPrefix $prefix): bool
    {
        return $user->can('ipam.manage');
    }
}
