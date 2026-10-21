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
            /** When the ticket last came back to work, by a reply of the customer on a resolved ticket or by staff reopening it; null if it never did. */
            'reopened_at' => $this->reopened_at,
            /**
             * What the customer rated the ticket (value 1 to 5, an optional comment, when); null until they did. A rating stays
             * when the ticket is reopened.
             */
            'rating' => $this->customer_rated_at === null ? null : ['value' => $this->customer_rating, 'comment' => $this->customer_rating_comment, 'rated_at' => $this->customer_rated_at],
            /**
             * True on a resolved ticket the customer has not rated: "Sudah beres" closes it together with a rating.
             *
             * @var bool
             */
            'can_confirm' => $this->status === 'resolved' && $this->customer_rated_at === null && $this->mayConfirm($request),
            /**
             * True on a closed ticket nobody rated: the customer can still rate it.
             *
             * @var bool
             */
            'can_rate' => $this->status === 'closed' && $this->customer_rated_at === null && $this->mayConfirm($request),
            'description' => $this->when($this->resource->relationLoaded('comments'), $this->description),
            'resolution_note' => $this->when($this->resource->relationLoaded('comments'), $this->resolution_note),
            'comments' => CustomerTicketCommentResource::collection($this->whenLoaded('comments')),
            'attachments' => $this->whenLoaded('media', fn () => TicketAttachmentResource::collection($this->resource->media->each->setRelation('model', $this->resource))),
        ]);
    }

    /** The confirmation route asks for the permission to rate and for the customer's own ticket, so the buttons follow it. */
    private function mayConfirm(Request $request): bool
    {
        return (bool) $request->user()?->can('confirm', $this->resource);
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
