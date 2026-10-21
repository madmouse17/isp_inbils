<?php

namespace Modules\Ticketing\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The customer's answer to "Masalah sudah beres?": a rating of 1 to 5 and, if they like, a word about it (K14). Whose ticket it is,
 * and whether it can be rated, is decided by TicketPolicy::confirm and TicketService::confirmByCustomer.
 */
class ConfirmCustomerTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('evaluation.customer.submit') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'rating' => ['required', 'integer', 'between:1,5'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
