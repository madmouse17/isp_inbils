<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StockReceiveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('inventory.stock.receive') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'product_id' => ['required', 'exists:products,id'],
            'location_id' => ['required', 'exists:locations,id'],
            'quantity' => ['required', 'numeric', 'min:0.01'],
            'note' => ['nullable', 'string', 'max:500'],
            'reference_type' => ['nullable', 'string', 'max:100'],
            'reference_id' => ['nullable', 'integer'],
        ];
    }
}
