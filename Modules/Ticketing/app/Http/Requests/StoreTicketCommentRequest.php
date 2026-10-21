<?php

namespace Modules\Ticketing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTicketCommentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('ticket.comment.create') ?? false;
    }

    protected function prepareForValidation(): void
    {
        // is_internal is staff-only; customers always comment publicly.
        if ($this->user()?->can('portal.access')) {
            $this->merge(['is_internal' => false]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:2000'],
            'is_internal' => ['boolean'],
        ];
    }
}
