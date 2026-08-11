<?php

namespace Modules\Ticketing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ResolveTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('ticket.resolve') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'resolution_note' => ['required', 'string', 'max:1000'],
        ];
    }
}
