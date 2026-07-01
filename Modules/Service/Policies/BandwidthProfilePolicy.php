<?php

namespace Modules\Service\Policies;

use App\Models\User;
use Modules\Service\Models\BandwidthProfile;

class BandwidthProfilePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('service.view') || $user->can('service.manage');
    }

    public function view(User $user, BandwidthProfile $bandwidthProfile): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->can('service.create') || $user->can('service.manage');
    }

    public function store(User $user): bool
    {
        return $this->create($user);
    }

    public function update(User $user, BandwidthProfile $bandwidthProfile): bool
    {
        return $user->can('service.update') || $user->can('service.manage');
    }

    public function edit(User $user, BandwidthProfile $bandwidthProfile): bool
    {
        return $this->update($user, $bandwidthProfile);
    }

    public function delete(User $user, BandwidthProfile $bandwidthProfile): bool
    {
        return $user->can('service.delete') || $user->can('service.manage');
    }
}
