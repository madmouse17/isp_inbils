<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StockAdjustRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('inventory.stock.adjust') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'product_id' => ['required', 'exists:products,id'],
            'location_id' => ['required', 'exists:locations,id'],
            'new_quantity' => ['required', 'numeric', 'min:0'],
            'note' => ['required', 'string', 'max:500'],
        ];
    }
}
