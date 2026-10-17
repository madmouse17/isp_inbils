<?php

namespace App\Http\Requests\Platform\Tenants;

use Illuminate\Contracts\Validation\ValidationRule;

class SuspendTenantRequest extends ConfirmedTenantRequest
{
    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [...parent::rules(), 'reason' => ['required', 'string', 'min:5', 'max:200']];
    }
}
