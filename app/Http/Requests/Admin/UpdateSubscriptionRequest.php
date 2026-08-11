<?php

namespace App\Http\Requests\Admin;

use App\Models\Core\ServiceSubscription;
use App\Services\Core\CompanyService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if (! ($user?->can('customer.subscription.manage') ?? false)) {
            return false;
        }

        /** @var ServiceSubscription|null $subscription */
        $subscription = $this->route('subscription');

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

        return [
            'service_package_id' => ['nullable', Rule::exists('service_packages', 'id')->where('company_id', $companyId)],
            'installation_address_id' => ['nullable', Rule::exists('customer_addresses', 'id')->where('company_id', $companyId)],
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
}
