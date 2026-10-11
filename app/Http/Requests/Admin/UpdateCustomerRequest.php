<?php

namespace App\Http\Requests\Admin;

use App\Models\Core\Customer;
use App\Queries\Admin\CustomerAcquirerQuery;
use App\Rules\IsEligibleCustomerAcquirer;
use App\Services\Core\CompanyService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('customer.update') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $routeCustomer = $this->route('customer');
        $customerId = is_object($routeCustomer) ? $routeCustomer->id : $routeCustomer;
        $companyId = CompanyService::currentId();

        return [
            'code' => ['required', 'string', 'max:50', Rule::unique('customers')->where('company_id', $companyId)->ignore($customerId)],
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
            ...$this->acquirerRules($customerId),
        ];
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
