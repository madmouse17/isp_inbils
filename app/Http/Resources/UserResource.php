<?php

namespace App\Http\Resources;

use App\Models\User;
use App\Services\Core\CompanyService;
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
        $actor = $request->user();
        // Login fields (email, secret, status) are edited in the home company only: a member homed elsewhere shows
        // here for his roles, so he is neither edited, deleted nor reset from this company.
        $memberOnly = $this->company_id !== null && (int) $this->company_id !== CompanyService::currentId();

        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'email' => $this->email,
            'company_id' => $this->company?->public_id,
            'is_active' => $this->is_active,
            'last_login_at' => $this->last_login_at,
            // The roles he holds in the company the request acts in: a role belongs to one company.
            'roles' => $this->roles->pluck('name')->values(),
            'is_member_only' => $memberOnly,
            'created_at' => $this->created_at,
            'two_factor_enabled' => $this->hasTwoFactorEnabled(),
            // The policy's answers. They judge the user in every company he belongs to and read what the list loaded for
            // the whole page (UserController::index), so a row costs no query: keep what they read there.
            'can' => [
                'delete' => ! $memberOnly && ($actor?->can('delete', $this->resource) ?? false),
                // Nothing to reset unless the user has it on; this also keeps the check off most index rows.
                'reset_two_factor' => ! $memberOnly && $this->hasTwoFactorEnabled()
                    && ($actor?->can('resetTwoFactor', $this->resource) ?? false),
                'assign_roles' => $actor?->can('assignRoles', $this->resource) ?? false,
            ],
        ];
    }
}
