<?php

namespace App\Http\Requests\Admin;

use App\Services\Core\CompanyService;
use App\Services\Core\NumberSequenceService;
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
        $profileId = $this->route('employee')?->id ?? $this->route('employee_profile')?->id;
        $companyId = CompanyService::currentId();

        return [
            'user_id' => [
                'sometimes',
                'bail',
                'required',
                Rule::exists('users', 'id')->where(StoreEmployeeRequest::usersOf($companyId)),
                Rule::unique('employee_profiles', 'user_id')->ignore($profileId),
            ],
            'organization_id' => ['nullable', Rule::exists('organization_units', 'id')->where('company_id', $companyId)],
            'vehicle_id' => ['nullable', Rule::exists('vehicles', 'id')->where('company_id', $companyId)],
            'employee_number' => NumberSequenceService::allowsManual('employee')
                ? ['required', 'string', 'max:50', Rule::unique('employee_profiles')->where('company_id', $companyId)->ignore($profileId)]
                : ['exclude'],
            'phone' => ['nullable', 'string', 'max:50'],
            'hire_date' => ['nullable', 'date_format:Y-m-d'],
            'status' => ['nullable', 'string', 'in:active,inactive,terminated'],
            'skills' => ['nullable', 'array'],
            'skills.*' => ['string', 'max:50'],
            'notes' => ['nullable', 'string'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'skills' => 'keahlian',
            'skills.*' => 'keahlian',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'user_id.unique' => 'Pengguna ini sudah punya profil karyawan.',
        ];
    }
}
