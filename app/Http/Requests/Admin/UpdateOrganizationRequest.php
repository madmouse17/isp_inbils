<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('organization.manage') ?? false;
    }

    public function rules(): array
    {
        $unitId = $this->route('organization_unit')->id;
        $companyId = \App\Services\Core\CompanyService::currentId();
        return [
            'code' => ['required', 'string', 'max:50', Rule::unique('organization_units')->where('company_id', $companyId)->ignore($unitId)],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'in:company,branch,area,unit,team'],
            'parent_id' => ['nullable', 'exists:organization_units,id'],
            'address' => ['nullable', 'string'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'is_active' => ['boolean'],
        ];
    }
}
