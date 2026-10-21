<?php

namespace App\Http\Resources;

use App\Services\Core\CompanyRoleService;
use App\Support\OptimisticLock;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RoleResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'template_key' => $this->template_key,
            'permissions' => $this->permissions->pluck('name')->values(),
            'permissions_count' => $this->whenCounted('permissions'),
            'users_count' => $this->whenCounted('users'),
            'version' => OptimisticLock::version($this->resource),
            'can' => [
                'update' => $user?->can('update', $this->resource) ?? false,
                // The role of customer accounts stays: CustomerService gives it to every new one (G6).
                'delete' => $this->template_key !== CompanyRoleService::CUSTOMER_TEMPLATE
                    && ($user?->can('delete', $this->resource) ?? false),
            ],
        ];
    }
}
