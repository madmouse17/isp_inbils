<?php

namespace Modules\Ticketing\Services;

use App\Services\Core\AuditService;
use App\Services\Core\CompanyService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\SPK\Models\WorkOrder;
use Modules\SPK\Services\SpkService;
use Modules\Ticketing\Models\Ticket;

class TicketService
{
    public static function generateCode(): string
    {
        $year = now()->year;
        $prefix = "TKT-{$year}-";

        $last = Ticket::forCompany(CompanyService::currentId())
            ->where('code', 'like', $prefix.'%')
            ->orderByDesc('code')
            ->lockForUpdate()
            ->first();

        $next = $last ? ((int) substr($last->code, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }

    public static function assign(Ticket $ticket, int $handlerId, int $assignedBy): Ticket
    {
        abort_if(! in_array($ticket->status, ['open']), 422, 'Ticket must be open to assign.');

        return DB::transaction(function () use ($ticket, $handlerId) {
            $ticket->update(['assigned_to' => $handlerId, 'status' => 'assigned']);

            AuditService::log('ticket', 'assigned', ['handler_id' => $handlerId], $ticket);

            return $ticket->fresh();
        });
    }

    public static function startWork(Ticket $ticket): Ticket
    {
        abort_if(! in_array($ticket->status, ['open', 'assigned']), 422, 'Ticket must be open or assigned to start.');

        return DB::transaction(function () use ($ticket) {
            $ticket->update([
                'status' => 'on_progress',
                'first_response_at' => $ticket->first_response_at ?? now(),
            ]);

            AuditService::log('ticket', 'started', ['code' => $ticket->code], $ticket);

            return $ticket->fresh();
        });
    }

    public static function resolve(Ticket $ticket, string $resolutionNote): Ticket
    {
        abort_if(! in_array($ticket->status, ['on_progress', 'assigned']), 422, 'Ticket must be on progress or assigned to resolve.');

        return DB::transaction(function () use ($ticket, $resolutionNote) {
            $ticket->update([
                'status' => 'resolved',
                'resolved_at' => now(),
                'resolution_note' => $resolutionNote,
            ]);

            AuditService::log('ticket', 'resolved', ['code' => $ticket->code], $ticket);

            return $ticket->fresh();
        });
    }

    public static function close(Ticket $ticket): Ticket
    {
        abort_if($ticket->status !== 'resolved', 422, 'Ticket must be resolved to close.');

        return DB::transaction(function () use ($ticket) {
            $ticket->update(['status' => 'closed', 'closed_at' => now()]);

            AuditService::log('ticket', 'closed', ['code' => $ticket->code], $ticket);

            return $ticket->fresh();
        });
    }

    public static function spawnSpk(Ticket $ticket): WorkOrder
    {
        abort_if($ticket->spawned_spk_id, 422, 'SPK already spawned for this ticket.');
        abort_if(! in_array($ticket->status, ['on_progress', 'assigned']), 422, 'Ticket must be on progress or assigned to spawn SPK.');

        $wo = DB::transaction(function () use ($ticket) {
            $wo = WorkOrder::create([
                'code' => SpkService::generateCode(),
                'type' => 'maintenance',
                'title' => 'SPK from Ticket '.$ticket->code,
                'description' => $ticket->description,
                'status' => 'generated',
                'customer_id' => $ticket->customer_id,
                'subscription_id' => $ticket->subscription_id,
                'location_id' => $ticket->location_id,
                'source' => 'ticket',
                'priority' => $ticket->priority,
                'created_by' => Auth::id(),
            ]);

            $ticket->update(['spawned_spk_id' => $wo->id]);

            AuditService::log('ticket', 'spawned_spk', ['ticket_id' => $ticket->id, 'spk_id' => $wo->id], $ticket);

            return $wo;
        });

        return $wo;
    }
}
