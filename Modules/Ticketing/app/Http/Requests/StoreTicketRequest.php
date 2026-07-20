<?php

namespace Modules\Ticketing\Http\Requests;

use App\Services\Core\CompanyService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('ticket.create') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $companyId = CompanyService::currentId();

        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'source' => ['required', 'string', 'in:customer,noc,internal'],
            'category_id' => ['required', Rule::exists('ticket_categories', 'id')->where('company_id', $companyId)],
            'priority' => ['nullable', 'string', 'in:low,medium,high,urgent'],
            'customer_id' => ['nullable', Rule::exists('customers', 'id')->where('company_id', $companyId)],
            'subscription_id' => ['nullable', Rule::exists('service_subscriptions', 'id')->where('company_id', $companyId)],
            'network_asset_id' => ['nullable', Rule::exists('network_assets', 'id')->where('company_id', $companyId)],
            'location_id' => ['nullable', Rule::exists('locations', 'id')->where('company_id', $companyId)],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('source') === 'customer' && !$this->input('customer_id')) {
            $this->getValidatorInstance()->errors()->add('customer_id', 'Customer required when source is customer.');
        }
    }
}
