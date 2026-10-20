<?php

namespace Modules\Ticketing\Http\Resources\Api\V1;

use App\Support\FieldCheckIn;
use Illuminate\Http\Request;
use Modules\Ticketing\Models\Ticket;

/** @mixin Ticket */
class TechnicianTicketResource extends CustomerTicketResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return array_merge($this->summary(), [
            /** What counted as the first response: a public staff reply ('reply'), the Mulai button ('start') or nothing yet (null). */
            'first_response_source' => $this->first_response_source,
            'check_in' => FieldCheckIn::apiSummary($this->resource, 'ticket'),
            'description' => $this->description,
            'resolution_note' => $this->resolution_note,
            'customer' => $this->customer ? [
                'name' => $this->customer->name,
                'phone' => $this->customer->phone,
            ] : null,
            'subscription' => $this->subscription ? ['id' => $this->subscription->public_id, 'code' => $this->subscription->code] : null,
            'location' => $this->location ? [
                'name' => $this->location->name,
                'address' => $this->location->address,
                'lat' => $this->location->lat === null ? null : (float) $this->location->lat,
                'lng' => $this->location->lng === null ? null : (float) $this->location->lng,
            ] : null,
            'comments' => TechnicianTicketCommentResource::collection($this->whenLoaded('comments')),
            'attachments' => TicketAttachmentResource::collection($this->whenLoaded('media', fn () => $this->resource->media->each->setRelation('model', $this->resource))),
        ]);
    }
}
