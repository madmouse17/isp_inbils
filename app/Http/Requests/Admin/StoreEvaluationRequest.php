<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreEvaluationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('evaluation.create') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'exists:users,id'],
            'reference_type' => ['required', 'string', 'in:WorkOrder,Ticket'],
            'reference_id' => ['required', 'integer'],
            'score' => ['required', 'numeric', 'between:1.0,5.0'],
            'customer_rating' => ['nullable', 'numeric', 'between:1.0,5.0'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
