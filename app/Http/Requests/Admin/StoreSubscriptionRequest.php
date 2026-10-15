<?php

namespace App\Http\Requests\Admin;

use App\Models\Core\Customer;
use App\Services\Core\CompanyService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('customer.subscription.manage') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $companyId = CompanyService::currentId();

        return [
            'customer_id' => ['required', Rule::exists('customers', 'id')->where('company_id', $companyId)],
            'service_package_id' => ['required', Rule::exists('service_packages', 'id')->where('company_id', $companyId)],
            'installation_address_id' => ['required', 'integer', Rule::exists('customer_addresses', 'id')->where(fn ($query) => $query
                ->where('company_id', $companyId)
                ->where('customer_id', $this->input('customer_id'))
                ->whereNull('deleted_at'))],
            'billing_day' => ['required', 'integer', 'between:1,28'],
            'mrc_amount' => ['nullable', 'numeric', 'min:0'],
            'otc_installation_fee' => ['nullable', 'numeric', 'min:0'],
            'contract_months' => ['nullable', 'integer', 'min:1'],
            'serving_pop_id' => ['nullable', Rule::exists('locations', 'id')->where('company_id', $companyId)],
            'activation_date' => ['nullable', 'date'],
            'expiration_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $customer = $this->route('customer');

        if ($customer instanceof Customer) {
            $this->merge(['customer_id' => $customer->id]);
        }
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'installation_address_id.exists' => 'Pilih salah satu alamat milik pelanggan ini.',
        ];
    }
}
