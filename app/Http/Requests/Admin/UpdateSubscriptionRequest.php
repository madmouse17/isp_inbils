<?php

namespace App\Http\Requests\Admin;

use App\Services\Core\CompanyService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSubscriptionRequest extends FormRequest
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
            'mrc_amount' => ['nullable', 'numeric', 'min:0'],
            'otc_installation_fee' => ['nullable', 'numeric', 'min:0'],
            'contract_months' => ['nullable', 'integer', 'min:1'],
            'serving_pop_id' => ['nullable', Rule::exists('locations', 'id')->where('company_id', $companyId)],
            'expiration_date' => ['nullable', 'date'],
            'next_invoice_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
