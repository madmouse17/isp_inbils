<?php

namespace Modules\NetworkAsset\Http\Requests;

use App\Services\Core\CompanyService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\NetworkAsset\Models\OdnSplitter;

class StoreSplitterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', OdnSplitter::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'outputs' => ['required', 'integer', Rule::in(OdnSplitter::OUTPUTS)],
            'input_port_id' => ['nullable', 'integer', Rule::exists('odn_ports', 'id')->where('company_id', CompanyService::currentId())],
            'label' => ['nullable', 'string', 'max:100'],
        ];
    }
}
