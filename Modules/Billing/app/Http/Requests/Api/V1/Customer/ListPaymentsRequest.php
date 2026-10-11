<?php

namespace Modules\Billing\Http\Requests\Api\V1\Customer;

use Illuminate\Foundation\Http\FormRequest;

class ListPaymentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }

    public function perPage(): int
    {
        $perPage = $this->validated('per_page');

        return is_numeric($perPage) ? (int) $perPage : 20;
    }
}
