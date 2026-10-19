<?php

namespace Modules\NetworkAsset\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\NetworkAsset\Models\NetworkAsset;

class UpdateAssetPortRequest extends FormRequest
{
    public function authorize(): bool
    {
        $asset = $this->route('asset');

        return $asset instanceof NetworkAsset && ($this->user()?->can('updatePanel', $asset) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'label' => ['nullable', 'string', 'max:64'],
            'note' => ['nullable', 'string', 'max:255'],
            'admin_down' => ['required', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'label' => 'label',
            'note' => 'catatan',
            'admin_down' => 'status admin',
        ];
    }
}
