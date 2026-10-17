<?php

namespace Modules\NetworkAsset\Http\Requests;

use App\Http\Requests\Concerns\ResolvesPublicIds;
use App\Services\Core\CompanyService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\NetworkAsset\Models\OdnPort;
use Modules\NetworkAsset\Models\OdnSplitter;

/** Both keys are optional; a key that is present (even null) is applied, so null disconnects the input. */
class UpdateSplitterRequest extends FormRequest
{
    use ResolvesPublicIds;

    /** @return array<string, class-string> */
    protected function publicIdFields(): array
    {
        return ['input_port_id' => OdnPort::class];
    }

    public function authorize(): bool
    {
        $splitter = $this->route('splitter');

        return $splitter instanceof OdnSplitter && ($this->user()?->can('update', $splitter) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'input_port_id' => ['sometimes', 'nullable', 'integer', Rule::exists('odn_ports', 'id')->where('company_id', CompanyService::currentId())],
            'label' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'input_port_id' => 'port masukan',
            'label' => 'label',
        ];
    }
}
