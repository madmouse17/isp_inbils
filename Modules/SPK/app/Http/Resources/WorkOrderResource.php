<?php

namespace Modules\SPK\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkOrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'type' => $this->type,
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status,
            'customer_id' => $this->customer_id,
            'subscription_id' => $this->subscription_id,
            'location_id' => $this->location_id,
            'assigned_to' => $this->assigned_to,
            'ticket_id' => $this->ticket_id,
            'source' => $this->source,
            'priority' => $this->priority,
            'scheduled_date' => $this->scheduled_date,
            'started_at' => $this->started_at,
            'completed_at' => $this->completed_at,
            'result' => $this->result,
            'rejection_reason' => $this->rejection_reason,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at,
            'customer' => new \App\Http\Resources\CustomerResource($this->whenLoaded('customer')),
            'subscription' => new \App\Http\Resources\SubscriptionResource($this->whenLoaded('subscription')),
            'location' => new \App\Http\Resources\LocationResource($this->whenLoaded('location')),
            'assignee' => new \App\Http\Resources\UserResource($this->whenLoaded('assignee')),
            'items' => WorkOrderItemResource::collection($this->whenLoaded('items')),
            'assignments' => WorkOrderAssignmentResource::collection($this->whenLoaded('assignments')),
            'evidence' => WorkOrderEvidenceResource::collection($this->whenLoaded('evidence')),
        ];
    }
}
