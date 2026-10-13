<?php

namespace Modules\Ticketing\Policies;

use App\Models\User;
use Modules\Ticketing\Models\TicketCategory;

class TicketCategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ticket.category.manage');
    }

    public function update(User $user, TicketCategory $category): bool
    {
        return $user->can('ticket.category.manage');
    }
}
