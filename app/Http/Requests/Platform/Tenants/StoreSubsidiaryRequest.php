<?php

namespace App\Http\Requests\Platform\Tenants;

use App\Http\Requests\Concerns\ResolvesPublicIds;
use App\Models\Core\Company;
use App\Models\User;
use App\Services\Platform\SubsidiaryRequestService;
use Closure;
use DateTimeZone;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A subsidiary for a tenant. Currency and timezone default to the head's. Members are users homed anywhere in this
 * tenant (head or subsidiaries): the platform never enrols a stranger from another tenant. It may answer one of the
 * tenant's open requests for a subsidiary (K34), which the creation then closes.
 */
class StoreSubsidiaryRequest extends FormRequest
{
    use ResolvesPublicIds;

    /** @return array<string, class-string> */
    protected function publicIdFields(): array
    {
        return ['member_ids.*' => User::class];
    }

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', 'regex:'.StoreTenantRequest::CODE_PATTERN, Rule::unique('companies', 'code')],
            'currency' => ['nullable', 'string', Rule::in(StoreTenantRequest::CURRENCIES)],
            'timezone' => ['nullable', 'string', Rule::in(DateTimeZone::listIdentifiers())],
            'member_ids' => ['nullable', 'array'],
            'member_ids.*' => ['integer', 'distinct', Rule::exists('users', 'id')->whereIn('company_id', $this->tenantCompanyIds())],
            // The tenant's own open request this subsidiary answers; the action closes it. The action re-checks under the lock.
            'subsidiary_request_id' => ['nullable', 'bail', 'string', 'ulid', function (string $attribute, mixed $value, Closure $fail): void {
                if (app(SubsidiaryRequestService::class)->findOpen($this->tenant(), (string) $value) === null) {
                    $fail('Permintaan tidak ditemukan atau sudah ditutup.');
                }
            }],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['member_ids' => 'anggota', 'member_ids.*' => 'anggota', 'subsidiary_request_id' => 'permintaan'];
    }

    /** The tenant in the URL: a top-level company, the route binding answers 404 for any other. */
    private function tenant(): Company
    {
        /** @var Company $root */
        $root = $this->route('company');

        return $root;
    }

    /** @return list<int> the tenant's head and its subsidiaries */
    private function tenantCompanyIds(): array
    {
        $root = $this->tenant();

        return [$root->id, ...Company::query()->where('parent_company_id', $root->id)->pluck('id')->all()];
    }
}
