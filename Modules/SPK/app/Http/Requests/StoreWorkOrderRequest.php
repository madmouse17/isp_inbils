<?php

namespace Modules\SPK\Http\Requests;

use App\Services\Core\CompanyService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWorkOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('spk.create') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $companyId = CompanyService::currentId();

        return [
            'type' => ['required', 'string', 'in:installation,maintenance,upgrade_service,relocation'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'customer_id' => ['nullable', Rule::exists('customers', 'id')->where('company_id', $companyId)],
            'subscription_id' => ['nullable', Rule::exists('service_subscriptions', 'id')->where('company_id', $companyId)],
            'location_id' => ['nullable', Rule::exists('locations', 'id')->where('company_id', $companyId)],
            'source' => ['nullable', 'string', 'in:manual,ticket,subscription,monitoring'],
            'priority' => ['nullable', 'string', 'in:low,medium,high,urgent'],
            'scheduled_date' => ['nullable', 'date'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (in_array($this->input('type'), ['installation', 'upgrade_service', 'relocation'])) {
            if (!$this->input('customer_id') || !$this->input('subscription_id')) {
                $this->getValidatorInstance()->errors()->add('customer_id', 'Customer and subscription required for this SPK type.');
            }
        }
    }
}
