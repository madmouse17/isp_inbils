<?php

namespace Modules\Billing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RecordPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('billing.payment.record') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['required', 'string', 'in:cash,transfer,cheque,other'],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'amount' => 'jumlah',
            'method' => 'metode',
            'reference' => 'referensi',
            'notes' => 'catatan',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'amount.required' => 'Jumlah wajib diisi.',
            'amount.min' => 'Jumlah harus lebih dari nol.',
            'method.required' => 'Metode wajib dipilih.',
            'method.in' => 'Metode yang dipilih tidak dikenal.',
        ];
    }
}
