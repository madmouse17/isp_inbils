<?php

namespace App\Http\Requests\Platform\CompanyRoles;

use App\Models\Core\Company;
use App\Models\Role;
use App\Models\User;
use App\Support\Platform\TenantRoles;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Deletes a role of the tenant. A role that still has users needs `move_to`: another role of the same company that
 * takes them over. A role of another tenant answers 404 before any rule runs.
 */
class DestroyCompanyRoleRequest extends FormRequest
{
    private ?Role $role = null;

    public function authorize(): bool
    {
        $this->role();

        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        $role = $this->role();
        $hasUsers = DB::table('model_has_roles')->where('role_id', $role->id)->where('model_type', User::class)->exists();

        return [
            'move_to' => [
                Rule::requiredIf($hasUsers), 'nullable', 'string',
                Rule::exists('roles', 'public_id')->where('company_id', $role->company_id)->where('guard_name', 'web'),
            ],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['move_to' => 'peran tujuan'];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->moveTo()?->is($this->role())) {
                $validator->errors()->add('move_to', 'Pilih peran lain sebagai tujuan.');
            }
        }];
    }

    public function role(): Role
    {
        /** @var Company $tenant */
        $tenant = $this->route('company');

        return $this->role ??= TenantRoles::role($tenant, $this->route('role'));
    }

    /** The role that takes over the users; null when none was asked for. */
    public function moveTo(): ?Role
    {
        $publicId = $this->input('move_to');

        if (! is_string($publicId) || $publicId === '') {
            return null;
        }

        return Role::withoutCompany()->wherePublicId($publicId)->where('company_id', $this->role()->company_id)->where('guard_name', 'web')->first();
    }
}
