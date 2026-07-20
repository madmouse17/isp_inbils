<?php

namespace Modules\SPK\Http\Requests;

use App\Services\Core\CompanyService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWorkOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('spk.update') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $companyId = CompanyService::currentId();

        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'customer_id' => ['nullable', Rule::exists('customers', 'id')->where('company_id', $companyId)],
            'subscription_id' => ['nullable', Rule::exists('service_subscriptions', 'id')->where('company_id', $companyId)],
            'location_id' => ['nullable', Rule::exists('locations', 'id')->where('company_id', $companyId)],
            'priority' => ['nullable', 'string', 'in:low,medium,high,urgent'],
            'scheduled_date' => ['nullable', 'date'],
        ];
    }
}
