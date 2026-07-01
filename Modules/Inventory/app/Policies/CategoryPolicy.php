<?php

namespace Modules\Inventory\Policies;

use App\Models\User;
use Modules\Inventory\Models\Category;

class CategoryPolicy
{
    public function viewAny(User $user): bool
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

    public function update(User $user, Category $category): bool
    {
        return $user->can('inventory.update');
    }

    public function delete(User $user, Category $category): bool
    {
        return $user->can('inventory.delete');
    }
}
