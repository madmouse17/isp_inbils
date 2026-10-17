<?php

namespace Modules\Ticketing\Http\Resources;

use App\Http\Resources\CustomerResource;
use App\Http\Resources\LocationResource;
use App\Http\Resources\MediaResource;
use App\Http\Resources\SubscriptionResource;
use App\Http\Resources\UserResource;
use App\Support\FieldCheckIn;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;
use Modules\NetworkAsset\Http\Resources\NetworkAssetResource;
use Modules\Ticketing\Models\Ticket;
use Modules\Ticketing\Services\TicketService;

/** @mixin Ticket */
class TicketResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'code' => $this->code,
            'title' => $this->title,
            'description' => $this->description,
            'source' => $this->source,
            'category_id' => $this->category?->public_id,
            'status' => $this->status,
            'priority' => $this->priority,
            'customer_id' => $this->customer?->public_id,
            'subscription_id' => $this->subscription?->public_id,
            'network_asset_id' => $this->networkAsset?->public_id,
            'location_id' => $this->location?->public_id,
            'assigned_to' => $this->assignee?->public_id,
            'spawned_spk_id' => $this->spawnedSpk?->public_id,
            'sla_deadline' => $this->sla_deadline,
            'response_deadline' => $this->response_deadline,
            'first_response_at' => $this->first_response_at,
            'resolved_at' => $this->resolved_at,
            'closed_at' => $this->closed_at,
            'resolution_note' => $this->resolution_note,
            'is_sla_breached' => $this->is_sla_breached,
            'is_response_breached' => $this->is_response_breached,
            'created_by' => $this->creator?->public_id,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at?->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'category' => new TicketCategoryResource($this->whenLoaded('category')),
            'customer' => new CustomerResource($this->whenLoaded('customer')),
            'subscription' => new SubscriptionResource($this->whenLoaded('subscription')),
            'network_asset' => new NetworkAssetResource($this->whenLoaded('networkAsset')),
            'location' => new LocationResource($this->whenLoaded('location')),
            'assignee' => new UserResource($this->whenLoaded('assignee')),
            'comments' => TicketCommentResource::collection($this->whenLoaded('comments')),
            'attachments' => MediaResource::collection($this->getMedia('attachments')),
            // Show page only: naming the source loads the subscription's package and tier.
            'deadline_source' => $this->when($request->routeIs('admin.tickets.show'), fn (): array => TicketService::deadlineSource($this->resource)),
            // Show page only: the page words the check-in itself, and the technician's own coordinates stay out of it.
            'check_in' => $this->when($request->routeIs('admin.tickets.show'), fn (): ?array => ($record = FieldCheckIn::recordFor($this->resource, 'ticket')) === null ? null : Arr::only($record, ['status', 'distance_m', 'accuracy_m', 'reason'])),
            // Show page only: lists and exports skip the per-row policy checks.
            'can' => $this->when($request->routeIs('admin.tickets.show'), fn (): array => [
                'start' => (bool) $request->user()?->can('start', $this->resource),
                'close' => (bool) $request->user()?->can('close', $this->resource),
            ]),
        ];
    }
}
