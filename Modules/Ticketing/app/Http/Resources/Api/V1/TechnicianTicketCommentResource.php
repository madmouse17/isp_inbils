<?php

namespace Modules\Ticketing\Http\Resources\Api\V1;

use Illuminate\Http\Request;

class TechnicianTicketCommentResource extends CustomerTicketCommentResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return parent::toArray($request) + [
            'is_internal' => (bool) $this->resource->getAttribute('is_internal'),
        ];
    }
}
