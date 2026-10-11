<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class RejectStockAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('reject', $this->route('adjustment')) ?? false;
    }

    protected function failedAuthorization(): void
    {
        if ($this->header('X-Inertia')) {
            throw new HttpResponseException(
                back()->withErrors(['error' => 'Permintaan penyesuaian stok tidak dapat ditolak karena status telah berubah atau Anda tidak berwenang.'])
            );
        }

        parent::failedAuthorization();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'rejection_reason' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }
}
