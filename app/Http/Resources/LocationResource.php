<?php

namespace App\Http\Resources;

use App\Models\Core\Location;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Location */
class LocationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'parent_id' => $this->parent?->public_id,
            'code' => $this->code,
            'name' => $this->name,
            'type' => $this->type,
            'path' => $this->path,
            'address' => $this->address,
            'lat' => $this->lat,
            'lng' => $this->lng,
            'is_active' => $this->is_active,
            ...$this->lifecycleAttributes(),
            'children' => LocationResource::collection($this->whenLoaded('children')),
        ];
    }
}
