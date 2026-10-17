<?php

namespace Modules\Service\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Service\Models\Announcement;

/** @mixin Announcement */
class CustomerAnnouncementResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'title' => $this->title,
            'body' => $this->body,
            'kind' => $this->kind,
            'starts_at' => $this->starts_at,
            'ends_at' => $this->ends_at,
            'published_at' => $this->published_at,
        ];
    }
}
