<?php

namespace Modules\Ticketing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('ticket.create') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'source' => ['required', 'string', 'in:customer,noc,internal'],
            'category_id' => ['required', 'exists:ticket_categories,id'],
            'priority' => ['nullable', 'string', 'in:low,medium,high,urgent'],
            'customer_id' => ['nullable', 'exists:customers,id'],
            'subscription_id' => ['nullable', 'exists:service_subscriptions,id'],
            'network_asset_id' => ['nullable', 'exists:network_assets,id'],
            'location_id' => ['nullable', 'exists:locations,id'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('source') === 'customer' && !$this->input('customer_id')) {
            $this->getValidatorInstance()->errors()->add('customer_id', 'Customer required when source is customer.');
        }
    }
}
