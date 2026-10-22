<?php

namespace App\Http\Requests\Platform\Tenants;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/** The code typed in "Perusahaan baru", asked about before the form is sent: the same shape the form accepts. */
class CodeCheckRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:50', 'regex:'.StoreTenantRequest::CODE_PATTERN],
        ];
    }
}
