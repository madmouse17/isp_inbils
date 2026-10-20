<?php

namespace Modules\Billing\Http\Requests;

use App\Services\Core\CompanyService;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Modules\Billing\Services\BillingService;

class GenerateInvoicesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('billing.create') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // A period is billed once it has ended; the month in progress is allowed (the dialog asks first), a later one never.
            'period' => ['bail', 'required', 'date_format:Y-m', function (string $attribute, mixed $value, Closure $fail): void {
                if ($value > BillingService::currentPeriodFor((int) CompanyService::currentId())) {
                    $fail('Periode tagihan paling lambat bulan berjalan.');
                }
            }],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'period' => 'periode',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'period.required' => 'Periode wajib dipilih.',
            'period.date_format' => 'Format :attribute harus tahun-bulan (YYYY-MM).',
        ];
    }
}
