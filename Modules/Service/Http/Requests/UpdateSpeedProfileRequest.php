<?php

namespace Modules\Service\Http\Requests;

use App\Services\Core\CompanyService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Service\Models\SpeedProfile;

class UpdateSpeedProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('service.update') || $this->user()?->can('service.manage');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $profile = $this->route('speed_profile');
        $id = $profile instanceof SpeedProfile ? $profile->id : null;

        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('speed_profiles')->where('company_id', CompanyService::currentId())->ignore($id)],
            'download_max_mbps' => ['required', 'integer', 'min:1'],
            'upload_max_mbps' => ['required', 'integer', 'min:1'],
            'burst_download_mbps' => ['nullable', 'integer', 'min:1'],
            'burst_upload_mbps' => ['nullable', 'integer', 'min:1'],
            'radius_profile_name' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ];
    }
}
