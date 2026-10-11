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

        if ($this->isCustomerUser($user)) {
            return $user->can('ticket.view') && $this->ownsTicket($user, $ticket);
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

    public function comment(User $user, Ticket $ticket): bool
    {
        return $this->sameCompany($user, $ticket)
            && (! $this->isCustomerUser($user) || $this->ownsTicket($user, $ticket));
    }

    public function attach(User $user, Ticket $ticket): bool
    {
        return $this->comment($user, $ticket);
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

    /** A checker (any ticket.close holder in the company other than the handler who resolved it) closes the work. */
    public function close(User $user, Ticket $ticket): bool
    {
        return $this->sameCompany($user, $ticket)
            && $user->is_active
            && $ticket->status === 'resolved'
            && (int) $ticket->assigned_to !== (int) $user->id
            && $user->can('ticket.close');
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

    private function isCustomerUser(User $user): bool
    {
        return $user->hasRole('customer');
    }

    private function ownsTicket(User $user, Ticket $ticket): bool
    {
        $customerId = $user->customer?->id;

        return $customerId !== null && (int) $ticket->customer_id === (int) $customerId;
    }
}
