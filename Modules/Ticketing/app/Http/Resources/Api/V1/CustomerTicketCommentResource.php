<?php

namespace Modules\Ticketing\Http\Resources\Api\V1;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerTicketCommentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $author = $this->resource->relationLoaded('author') ? $this->resource->getRelation('author') : null;

        return [
            /** @var int */
            'id' => $this->resource->getKey(),
            'body' => $this->resource->getAttribute('body'),
            'author' => $author instanceof User ? ['name' => $author->name] : null,
            'mine' => (int) $this->resource->getAttribute('author_id') === (int) $request->user()?->id,
            'created_at' => $this->resource->getAttribute('created_at'),
        ];
    }
}
