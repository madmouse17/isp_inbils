<?php

namespace Modules\NetworkAsset\Http\Requests;

use App\Http\Requests\Concerns\ResolvesPublicIds;
use App\Services\Core\CompanyService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\NetworkAsset\Models\NetworkAsset;

/** A rack with a unit and a height mounts the device; `rack_asset_id: null` takes it out. */
class UpdateRackPlacementRequest extends FormRequest
{
    use ResolvesPublicIds;

    /** @return array<string, class-string> */
    protected function publicIdFields(): array
    {
        return ['rack_asset_id' => NetworkAsset::class];
    }

    public function authorize(): bool
    {
        $asset = $this->route('asset');

        return $asset instanceof NetworkAsset && ($this->user()?->can('updatePanel', $asset) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'rack_asset_id' => ['nullable', 'integer', Rule::exists('network_assets', 'id')->where('company_id', CompanyService::currentId())->where('asset_type', 'rack')->whereNull('deleted_at')],
            'rack_unit' => ['required_with:rack_asset_id', 'integer', 'between:1,52'],
            'height_u' => ['required_with:rack_asset_id', 'integer', 'between:1,10'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $asset = $this->route('asset');

            if ($asset instanceof NetworkAsset && (int) $this->input('rack_asset_id') === $asset->id && ! $validator->errors()->has('rack_asset_id')) {
                $validator->errors()->add('rack_asset_id', 'Perangkat tidak dapat dipasang di dirinya sendiri.');
            }
        }];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'rack_asset_id' => 'rak',
            'rack_unit' => 'posisi (U)',
            'height_u' => 'tinggi (U)',
        ];
    }
}
