<?php

namespace App\Http\Requests\Platform\Tenants;

use App\Models\Core\Company;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A lifecycle action on a tenant that the operator confirms by typing the tenant's company code, compared exactly
 * (case included) with the code of the head company in the URL. The platform password step-up is the route's job.
 */
abstract class ConfirmedTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        /** @var Company $tenant */
        $tenant = $this->route('company');

        return ['confirmation_code' => ['required', 'string', Rule::in([$tenant->code])]];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['confirmation_code.in' => 'Kode perusahaan yang diketik tidak sesuai.'];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['confirmation_code' => 'kode konfirmasi'];
    }
}
