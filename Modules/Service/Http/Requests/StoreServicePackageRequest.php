<?php

namespace Modules\Service\Http\Requests;

use App\Http\Requests\Concerns\ResolvesPublicIds;
use App\Services\Core\CompanyService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Service\Models\BandwidthProfile;
use Modules\Service\Models\ServicePackageCategory;
use Modules\Service\Models\SLATier;
use Modules\Service\Models\SpeedProfile;

class StoreServicePackageRequest extends FormRequest
{
    use ResolvesPublicIds;

    /** @return array<string, class-string> */
    protected function publicIdFields(): array
    {
        return [
            'bandwidth_profile_id' => BandwidthProfile::class,
            'speed_profile_id' => SpeedProfile::class,
            'sla_tier_id' => SLATier::class,
            'service_package_category_id' => ServicePackageCategory::class,
        ];
    }

    public function authorize(): bool
    {
        return $this->user()?->can('service.create') || $this->user()?->can('service.manage');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $companyId = CompanyService::currentId();

        return [
            'code' => ['required', 'string', 'max:255', Rule::unique('service_packages')->where('company_id', $companyId)],
            'name' => ['required', 'string', 'max:255'],
            'bandwidth_profile_id' => ['required', 'integer', Rule::exists('bandwidth_profiles', 'id')->where('company_id', $companyId)->where('is_active', true)],
            'speed_profile_id' => ['required', 'integer', Rule::exists('speed_profiles', 'id')->where('company_id', $companyId)->where('is_active', true)],
            'sla_tier_id' => ['required', 'integer', Rule::exists('sla_tiers', 'id')->where('company_id', $companyId)->where('is_active', true)],
            'service_package_category_id' => ['required', 'integer', Rule::exists('service_package_categories', 'id')->where('company_id', $companyId)->where('is_active', true)],
            'price_mrc' => ['required', 'numeric', 'min:0'],
            'price_otc' => ['sometimes', 'numeric', 'min:0'],
            'contract_min_months' => ['sometimes', 'integer', 'min:0'],
            'description' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'bandwidth_profile_id' => 'profil bandwidth',
            'speed_profile_id' => 'profil kecepatan',
            'sla_tier_id' => 'tingkat SLA',
            'service_package_category_id' => 'kategori',
            'price_mrc' => 'harga bulanan',
            'price_otc' => 'harga pemasangan',
            'contract_min_months' => 'masa kontrak minimum',
        ];
    }
}
