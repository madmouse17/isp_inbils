<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Concerns\ResolvesPublicIds;
use App\Models\Core\CustomerAddress;
use App\Models\Core\Location;
use App\Models\Core\ServiceSubscription;
use App\Services\Core\CompanyService;
use App\Support\RecordNotFound;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Service\Models\ServicePackage;

class UpdateSubscriptionRequest extends FormRequest
{
    use ResolvesPublicIds;

    /** @return array<string, class-string> */
    protected function publicIdFields(): array
    {
        return [
            'service_package_id' => ServicePackage::class,
            'installation_address_id' => CustomerAddress::class,
            'serving_pop_id' => Location::class,
        ];
    }

    public function authorize(): bool
    {
        $user = $this->user();

        if (! ($user?->can('customer.subscription.manage') ?? false)) {
            return false;
        }

        /** @var ServiceSubscription|null $subscription */
        $subscription = $this->route('subscription');

        if ($subscription instanceof ServiceSubscription
            && (int) $subscription->company_id !== (int) CompanyService::currentId()) {
            RecordNotFound::abort();
        }

        if ($subscription && $subscription->exists) {
            $locked = $subscription->workOrders()
                ->whereNotIn('status', ['draft', 'generated', 'rejected', 'cancelled'])
                ->exists();

            if ($locked) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $companyId = CompanyService::currentId();
        $subscription = $this->route('subscription');
        $customerId = $subscription instanceof ServiceSubscription ? $subscription->customer_id : null;

        return [
            'service_package_id' => ['nullable', Rule::exists('service_packages', 'id')->where('company_id', $companyId)],
            'installation_address_id' => ['sometimes', 'required', 'integer', Rule::exists('customer_addresses', 'id')->where(fn ($query) => $query
                ->where('company_id', $companyId)
                ->where('customer_id', $customerId)
                ->whereNull('deleted_at'))],
            'billing_day' => ['nullable', 'integer', 'between:1,28'],
            'serving_pop_id' => ['nullable', Rule::exists('locations', 'id')->where('company_id', $companyId)],
            'mrc_amount' => ['nullable', 'numeric', 'min:0'],
            'otc_installation_fee' => ['nullable', 'numeric', 'min:0'],
            'contract_months' => ['nullable', 'integer', 'min:1'],
            'expiration_date' => ['nullable', 'date'],
            'next_invoice_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'installation_address_id.exists' => 'Pilih salah satu alamat milik pelanggan ini.',
        ];
    }
}
