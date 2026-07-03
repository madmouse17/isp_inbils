<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('employee.manage') ?? false;
    }

    public function rules(): array
    {
        $companyId = \App\Services\Core\CompanyService::currentId();
        return [
            'user_id' => ['required', 'exists:users,id'],
            'organization_id' => ['nullable', 'exists:organization_units,id'],
            'vehicle_id' => ['nullable', 'exists:vehicles,id'],
            'employee_number' => ['required', 'string', 'max:50', Rule::unique('employee_profiles')->where('company_id', $companyId)],
            'phone' => ['nullable', 'string', 'max:50'],
            'hire_date' => ['nullable', 'date'],
            'status' => ['nullable', 'string', 'in:active,inactive,terminated'],
            'skills' => ['nullable', 'array'],
            'skills.*' => ['string', 'max:50'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
