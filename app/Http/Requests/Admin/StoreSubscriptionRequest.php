<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Concerns\ResolvesPublicIds;
use App\Models\Core\Customer;
use App\Models\Core\CustomerAddress;
use App\Models\Core\Location;
use App\Services\Core\CompanyService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Service\Models\ServicePackage;

class StoreSubscriptionRequest extends FormRequest
{
    use ResolvesPublicIds;

    /** @return array<string, class-string> */
    protected function publicIdFields(): array
    {
        return [
            'customer_id' => Customer::class,
            'service_package_id' => ServicePackage::class,
            'installation_address_id' => CustomerAddress::class,
            'serving_pop_id' => Location::class,
        ];
    }

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
            'service_package_id' => ['required', Rule::exists('service_packages', 'id')->where('company_id', $companyId)->where('is_active', true)->whereNull('deleted_at')],
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
            'service_package_id.exists' => 'Pilih paket layanan yang masih aktif.',
        ];
    }
}
