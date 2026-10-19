<?php

namespace App\Policies;

use App\Models\Core\Location;
use App\Models\User;
use App\Services\Core\CompanyService;

class LocationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('location.view');
    }

    public function view(User $user, Location $location): bool
    {
        return $user->can('location.view')
            && $this->sameCompany($user, $location);
    }

    public function create(User $user): bool
    {
        return $user->can('location.create');
    }

    public function store(User $user): bool
    {
        return $this->create($user);
    }

    public function update(User $user, Location $location): bool
    {
        return $user->can('location.update')
            && $this->sameCompany($user, $location);
    }

    public function edit(User $user, Location $location): bool
    {
        return $this->update($user, $location);
    }

    public function move(User $user, Location $location): bool
    {
        return $user->can('location.move')
            && $this->sameCompany($user, $location);
    }

    public function delete(User $user, Location $location): bool
    {
        return $user->can('location.delete')
            && $this->sameCompany($user, $location);
    }

    public function restore(User $user, Location $location): bool
    {
        return $this->delete($user, $location);
    }

    private function sameCompany(User $user, Location $location): bool
    {
        $userCompanyId = CompanyService::idFor($user);
        $locationCompanyId = $location->getAttribute('company_id');

        return $userCompanyId !== null
            && $locationCompanyId !== null
            && $userCompanyId === (int) $locationCompanyId;
    }
}
