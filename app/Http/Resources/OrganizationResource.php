<?php

namespace App\Http\Resources;

use App\Models\Core\OrganizationUnit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin OrganizationUnit */
class OrganizationResource extends JsonResource
{
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
            'phone' => $this->phone,
            'email' => $this->email,
            'is_active' => $this->is_active,
            'children_count' => $this->whenCounted('children'),
            ...$this->lifecycleAttributes(),
        ];
    }
}
