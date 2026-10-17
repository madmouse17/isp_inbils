<?php

namespace App\Http\Requests\Platform\Roles;

use App\Rules\NoSodConflict;
use App\Services\Platform\PlatformRoleService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

class UpdateRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * A seeded role keeps its name; only its permissions change. The seeded `admin` role alone may break the
     * separation-of-duties set (break-glass).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Role $role */
        $role = $this->route('role');

        return [
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('roles', 'name')->where('guard_name', 'web')->ignore($role->id),
                ...(PlatformRoleService::isProtected($role) ? [Rule::in([$role->name])] : []),
            ],
            'permissions' => ['array', new NoSodConflict(exempt: $role->name === 'admin')],
            'permissions.*' => ['string', Rule::exists('permissions', 'name')->where('guard_name', 'web')],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['name.in' => 'Nama peran bawaan tidak dapat diubah.'];
    }

    /** @return list<string> */
    public function permissionNames(): array
    {
        return array_values($this->validated('permissions') ?? []);
    }
}
