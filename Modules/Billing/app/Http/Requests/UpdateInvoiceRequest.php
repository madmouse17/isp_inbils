<?php

namespace Modules\Billing\Http\Requests;

use App\Support\OptimisticLock;
use Illuminate\Foundation\Http\FormRequest;
use Modules\Billing\Models\Invoice;

class UpdateInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $invoice = $this->route('invoice');

        return $invoice instanceof Invoice && ($this->user()?->can('update', $invoice) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $invoice = $this->route('invoice');
        // The issue date is not editable, so the due date is held against the stored one.
        $issued = $invoice instanceof Invoice ? $invoice->issue_date->toDateString() : null;

        return [
            // Absent leaves a field alone; a cleared one is refused: both columns are never null (a discount of nothing is zero).
            'due_date' => ['bail', 'sometimes', 'required', 'date_format:Y-m-d', ...($issued === null ? [] : ['after_or_equal:'.$issued])],
            // The column holds 13 digits and cents; what the invoice comes to is the real ceiling, checked under the lock of the save.
            'discount_amount' => ['sometimes', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999999.99'],
            'notes' => ['nullable', 'string', 'max:5000'],
            OptimisticLock::FIELD => OptimisticLock::rules(),
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
            'due_date.required' => 'Jatuh tempo wajib diisi.',
            'due_date.date_format' => 'Format :attribute harus tahun-bulan-tanggal (YYYY-MM-DD).',
            'due_date.after_or_equal' => 'Jatuh tempo tidak boleh sebelum tanggal terbit.',
            'discount_amount.numeric' => 'Diskon harus berupa angka.',
            'discount_amount.decimal' => 'Diskon paling banyak dua angka di belakang koma.',
            'discount_amount.min' => 'Diskon tidak boleh negatif.',
            'discount_amount.max' => 'Diskon terlalu besar.',
            'notes.max' => 'Catatan paling banyak :max karakter.',
        ];
    }
}
