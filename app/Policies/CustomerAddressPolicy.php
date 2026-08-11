<?php

namespace App\Policies;

use App\Models\Core\CustomerAddress;
use App\Models\User;

class CustomerAddressPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('customer.view') || $user->can('customer.address.manage');
    }

    public function view(User $user, CustomerAddress $customerAddress): bool
    {
        return $user->can('customer.view') || $user->can('customer.address.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('customer.address.manage');
    }

    public function update(User $user, CustomerAddress $customerAddress): bool
    {
        return $user->can('customer.address.manage');
    }

    public function delete(User $user, CustomerAddress $customerAddress): bool
    {
        return $user->can('customer.address.manage');
    }
}
