<?php

namespace App\Http\Requests\Admin;

use App\Rules\NoSodConflict;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('roles.manage') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $role = $this->route('role');

        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('roles', 'name')->where('guard_name', 'web')->ignore($role?->id)],
            'permissions' => ['array', new NoSodConflict($this->user())],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ];
    }
}
