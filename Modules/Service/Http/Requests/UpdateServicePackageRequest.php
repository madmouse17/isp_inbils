<?php

namespace Modules\Service\Http\Requests;

use App\Services\Core\CompanyService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Service\Models\ServicePackage;

class UpdateServicePackageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('service.update') || $this->user()?->can('service.manage');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $companyId = CompanyService::currentId();
        $package = $this->route('service_package');
        $id = $package instanceof ServicePackage ? $package->id : null;

        return [
            'code' => ['required', 'string', 'max:255', Rule::unique('service_packages')->where('company_id', $companyId)->ignore($id)],
            'name' => ['required', 'string', 'max:255'],
            'bandwidth_profile_id' => ['required', 'integer', Rule::exists('bandwidth_profiles', 'id')->where('company_id', $companyId)],
            'speed_profile_id' => ['required', 'integer', Rule::exists('speed_profiles', 'id')->where('company_id', $companyId)],
            'sla_tier_id' => ['required', 'integer', Rule::exists('sla_tiers', 'id')->where('company_id', $companyId)],
            'price_mrc' => ['required', 'numeric', 'min:0'],
            'price_otc' => ['sometimes', 'numeric', 'min:0'],
            'contract_min_months' => ['sometimes', 'integer', 'min:0'],
            'description' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ];
    }
}
