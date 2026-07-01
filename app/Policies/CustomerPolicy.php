<?php

namespace App\Policies;

use App\Models\Core\Customer;
use App\Models\User;

class CustomerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('customer.view');
    }

    public function view(User $user, Customer $customer): bool
    {
        return $user->can('customer.view');
    }

    public function create(User $user): bool
    {
        return $user->can('customer.create');
    }

    public function store(User $user): bool
    {
        return $this->create($user);
    }

    public function update(User $user, Customer $customer): bool
    {
        return $user->can('customer.update');
    }

    public function edit(User $user, Customer $customer): bool
    {
        return $this->update($user, $customer);
    }

    public function delete(User $user, Customer $customer): bool
    {
        return $user->can('customer.delete');
    }
}
