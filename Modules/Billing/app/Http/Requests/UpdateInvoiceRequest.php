<?php

namespace Modules\Billing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('billing.update') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'due_date' => ['nullable', 'date_format:Y-m-d'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'due_date' => 'jatuh tempo',
            'discount_amount' => 'diskon',
            'notes' => 'catatan',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'due_date.date_format' => 'Format :attribute harus tahun-bulan-tanggal (YYYY-MM-DD).',
        ];
    }
}
