<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Concerns\ResolvesPublicIds;
use App\Models\Core\OrganizationUnit;
use App\Services\Core\CompanyService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOrganizationRequest extends FormRequest
{
    use ResolvesPublicIds;

    /** @return array<string, class-string> */
    protected function publicIdFields(): array
    {
        return ['parent_id' => OrganizationUnit::class];
    }

    public function authorize(): bool
    {
        return $this->user()?->can('organization.manage') ?? false;
    }

    public function rules(): array
    {
        $unitId = $this->route('organization_unit')->id;
        $companyId = CompanyService::currentId();

        return [
            'code' => ['required', 'string', 'max:50', Rule::unique('organization_units')->where('company_id', $companyId)->ignore($unitId)],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'in:company,branch,area,unit,team'],
            'parent_id' => ['nullable', Rule::exists('organization_units', 'id')->where('company_id', $companyId)],
            'address' => ['nullable', 'string'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'is_active' => ['boolean'],
        ];
    }
}
