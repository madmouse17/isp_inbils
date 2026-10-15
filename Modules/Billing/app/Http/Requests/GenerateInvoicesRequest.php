<?php

namespace Modules\Billing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
            'period' => ['required', 'date_format:Y-m'],
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
