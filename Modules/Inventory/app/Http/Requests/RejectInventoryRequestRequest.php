<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RejectInventoryRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        $request = $this->route('inventory_request');

        return $this->user()?->can('reject', $request) ?? false;
    }

    protected function failedAuthorization(): void
    {
        if ($this->header('X-Inertia')) {
            throw new \Illuminate\Http\Exceptions\HttpResponseException(
                back()->withErrors(['error' => 'Permintaan barang tidak dapat ditolak karena status telah berubah atau Anda tidak berwenang.'])
            );
        }

        parent::failedAuthorization();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'rejection_reason' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }
}
