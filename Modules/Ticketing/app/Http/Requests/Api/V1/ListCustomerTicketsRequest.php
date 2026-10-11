<?php

namespace Modules\Ticketing\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListCustomerTicketsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('ticket.view') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['nullable', 'string', Rule::in(['active', 'closed', 'all'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }

    public function perPage(): int
    {
        return $this->integer('per_page') ?: 20;
    }
}
