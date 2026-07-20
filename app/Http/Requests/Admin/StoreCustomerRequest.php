<?php

namespace App\Http\Requests\Admin;

use App\Services\Core\CompanyService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('customer.create') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $companyId = CompanyService::currentId();

        return [
            'code' => ['required', 'string', 'max:50', Rule::unique('customers')->where('company_id', $companyId)],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'in:Individual,Company'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['required', 'string', 'max:50'],
            'tax_id' => [Rule::requiredIf($this->input('type') === 'Company'), 'nullable', 'string', 'max:100'],
            'contact_person' => [Rule::requiredIf($this->input('type') === 'Company'), 'nullable', 'string', 'max:255'],
            'area_coverage_id' => ['nullable', Rule::exists('locations', 'id')->where('company_id', $companyId)],
            'notes' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ];
    }
}
