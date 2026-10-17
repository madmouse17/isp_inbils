<?php

namespace Modules\Inventory\Http\Requests;

use App\Http\Requests\Concerns\ResolvesPublicIds;
use Illuminate\Foundation\Http\FormRequest;
use Modules\Inventory\Models\MaterialReturnLine;

class ReceiveAcceptMaterialReturnRequest extends FormRequest
{
    use ResolvesPublicIds;

    /** @return array<string, class-string> */
    protected function publicIdFields(): array
    {
        return ['lines.*.id' => MaterialReturnLine::class];
    }

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
