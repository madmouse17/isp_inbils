<?php

namespace Modules\NetworkAsset\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\NetworkAsset\Models\OdnPort;

class StorePonPortsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', OdnPort::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'count' => ['required', 'integer', 'between:1,64'],
        ];
    }
}
