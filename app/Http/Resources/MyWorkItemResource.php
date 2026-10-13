<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\SPK\Models\WorkOrder;
use Modules\Ticketing\Models\Ticket;

class MyWorkItemResource extends JsonResource
{
    /**
     * @return array{id: int, domain: 'ticket', composite_id: string, code: string, title: string, priority: string, status: string, customer: array{id: int, name: string, code: string}|null, assigned_to: int|null, submitted_at: string|null, age_seconds: int|null, due_at: string|null, sla_breached: bool, updated_at: string|null, summary: array{category: string|null, customer_name: string|null, sla_deadline: string|null}, actions: array{start: bool, resolve: bool, close: bool, view: bool, can_view: bool}, detail_url: string|null}|array{id: int, domain: 'spk', composite_id: string, code: string, type: string, title: string, priority: string, status: string, customer: array{id: int, name: string, code: string}|null, assigned_to: int|null, submitted_at: string|null, age_seconds: int|null, scheduled_date: string|null, updated_at: string|null, summary: array{type: string, customer_name: string|null, scheduled_date: string|null, items_count: int}, actions: array{start: bool, submit: bool, view: bool, can_view: bool}, detail_url: string|null}
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
                'customer' => $ticket->relationLoaded('customer') && $ticket->customer
                    ? ['id' => $ticket->customer->id, 'name' => $ticket->customer->name, 'code' => $ticket->customer->code]
                    : null,
                'assigned_to' => $ticket->assigned_to,
                'submitted_at' => $ticket->created_at?->toISOString(),
                'age_seconds' => $ticket->created_at ? max(0, (int) $ticket->created_at->diffInSeconds(now())) : null,
                'due_at' => $ticket->sla_deadline?->toISOString(),
                'sla_breached' => (bool) $ticket->is_sla_breached,
                'updated_at' => $ticket->updated_at?->toISOString(),
                'summary' => [
                    'category' => $ticket->relationLoaded('category') && $ticket->category ? $ticket->category->name : null,
                    'customer_name' => $ticket->relationLoaded('customer') && $ticket->customer ? $ticket->customer->name : null,
                    'sla_deadline' => $ticket->sla_deadline?->toISOString(),
                ],
                'actions' => [
                    'start' => (bool) $user?->can('start', $ticket),
                    'resolve' => (bool) $user?->can('resolve', $ticket),
                    'close' => (bool) $user?->can('close', $ticket),
                    'view' => $canView,
                    'can_view' => $canView,
                ],
                'detail_url' => $canView ? route('admin.tickets.show', $ticket) : null,
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
                'customer' => $wo->relationLoaded('customer') && $wo->customer
                    ? ['id' => $wo->customer->id, 'name' => $wo->customer->name, 'code' => $wo->customer->code]
                    : null,
                'assigned_to' => $wo->assigned_to,
                'submitted_at' => $wo->created_at?->toISOString(),
                'age_seconds' => $wo->created_at ? max(0, (int) $wo->created_at->diffInSeconds(now())) : null,
                'scheduled_date' => $wo->scheduled_date?->toDateString(),
                'updated_at' => $wo->updated_at?->toISOString(),
                'summary' => [
                    'type' => $wo->type,
                    'customer_name' => $wo->relationLoaded('customer') && $wo->customer ? $wo->customer->name : null,
                    'scheduled_date' => $wo->scheduled_date?->toDateString(),
                    'items_count' => $wo->relationLoaded('items') ? $wo->items->count() : 0,
                ],
                'actions' => [
                    'start' => (bool) $user?->can('start', $wo),
                    'submit' => (bool) $user?->can('submit', $wo),
                    'view' => $canView,
                    'can_view' => $canView,
                ],
                'detail_url' => $canView ? route('admin.spk.show', $wo) : null,
            ];
        }

        return parent::toArray($request);
    }
}
