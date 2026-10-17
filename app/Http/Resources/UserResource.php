<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'email' => $this->email,
            'company_id' => $this->company?->public_id,
            'is_active' => $this->is_active,
            'last_login_at' => $this->last_login_at,
            'roles' => $this->roles->pluck('name')->values(),
            'created_at' => $this->created_at,
            'two_factor_enabled' => $this->hasTwoFactorEnabled(),
            'can' => [
                'delete' => $request->user()?->can('delete', $this->resource) ?? false,
                // Nothing to reset unless the user has it on; this also keeps the check off most index rows.
                'reset_two_factor' => $this->hasTwoFactorEnabled()
                    && ($request->user()?->can('resetTwoFactor', $this->resource) ?? false),
            ],
        ];
    }
}
