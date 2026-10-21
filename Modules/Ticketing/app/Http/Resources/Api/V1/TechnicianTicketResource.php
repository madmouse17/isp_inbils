<?php

namespace Modules\Ticketing\Http\Resources\Api\V1;

use App\Models\Core\CustomerAddress;
use App\Models\Core\Location;
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
            /**
             * Where the technician goes: the installation address of the subscription (source 'installation'), else the location of the
             * ticket (source 'pop'); null when it has neither. The check-in is measured against this same place, so the header and the
             * distance never name two places. `location` above stays the POP.
             */
            'site' => $this->site(),
            'comments' => TechnicianTicketCommentResource::collection($this->whenLoaded('comments')),
            'attachments' => $this->whenLoaded('media', fn () => TicketAttachmentResource::collection($this->resource->media->each->setRelation('model', $this->resource))),
        ]);
    }

    /** @return array{address: ?string, lat: ?float, lng: ?float, source: 'installation'|'pop'}|null */
    private function site(): ?array
    {
        // The same pick as the check-in's (TicketController::start): the installation address, else the location.
        $installation = $this->subscription?->installationAddress;
        /** @var CustomerAddress|Location|null $place */
        $place = $installation ?? $this->location;

        return $place === null ? null : [
            'address' => $place->address,
            'lat' => $place->lat === null ? null : (float) $place->lat,
            'lng' => $place->lng === null ? null : (float) $place->lng,
            'source' => $installation === null ? 'pop' : 'installation',
        ];
    }
}
