<?php

namespace Modules\Inventory\Http\Requests\Api\V1;

use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Modules\Inventory\Models\InventoryRequest;

class CancelTechnicianInventoryRequestRequest extends FormRequest
{
    /**
     * The web's rule (InventoryRequestPolicy::cancel: own request, draft or submitted), asked before the body is read.
     * The policy's own answer is returned, not a boolean, so a request the person cannot see is a 404 and not a 403.
     */
    public function authorize(): bool|Response
    {
        $inventoryRequest = $this->route('inventoryRequest');
        $user = $this->user();

        return $inventoryRequest instanceof InventoryRequest && $user !== null ? Gate::forUser($user)->inspect('cancel', $inventoryRequest) : false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'cancellation_reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
