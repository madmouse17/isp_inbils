<?php

namespace Modules\Billing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RefundPaymentAttemptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('billing.payment.reverse') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reference' => ['required', 'string', 'max:100'],
            'note' => ['required', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'reference' => 'nomor referensi pengembalian',
            'note' => 'catatan',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'reference.required' => 'Nomor referensi pengembalian wajib diisi.',
            'reference.max' => 'Nomor referensi pengembalian maksimal :max karakter.',
            'note.required' => 'Catatan wajib diisi.',
            'note.max' => 'Catatan maksimal :max karakter.',
        ];
    }
}
