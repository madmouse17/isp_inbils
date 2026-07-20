<?php

namespace Modules\Ticketing\Http\Resources;

use App\Http\Resources\CustomerResource;
use App\Http\Resources\LocationResource;
use App\Http\Resources\MediaResource;
use App\Http\Resources\SubscriptionResource;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\NetworkAsset\Http\Resources\NetworkAssetResource;

class TicketResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'title' => $this->title,
            'description' => $this->description,
            'source' => $this->source,
            'category_id' => $this->category_id,
            'status' => $this->status,
            'priority' => $this->priority,
            'customer_id' => $this->customer_id,
            'subscription_id' => $this->subscription_id,
            'network_asset_id' => $this->network_asset_id,
            'location_id' => $this->location_id,
            'assigned_to' => $this->assigned_to,
            'spawned_spk_id' => $this->spawned_spk_id,
            'sla_deadline' => $this->sla_deadline,
            'first_response_at' => $this->first_response_at,
            'resolved_at' => $this->resolved_at,
            'closed_at' => $this->closed_at,
            'resolution_note' => $this->resolution_note,
            'is_sla_breached' => $this->is_sla_breached,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at,
            'category' => new TicketCategoryResource($this->whenLoaded('category')),
            'customer' => new CustomerResource($this->whenLoaded('customer')),
            'subscription' => new SubscriptionResource($this->whenLoaded('subscription')),
            'network_asset' => new NetworkAssetResource($this->whenLoaded('networkAsset')),
            'location' => new LocationResource($this->whenLoaded('location')),
            'assignee' => new UserResource($this->whenLoaded('assignee')),
            'comments' => TicketCommentResource::collection($this->whenLoaded('comments')),
            'attachments' => MediaResource::collection($this->getMedia('attachments')),
        ];
    }
}
