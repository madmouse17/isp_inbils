<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateMeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            // The web's customer rule, plus the space people type inside a number; null clears it.
            'phone' => ['sometimes', 'nullable', 'string', 'max:50', 'regex:/^[0-9()+\- ]+$/'],
        ];
    }
}
