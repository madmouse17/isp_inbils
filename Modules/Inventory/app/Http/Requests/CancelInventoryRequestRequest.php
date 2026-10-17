<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class CancelInventoryRequestRequest extends FormRequest
{
    /** The policy's own answer, so a request the person cannot see is a 404 and not a 403. */
    public function authorize(): bool|Response
    {
        $request = $this->route('inventory_request');
        $user = $this->user();

        return $user === null ? false : Gate::forUser($user)->inspect('cancel', $request);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'cancellation_reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
