<?php

namespace Modules\Ipam\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Ipam\Models\IpPrefix;

class StoreReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $prefix = $this->route('prefix');

        return $prefix instanceof IpPrefix && ($this->user()?->can('reserve', $prefix) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'address' => ['required', 'string', 'max:45'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'address' => 'alamat',
            'note' => 'catatan',
        ];
    }
}
