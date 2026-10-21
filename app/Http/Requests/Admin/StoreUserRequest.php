<?php

namespace App\Http\Requests\Admin;

use App\Rules\NoStackedSodConflict;
use App\Services\Core\CompanyService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('users.manage') ?? false;
    }

    /**
     * The roles are the ones of this company. Which of them the user may give is decided by
     * CompanyRoleService::syncUserRoles when the user is saved: it refuses a role richer than the actor.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'is_active' => ['boolean'],
            'roles' => ['array', new NoStackedSodConflict($this->user())],
            'roles.*' => ['string', Rule::exists('roles', 'name')->where('company_id', CompanyService::currentId())->where('guard_name', 'web')],
        ];
    }
}
