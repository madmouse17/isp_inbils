<?php

namespace Modules\NetworkAsset\Policies;

use App\Models\User;
use Modules\NetworkAsset\Models\NetworkAsset;

class NetworkAssetPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('network_asset.view');
    }

    public function view(User $user, NetworkAsset $asset): bool
    {
        return $user->can('network_asset.view');
    }

    public function create(User $user): bool
    {
        return $user->can('network_asset.create');
    }

    public function store(User $user): bool
    {
        return $this->create($user);
    }

    public function update(User $user, NetworkAsset $asset): bool
    {
        return $user->can('network_asset.update');
    }

    public function edit(User $user, NetworkAsset $asset): bool
    {
        return $this->update($user, $asset);
    }

    /** The device panel: apply a model, label and link ports, mount in a rack. */
    public function updatePanel(User $user, NetworkAsset $asset): bool
    {
        return $user->can('network_asset.update');
    }

    public function delete(User $user, NetworkAsset $asset): bool
    {
        return $user->can('network_asset.delete');
    }

    public function restore(User $user, NetworkAsset $asset): bool
    {
        return $this->delete($user, $asset);
    }
}
