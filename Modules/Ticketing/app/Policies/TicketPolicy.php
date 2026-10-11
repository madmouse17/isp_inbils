<?php

namespace Modules\Ticketing\Policies;

use App\Models\User;
use App\Services\Core\CompanyService;
use Modules\Ticketing\Models\Ticket;

class TicketPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ticket.view');
    }

    public function view(User $user, Ticket $ticket): bool
    {
        if (! $this->sameCompany($user, $ticket)) {
            return false;
        }

        if (! $user->can('ticket.view')) {
            return $this->canActOnCurrentStatus($user, $ticket);
        }

        if (($user->can('ticket.start') || $user->can('ticket.resolve') || $user->can('ticket.close'))
            && ! $user->can('ticket.assign')) {
            return $this->isAssignedEligibleHandler($user, $ticket);
        }

        return true;
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
        return $this->sameCompany($user, $ticket) && $user->can('ticket.delete');
    }

    public function assign(User $user, Ticket $ticket): bool
    {
        if (! $user->is_active || ! $this->sameCompany($user, $ticket)) {
            return false;
        }

        if ($ticket->assigned_to !== null) {
            return $user->can('ticket.reassign');
        }

        return $user->can('ticket.assign');
    }

    public function reassign(User $user, Ticket $ticket): bool
    {
        return $this->sameCompany($user, $ticket)
            && $ticket->assigned_to !== null
            && $user->can('ticket.reassign');
    }

    public function start(User $user, Ticket $ticket): bool
    {
        return $this->sameCompany($user, $ticket)
            && $ticket->status === 'assigned'
            && $user->can('ticket.start')
            && $this->isAssignedEligibleHandler($user, $ticket);
    }

    public function resolve(User $user, Ticket $ticket): bool
    {
        return $this->sameCompany($user, $ticket)
            && $ticket->status === 'on_progress'
            && $user->can('ticket.resolve')
            && $this->isAssignedEligibleHandler($user, $ticket);
    }

    public function close(User $user, Ticket $ticket): bool
    {
        return $this->sameCompany($user, $ticket)
            && $ticket->status === 'resolved'
            && $user->can('ticket.close')
            && $this->isAssignedEligibleHandler($user, $ticket);
    }

    public function spawnSpk(User $user, Ticket $ticket): bool
    {
        return $this->sameCompany($user, $ticket) && $user->can('ticket.spawn_spk');
    }

    private function sameCompany(User $user, Ticket $ticket): bool
    {
        $companyId = CompanyService::idFor($user);

        return $companyId !== null && $companyId === (int) $ticket->company_id;
    }

    private function isAssignedEligibleHandler(User $user, Ticket $ticket): bool
    {
        return $user->is_active
            && $ticket->assigned_to !== null
            && (int) $ticket->assigned_to === (int) $user->id;
    }

    private function canActOnCurrentStatus(User $user, Ticket $ticket): bool
    {
        return match ($ticket->status) {
            'assigned' => $this->start($user, $ticket),
            'on_progress' => $this->resolve($user, $ticket),
            'resolved' => $this->close($user, $ticket),
            default => false,
        };
    }
}
