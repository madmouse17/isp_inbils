<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateEvaluationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('evaluation.update') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'score' => ['required', 'numeric', 'between:1.0,5.0'],
            'customer_rating' => ['nullable', 'numeric', 'between:1.0,5.0'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
