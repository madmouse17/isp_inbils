<?php

namespace Modules\Billing\Http\Requests\Api\V1\Customer;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListInvoicesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::in(['unpaid', 'paid', 'all'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }

    public function status(): string
    {
        $status = $this->validated('status');

        return is_string($status) ? $status : 'all';
    }

    public function perPage(): int
    {
        $perPage = $this->validated('per_page');

        return is_numeric($perPage) ? (int) $perPage : 20;
    }
}
