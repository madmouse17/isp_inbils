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
            'due_date' => ['bail', 'nullable', 'date_format:Y-m-d', ...($issued === null ? [] : ['after_or_equal:'.$issued])],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
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
            'due_date.date_format' => 'Format :attribute harus tahun-bulan-tanggal (YYYY-MM-DD).',
            'due_date.after_or_equal' => 'Jatuh tempo tidak boleh sebelum tanggal terbit.',
        ];
    }
}
