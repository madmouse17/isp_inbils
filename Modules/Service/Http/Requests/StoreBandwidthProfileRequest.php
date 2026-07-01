<?php

namespace Modules\Service\Http\Requests;

use App\Services\Core\CompanyService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBandwidthProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('service.create') || $this->user()?->can('service.manage');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('bandwidth_profiles')->where('company_id', CompanyService::currentId())],
            'download_mbps' => ['required', 'integer', 'min:1'],
            'upload_mbps' => ['required', 'integer', 'min:1'],
            'type' => ['required', Rule::in(['shared', 'dedicated'])],
            'contention_ratio' => [Rule::requiredIf($this->input('type') === 'shared'), 'nullable', 'integer', 'min:1'],
            'is_active' => ['boolean'],
        ];
    }
}
