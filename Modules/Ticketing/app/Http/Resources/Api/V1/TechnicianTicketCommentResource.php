<?php

namespace Modules\Ticketing\Http\Resources\Api\V1;

use Illuminate\Http\Request;

class TechnicianTicketCommentResource extends CustomerTicketCommentResource
{
    /** @return array{id: int, body: string, author: array{name: string}|null, mine: bool, created_at: string, is_internal: bool} */
    public function toArray(Request $request): array
    {
        return parent::toArray($request) + [
            'is_internal' => (bool) $this->resource->getAttribute('is_internal'),
        ];
    }
}
