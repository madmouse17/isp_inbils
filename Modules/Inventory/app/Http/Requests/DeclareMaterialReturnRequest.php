<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DeclareMaterialReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['nullable', 'string', 'max:64'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.id' => ['required', 'integer'],
            'lines.*.actual_used_qty' => ['required', 'numeric', 'min:0'],
            'lines.*.declared_good_return_qty' => ['required', 'numeric', 'min:0'],
            'lines.*.declared_discrepancy_qty' => ['required', 'numeric', 'min:0'],
            'lines.*.discrepancy_code' => ['nullable', 'string', 'in:damaged,lost,other'],
            'lines.*.discrepancy_reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
