<?php

namespace Modules\Ticketing\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Ticketing\Models\Ticket;

/** @mixin Ticket */
class CustomerTicketResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return array_merge($this->summary(), [
            'description' => $this->when($this->resource->relationLoaded('comments'), $this->description),
            'resolution_note' => $this->when($this->resource->relationLoaded('comments'), $this->resolution_note),
            'comments' => CustomerTicketCommentResource::collection($this->whenLoaded('comments')),
            'attachments' => TicketAttachmentResource::collection($this->whenLoaded('media')),
        ]);
    }

    /** @return array<string, mixed> */
    protected function summary(): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'title' => $this->title,
            'status' => $this->status,
            'priority' => $this->priority,
            'category' => $this->category ? ['id' => $this->category->id, 'name' => $this->category->name] : null,
            'subscription' => $this->subscription ? ['id' => $this->subscription->id, 'code' => $this->subscription->code] : null,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'resolved_at' => $this->resolved_at,
        ];
    }
}
