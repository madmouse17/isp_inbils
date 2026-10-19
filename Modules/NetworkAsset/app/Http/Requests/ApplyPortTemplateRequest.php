<?php

namespace Modules\NetworkAsset\Http\Requests;

use App\Support\RecordNotFound;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\NetworkAsset\Models\NetworkAsset;
use Modules\NetworkAsset\Models\OdnPort;
use Modules\NetworkAsset\Support\PanelLayout;

/** A catalogue model, or a custom layout with its height; for a rack only its size in U. */
class ApplyPortTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $asset = $this->route('asset');

        if (! $asset instanceof NetworkAsset || ! ($this->user()?->can('updatePanel', $asset) ?? false)) {
            return false;
        }

        RecordNotFound::abortUnless(in_array($asset->asset_type, PanelLayout::ASSET_TYPES, true));

        // OLT and ODP models write the ODN register, which has its own permission (the seeder calls the service without a user).
        if (in_array($asset->asset_type, ['olt', 'odp'], true) && ! $this->user()->can('create', OdnPort::class)) {
            return false;
        }

        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $type = $this->assetType();

        if ($type === 'rack') {
            return [
                'template_key' => ['prohibited'],
                'layout' => ['prohibited'],
                'height_u' => ['required', 'integer', 'between:1,52'],
            ];
        }

        return [
            'template_key' => ['nullable', 'string', 'max:64', Rule::exists('port_templates', 'key')->where('asset_type', $type), 'prohibits:layout'],
            'layout' => ['nullable', 'array', 'required_without:template_key'],
            'height_u' => ['nullable', 'integer', 'between:1,52'],
            ...PanelLayout::rules('layout', $type),
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [fn (Validator $validator) => PanelLayout::checkAfter($validator, $this->assetType())];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'template_key' => 'model perangkat',
            'layout' => 'tata letak',
            'height_u' => 'tinggi (U)',
        ];
    }

    private function assetType(): string
    {
        $asset = $this->route('asset');

        return $asset instanceof NetworkAsset ? $asset->asset_type : '';
    }
}
