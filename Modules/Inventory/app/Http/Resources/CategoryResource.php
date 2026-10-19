<?php

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CategoryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'parent_id' => $this->parent?->public_id,
            'unit_id' => $this->unit?->public_id,
            'name' => $this->name,
            'code' => $this->code,
            'description' => $this->description,
            'is_active' => $this->is_active,
            ...$this->lifecycleAttributes(),
            'unit' => new UnitResource($this->whenLoaded('unit')),
            'children_count' => $this->whenCounted('children'),
        ];
    }
}
