<?php

namespace Modules\Billing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Billing\Queries\ReceivableInvoicesQuery;

/** The filters of the "Per tagihan" list of Tunggakan and of its export: both read the same four words. */
class ReceivableInvoicesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('billing.view') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'bucket' => ['nullable', Rule::in(ReceivableInvoicesQuery::BUCKETS)],
            'due_from' => ['nullable', 'date_format:Y-m-d'],
            'due_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:due_from'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'search' => 'kata kunci',
            'bucket' => 'umur',
            'due_from' => 'jatuh tempo awal',
            'due_to' => 'jatuh tempo akhir',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'bucket.in' => 'Kelompok umur tidak dikenal.',
            'due_from.date_format' => 'Format :attribute harus tahun-bulan-tanggal (YYYY-MM-DD).',
            'due_to.date_format' => 'Format :attribute harus tahun-bulan-tanggal (YYYY-MM-DD).',
            'due_to.after_or_equal' => 'Jatuh tempo akhir tidak boleh sebelum jatuh tempo awal.',
        ];
    }

    /** @return array{search: ?string, bucket: ?string, due_from: ?string, due_to: ?string} */
    public function filters(): array
    {
        $search = trim((string) $this->validated('search'));

        return [
            'search' => $search === '' ? null : $search,
            'bucket' => $this->validated('bucket'),
            'due_from' => $this->validated('due_from'),
            'due_to' => $this->validated('due_to'),
        ];
    }
}
