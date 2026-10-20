<?php

namespace Modules\Ticketing\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TicketCommentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'author_id' => $this->author?->public_id,
            'body' => $this->body,
            'is_internal' => $this->is_internal,
            'created_at' => $this->created_at,
            // The ticket page shows a name and tells staff from the customer by the id. The user resource would add the email,
            // roles and 2FA state of staff for every viewer, and a roles query and a policy check for every comment.
            'author' => $this->whenLoaded('author', fn (User $author): array => ['id' => $author->public_id, 'name' => $author->name]),
        ];
    }
}
