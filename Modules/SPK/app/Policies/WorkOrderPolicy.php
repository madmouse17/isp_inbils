<?php

namespace Modules\SPK\Policies;

use App\Models\User;
use Modules\SPK\Models\WorkOrder;

class WorkOrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('spk.view');
    }

    public function view(User $user, WorkOrder $wo): bool
    {
        if ($user->hasRole('technician')) {
            return $wo->assigned_to === $user->id;
        }
        return $user->can('spk.view');
    }

    public function create(User $user): bool
    {
        return $user->can('spk.create');
    }

    public function store(User $user): bool
    {
        return $this->create($user);
    }

    public function update(User $user, WorkOrder $wo): bool
    {
        return $user->can('spk.update');
    }

    public function edit(User $user, WorkOrder $wo): bool
    {
        return $this->update($user, $wo);
    }

    public function delete(User $user, WorkOrder $wo): bool
    {
        return $user->can('spk.delete');
    }
}
