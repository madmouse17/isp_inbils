<?php

namespace Modules\Ticketing\Policies;

use App\Models\User;
use App\Services\Core\CompanyService;
use Illuminate\Auth\Access\Response;
use Modules\Ticketing\Models\Ticket;

/**
 * A denial that comes from WHERE the ticket is (another company's, another customer's, another handler's) answers
 * like a ticket that does not exist: a 404 (spec .scratch/opaque-ids, K3). A missing permission, or a status the
 * ticket is not in, stays a 403. An ability the user lacks on a ticket they cannot see answers like seeing it.
 */
class TicketPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ticket.view');
    }

    public function view(User $user, Ticket $ticket): Response|bool
    {
        if (! $this->sameCompany($user, $ticket)) {
            return Response::denyAsNotFound();
        }

        if ($this->isCustomerUser($user)) {
            if (! $user->can('ticket.view')) {
                return false;
            }

            return $this->ownsTicket($user, $ticket) ?: Response::denyAsNotFound();
        }

        if (! $user->can('ticket.view')) {
            return $this->canActOnCurrentStatus($user, $ticket);
        }

        if (($user->can('ticket.start') || $user->can('ticket.resolve') || $user->can('ticket.close'))
            && ! $user->can('ticket.assign')) {
            return $this->isAssignedEligibleHandler($user, $ticket) ?: Response::denyAsNotFound();
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

    public function comment(User $user, Ticket $ticket): Response|bool
    {
        if (! $this->sameCompany($user, $ticket) || ($this->isCustomerUser($user) && ! $this->ownsTicket($user, $ticket))) {
            return Response::denyAsNotFound();
        }

        return true;
    }

    public function attach(User $user, Ticket $ticket): Response|bool
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

    public function delete(User $user, Ticket $ticket): Response|bool
    {
        return $this->sameCompany($user, $ticket) ? $user->can('ticket.delete') : Response::denyAsNotFound();
    }

    public function assign(User $user, Ticket $ticket): Response|bool
    {
        if (! $this->sameCompany($user, $ticket)) {
            return Response::denyAsNotFound();
        }

        if (! $user->is_active) {
            return false;
        }

        if ($ticket->assigned_to !== null) {
            return $user->can('ticket.reassign');
        }

        return $user->can('ticket.assign');
    }

    public function reassign(User $user, Ticket $ticket): Response|bool
    {
        if (! $this->sameCompany($user, $ticket)) {
            return Response::denyAsNotFound();
        }

        return $ticket->assigned_to !== null && $user->can('ticket.reassign');
    }

    public function start(User $user, Ticket $ticket): Response|bool
    {
        return $this->mayStart($user, $ticket) ?: $this->refusal($user, $ticket);
    }

    public function resolve(User $user, Ticket $ticket): Response|bool
    {
        return $this->mayResolve($user, $ticket) ?: $this->refusal($user, $ticket);
    }

    /** A checker (any ticket.close holder in the company other than the handler who resolved it) closes the work. */
    public function close(User $user, Ticket $ticket): Response|bool
    {
        return $this->mayClose($user, $ticket) ?: $this->refusal($user, $ticket);
    }

    public function spawnSpk(User $user, Ticket $ticket): Response|bool
    {
        return $this->sameCompany($user, $ticket) ? $user->can('ticket.spawn_spk') : Response::denyAsNotFound();
    }

    private function mayStart(User $user, Ticket $ticket): bool
    {
        return $this->sameCompany($user, $ticket)
            && $ticket->status === 'assigned'
            && $user->can('ticket.start')
            && $this->isAssignedEligibleHandler($user, $ticket);
    }

    private function mayResolve(User $user, Ticket $ticket): bool
    {
        return $this->sameCompany($user, $ticket)
            && $ticket->status === 'on_progress'
            && $user->can('ticket.resolve')
            && $this->isAssignedEligibleHandler($user, $ticket);
    }

    private function mayClose(User $user, Ticket $ticket): bool
    {
        return $this->sameCompany($user, $ticket)
            && $user->is_active
            && $ticket->status === 'resolved'
            && (int) $ticket->assigned_to !== (int) $user->id
            && $user->can('ticket.close');
    }

    /** What the user is told when an ability is refused: nothing they could not already see of the ticket. */
    private function refusal(User $user, Ticket $ticket): Response|bool
    {
        $view = $this->view($user, $ticket);

        return $view === true ? false : $view;
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
            'assigned' => $this->mayStart($user, $ticket),
            'on_progress' => $this->mayResolve($user, $ticket),
            'resolved' => $this->mayClose($user, $ticket),
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
