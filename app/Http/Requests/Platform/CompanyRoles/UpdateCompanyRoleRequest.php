<?php

namespace App\Http\Requests\Platform\CompanyRoles;

use App\Models\Role;
use App\Support\OptimisticLock;
use App\Support\Platform\TenantRoles;
use Illuminate\Validation\Rule;

/** Renames a role of the tenant or replaces its permissions. A role of another tenant answers 404 before any rule runs. */
class UpdateCompanyRoleRequest extends StoreCompanyRoleRequest
{
    private ?Role $role = null;

    public function authorize(): bool
    {
        $this->role();

        return true;
    }

    public function role(): Role
    {
        return $this->role ??= TenantRoles::role($this->tenant(), $this->route('role'));
    }

    /** @return array<string, array<mixed>> */
    protected function scopeRules(): array
    {
        $role = $this->role();

        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('roles', 'name')->where('company_id', $role->company_id)->where('guard_name', 'web')->ignore($role->id)],
            OptimisticLock::FIELD => OptimisticLock::rules(),
        ];
    }
}
