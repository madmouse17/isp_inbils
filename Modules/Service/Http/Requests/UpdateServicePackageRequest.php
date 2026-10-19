<?php

namespace Modules\Service\Http\Requests;

use App\Http\Requests\Concerns\ResolvesPublicIds;
use App\Services\Core\CompanyService;
use App\Support\OptimisticLock;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Modules\Service\Models\BandwidthProfile;
use Modules\Service\Models\ServicePackage;
use Modules\Service\Models\ServicePackageCategory;
use Modules\Service\Models\SLATier;
use Modules\Service\Models\SpeedProfile;

class UpdateServicePackageRequest extends FormRequest
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
        return $this->user()?->can('service.update') || $this->user()?->can('service.manage');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $companyId = CompanyService::currentId();
        $route = $this->route('service_package');
        $package = $route instanceof ServicePackage ? $route : null;

        return [
            OptimisticLock::FIELD => OptimisticLock::rules(),
            'code' => ['required', 'string', 'max:255', Rule::unique('service_packages')->where('company_id', $companyId)->ignore($package?->id)],
            'name' => ['required', 'string', 'max:255'],
            'bandwidth_profile_id' => ['required', 'integer', $this->activeOrHeld('bandwidth_profiles', $package?->bandwidth_profile_id)],
            'speed_profile_id' => ['required', 'integer', $this->activeOrHeld('speed_profiles', $package?->speed_profile_id)],
            'sla_tier_id' => ['required', 'integer', $this->activeOrHeld('sla_tiers', $package?->sla_tier_id)],
            'service_package_category_id' => ['required', 'integer', $this->activeOrHeld('service_package_categories', $package?->service_package_category_id)],
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

    /**
     * A row of the company that is active, or the one the package already holds: a retired profile cannot be picked
     * again, yet the package that uses it stays editable. A deleted row never gets here, its public id does not resolve.
     */
    private function activeOrHeld(string $table, ?int $heldId): Exists
    {
        return Rule::exists($table, 'id')->where(fn ($query) => $query
            ->where('company_id', CompanyService::currentId())
            ->where(fn ($active) => $active->where('is_active', true)->orWhere('id', $heldId)));
    }
}
