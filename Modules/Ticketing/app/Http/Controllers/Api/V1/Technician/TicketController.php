<?php

namespace Modules\Ticketing\Http\Controllers\Api\V1\Technician;

use App\Http\Controllers\Controller;
use App\Services\Core\AuditService;
use App\Support\FieldCheckIn;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\Ticketing\Http\Requests\Api\V1\StartTicketRequest;
use Modules\Ticketing\Http\Requests\ResolveTicketRequest;
use Modules\Ticketing\Http\Resources\Api\V1\TechnicianTicketResource;
use Modules\Ticketing\Models\Ticket;
use Modules\Ticketing\Services\TicketService;

class TicketController extends Controller
{
    public function show(int $id): TechnicianTicketResource
    {
        $ticket = $this->ticket($id);
        Gate::authorize('view', $ticket);

        return new TechnicianTicketResource($this->detail($ticket));
    }

    public function start(StartTicketRequest $request, int $id): TechnicianTicketResource
    {
        $ticket = $this->ticket($id);
        $ticket->status === 'assigned' ? Gate::authorize('start', $ticket) : Gate::authorize('view', $ticket);

        $validated = $request->validated();
        $started = DB::transaction(function () use ($ticket, $validated): Ticket {
            $started = TicketService::startWork($ticket);

            if (($validated['check_in'] ?? []) !== []) {
                $started->loadMissing(['location', 'subscription.installationAddress']);
                AuditService::log('ticket', 'check_in', FieldCheckIn::properties(
                    $validated['check_in'],
                    FieldCheckIn::site($started->subscription?->installationAddress ?? $started->location),
                ), $started);
            }

            return $started;
        });

        return new TechnicianTicketResource($this->detail($started));
    }

    public function resolve(ResolveTicketRequest $request, int $id): TechnicianTicketResource
    {
        $ticket = $this->ticket($id);
        $ticket->status === 'on_progress' ? Gate::authorize('resolve', $ticket) : Gate::authorize('view', $ticket);

        $resolved = TicketService::resolve($ticket, $request->validated('resolution_note'));

        return new TechnicianTicketResource($this->detail($resolved));
    }

    private function ticket(int $id): Ticket
    {
        return Ticket::query()->findOrFail($id);
    }

    private function detail(Ticket $ticket): Ticket
    {
        return $ticket->fresh()->load([
            'category:id,name',
            'customer:id,name,phone',
            'subscription:id,code,installation_address_id',
            'subscription.installationAddress:id,address,lat,lng',
            'location:id,name,address,lat,lng',
            'comments' => fn ($query) => $query->orderBy('created_at')->orderBy('id'),
            'comments.author:id,name',
            'media',
        ]);
    }
}
