<?php

namespace Modules\Inventory\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Inventory\Models\InventoryRequest;

class CancelTechnicianInventoryRequestRequest extends FormRequest
{
    /** The web's rule (InventoryRequestPolicy::cancel: own request, draft or submitted), asked before the body is read. */
    public function authorize(): bool
    {
        $inventoryRequest = $this->route('inventoryRequest');

        return $inventoryRequest instanceof InventoryRequest && ($this->user()?->can('cancel', $inventoryRequest) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'cancellation_reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
