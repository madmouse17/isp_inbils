<?php

namespace App\Http\Requests\Admin;

use App\Rules\NoStackedSodConflict;
use App\Services\Core\CompanyService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('users.manage') ?? false;
    }

    /**
     * The roles are the ones of this company. Which of them the user may give or take is decided by
     * CompanyRoleService::syncUserRoles when the user is saved.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $user = $this->route('user');

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user?->id)],
            'password' => ['nullable', 'string', 'min:8'],
            'is_active' => ['boolean'],
            'roles' => ['array', new NoStackedSodConflict($this->user())],
            'roles.*' => ['string', Rule::exists('roles', 'name')->where('company_id', CompanyService::currentId())->where('guard_name', 'web')],
        ];
    }
}
