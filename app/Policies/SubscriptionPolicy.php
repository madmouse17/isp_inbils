<?php

namespace App\Policies;

use App\Models\Core\ServiceSubscription;
use App\Models\User;

class SubscriptionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('customer.subscription.view');
    }

    public function view(User $user, ServiceSubscription $subscription): bool
    {
        return $user->can('customer.subscription.view');
    }

    public function create(User $user): bool
    {
        return $user->can('customer.subscription.manage');
    }

    public function store(User $user): bool
    {
        return $this->create($user);
    }

    public function update(User $user, ServiceSubscription $subscription): bool
    {
        return $user->can('customer.subscription.manage');
    }

    public function edit(User $user, ServiceSubscription $subscription): bool
    {
        return $this->update($user, $subscription);
    }

    public function activate(User $user, ServiceSubscription $subscription): bool
    {
        return $user->can('customer.subscription.activate');
    }

    public function suspend(User $user, ServiceSubscription $subscription): bool
    {
        return $user->can('customer.subscription.suspend');
    }

    public function reactivate(User $user, ServiceSubscription $subscription): bool
    {
        return $user->can('customer.subscription.reactivate');
    }

    public function terminate(User $user, ServiceSubscription $subscription): bool
    {
        return $user->can('customer.subscription.terminate');
    }
}
