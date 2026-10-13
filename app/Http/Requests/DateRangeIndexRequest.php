<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * List pages with a date_from/date_to filter (Invoices, Dispatch queue, My Work). Either end may stand
 * alone (a half range); when both are given the end may not precede the start. Dates are ISO yyyy-mm-dd.
 */
class DateRangeIndexRequest extends FormRequest
{
    /** Access is decided by the controller (policy or per-domain permission). */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'date_from.date_format' => 'Tanggal awal harus berformat TTTT-BB-HH.',
            'date_to.date_format' => 'Tanggal akhir harus berformat TTTT-BB-HH.',
            'date_to.after_or_equal' => 'Tanggal akhir tidak boleh sebelum tanggal awal.',
        ];
    }
}
