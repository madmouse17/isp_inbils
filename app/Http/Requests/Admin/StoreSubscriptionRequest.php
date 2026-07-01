<?php

namespace App\Http\Requests\Admin;

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
        $companyId = \App\Services\Core\CompanyService::currentId();

        return [
            'customer_id' => ['required', 'exists:customers,id'],
            'service_package_id' => ['required', 'exists:service_packages,id'],
            'installation_address_id' => ['required', 'exists:customer_addresses,id'],
            'billing_day' => ['required', 'integer', 'between:1,28'],
            'mrc_amount' => ['nullable', 'numeric', 'min:0'],
            'otc_installation_fee' => ['nullable', 'numeric', 'min:0'],
            'contract_months' => ['nullable', 'integer', 'min:1'],
            'serving_pop_id' => ['nullable', 'exists:locations,id'],
            'activation_date' => ['nullable', 'date'],
            'expiration_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ];
    }

    public function after(): array
    {
        $companyId = \App\Services\Core\CompanyService::currentId();

        return [
            function ($validator) use ($companyId) {
                $pkg = \Modules\Service\Models\ServicePackage::find($this->input('service_package_id'));
                if ($pkg && (int) $pkg->company_id !== (int) $companyId) {
                    $validator->errors()->add('service_package_id', 'Invalid package for this company.');
                }

                $addr = \App\Models\Core\CustomerAddress::find($this->input('installation_address_id'));
                if ($addr && (int) $addr->customer_id !== (int) $this->input('customer_id')) {
                    $validator->errors()->add('installation_address_id', 'Address does not belong to customer.');
                }
            },
        ];
    }
}
