<?php

namespace Modules\Ticketing\Policies;

use App\Models\User;
use Modules\Ticketing\Models\Ticket;

class TicketPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ticket.view');
    }

    public function view(User $user, Ticket $ticket): bool
    {
        if ($user->hasRole('technician')) {
            return $ticket->assigned_to === $user->id;
        }
        return $user->can('ticket.view');
    }

    public function create(User $user): bool
    {
        return $user->can('ticket.create');
    }

    public function store(User $user): bool
    {
        return $this->create($user);
    }

    public function update(User $user, Ticket $ticket): bool
    {
        return $user->can('ticket.update');
    }

    public function edit(User $user, Ticket $ticket): bool
    {
        return $this->update($user, $ticket);
    }

    public function delete(User $user, Ticket $ticket): bool
    {
        return $user->can('ticket.delete');
    }
}
