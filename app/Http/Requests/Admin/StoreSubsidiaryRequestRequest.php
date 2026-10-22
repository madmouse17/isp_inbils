<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Platform\Tenants\StoreTenantRequest;
use App\Services\Core\CompanyService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/**
 * "Minta anak perusahaan" (K34): what a tenant admin asks the platform operator for. The code is a suggestion in the
 * format of every company code and is never checked against the codes that exist: a tenant must not be able to probe
 * which codes are taken (spec platform-superadmin R3), and the operator sets the final one when creating the company.
 * Who may ask is CompanyPolicy::requestSubsidiary.
 */
class StoreSubsidiaryRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        $company = CompanyService::current();

        return $company !== null && ($this->user()?->can('requestSubsidiary', $company) ?? false);
    }

    /** A name and a code are one line: the operator reads them in a mail subject and a table. */
    protected function prepareForValidation(): void
    {
        foreach (['name', 'code'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => Str::squish($this->input($field))]);
            }
        }
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:3', 'max:100'],
            'code' => ['nullable', 'string', 'max:50', 'regex:'.StoreTenantRequest::CODE_PATTERN],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
