<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class MyWorkRequest extends FormRequest
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
            'domain' => ['sometimes', 'nullable', 'string', 'max:50'],
            'status' => ['sometimes', 'nullable', 'string', 'max:50'],
            'priority' => ['sometimes', 'nullable', 'string', 'max:50'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'date_from' => ['sometimes', 'nullable', 'string', 'max:10'],
            'date_to' => ['sometimes', 'nullable', 'string', 'max:10'],
            'sort' => ['sometimes', 'nullable', 'string', 'max:50'],
            'direction' => ['sometimes', 'nullable', 'string', 'max:4'],
            'per_page' => ['sometimes', 'integer', 'min:1'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
