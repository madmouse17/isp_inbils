<?php

namespace Modules\Billing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CancelPaymentAttemptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('billing.payment.record') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
