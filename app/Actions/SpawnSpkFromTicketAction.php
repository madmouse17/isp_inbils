<?php

namespace App\Actions;

use App\Services\Core\AuditService;
use App\Services\Core\NumberSequenceService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\SPK\Models\WorkOrder;
use Modules\Ticketing\Models\Ticket;

/**
 * Cross-module write orchestration: Ticketing -> SPK.
 * Lives in app/Actions (not Modules/Ticketing or Modules/SPK) so neither
 * module's Controller/Service imports the other's Model directly.
 */
class SpawnSpkFromTicketAction
{
    public static function execute(Ticket $ticket): WorkOrder
    {
        abort_if($ticket->spawned_spk_id, 422, 'SPK already spawned for this ticket.');
        abort_if(! in_array($ticket->status, ['on_progress', 'assigned']), 422, 'Ticket must be on progress or assigned to spawn SPK.');

        return DB::transaction(function () use ($ticket) {
            $wo = WorkOrder::create([
                'code' => NumberSequenceService::generate('spk', 'SPK', $ticket->company_id),
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
    }
}
