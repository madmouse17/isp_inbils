<?php

namespace Modules\NetworkAsset\Http\Requests;

use App\Http\Requests\Concerns\ResolvesPublicIds;
use App\Services\Core\CompanyService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\NetworkAsset\Models\AssetPort;
use Modules\NetworkAsset\Models\NetworkAsset;

class LinkAssetPortRequest extends FormRequest
{
    use ResolvesPublicIds;

    /** @return array<string, class-string> */
    protected function publicIdFields(): array
    {
        return ['target_port_id' => AssetPort::class];
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
            'target_port_id' => ['required', 'integer', Rule::exists('asset_ports', 'id')->where('company_id', CompanyService::currentId())],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'target_port_id' => 'port tujuan',
        ];
    }
}
