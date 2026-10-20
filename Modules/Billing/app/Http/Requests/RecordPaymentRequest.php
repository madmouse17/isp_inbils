<?php

namespace Modules\Billing\Http\Requests;

use App\Support\Money;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Modules\Billing\Models\Invoice;

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
            // Whole rupiah, so an invoice balance stays whole. The one exception: the exact balance of an invoice issued
            // before whole-rupiah billing, which carries cents and could never be cleared otherwise.
            'amount' => ['bail', 'required', 'numeric', 'min:0.01', function (string $attribute, mixed $value, Closure $fail): void {
                $invoice = $this->route('invoice');

                if (! preg_match('/^\d+(\.0{1,2})?$/', (string) $value) && ! ($invoice instanceof Invoice && Money::compare((float) $value, $invoice->sisa) === 0)) {
                    $fail('Jumlah pembayaran harus rupiah penuh, tanpa sen.');
                }
            }],
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
