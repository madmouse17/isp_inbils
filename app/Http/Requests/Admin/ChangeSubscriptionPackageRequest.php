<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Concerns\ResolvesPublicIds;
use App\Services\Core\CompanyService;
use App\Support\RecordNotFound;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Service\Models\ServicePackage;

/** "Ganti paket": the package whose latest version the subscription moves to, and optionally the new monthly fee. */
class ChangeSubscriptionPackageRequest extends FormRequest
{
    use ResolvesPublicIds;

    /** @return array<string, class-string> */
    protected function publicIdFields(): array
    {
        return ['service_package_id' => ServicePackage::class];
    }

    public function authorize(): bool
    {
        return $this->user()?->can('customer.subscription.manage') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'service_package_id' => ['required', Rule::exists('service_packages', 'id')
                ->where('company_id', CompanyService::currentId())
                ->where('is_active', true)
                ->whereNull('deleted_at')],
            // Empty: the monthly fee becomes the price of the version the subscription moves to. The column is decimal(15,2).
            'mrc_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999999.99'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'service_package_id.exists' => 'Pilih paket layanan yang masih aktif.',
        ];
    }

    /**
     * ResolvesPublicIds leaves an id it cannot resolve as it was sent: that is a package of another company or one that
     * does not exist, a record this caller may not know of, so it answers the uniform 404 and not a field error
     * (spec .scratch/opaque-ids K3). A package of the company that is only inactive stays a field error.
     */
    protected function failedValidation(Validator $validator): void
    {
        RecordNotFound::abortIf($this->filled('service_package_id') && ! is_int($this->input('service_package_id')));

        parent::failedValidation($validator);
    }
}
