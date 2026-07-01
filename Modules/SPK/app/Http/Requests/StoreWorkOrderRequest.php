<?php

namespace Modules\SPK\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreWorkOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('spk.create') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', 'in:installation,maintenance,upgrade_service,relocation'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'customer_id' => ['nullable', 'exists:customers,id'],
            'subscription_id' => ['nullable', 'exists:service_subscriptions,id'],
            'location_id' => ['nullable', 'exists:locations,id'],
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
