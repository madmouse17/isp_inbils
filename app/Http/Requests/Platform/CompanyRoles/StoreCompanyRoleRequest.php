<?php

namespace App\Http\Requests\Platform\CompanyRoles;

use App\Models\Core\Company;
use App\Rules\NoSodConflict;
use App\Support\PermissionCatalogue;
use App\Support\Platform\TenantRoles;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A role the operator adds to one company of a tenant. `{company}` of the route is the tenant's head; `company_id` names
 * the company that receives the role, the head or one of its subsidiaries, and nothing outside this tenant. A set
 * holding roles.manage is exempt from the separation-of-duties check, as in every other role save.
 */
class StoreCompanyRoleRequest extends FormRequest
{
    private ?Company $target = null;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            ...$this->scopeRules(),
            'permissions' => ['array', new NoSodConflict(exempt: in_array(PermissionCatalogue::ADMINISTRATION, (array) $this->input('permissions'), true))],
            'permissions.*' => ['string', 'distinct', Rule::exists('permissions', 'name')->where('guard_name', 'web')],
        ];
    }

    /** The company that receives the role; null when `company_id` names none of the tenant's. */
    public function targetCompany(): ?Company
    {
        return $this->target ??= TenantRoles::company($this->tenant(), $this->input('company_id'));
    }

    /** @return list<string> */
    public function permissionNames(): array
    {
        return array_values($this->validated('permissions') ?? []);
    }

    /** @return array<string, array<mixed>> the fields that tie the role to its company */
    protected function scopeRules(): array
    {
        return [
            'company_id' => ['required', 'string', Rule::exists('companies', 'public_id')->whereIn('id', TenantRoles::companyIds($this->tenant()))],
            'name' => ['required', 'string', 'max:255', Rule::unique('roles', 'name')->where('company_id', $this->targetCompany()?->id)->where('guard_name', 'web')],
        ];
    }

    protected function tenant(): Company
    {
        /** @var Company */
        return $this->route('company');
    }
}
