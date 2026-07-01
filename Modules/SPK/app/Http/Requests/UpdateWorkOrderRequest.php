<?php

namespace Modules\SPK\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateWorkOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('spk.update') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'customer_id' => ['nullable', 'exists:customers,id'],
            'subscription_id' => ['nullable', 'exists:service_subscriptions,id'],
            'location_id' => ['nullable', 'exists:locations,id'],
            'priority' => ['nullable', 'string', 'in:low,medium,high,urgent'],
            'scheduled_date' => ['nullable', 'date'],
        ];
    }
}
