<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('employee.manage') ?? false;
    }

    public function rules(): array
    {
        $profileId = $this->route('employee_profile')->id;
        $companyId = \App\Services\Core\CompanyService::currentId();
        return [
            'organization_id' => ['nullable', Rule::exists('organization_units', 'id')->where('company_id', $companyId)],
            'vehicle_id' => ['nullable', Rule::exists('vehicles', 'id')->where('company_id', $companyId)],
            'employee_number' => ['required', 'string', 'max:50', Rule::unique('employee_profiles')->where('company_id', $companyId)->ignore($profileId)],
            'phone' => ['nullable', 'string', 'max:50'],
            'hire_date' => ['nullable', 'date'],
            'status' => ['nullable', 'string', 'in:active,inactive,terminated'],
            'skills' => ['nullable', 'array'],
            'skills.*' => ['string', 'max:50'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
