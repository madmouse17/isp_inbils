<?php

namespace Modules\Ticketing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTicketCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('ticket.category.manage') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'default_sla_hours' => ['required', 'integer', 'min:1', 'max:720'],
            'default_response_hours' => ['required', 'integer', 'min:1', 'max:720', 'lte:default_sla_hours'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'default_response_hours.lte' => 'Target respons tidak boleh lebih lama dari target penyelesaian.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'name' => 'nama',
            'default_sla_hours' => 'target penyelesaian',
            'default_response_hours' => 'target respons',
            'is_active' => 'status',
        ];
    }
}
