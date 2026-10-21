<?php

namespace Modules\SPK\Http\Resources;

use App\Http\Resources\CustomerResource;
use App\Http\Resources\LocationResource;
use App\Http\Resources\MediaResource;
use App\Http\Resources\SubscriptionResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkOrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'code' => $this->code,
            'type' => $this->type,
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status,
            'customer_id' => $this->customer?->public_id,
            'subscription_id' => $this->subscription?->public_id,
            'location_id' => $this->location?->public_id,
            'assigned_to' => $this->assignee?->public_id,
            'ticket_id' => $this->ticket?->public_id,
            'source' => $this->source,
            'priority' => $this->priority,
            'scheduled_date' => $this->scheduled_date,
            'started_at' => $this->started_at,
            'completed_at' => $this->completed_at,
            'result' => $this->result,
            'rejection_reason' => $this->rejection_reason,
            'approved_by' => $this->approver?->public_id,
            'approved_at' => $this->approved_at,
            'rejected_by' => $this->rejector?->public_id,
            'rejected_at' => $this->rejected_at,
            'created_by' => $this->creator?->public_id,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at?->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'technician_name_snapshot' => $this->technician_name_snapshot,
            'location_code_snapshot' => $this->location_code_snapshot,
            ...$this->lifecycleAttributes(),
            'customer' => $this->whenLoaded('customer', fn (): ?array => CustomerResource::summary($this->customer)),
            'subscription' => $this->whenLoaded('subscription', fn (): ?array => SubscriptionResource::summary($this->subscription)),
            'location' => new LocationResource($this->whenLoaded('location')),
            'assignee' => $this->whenLoaded('assignee', fn (): ?array => $this->assignee === null ? null : ['id' => $this->assignee->public_id, 'name' => $this->assignee->name]),
            'items' => WorkOrderItemResource::collection($this->whenLoaded('items')),
            'assignments' => WorkOrderAssignmentResource::collection($this->whenLoaded('assignments')),
            // Only the detail page shows the photos; the list and the edit form would pay a media query per row for nothing.
            'evidence' => $this->when($request->routeIs('admin.spk.show'), fn () => MediaResource::collection($this->getMedia('evidence'))),
        ];
    }
}
