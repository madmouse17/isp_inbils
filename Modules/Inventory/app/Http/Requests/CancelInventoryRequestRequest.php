<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CancelInventoryRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        $request = $this->route('inventory_request');

        return $this->user()?->can('cancel', $request) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'cancellation_reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
