<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReceiveAcceptMaterialReturnRequest extends FormRequest
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
            'reason' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.id' => ['required', 'integer'],
            'lines.*.accepted_good_return_qty' => ['required', 'numeric', 'min:0'],
            'lines.*.damaged_return_qty' => ['nullable', 'numeric', 'min:0'],
            'lines.*.lost_qty' => ['nullable', 'numeric', 'min:0'],
            'lines.*.other_discrepancy_qty' => ['nullable', 'numeric', 'min:0'],
            'lines.*.discrepancy_code' => ['nullable', 'string', 'in:damaged,lost,other'],
            'lines.*.discrepancy_reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
