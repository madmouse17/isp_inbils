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

    public function restore(User $user, Customer $customer): bool
    {
        return $this->delete($user, $customer);
    }

    /** Attribution (column, filter, display, export) is visible only to holders of assign or correct. */
    public function viewAcquirer(User $user): bool
    {
        return $this->assignAcquirer($user) || $this->correctAcquirer($user);
    }

    /** Set the acquirer of an Unassigned customer (and, on create, pick a non-default one). */
    public function assignAcquirer(User $user): bool
    {
        return $user->can('customer.sales.assign');
    }

    /** Change or clear an existing acquirer; the caller must also record a reason. */
    public function correctAcquirer(User $user): bool
    {
        return $user->can('customer.sales.correct');
    }
}
