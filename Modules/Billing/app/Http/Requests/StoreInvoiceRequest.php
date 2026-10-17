<?php

namespace Modules\Billing\Http\Requests;

use App\Http\Requests\Concerns\ResolvesPublicIds;
use App\Models\Core\Customer;
use App\Models\Core\ServiceSubscription;
use App\Rules\MatchesParentAttribute;
use App\Services\Core\CompanyService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInvoiceRequest extends FormRequest
{
    use ResolvesPublicIds;

    /** @return array<string, class-string> */
    protected function publicIdFields(): array
    {
        return ['customer_id' => Customer::class, 'subscription_id' => ServiceSubscription::class];
    }

    public function authorize(): bool
    {
        return $this->user()?->can('billing.create') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $companyId = CompanyService::currentId();

        return [
            'customer_id' => ['required', Rule::exists('customers', 'id')->where('company_id', $companyId)],
            // bail: the pair rule reads the row without the company boundary, so it never runs for an id the company does not own.
            'subscription_id' => [
                'bail',
                'nullable',
                'integer',
                Rule::exists('service_subscriptions', 'id')->where('company_id', $companyId)->withoutTrashed(),
                new MatchesParentAttribute('service_subscriptions', 'customer_id', 'customer_id'),
            ],
            'issue_date' => ['nullable', 'date_format:Y-m-d'],
            'due_date' => ['nullable', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'customer_id' => 'pelanggan',
            'subscription_id' => 'langganan',
            'issue_date' => 'tanggal terbit',
            'due_date' => 'jatuh tempo',
            'notes' => 'catatan',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'customer_id.required' => 'Pelanggan wajib dipilih.',
            'customer_id.exists' => 'Pelanggan yang dipilih tidak ditemukan.',
            'subscription_id.exists' => 'Langganan yang dipilih tidak ditemukan.',
            'issue_date.date_format' => 'Format :attribute harus tahun-bulan-tanggal (YYYY-MM-DD).',
            'due_date.date_format' => 'Format :attribute harus tahun-bulan-tanggal (YYYY-MM-DD).',
        ];
    }
}
