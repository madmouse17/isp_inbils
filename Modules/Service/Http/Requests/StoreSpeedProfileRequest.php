<?php

namespace Modules\Service\Http\Requests;

use App\Services\Core\CompanyService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSpeedProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('service.create') || $this->user()?->can('service.manage');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('speed_profiles')->where('company_id', CompanyService::currentId())],
            'download_max_mbps' => ['required', 'integer', 'min:1'],
            'upload_max_mbps' => ['required', 'integer', 'min:1'],
            'burst_download_mbps' => ['nullable', 'integer', 'min:1'],
            'burst_upload_mbps' => ['nullable', 'integer', 'min:1'],
            'radius_profile_name' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ];
    }
}
