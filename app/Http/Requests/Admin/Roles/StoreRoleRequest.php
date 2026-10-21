<?php

namespace App\Http\Requests\Admin\Roles;

use App\Models\Role;
use App\Rules\NoSodConflict;
use App\Services\Core\CompanyService;
use App\Support\PermissionCatalogue;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

/**
 * A role of the company the user acts in. What the user may grant (the permissions he holds, G1) and the last
 * administrator (G5) are decided by CompanyRoleService under the company lock; this request checks the shape, the
 * name inside the company, and the separation of duties of the set (G3), which a set holding roles.manage is exempt from.
 */
class StoreRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Role::class) ?? false;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', $this->uniqueName()],
            'permissions' => ['array', new NoSodConflict(exempt: in_array(PermissionCatalogue::ADMINISTRATION, (array) $this->input('permissions'), true))],
            'permissions.*' => ['string', 'distinct', Rule::exists('permissions', 'name')->where('guard_name', 'web')],
        ];
    }

    /** @return list<string> */
    public function permissionNames(): array
    {
        return array_values($this->validated('permissions') ?? []);
    }

    protected function uniqueName(): Unique
    {
        return Rule::unique('roles', 'name')->where('company_id', CompanyService::currentId())->where('guard_name', 'web');
    }
}
