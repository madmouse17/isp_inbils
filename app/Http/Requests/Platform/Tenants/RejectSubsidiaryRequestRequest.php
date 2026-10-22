<?php

namespace App\Http\Requests\Platform\Tenants;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/** Turning down a tenant's request for a subsidiary (K34): the reason is audited and mailed to the person who asked. The step-up is the route's job. */
class RejectSubsidiaryRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'min:5', 'max:200']];
    }
}
