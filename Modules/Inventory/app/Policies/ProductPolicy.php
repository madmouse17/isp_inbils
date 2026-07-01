<?php

namespace Modules\Inventory\Policies;

use App\Models\User;
use Modules\Inventory\Models\Product;

class ProductPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('inventory.view');
    }

    public function view(User $user, Product $product): bool
    {
        return $user->can('inventory.view');
    }

    public function create(User $user): bool
    {
        return $user->can('inventory.create');
    }

    public function store(User $user): bool
    {
        return $this->create($user);
    }

    public function update(User $user, Product $product): bool
    {
        return $user->can('inventory.update');
    }

    public function edit(User $user, Product $product): bool
    {
        return $this->update($user, $product);
    }

    public function delete(User $user, Product $product): bool
    {
        return $user->can('inventory.delete');
    }
}
