<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Concerns\ResolvesPublicIds;
use App\Models\Core\Customer;
use App\Models\Core\Location;
use App\Models\User;
use App\Queries\Admin\CustomerAcquirerQuery;
use App\Rules\IsEligibleCustomerAcquirer;
use App\Services\Core\CompanyService;
use App\Services\Core\NumberSequenceService;
use App\Support\OptimisticLock;
use App\Support\PublicId;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCustomerRequest extends FormRequest
{
    use ResolvesPublicIds;

    /** @return array<string, class-string> */
    protected function publicIdFields(): array
    {
        return ['area_coverage_id' => Location::class, 'acquired_by_user_id' => User::class];
    }

    public function authorize(): bool
    {
        return $this->user()?->can('customer.update') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $routeCustomer = $this->route('customer');
        $customerId = $routeCustomer instanceof Customer ? $routeCustomer->id : PublicId::resolveOne(Customer::class, $routeCustomer);
        $companyId = CompanyService::currentId();

        return [
            'code' => NumberSequenceService::allowsManual('customer')
                ? ['required', 'string', 'max:50', Rule::unique('customers')->where('company_id', $companyId)->ignore($customerId)]
                : ['exclude'],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'in:Individual,Company'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50', 'regex:/^[0-9()+-]+$/'],
            'tax_id' => ['nullable', 'string', 'max:100'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'area_coverage_id' => ['nullable', Rule::exists('locations', 'id')->where('company_id', $companyId)],
            'notes' => ['nullable', 'string'],
            'is_active' => ['boolean'],
            'whatsapp_opt_in' => ['sometimes', 'boolean'],
            OptimisticLock::FIELD => OptimisticLock::rules(),
            ...$this->acquirerRules($customerId),
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['type' => 'tipe pelanggan'];
    }

    /** @return array<string, string> an id that names no record answers as an employee who may not acquire does: nobody learns which */
    public function messages(): array
    {
        return ['acquired_by_user_id.exists' => 'Pilih karyawan aktif yang berhak membuat pelanggan.'];
    }

    /**
     * Holders of neither sales capability never get these keys validated, so a forged value never
     * reaches validated(). Re-submitting the current acquirer is a no-op (it may be a former employee
     * who is no longer eligible). A change must name an eligible employee; correcting or clearing a set
     * acquirer needs a reason. The capability itself is enforced by AssignCustomerAcquirerAction.
     *
     * @return array<string, array<int, mixed>>
     */
    private function acquirerRules(int|string|null $customerId): array
    {
        $user = $this->user();

        if (! ($user?->can('viewAcquirer', Customer::class) ?? false)) {
            return [];
        }

        $current = Customer::query()
            ->forCompany(CompanyService::idFor($user))
            ->whereKey($customerId)
            ->value('acquired_by_user_id');
        $submitted = $this->input('acquired_by_user_id');
        $normalized = $submitted === null ? null : (CustomerAcquirerQuery::isId($submitted) ? (int) $submitted : false);
        $isChange = $this->has('acquired_by_user_id') && $normalized !== $current;
        $isCorrection = $isChange && $current !== null && $user->can('correctAcquirer', Customer::class);

        return [
            'acquired_by_user_id' => ['nullable', 'bail', CustomerAcquirerQuery::idRule(), ...($isChange && $submitted !== null ? [new IsEligibleCustomerAcquirer()] : [])],
            'acquirer_correction_reason' => [Rule::requiredIf($isCorrection), 'nullable', 'string', 'max:500'],
        ];
    }
}
