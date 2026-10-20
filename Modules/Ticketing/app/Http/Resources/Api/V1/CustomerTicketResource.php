<?php

namespace Modules\Ticketing\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;
use Modules\Ticketing\Models\Ticket;

/** @mixin Ticket */
class CustomerTicketResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return array_merge($this->summary(), [
            /** False once the ticket is closed: replies are refused and the customer is pointed to a new ticket. */
            'can_reply' => $this->status !== 'closed',
            /** When a reply of the customer last brought the resolved ticket back; null if it never did. */
            'reopened_at' => $this->reopened_at,
            'description' => $this->when($this->resource->relationLoaded('comments'), $this->description),
            'resolution_note' => $this->when($this->resource->relationLoaded('comments'), $this->resolution_note),
            'comments' => CustomerTicketCommentResource::collection($this->whenLoaded('comments')),
            'attachments' => TicketAttachmentResource::collection($this->whenLoaded('media', fn () => $this->resource->media->each->setRelation('model', $this->resource))),
        ]);
    }

    /** @return array<string, mixed> */
    protected function summary(): array
    {
        // A closed ticket keeps the names it closed with; the rest follow the live records.
        $handler = $this->assignee_name_snapshot ?? $this->assignee?->name;

        return [
            'id' => $this->public_id,
            'code' => $this->code,
            'title' => $this->title,
            'status' => $this->status,
            'priority' => $this->priority,
            'category' => $this->category ? ['id' => $this->category->public_id, 'name' => $this->category_name_snapshot ?? $this->category->name] : null,
            'subscription' => $this->subscription ? ['id' => $this->subscription->public_id, 'code' => $this->subscription->code] : null,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'resolved_at' => $this->resolved_at,
            'first_response_at' => $this->first_response_at,
            'response_deadline' => $this->response_deadline,
            'sla_deadline' => $this->sla_deadline,
            /** First name of the staff member handling the ticket; null while unassigned. The rest of the profile stays internal. */
            'handler_first_name' => $handler === null ? null : Str::of($handler)->trim()->before(' ')->toString(),
        ];
    }
}
