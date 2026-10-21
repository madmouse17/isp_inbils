<?php

namespace App\Http\Resources;

use App\Models\RoleTemplate;
use App\Services\Platform\RoleTemplateService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin RoleTemplate */
class RoleTemplateResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'key' => $this->key,
            'name' => $this->name,
            'permissions' => $this->whenLoaded('permissions', fn () => $this->permissions->pluck('name')->sort()->values()),
            'permissions_count' => $this->whenCounted('permissions'),
            'deletable' => RoleTemplateService::isDeletable($this->resource),
        ];
    }
}
