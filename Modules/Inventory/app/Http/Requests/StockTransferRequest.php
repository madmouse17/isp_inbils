<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StockTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('inventory.stock.transfer') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'product_id' => ['required', 'exists:products,id'],
            'from_location_id' => ['required', 'exists:locations,id'],
            'to_location_id' => ['required', 'exists:locations,id'],
            'quantity' => ['required', 'numeric', 'min:0.01'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
