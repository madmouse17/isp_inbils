<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\SPK\Models\WorkOrder;
use Modules\Ticketing\Models\Ticket;

class DispatchItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();

        if ($this->resource instanceof Ticket) {
            /** @var Ticket $ticket */
            $ticket = $this->resource;
            $canView = (bool) $user?->can('view', $ticket);

            return [
                'id' => $ticket->id,
                'domain' => 'ticket',
                'composite_id' => "ticket:{$ticket->id}",
                'code' => $ticket->code,
                'title' => $ticket->title,
                'priority' => $ticket->priority,
                'status' => $ticket->status,
                'requester' => $ticket->relationLoaded('customer') && $ticket->customer
                    ? ['id' => $ticket->customer->id, 'name' => $ticket->customer->name, 'code' => $ticket->customer->code]
                    : null,
                'assigned_to' => $ticket->assigned_to,
                'assignee' => $ticket->relationLoaded('assignee') && $ticket->assignee
                    ? ['id' => $ticket->assignee->id, 'name' => $ticket->assignee->name]
                    : null,
                'submitted_at' => $ticket->created_at?->toISOString(),
                'age_seconds' => $ticket->created_at ? max(0, now()->diffInSeconds($ticket->created_at)) : null,
                'due_at' => $ticket->sla_deadline?->toISOString(),
                'sla_breached' => (bool) $ticket->is_sla_breached,
                'updated_at' => $ticket->updated_at?->utc()->format('Y-m-d\TH:i:s.v\Z'),
                'summary' => [
                    'category' => $ticket->relationLoaded('category') && $ticket->category ? $ticket->category->name : null,
                    'customer_name' => $ticket->relationLoaded('customer') && $ticket->customer ? $ticket->customer->name : null,
                    'sla_deadline' => $ticket->sla_deadline?->toISOString(),
                ],
                'actions' => [
                    'assign' => (bool) $user?->can('assign', $ticket),
                    'view' => $canView,
                ],
                'detail_url' => $canView ? route('admin.tickets.show', $ticket) : null,
                'assign_url' => $user?->can('assign', $ticket)
                    ? route('admin.tickets.assign', $ticket) : null,
            ];
        }

        if ($this->resource instanceof WorkOrder) {
            /** @var WorkOrder $wo */
            $wo = $this->resource;
            $canView = (bool) $user?->can('view', $wo);

            return [
                'id' => $wo->id,
                'domain' => 'spk',
                'composite_id' => "spk:{$wo->id}",
                'code' => $wo->code,
                'type' => $wo->type,
                'title' => $wo->title,
                'priority' => $wo->priority,
                'status' => $wo->status,
                'requester' => $wo->relationLoaded('customer') && $wo->customer
                    ? ['id' => $wo->customer->id, 'name' => $wo->customer->name, 'code' => $wo->customer->code]
                    : ($wo->relationLoaded('creator') && $wo->creator ? ['id' => $wo->creator->id, 'name' => $wo->creator->name] : null),
                'assigned_to' => $wo->assigned_to,
                'assignee' => $wo->relationLoaded('assignee') && $wo->assignee
                    ? ['id' => $wo->assignee->id, 'name' => $wo->assignee->name]
                    : null,
                'submitted_at' => $wo->created_at?->toISOString(),
                'age_seconds' => $wo->created_at ? max(0, now()->diffInSeconds($wo->created_at)) : null,
                'scheduled_date' => $wo->scheduled_date?->toDateString(),
                'updated_at' => $wo->updated_at?->utc()->format('Y-m-d\TH:i:s.v\Z'),
                'summary' => [
                    'type' => $wo->type,
                    'customer_name' => $wo->relationLoaded('customer') && $wo->customer ? $wo->customer->name : null,
                    'scheduled_date' => $wo->scheduled_date?->toDateString(),
                    'items_count' => $wo->relationLoaded('items') ? $wo->items->count() : 0,
                ],
                'actions' => [
                    'assign' => (bool) $user?->can('assign', $wo),
                    'view' => $canView,
                ],
                'detail_url' => $canView ? route('admin.spk.show', $wo) : null,
                'assign_url' => $user?->can('assign', $wo)
                    ? route('admin.spk.assign', $wo) : null,
            ];
        }

        return parent::toArray($request);
    }
}
